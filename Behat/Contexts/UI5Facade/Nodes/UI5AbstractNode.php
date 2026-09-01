<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Common\ErrorManager;
use axenox\BDT\Behat\Common\Traits\CdpConnectionDetectorTrait;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\AjaxException;
use axenox\BDT\Exceptions\ChromeHangException;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Exceptions\FacadeNodeScriptException;
use axenox\BDT\Exceptions\UIException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Session;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use PHPUnit\Framework\Assert;
use Throwable;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    use CdpConnectionDetectorTrait;

    const CATEGORY_FILTERING = 'Filtering';
    const CATEGORY_BUTTONS = 'Buttons';
    /**
     * Suffix UI5 appends to an OverflowToolbar's id to build its overflow ("...") button.
     *
     * WHY THESE LIVE ON THE ABSTRACT NODE AND NOT ON THE TABLE: an OverflowToolbar is not a table
     * feature - dialogs, panels and forms render one too. Every node that resolves a button has to
     * be able to look behind the overflow, so the knowledge belongs to the common base. The two
     * suffixes stay next to each other because the id is the only reliable bridge between a button
     * and the popover it opens; a UI5 upgrade renaming one would have to rename the other as well.
     */
    const OVERFLOW_BUTTON_ID_SUFFIX = '-overflowButton';

    /**
     * Suffix UI5 appends to an OverflowToolbar's id to build the popover holding the moved buttons.
     */
    const OVERFLOW_MENU_ID_SUFFIX = '-popover';

    /**
     * CSS of the toolbar overflow ("...") button.
     *
     * WHY ONLY THE ID SUFFIX AND NO LOOSER VARIANTS: the id is what makes the button traceable back
     * to its toolbar and forward to its popover. A looser `id*="overflowButton"` match could pick an
     * element that does not follow that naming and would then silently break the popover resolution.
     */
    const CSS_OVERFLOW_BUTTON = 'button[id$="' . self::OVERFLOW_BUTTON_ID_SUFFIX . '"]';

    /**
     * Translation key of the title of the core's "unsaved changes" confirmation.
     *
     * WHY THE KEY AND NOT THE TEXT: the core renders this MessageBox
     * (getConfirmationsForUnsavedChanges()) in the language of the logged-in test user, so its
     * caption reads "Änderungen verwerfen?" on a German system and "Unsaved changes?" on an
     * English one. Matching the translated key is the only locale-independent way to tell this
     * particular dialog apart from every other sap.m message dialog - and the only way that does
     * not silently swallow a genuine warning that happens to look similar.
     */
    const TRANSLATION_DISCARD_CHANGES_TITLE = 'MESSAGE.DISCARD_CHANGES.TITLE';

    /**
     * Translation key of the button that leaves the dialog and drops the unsaved changes.
     *
     * WHY THIS BUTTON AND NOT ITS CSS CLASS: UI5 marks it as `sapMDialogBeginButton`, but that
     * class only says "this is the primary action of a MessageBox" - it would just as happily
     * match a "Save" button in a differently built confirmation. The caption key names the
     * intent. Discarding is also the only correct choice for the framework: a validation run
     * must never persist the values it typed into the dialog, and pressing Cancel would leave
     * the dialog open forever.
     */
    const TRANSLATION_DISCARD_CHANGES_CONTINUE = 'MESSAGE.DISCARD_CHANGES.CONTINUE';

    /**
     * How many action-triggered checks may be nested into each other.
     *
     * WHY 3: a dialog opening a detail dialog opening a lookup dialog is a real pattern and must stay
     * testable, but nothing beyond that carries information a test run needs - it only multiplies the
     * runtime. The limit is a safety net against cycles, not a modelling statement.
     */
    public const MAX_NESTING_DEPTH = 3;
    /**
     * Current depth of nested checks triggered by an action (a dialog opened from within a dialog,
     * a page opened from within a page).
     *
     * WHY it is static: every nested check runs on a freshly created node, so an instance property
     * could never tell how deep the chain already is.
     *
     * WHY it is needed at all although actions are memoized: the memo in UI5ButtonNode is written
     * only AFTER the nested check returned. On a cyclic path - dialog A holds a button opening
     * dialog A again, or page A links to page B which links back to page A - the second visit happens
     * while the first one is still running, so the memo is still empty and the recursion never ends.
     */

    /**
     * Id of the overflow popover THIS node opened and is therefore responsible for closing again.
     *
     * WHY AN ID AND NOT THE NodeElement: the popover element goes stale as soon as UI5 re-renders
     * the toolbar, and reading an attribute off a stale handle throws. The id is a plain string that
     * stays usable for the closing round-trip no matter what happened to the DOM in between.
     */
    private ?string $overflowMenuIdOpened = null;
    
    private static int $nestingDepth = 0;
    /** @var UI5Browser|null */
    protected $browser;

    protected ?WidgetInterface $widget = null;
    private $domNode = null;
    private $session = null;

    /**
     * @param NodeElement $nodeElement
     * @param Session $session
     * @param UI5Browser $browser
     * @param WidgetInterface|null $widget The widget this node stands for, if the caller knows it
     *
     * WHY the optional $widget: the way from a widget to its element id is lossy, so the way back is
     * not generally possible. cleanId() replaces the dot - which UiPage uses as its id space
     * separator - with "_", so a widget behind an id space cannot be looked up by its rendered id
     * any more. Whenever the caller already holds the widget (the container check iterates the model
     * anyway), handing it over avoids that dead end instead of guessing from the DOM.
     */
    public function __construct(NodeElement $nodeElement, Session $session, UI5Browser $browser, ?WidgetInterface $widget = null)
    {
        $this->domNode = $nodeElement;
        $this->session = $session;
        $this->browser = $browser;
        $this->widget = $widget;
    }

    public static function findWidgetNode(NodeElement $innerDomNode): NodeElement
    {
        if ($innerDomNode->hasClass('exfw')) {
            return $innerDomNode;
        }

        try {
            $currentDomNode = $innerDomNode;
            while ($parentDomNode = $currentDomNode->getParent()) {
                if ($parentDomNode->hasClass('exfw')) {
                    return $parentDomNode;
                }
                $currentDomNode = $parentDomNode;
            }
        } catch (DriverException $e) {
            return $innerDomNode;
        }
        return $innerDomNode;
    }

    /**
     * Tells whether another action-triggered check may be nested into the current one.
     *
     * @return bool
     */
    protected static function isNestingLimitReached(): bool
    {
        return self::$nestingDepth >= self::MAX_NESTING_DEPTH;
    }

    /**
     * Runs the given check one nesting level deeper.
     *
     * WHY the counter is decremented in a finally block: the check may fail with an exception, and a
     * counter left too high would silently skip every later dialog of the same scenario. The
     * exception itself is not touched - it propagates as before.
     *
     * @param callable $check
     * @return TestResultInterface
     */
    protected static function runNested(callable $check): TestResultInterface
    {
        self::$nestingDepth++;
        try {
            return $check();
        } finally {
            self::$nestingDepth--;
        }
    }

    /**
     * {@inheritDoc}
     * @see FacadeNodeInterface::usesOwnDomElement()
     */
    public function usesOwnDomElement(): bool
    {
        return true;
    }

    public function capturesFocus(): bool
    {
        return true;
    }

    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $widgetType = $this->getWidgetType();
        $logbook->addLine('No checks defined at `' . $widgetType . '` ' . $this->getCaption());
        return SubstepResult::createPassed($logbook);
    }

    /**
     * Returns the widget type this node stands for.
     *
     * WHY the model comes first: the `exfw exfw-<Type>` CSS classes are not guaranteed. UI5Display
     * overrides buildJsConstructor() without adding them, so a Display and everything derived from it
     * (e.g. a Text inside an InlineGroup) carries only `exf-element`. Deriving the type from the DOM
     * therefore aborted with "Cannot find widget inside of DOM node" for controls that were rendered
     * perfectly well. The DOM stays as the fallback for nodes discovered by search, where no widget
     * is known.
     */
    public function getWidgetType(): ?string
    {
        if ($this->widget !== null) {
            return $this->widget->getWidgetType();
        }
        if (null !== $thisElementClass = UI5FacadeNodeFactory::findWidgetType($this->getNodeElement())) {
            return $thisElementClass;
        }
        $firstWidgetChild = $this->getNodeElement()->find('css', '.exfw');
        if (!$firstWidgetChild) {
            throw new FacadeNodeException($this, 'Cannot find widget inside of DOM node "' . $this->getNodeElement()->getXpath() . '"');
        }
        return UI5FacadeNodeFactory::findWidgetType($firstWidgetChild);
    }

    /**
     * Returns the Mink DOM node element representing the widget.
     *
     * @return NodeElement
     */
    public function getNodeElement(): NodeElement
    {
        return $this->domNode;
    }

    /**
     * Returns the rendered caption of this widget node.
     *
     * WHY the guards: UI5 exposes a caption via `aria-label` only for some controls, and where it does,
     * the attribute often carries the caption plus the debug block on following lines. strstr() returns
     * FALSE both when the attribute is absent and when it contains no newline at all, so returning its
     * result directly violated the declared string return type and raised a TypeError for every control
     * with a single-line or missing label - which is the majority of them.
     *
     * {@inheritDoc}
     * @see FacadeNodeInterface::getCaption()
     */
    public function getCaption(): string
    {
        $label = $this->getNodeElement()->getAttribute('aria-label');
        if ($label === null || $label === '') {
            return '';
        }
        $firstLine = strstr($label, "\n", true);
        return trim($firstLine === false ? $label : $firstLine);
    }

    public function findVisibleButtonByCaption(string $caption, bool $isTranslated, ?NodeElement $scope = null): ?NodeElement
    {
        if (!$isTranslated) {
            $caption = $this->translate($caption);
        }

        $xpath = sprintf(
            ".//button[
            .//bdi[normalize-space(.)=%s]
            or normalize-space(@title)=%s
            or normalize-space(@aria-label)=%s
        ]",
            $this->xpathLiteral($caption),
            $this->xpathLiteral($caption),
            $this->xpathLiteral($caption)
        );

        // If scope is given, search ONLY within scope — throw if not found there
        if ($scope !== null) {
            $scope = $this->getWidgetScope($scope);
            $candidates = $scope->findAll('xpath', $xpath);
            foreach (array_reverse($candidates) as $el) {
                if ($this->isElementVisibleInBrowser($el)) {
                    return $el;
                }
            }
            return null;
        }

        // No scope: fall back to full page search
        $candidates = $this->getSession()->getPage()->findAll('xpath', $xpath);
        foreach (array_reverse($candidates) as $el) {
            if ($this->isElementVisibleInBrowser($el)) {
                return $el;
            }
        }

        return null;
    }

    /**
     * Safely quote arbitrary strings for XPath literal usage.
     */
    public function xpathLiteral(string $value): string
    {
        // If the string contains no single quotes, we can wrap it in single quotes.
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        // Otherwise build concat('a', "'", 'b', ...)
        $parts = explode("'", $value);
        $out = "concat(";
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $out .= ", \"'\", ";
            }
            $out .= "'" . $p . "'";
        }
        $out .= ")";
        return $out;
    }

    /**
     * Returns the nearest widget root ancestor that contains both
     * the toolbar and the content area of a widget.
     *
     * Use this to resolve the correct scope before passing it to
     * findVisibleButtonByCaption() when the button lives outside
     * the element you have at hand (e.g. toolbar above a sapUiTable).
     *
     * Priority:
     *  1. Open dialog  — never escape a dialog boundary
     *  2. article|section with data-sap-ui-render — SAP UI5 widget root
     *  3. Nearest exfw ancestor — ExFace widget container fallback
     */
    public function getWidgetScope(NodeElement $inner): NodeElement
    {
        // 1. If inside a dialog, the dialog itself is the boundary
        $dialog = $inner->find('xpath',
            'ancestor-or-self::*[@role="dialog"][1]'
        );
        if ($dialog !== null) {
            return $dialog;
        }

        // 2. Nearest SAP UI5 rendered widget root (article or section)
        $sapRoot = $inner->find('xpath',
            'ancestor-or-self::*[@data-sap-ui-render and (self::article or self::section)][1]'
        );
        if ($sapRoot !== null) {
            return $sapRoot;
        }

        // 3. Fallback: nearest ExFace widget container
        $exfRoot = $inner->find('xpath',
            'ancestor-or-self::*[contains(concat(" ",normalize-space(@class)," ")," exfw ")][1]'
        );

        return $exfRoot ?? $inner;
    }

    public function isElementVisibleInBrowser(NodeElement $el): bool
    {
        $id = $el->getAttribute('id');
        if (!$id) {
            return false;
        }

        $idJs = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $script = <<<JS
(function(){
  var el = document.getElementById($idJs);
  if (!el) return false;

  // Check aria-hidden on ancestors
  for (var p = el; p; p = p.parentElement) {
    if (p.getAttribute && p.getAttribute('aria-hidden') === 'true') return false;
  }

  var cs = window.getComputedStyle(el);
  if (!cs) return false;
  if (cs.display === 'none' || cs.visibility === 'hidden') return false;

  var opacity = parseFloat(cs.opacity || '1');
  if (opacity <= 0) return false;

  var rect = el.getBoundingClientRect();
  if (!rect || (rect.width <= 0 && rect.height <= 0)) return false;

  return true;
})();
JS;

        return (bool)$this->getSession()->evaluateScript($script);
    }

    /**
     * Returns every toolbar overflow ("...") button that could belong to this node.
     *
     * WHY IT MAY HAVE TO LOOK BEYOND getNodeElement(): depending on the facade template the widget
     * element is sometimes the sapUiTable/content itself, with the toolbar rendered as a SIBLING
     * above it. getWidgetScope() resolves the nearest ancestor holding both.
     *
     * WHY IT RETURNS A LIST INSTEAD OF ONE BUTTON: the widened scope can legitimately span several
     * toolbars. The strict single-button resolution (findOverflowButton) refuses that case, which is
     * right for an explicit "open the overflow menu" step, but wrong for a fallback search - there we
     * simply want to look into every menu in reach instead of failing on ambiguity.
     *
     * @return NodeElement[]
     */
    protected function findOverflowButtons(): array
    {
        // The node element first: when the toolbar IS inside it, these buttons are unambiguous by
        // definition and the scope must not be widened any further.
        $buttons = $this->getNodeElement()->findAll('css', self::CSS_OVERFLOW_BUTTON);
        if (! empty($buttons)) {
            return $buttons;
        }
        return $this->getWidgetScope($this->getNodeElement())->findAll('css', self::CSS_OVERFLOW_BUTTON);
    }

    /**
     * Locates the ONE toolbar overflow button belonging to this node.
     *
     * WHY THE COUNT CHECK: widening the scope is only safe as long as it stays inside ONE widget. On
     * a split layout the nearest rendered widget root can span several tables, and silently taking
     * the first overflow button found there would operate on the neighbouring widget - the exact kind
     * of failure a test cannot notice, because the menu does open, just for the wrong widget.
     * Refusing an ambiguous scope turns that into a visible, explainable failure.
     *
     * @throws RuntimeException If the widened scope contains more than one overflow button.
     * @return NodeElement|null
     */
    protected function findOverflowButton(): ?NodeElement
    {
        $own = $this->getNodeElement()->find('css', self::CSS_OVERFLOW_BUTTON);
        if ($own !== null) {
            return $own;
        }

        $buttons = $this->findOverflowButtons();
        if (count($buttons) > 1) {
            throw new RuntimeException(
                'Cannot tell which overflow button belongs to ' . $this->getWidgetType() . ' `' . $this->getCaption()
                . '`: its own element has none and the surrounding widget scope contains ' . count($buttons)
                . ' of them. Focus the widget first (e.g. "I look at table 1") or name it in the step'
                . ' ("... on the 2 table") so the search can be scoped to one toolbar'
            );
        }

        return $buttons[0] ?? null;
    }

    /**
     * Looks for a button behind the toolbar overflow using the loose caption match of the click steps.
     *
     * WHY IT IS SEPARATE FROM findButtonInOverflowByCaption(): that one matches captions exactly and
     * translates them first, which is right for framework-driven checks. The click steps match
     * "contains, case insensitive" on text and tooltip, and the overflow fallback must match captions
     * exactly the way the regular search of the same step does - otherwise a button that the step
     * would have clicked in the toolbar becomes unreachable once UI5 moves it into the overflow.
     */
    public function findButtonInOverflowByCaptionLoose(string $caption): ?NodeElement
    {
        return $this->findInOverflow(function (NodeElement $menu) use ($caption) {
            return $this->getBrowser()->findButtonInScopeByCaption($menu, $caption);
        });
    }

    /**
     * Clicks the given overflow button and returns the popover it opened.
     *
     * WHY IT IS SEPARATE FROM clickOverflowButton(): the click-wait-retry mechanics are identical for
     * the explicit "open the overflow menu" step (which must fail loudly) and for the silent fallback
     * search (which must just move on to the next toolbar). Only the error handling differs, so only
     * the error handling is duplicated - never the interaction itself.
     *
     * @param NodeElement $button
     * @return NodeElement|null The opened popover, or null if it did not become visible in time.
     */
    protected function pressOverflowButton(NodeElement $button): ?NodeElement
    {
        $this->getBrowser()->highlightWidget($button, 'Button', 0);
        $button->click();

        $menu = $this->waitForOverflowMenu($button);
        if ($menu === null) {
            // Retry once: the first click is occasionally swallowed while UI5 is still attaching the
            // toolbar press handler, and a second click then opens the menu.
            $button->click();
            $menu = $this->waitForOverflowMenu($button);
        }

        if ($menu !== null) {
            // Remember what we opened so whoever touches the page behind the popover afterwards can
            // close it - an open popover swallows the next click on the page underneath it.
            $this->overflowMenuIdOpened = (string) $menu->getAttribute('id');
        }

        return $menu;
    }

    /**
     * Opens the toolbar overflow ("...") menu of THIS node and returns the opened popover.
     *
     * WHY IT RETURNS THE POPOVER: the step that follows this one always wants to act on an entry of
     * that menu. Handing back the resolved container means the follow-up step never has to search
     * the page for "a popover" and can never act on the menu of a neighbouring widget.
     *
     * @throws RuntimeException If no overflow button is rendered, if it is rendered but hidden, or
     *         if clicking it does not open the menu.
     * @return NodeElement The opened overflow popover.
     */
    public function clickOverflowButton(): NodeElement
    {
        $button = $this->findOverflowButton();
        if ($button === null) {
            throw new RuntimeException(
                'Overflow button not found for ' . $this->getWidgetType() . ' `' . $this->getCaption() . '`'
            );
        }

        // Distinguish "not rendered" from "rendered but hidden": UI5 keeps the overflow button in the
        // DOM and only shows it once the toolbar actually overflows. Clicking a hidden button does
        // nothing at all, which would otherwise surface as the misleading "menu did not open" below.
        if (! $this->isElementVisibleInBrowser($button)) {
            throw new RuntimeException(
                'Overflow button of ' . $this->getWidgetType() . ' `' . $this->getCaption()
                . '` exists but is not visible - the toolbar is wide enough to show all buttons'
            );
        }

        $menu = $this->pressOverflowButton($button);
        if ($menu === null) {
            throw new RuntimeException(
                'Overflow button of ' . $this->getWidgetType() . ' `' . $this->getCaption()
                . '` was clicked, but its overflow menu did not become visible'
            );
        }

        return $menu;
    }

    /**
     * Clicks an entry of this node's toolbar overflow menu, opening the menu first if needed.
     *
     * WHY IT OPENS THE MENU ITSELF: a scenario reads "click X in the overflow menu" as one action.
     * Requiring a separate opening step would make the assertion depend on step ordering and would
     * break as soon as UI5 closes the popover on its own (e.g. after a re-render).
     *
     * WHY THE POPOVER IS PASSED AS SCOPE: getWidgetScope() stops at the nearest `role="dialog"`
     * ancestor, and the overflow popover carries exactly that role - so the button search is
     * guaranteed to stay inside this menu and can never reach the toolbar behind it.
     *
     * @param string $caption Caption of the entry, exactly as rendered in the menu.
     * @throws RuntimeException If the menu holds no visible entry with that caption.
     */
    public function clickOverflowMenuItem(string $caption): void
    {
        $menu = $this->clickOverflowButton();

        // isTranslated = true: the caption comes from the scenario and is already written the way
        // the user sees it, so it must not be run through the translator again.
        $item = $this->findVisibleButtonByCaption($caption, true, $menu);

        if ($item === null) {
            throw new RuntimeException(
                'No entry `' . $caption . '` in the overflow menu of ' . $this->getWidgetType()
                . ' `' . $this->getCaption() . '`'
            );
        }

        $this->getBrowser()->highlightWidget($item, 'Button', 0);
        $item->click();
        // UI5 closes the popover on its own once an entry was pressed, so there is nothing left for
        // closeOverflowMenuIfOpened() to do - forgetting it here avoids a pointless close round trip.
        $this->overflowMenuIdOpened = null;
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
    }

    /**
     * Runs the given search inside the toolbar overflow menu of THIS node.
     *
     * WHY THIS EXISTS: UI5 does not merely hide the buttons that do not fit into a toolbar - it moves
     * them into the overflow popover, which is not even rendered before that popover is opened for the
     * first time. A button that "is not on the page" is therefore indistinguishable from a button that
     * simply overflowed, and both a click step and the automatic button check would report it as
     * missing. Every caption-based button lookup needs this as its last resort before giving up.
     *
     * WHY THERE IS NO PAGE-WIDE SEARCH HERE, UNLIKE IN THE REGULAR BUTTON LOOKUP: a button can only be
     * reached through the overflow of the widget it belongs to, and that widget is always known - every
     * step that clicks a button either works on a focused widget or names its table explicitly, and the
     * automatic check runs on the node of the widget it is checking. Guessing an owner would mean
     * opening a foreign menu and clicking a same-named button of the neighbouring widget: the menu
     * opens, the click happens, the step turns green and nothing in the report shows that the wrong
     * widget was operated on. When nothing is focused, the focused node is a UI5PageNode, which does
     * not carry this method at all - so the fallback is structurally unreachable rather than merely
     * discouraged.
     *
     * WHY IT DOES NOT CLOSE THE MENU ON SUCCESS: the caller wants to act on what it found, and an
     * element inside a closed popover is not clickable. The popover is closed again by whoever next
     * interacts with the page behind it (see closeOverflowMenuIfOpened), or by UI5 itself on the click.
     *
     * @param callable $search Receives the opened popover NodeElement, returns a NodeElement or null.
     * @throws RuntimeException If this node's scope holds more than one overflow button.
     * @return NodeElement|null
     */
    public function findInOverflow(callable $search): ?NodeElement
    {
        $button = $this->findOverflowButton();
        // UI5 keeps the overflow button in the DOM even while the toolbar has room for all its
        // buttons. Clicking a hidden one does nothing and would only burn a full menu timeout.
        if ($button === null || ! $this->isElementVisibleInBrowser($button)) {
            return null;
        }

        $menu = $this->pressOverflowButton($button);
        if ($menu === null) {
            return null;
        }

        $found = $search($menu);
        if ($found === null) {
            // Leave the UI as we found it: the caller will report "not found" and go on working with
            // the page behind the popover.
            $this->closeOverflowMenuIfOpened();
        }
        return $found;
    }

    /**
     * Looks for a button with the given caption behind the toolbar overflow.
     *
     * Thin wrapper around findInOverflow() and the regular caption search, so that a caller does not
     * have to know how an overflow popover is opened to be able to fall back to it.
     *
     * @param string $caption
     * @param bool $isTranslated
     * @return NodeElement|null
     */
    public function findButtonInOverflowByCaption(string $caption, bool $isTranslated): ?NodeElement
    {
        return $this->findInOverflow(function (NodeElement $menu) use ($caption, $isTranslated) {
            return $this->findVisibleButtonByCaption($caption, $isTranslated, $menu);
        });
    }

    /**
     * Looks for an element with the given id behind the toolbar overflow.
     *
     * WHY IT DOES NOT GO THROUGH findInOverflow(): that method refuses an ambiguous scope, which is
     * right for a caption (several toolbars can offer "Export") but pointless for an id. An id is
     * unique in the document, so opening the menu of a neighbouring toolbar can never return the wrong
     * element - it just costs one round trip. Refusing to look would instead make the automatic button
     * check fail on every split layout where the widget scope spans two tables, turning a harmless
     * ambiguity into a red scenario.
     *
     * WHY IT SEARCHES THE WHOLE PAGE INSTEAD OF THE POPOVER: UI5 renders popovers into the static area,
     * and whether the moved button ends up inside the popover element or beside it is an implementation
     * detail of the UI5 version. The popover only had to be opened to make the element exist at all.
     *
     * @param string $elementId
     * @return NodeElement|null
     */
    public function findElementInOverflowById(string $elementId): ?NodeElement
    {
        foreach ($this->findOverflowButtons() as $button) {
            if (! $this->isElementVisibleInBrowser($button)) {
                continue;
            }

            $menu = $this->pressOverflowButton($button);
            if ($menu === null) {
                continue;
            }

            $el = $this->getSession()->getPage()->findById($elementId);
            if ($el !== null && $this->isElementVisibleInBrowser($el)) {
                return $el;
            }

            // Not this toolbar's menu - close it before opening the next one, otherwise the popovers
            // would stack and the last one could no longer be attributed to any button we opened.
            $this->closeOverflowMenuIfOpened();
        }

        return null;
    }

    /**
     * Closes the overflow popover this node opened, if it is still open.
     *
     * WHY EVERY INTERACTION WITH THE PAGE HAS TO CALL THIS FIRST: an open popover is modal enough to
     * swallow the next click on the page underneath it. Selecting a table row while a popover is open
     * therefore does not select the row - it only closes the popover - and the check that follows sees
     * an unselected row and blames the wrong thing.
     *
     * @throws RuntimeException If the popover is still visible after the close was requested.
     */
    public function closeOverflowMenuIfOpened(): void
    {
        $menuId = $this->overflowMenuIdOpened;
        if ($menuId === null || $menuId === '') {
            $this->overflowMenuIdOpened = null;
            return;
        }
        // Forget the popover BEFORE closing it: if the close fails, a second attempt would otherwise
        // be triggered from every later interaction and turn one clear error into a flood of them.
        $this->overflowMenuIdOpened = null;

        $idJs = json_encode($menuId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->getSession()->executeScript(<<<JS
(function(){
    var el = document.getElementById($idJs);
    if (! el || ! window.sap || ! sap.ui) { return; }
    var ctrl = sap.ui.getCore().byId(el.id);
    if (ctrl && typeof ctrl.close === 'function') { ctrl.close(); }
})();
JS
        );

        // Poll for the closing animation to finish. Presence alone proves nothing: UI5 keeps a once
        // opened popover in the DOM, so only its visibility can tell us the click is through again.
        $deadline = microtime(true) + 2;
        do {
            $menu = $this->getSession()->getPage()->find('xpath', '//*[@id=' . $this->xpathLiteral($menuId) . ']');
            if ($menu === null || ! $this->isElementVisibleInBrowser($menu)) {
                return;
            }
            usleep(100000);
        } while (microtime(true) < $deadline);

        throw new RuntimeException(
            'Overflow menu `' . $menuId . '` of ' . $this->getWidgetType() . ' `' . $this->getCaption()
            . '` did not close - every following click would be swallowed by it'
        );
    }

    /**
     * Waits until the overflow popover belonging to the given overflow button is visible.
     *
     * WHY THE POPOVER IS DERIVED FROM THE BUTTON ID: UI5 names both after the toolbar that owns
     * them - `<toolbarId>-overflowButton` opens `<toolbarId>-popover`. Every other way of finding
     * the popup is ambiguous on a page with several toolbars, because the popovers carry generic ids
     * (`__toolbar0-popover`, `__toolbar1-popover`) and identical CSS classes, and UI5 keeps a once
     * opened popover in the DOM afterwards. Searching for "a popover" would therefore happily match
     * the leftover popup of the OTHER widget and let the following step click the wrong entry.
     *
     * WHY IT POLLS FOR VISIBILITY INSTEAD OF EXISTENCE: for the same reason - the element exists in
     * the DOM from the first open onwards, so its mere presence proves nothing about this click.
     *
     * @param NodeElement $overflowButton
     * @param int $timeoutSeconds
     * @return NodeElement|null Null when the menu did not become visible in time.
     */
    protected function waitForOverflowMenu(NodeElement $overflowButton, int $timeoutSeconds = 5): ?NodeElement
    {
        $buttonId = (string) $overflowButton->getAttribute('id');
        $toolbarId = substr($buttonId, 0, -strlen(self::OVERFLOW_BUTTON_ID_SUFFIX));
        $menuId = $toolbarId . self::OVERFLOW_MENU_ID_SUFFIX;

        $page = $this->getSession()->getPage();
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            // XPath rather than a CSS id selector: UI5 ids start with underscores and contain
            // characters that would have to be escaped in CSS, and an XPath literal needs no escaping.
            $menu = $page->find('xpath', '//*[@id=' . $this->xpathLiteral($menuId) . ']');
            if ($menu !== null && $this->isElementVisibleInBrowser($menu)) {
                return $menu;
            }
            // 100 ms is a compromise: short enough to not add noticeable latency to a passing step,
            // long enough to keep the number of synchronous CDP round trips per wait in the tens.
            usleep(100000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * @return Session
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    public function isVisible(): bool
    {
        return $this->getNodeElement()->isVisible();
    }

    /**
     * Runs test substep defined by the given callable and returns the corresponding result object
     *
     * The $callable will receive the default result object as argument and may modify it or return
     * a new one. If the callable does not return anything, it will not fail - the default result
     * will be used. If the callable throws an exception, a failed result will be created automatically
     *
     *  Execution order on failure:
     *    1. Exception is caught.
     *    2. Screenshot is captured — the browser is still in the failed state,
     *       so the screenshot reflects exactly what went wrong (e.g. an error dialog is still visible).
     *    3. If the exception is a UI5DialogException, the error dialog is dismissed
     *       so the DOM is unblocked for subsequent interactions.
     *    4. The optional $onFailure callback is invoked — use this for cleanup that must
     *       happen after the screenshot but before the exception propagates (e.g. back-navigation
     *       after a tile click so the browser is not left on the wrong page).
     *    5. The node is reset so subsequent steps find it in the same state as if no error occurred.
     *
     *  The $onFailure callback is intentionally separate from the main $fn closure so that
     *  cleanup logic does not interfere with the screenshot: anything inside $fn that runs
     *  after the failure point would change the browser state before the screenshot is taken.
     *
     *  All failure-handling steps are wrapped in a secondary try/catch. If Chrome has
     *  crashed, any of those steps can throw a secondary CDP exception (e.g. "Server is closed",
     *  "Unable to connect to tcp://..."). Without the secondary guard that exception would escape
     *  runAsSubstep, replace the original error in the caller's error report, and surface as a
     *  confusing socket error instead of the real test failure. The secondary exception is logged
     *  to ErrorManager and the logbook and then swallowed so that $substepResult always carries
     *  the original failure.
     *
     * @param callable $callable The substep logic to execute.
     * @param string $title Human-readable substep title used in the logbook and events.
     * @param string|null $category Optional grouping category for the substep event.
     * @param LogBookInterface|null $logbook Logbook to write step details into.
     * @param callable|null $onFailure Optional cleanup callback invoked after screenshot
     *                                     and dialog dismiss. Exceptions thrown inside this
     *                                     callback are silently swallowed to preserve the
     *                                     original error.
     * @return SubstepResult
     */
    public function runAsSubstep(
        callable $callable,
        string $title,
        ?string $category = null,
        ?LogBookInterface $logbook = null,
        callable $onFailure = null
    ) : SubstepResult
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($title, $category));
        try {
            $substepResult = SubstepResult::createPassed($logbook);
            $substepResult->setTitle($title);
            $returnValue = $callable($substepResult);
            if ($returnValue instanceof SubstepResult) {
                $substepResult = $returnValue;
            }
        } catch (Throwable $e) {
            $logbook?->addLine('**ERROR:** ' . $e->getMessage());

            // If the original exception is (or wraps) a CDP connection failure, convert it
            // to ChromeHangException so that callers such as UI5ContainerNode can trigger
            // the Chrome recovery path. We still attempt a screenshot and log entry first
            // (both best-effort — Chrome may be gone), then re-throw so the SubstepResult
            // is never silently swallowed when a restart is needed.
            $isCdpCrash = $this->isCdpConnectionError($e);
            if ($isCdpCrash) {
                // Best-effort: screenshot and log may fail if Chrome is already dead.
                try {
                    $this->getBrowser()->captureScreenshot($logbook);
                } catch (Throwable $ignored) {
                }
                try {
                    ErrorManager::getInstance()->logException($e, $this->getBrowser()->getWorkbench());
                } catch (Throwable $ignored) {
                }
                if ($e instanceof ChromeHangException) {
                    $this->getBrowser()->recoverChrome($this->getSession()->getCurrentUrl());
                }
            }

            // Steps 2–5 all touch the live browser or DOM. If Chrome has crashed, any of
            // them can throw a secondary CDP exception ("Server is closed", socket errors, …).
            // The outer guard ensures that a secondary failure never escapes runAsSubstep and
            // never replaces the original exception $e in the caller's error report.
            try {
                $this->getBrowser()->captureScreenshot($logbook);
                $substepResult = SubstepResult::createFailed($e, $logbook);
                ErrorManager::getInstance()->logException($e, $this->getBrowser()->getWorkbench());
                if ($e instanceof UIException || $e instanceof AjaxException) {
                    $this->getBrowser()->dismissErrorDialogIfPresent();
                }
                if ($onFailure !== null) {
                    try {
                        ($onFailure)();
                    } catch (Throwable $ignored) {
                    }
                }
                // getWidgetType() calls getAttribute() on the live DOM — safe during normal
                // failures but will throw if Chrome has crashed. Fall back to the PHP class
                // name so the reset log line is still written even when the browser is gone.
                try {
                    $widgetTypeLabel = $this->getWidgetType() ?? get_class($this);
                } catch (Throwable $ignored) {
                    $widgetTypeLabel = get_class($this);
                }
                // IMPORTANT: reset the node so subsequent steps find it in the same state
                // as they would if no error had occurred.
                $logbook?->continueLine(' - resetting ' . $widgetTypeLabel);
                $this->reset();
            } catch (Throwable $secondaryError) {
                // A secondary CDP/browser error occurred during failure handling (e.g. Chrome
                // crashed between the original failure and the screenshot/reset calls).
                // The logException call is wrapped defensively: when the monitor filegroup is full
                // the monitor write itself can fail and leave the ambient transaction unrollbackable,
                // so a failure here must never escape this last-resort catch and turn a handled
                // substep failure into an uncaught process-killing error (exit 255).
                try {
                    ErrorManager::getInstance()->logException($secondaryError, $this->getBrowser()->getWorkbench());
                } catch (Throwable $ignored) {
                }
                $logbook?->addLine('**WARNING:** Secondary error during failure handling (Chrome may have crashed): ' . $secondaryError->getMessage());
                // Guarantee $substepResult is always assigned even if captureScreenshot()
                // threw before line "SubstepResult::createFailed($e, $logbook)" was reached.
                if (!isset($substepResult)) {
                    $substepResult = SubstepResult::createFailed($e, $logbook);
                }
            }
        }
        $resultEvent = new AfterSubstep($substepResult, $substepResult->getTitle() ?? $title, $category);
        $dispatcher->dispatch($resultEvent);
        return $substepResult;
    }

    /**
     * {@inheritDoc}
     * @see FacadeNodeInterface::reset()
     */
    public function reset(): FacadeNodeInterface
    {
        return $this;
    }

    public function logSubstep(string $title, int $resultCode, ?string $reason, ?string $category = null): AfterSubstep
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($title, $category));
        $result = new SubstepResult($resultCode);
        // Capture the screen for failures reported directly, not through runAsSubstep().
        // WHY: those are exactly the "widget not found" cases, where the picture is the only way to
        // tell a real defect from a widget that was simply off screen.
        if ($resultCode === StepStatusDataType::FAILED || $resultCode === StepStatusDataType::TIMEOUT) {
            $this->getBrowser()->captureScreenshot();
        }
        if ($reason !== null) {
            $result->setReason($reason);
        }
        $resultEvent = new AfterSubstep($result, $title, $category);
        $dispatcher->dispatch($resultEvent);
        return $resultEvent;
    }

    public function checkDisabled(): bool
    {
        return false;
    }

    public function waitWhileBusy(int|float $timeoutSeconds = 30): FacadeNodeInterface
    {
        usleep(100);
        $this->getSession()->wait(
            $timeoutSeconds * 1000,
            <<<JS
            (function() {
                var element = sap.ui.getCore().byId('{$this->getElementId()}');
                
                if (!element || typeof element.isBusy === "undefined") {
                    return true;
                }
                
                return element.isBusy() === false;
            })()
            JS
        );
        return $this;
    }

    /**
     * Resolves the widget behind a rendered UI5 element id.
     *
     * WHY the "---" prefix is stripped: controls that UI5 creates as the root of an own view - dialogs
     * are the typical case - are registered through the owning sap.ui.core.Component, and UI5 prefixes
     * their id with "<componentId>---". Only the part after that separator is the id our facade
     * generated. Without stripping it, the "<pageUid>__<widgetId>" split below runs on the component
     * id and the model is asked for a page that cannot exist.
     *
     * WHY it throws instead of falling back: an id without the "__" separator was not produced by
     * getElementIdFromWidget(), so any page or widget derived from it would be a guess. Failing here
     * names the real problem instead of letting it resurface as a misleading
     * "UI Page with alias ... not found!" from deep inside the model layer.
     *
     * @param string $ui5ElementId
     * @param UiPageInterface|null $page
     * @return WidgetInterface
     */
    protected function getWidgetFromElementId(string $ui5ElementId, ?UiPageInterface $page = null): WidgetInterface
    {
        // Strip the UI5 component prefix if - and only if - one is really present.
        //
        // WHY the prefix exists: controls that UI5 creates as the root of an own view (dialogs are the
        // typical case) are registered through the owning sap.ui.core.Component, so UI5 prefixes their
        // id with "<componentId>---". Only the part behind that separator is the id our facade built.
        //
        // WHY the two conditions: "---" is not reserved - a widget id defined in UXON may legitimately
        // contain it. A real component prefix never contains the "<pageUid>__<widgetId>" separator,
        // while the remainder always does. Cutting only in that constellation keeps every id that
        // already resolved today resolving exactly the same way.
        if (false !== $pos = strrpos($ui5ElementId, '---')) {
            $prefix = substr($ui5ElementId, 0, $pos);
            $suffix = substr($ui5ElementId, $pos + 3);
            if (!str_contains($prefix, '__') && str_contains($suffix, '__')) {
                $ui5ElementId = $suffix;
            }
        }
        if (!str_contains($ui5ElementId, '__')) {
            throw new RuntimeException('Cannot resolve a widget from UI5 element id "' . $ui5ElementId . '": expected the "<pageUid>__<widgetId>" format.');
        }
        list($pageUid, $widgetId) = explode('__', $ui5ElementId, 2);
        // Make sure the page UID has the 0x-format
        $pageUid = '0' . ltrim($pageUid, '0');
        if ($page === null) {
            $page = UiPageFactory::createFromModel($this->browser->getWorkbench(), $pageUid);
        }
        return $page->getWidget($widgetId);
    }

    /**
     * {@inheritDoc}
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->getBrowser()->getWorkbench();
    }

    /**
     * @return UI5Browser
     */
    public function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            throw new RuntimeException('BDT Browser not initialized on node! Did you forget to call setBrowser()?');
        }
        return $this->browser;
    }

    /**
     * @return WidgetInterface#
     */
    public function getWidget(): WidgetInterface
    {
        if ($this->widget === null) {
            $elementId = $this->getElementId();
            $this->widget = $this->getWidgetFromElementId($elementId);
        }
        return $this->widget;
    }

    /**
     * @return string
     */
    public function getElementId(): string
    {
        return $this->getNodeElement()->getAttribute('id');
    }

    protected function logSubstepResult(SubstepResult $result, ?string $category = null): AfterSubstep
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($result->getTitle(), $category));
        $resultEvent = new AfterSubstep($result, $result->getTitle(), $category);
        $dispatcher->dispatch($resultEvent);
        return $resultEvent;
    }

    /**
     * Tells whether a real mouse click at the centre of the given element would actually reach it.
     *
     * WHY isElementVisibleInBrowser() IS NOT ENOUGH: that method answers "does this element have a
     * box and is it not display:none / visibility:hidden / opacity:0". Mink clicks by dispatching a
     * mouse event at the element's centre COORDINATES, so two further conditions decide whether the
     * click lands and neither is covered there: the centre must lie inside the viewport, and the
     * top-most element at that point must be the element itself. A control living in a popover in
     * the static area fails both as soon as that popover is closed - it keeps a real box, so the
     * visibility check still passes, while every click silently goes somewhere else. That is how
     * "did not open after 3 attempts" is produced without a single error on the way.
     *
     * WHY IT REUSES isElementVisibleInBrowser(): the cheap disqualifiers stay in one place, this
     * only adds the two questions that are specific to clicking.
     *
     * @param NodeElement $el
     * @return bool
     */
    public function isElementClickable(NodeElement $el): bool
    {
        if (! $this->isElementVisibleInBrowser($el)) {
            return false;
        }
        $id = $el->getAttribute('id');
        if (! $id) {
            return false;
        }
        $idJs = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return (bool) $this->getSession()->evaluateScript(<<<JS
(function(){
    var el = document.getElementById($idJs);
    if (! el) { return false; }
    var r = el.getBoundingClientRect();
    var cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    // Outside the viewport there is nothing to hit: elementFromPoint() returns null and the
    // dispatched mouse event lands on whatever happens to sit at those coordinates.
    if (cx < 0 || cy < 0 || cx > window.innerWidth || cy > window.innerHeight) { return false; }
    // A descendant counts as a hit: UI5 buttons render inner spans and a click on those bubbles
    // up to the button. An unrelated element (an overlay, a block layer, the page behind a
    // closed popover) does not.
    for (var hit = document.elementFromPoint(cx, cy); hit; hit = hit.parentElement) {
        if (hit === el) { return true; }
    }
    return false;
})();
JS
        );
    }

    /**
     * Returns the result of the given JavaScript snippet
     *
     * The script must evaluate to a scalar value. It is a good idea to wrap the script in an iife:
     *
     * ```
     *  (function(oInput, sDelim){
     *      var aTokens = oInput.getTokens();
     *      var sVal = '';
     *      aTokens.forEach(function(oToken) {
     *          sVal += (sVal === '' ? '' : sDelim) + oToken.getText();
     *      });
     *      return sVal;
     *  })(sap.ui.getCore().byId('{$this->getElementId()}'), '{$this->getWidget()->getMultiSelectTextDelimiter()}')
     *
     * ```
     *
     * @param string $script
     * @return mixed
     */
    protected function getFromJavascript(string $script)
    {
        try {
            return $this->getSession()->evaluateScript($script);
        } catch (Throwable $e) {
            throw new FacadeNodeScriptException($this, $script, $e->getCode(), null, $e);
        }
    }

    protected function checkCaptionMatchesWidget(): FacadeNodeInterface
    {
        $widgetCaption = $this->getWidget()->getCaption();
        $nodeCaption = $this->getCaption();
        Assert::assertEquals(trim($widgetCaption), trim($nodeCaption), 'Widget caption "' . $widgetCaption . '" does not match rendered caption "' . $nodeCaption . '"');
        return $this;
    }

    /**
     * Returns the core's open "unsaved changes" confirmation, or null if none is on screen.
     *
     * WHY THE TITLE IS PART OF THE SELECTOR: `sapMMessageDialog` is worn by every MessageBox the
     * core raises - delete confirmations, error popups, "discard inputs" warnings. Dismissing
     * whatever wears that class would hide real problems instead of solving one. The dialog is
     * therefore identified by the combination of "is an open dialog" and "carries exactly this
     * translated title".
     *
     * @return NodeElement|null
     */
    protected function findDiscardChangesConfirmation(): ?NodeElement
    {
        $title = $this->translate(self::TRANSLATION_DISCARD_CHANGES_TITLE);
        $xpath = sprintf(
            '//div[contains(concat(" ", normalize-space(@class), " "), " sapMDialogOpen ")]'
            . '[.//h1[contains(concat(" ", normalize-space(@class), " "), " sapMDialogTitle ")]'
            . '[normalize-space(.)=%s]]',
            $this->xpathLiteral($title)
        );

        // Prefer the last match: UI5 appends newly opened dialogs, so the last one is the
        // top-most. Earlier matches can be leftovers of dialogs that were already dismissed.
        foreach (array_reverse($this->getSession()->getPage()->findAll('xpath', $xpath)) as $el) {
            if ($this->isElementVisibleInBrowser($el)) {
                return $el;
            }
        }

        return null;
    }

    /**
     * Confirms the core's "unsaved changes" MessageBox if it is currently blocking the screen.
     *
     * WHY THIS IS NOT DONE IN waitForPendingOperations(): that method is called from read-only
     * assertions as well, and a side effect that dismisses dialogs would silently break any
     * scenario that deliberately tests the unsaved-changes protection. The confirmation is
     * answered only where the framework itself provoked it - while closing a dialog it has just
     * validated.
     *
     * @param LogBookInterface|null $logbook
     * @return bool True if a confirmation was on screen and was confirmed.
     */
    public function confirmDiscardChangesIfPresent(?LogBookInterface $logbook = null): bool
    {
        $confirmation = $this->findDiscardChangesConfirmation();
        if ($confirmation === null) {
            return false;
        }

        $continueBtn = $this->findVisibleButtonByCaption(
            self::TRANSLATION_DISCARD_CHANGES_CONTINUE,
            false,
            $confirmation
        );

        // Not a case to swallow: the confirmation is on screen and blocks everything behind it,
        // so failing to answer it must be reported as the cause instead of leaving the run to
        // collapse into unrelated "element not found" errors a few steps later.
        if ($continueBtn === null) {
            throw new RuntimeException(
                'Cannot answer the "unsaved changes" confirmation: no visible button captioned "'
                . $this->translate(self::TRANSLATION_DISCARD_CHANGES_CONTINUE) . '" inside it.'
            );
        }

        $continueBtn->click();
        $logbook?->addLine('Answering the "unsaved changes" confirmation - discarding the values entered while testing');
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        return true;
    }

    /**
     * Translates a core translation key into the language of the current browser session.
     *
     * WHY A HELPER: the chain workbench -> core app -> translator -> translate() was repeated
     * verbatim in every place that needs a caption to compare against the DOM. Having it once
     * means the locale used for lookups can never drift apart between call sites.
     *
     * @param string $key
     * @return string
     */
    public function translate(string $key): string
    {
        return $this->getBrowser()
            ->getWorkbench()
            ->getCoreApp()
            ->getTranslator($this->getBrowser()->getLocale())
            ->translate($key);
    }
}