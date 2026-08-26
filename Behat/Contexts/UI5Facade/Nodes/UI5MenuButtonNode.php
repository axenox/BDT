<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iHaveButtons;

/**
 * Facade node for UI5 MenuButton widgets (e.g. the "Aktionen" toolbar button).
 *
 * Why this exists:
 * A MenuButton renders as a <button aria-haspopup="menu"> inside an
 * <div class="exfw-MenuButton"> container. Pressing it opens a sapMMenu popover whose
 * entries are <li role="menuitem"> elements rendered into a detached, modal popover in
 * the static area - outside the widget subtree. Consequently the entries exist in the
 * DOM only while the menu is open, and the generic "click button" logic (which scans
 * <button> elements) cannot reach them. The MenuButton also has no action of its own,
 * so the DataTable button loop would skip it and never test its entries; this node
 * fills that gap.
 *
 * What it does:
 * - checkWorksAsExpected() opens the menu and validates every visible entry. Each entry
 *   is tagged `exfw exfw-DataButton`, so it is resolved through the node factory and the
 *   existing button validation (GoToPage / ShowDialog) is reused. Every entry maps 1:1
 *   to a child DataButton widget, and its <li> id round-trips to that widget via
 *   getWidgetFromElementId(), so the correct action is checked.
 * - Row preconditions are satisfied deterministically: entries whose action requires a
 *   selected row, or that are enabled only for specific rows, are handled by walking the
 *   owning DataTable's first-page rows until the entry becomes enabled. Because the
 *   popover is modal, the menu is closed to select a row and reopened to re-check; the
 *   enabled state is read from `aria-disabled` (the <li> carries no `disabled` attribute).
 * - clickItem() opens the menu and clicks a single entry by caption, for user-facing
 *   steps that just need to trigger one specific action.
 */
class UI5MenuButtonNode extends UI5AbstractNode implements FacadeNodeInterface
{
    /**
     * The widget this node stands for, if the creator knew it.
     *
     * WHY protected: subclasses that resolve their widget from a different DOM element must still be
     * able to prefer an injected widget over the DOM round-trip, which is lossy - cleanId() replaces
     * the id space separator "." with "_", so not every rendered id can be mapped back to a widget.
     */
    protected ?WidgetInterface $widget = null;

    /**
     * Open states of a sap.ui.core.Popup, mirrored from sap.ui.core.OpenState.
     *
     * WHY they exist as constants: the state is read out of the browser as a plain string and
     * compared in three different methods. Treating "OPENING" as "not open" in only one of them is
     * exactly what made openMenu() click a popover that was already on its way up - and toggle it
     * shut again.
     */
    private const POPUP_STATE_OPEN = 'OPEN';
    private const POPUP_STATE_CLOSED = 'CLOSED';

    /**
     * Seconds an open or close animation may take before the state is considered stuck.
     */
    private const MENU_ANIMATION_TIMEOUT = 5;

    /**
     * How many clicks openMenu() may spend before it gives up.
     *
     * WHY it is lower than the six attempts it replaces: with the popup state polled instead of
     * slept for, a failed attempt means the click never reached the button. Repeating it more than
     * a few times only delays the error message.
     */
    private const MENU_OPEN_ATTEMPTS = 3;

    /**
     * DOM id of the trigger button, cached instead of the NodeElement itself.
     *
     * WHY the id and not the element: a Mink NodeElement is only an XPath into the document. The
     * toolbar re-renders whenever a menu entry opened and closed a dialog, and after that render the
     * recorded XPath either resolves to nothing or - worse - to a DIFFERENT button that happens to
     * sit at the same position. Clicks then land nowhere while aria-expanded never changes. The id
     * survives the re-render, so it is the only handle that stays correct.
     */
    private ?string $triggerButtonId = null;

    /**
     * Opens the menu and validates every visible entry as its own button action.
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $widget = $this->getWidget();
        if (!$widget instanceof iHaveButtons) {
            // Nothing to validate if the model does not expose child buttons.
            return SubstepResult::createSkipped('MenuButton `' . $this->getCaption() . '` has no child buttons', $logbook);
        }

        $failed = false;
        foreach ($widget->getButtons() as $entryWidget) {
            if ($entryWidget->isHidden()) {
                continue;
            }

            $entryId = $this->getBrowser()->getElementIdFromWidget($entryWidget);
            $table = $this->getOwningDataTableNode();

            // Satisfy the row-selection precondition of THIS entry's action before the menu
            // is opened. Menu entries carry their own action, and an entry that is not
            // `disable_if_input_invalid` stays clickable with no row selected - the action
            // then fails with "please select exactly 1 record". The disabled-driven row walk
            // below only reacts to the rendered DOM state and never covers that case, so the
            // precondition is taken from the action model here, reusing the guard the
            // DataTable button loop already relies on. Without this, the reactive retry would
            // have to absorb every such entry, and each first attempt would be recorded as a
            // real failure in the test report.
            $entryAction = $entryWidget->getAction();
            if ($table !== null && $entryAction !== null && !$table->ensureRowSelectedForAction($entryAction)) {
                $logbook->addLine('Skipping menu item `' . $entryWidget->getCaption() . '` - its action requires a selected row, but the table has no rows to select');
                continue;
            }
            // Open the menu and read the entry's enabled state from aria-disabled
            // (the <li> carries no `disabled` attribute, so UI5ButtonNode::checkDisabled
            // cannot be used here).
            $this->openMenu();
            $entryEl = $this->getSession()->getPage()->findById($entryId);
            $isDisabled = $entryEl === null || !$entryEl->isVisible()
                || UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser())->checkDisabled();

            // Some actions enable only for specific rows. If the entry is disabled,
            // walk the first-page rows until one enables it. The popover is modal, so
            // the menu must be closed to select a row and reopened to re-check.
            if ($isDisabled && $table !== null) {
                $this->closeMenuIfOpen();
                $ready = $table->selectEachRowUntil(function () use ($entryId, &$entryEl) {
                    $this->openMenu();
                    $entryEl = $this->getSession()->getPage()->findById($entryId);
                    if ($entryEl === null || !$entryEl->isVisible()) {
                        $this->closeMenuIfOpen();
                        return false;
                    }
                    // Single source of truth for "disabled": the <li> re-renders on each
                    // reopen, so the node is rebuilt from the fresh element before asking.
                    $entryNode = UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser());
                    $enabled = !$entryNode->checkDisabled();
                    if (!$enabled) {
                        $this->closeMenuIfOpen();
                    }
                    return $enabled;
                });
                if (!$ready) {
                    $logbook->addLine('Skipping menu item `' . $entryWidget->getCaption() . '` - no row on the first page enables it');
                    $this->closeMenuIfOpen();
                    continue;
                }
            }

            if ($entryEl === null || !$entryEl->isVisible()) {
                $logbook->addLine('Skipping menu item `' . $entryWidget->getCaption() . '` - not present/visible in the open menu');
                $this->closeMenuIfOpen();
                continue;
            }

            // The <li> is tagged `exfw exfw-DataButton`, so the factory resolves the
            // proper button node; its id round-trips to the child DataButton widget.
            $urlBeforeClick = $this->getSession()->getCurrentUrl();

            // Re-resolve the entry on every attempt: the popover closes when the entry's
            // action runs, so the <li> goes stale between the click and any retry. The
            // menu is reopened and the entry re-found by id before each attempt.
            $runEntryClick = function () use ($entryId, $entryWidget, $logbook, $urlBeforeClick) {
                $this->openMenu();
                $entryEl = $this->getSession()->getPage()->findById($entryId);
                if ($entryEl === null || !$entryEl->isVisible()) {
                    $this->closeMenuIfOpen();
                    return SubstepResult::createSkipped('Menu item `' . $entryWidget->getCaption() . '` is no longer present after reopening the menu', $logbook);
                }
                $entryNode = UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser());
                return $this->runAsSubstep(
                    function () use ($entryNode, $logbook) {
                        return $entryNode->checkWorksAsExpected($logbook);
                    },
                    'Clicking menu item "' . $entryWidget->getCaption() . '"',
                    'Dialogs',
                    $logbook,
                    function () use ($urlBeforeClick) {
                        if ($this->getSession()->getCurrentUrl() !== $urlBeforeClick) {
                            $this->getBrowser()->navigateToPreviousPage();
                        }
                    }
                );
            };

            // If the entry's action reports a lost row selection, re-select a row and
            // retry the click once. The modal popover must be closed before a row can be
            // selected, hence the closeMenuIfOpen() hook.
            if ($table !== null) {
                $result = $table->retryClickIfRowSelectionLost(
                    $runEntryClick,
                    $logbook,
                    function () {
                        $this->closeMenuIfOpen();
                    }
                );
            } else {
                $result = $runEntryClick();
            }
            if ($result->isFailed()) {
                $failed = true;
            }
        }

        // Leave no popover open for the buttons that follow this one in the toolbar.
        $this->closeMenuIfOpen();

        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }

    /**
     * Resolves the ExFace widget behind this MenuButton.
     *
     * WHY an injected widget is preferred: the way from a DOM id back to a widget is lossy.
     * AbstractJqueryElement::cleanId() replaces every character the facade config lists as forbidden
     * with "_" - including the dot, which UiPage uses as its id space separator. A MenuButton behind
     * an id space can therefore not be found under its rendered id at all. Whoever created this node
     * usually walked the widget model anyway and handed the widget over; resolving it from the DOM is
     * only the fallback for nodes discovered by searching the page.
     *
     * WHY the container element and not this node's element: the node may have been created for an
     * inner part of the MenuButton (e.g. the clickable inner span). Only the container carries the id
     * the facade generated for the widget.
     *
     * @return WidgetInterface
     */
    public function getWidget(): WidgetInterface
    {
        if ($this->widget !== null) {
            return $this->widget;
        }
        $container = $this->getMenuButtonContainer();
        $this->widget = $this->getWidgetFromElementId($container->getAttribute('id'));
        return $this->widget;
    }

    /**
     * Returns the `.exfw-MenuButton` container, whether the node was built from the
     * container itself or from its internal trigger <button>.
     *
     * Why this exists:
     * Widget resolution and entry-id prefixing both need the clean container id;
     * depending on the construction path the node element may be the container or
     * the inner button, so both cases are normalized here.
     *
     * @return NodeElement
     * @throws RuntimeException If no MenuButton container can be located
     */
    protected function getMenuButtonContainer(): NodeElement
    {
        $el = $this->getNodeElement();
        if ($el->hasClass('exfw-MenuButton')) {
            return $el;
        }
        while ($parent = $el->getParent()) {
            if ($parent->hasClass('exfw-MenuButton')) {
                return $parent;
            }
            $el = $parent;
        }
        throw new RuntimeException('Cannot locate the .exfw-MenuButton container for this menu button node.');
    }

    /**
     * Returns the trigger caption of the MenuButton.
     *
     * @return string
     */
    public function getCaption(): string
    {
        return trim($this->getTriggerButton()->getText() ?? '');
    }

    /**
     * Returns the clickable <button> that toggles the menu popover, freshly resolved from the DOM.
     *
     * WHY it re-resolves on every call: see $triggerButtonId - the cached node element of this node
     * is captured once in the constructor and points into a DOM tree that the toolbar replaces on
     * every dialog round trip.
     *
     * WHY there is no fallback to "the first button in the container": the menu state is read from
     * whatever this method returns. A container can hold more than one button, and picking the wrong
     * one makes an open menu look permanently closed - openMenu() then keeps re-clicking, toggling
     * the popover shut on every second attempt, and finally reports that the menu did not open
     * although it was on screen the whole time. Failing here instead names the real problem: this
     * node is not pointing at a menu button.
     *
     * @throws RuntimeException If no trigger button is found
     * @return NodeElement
     */
    protected function getTriggerButton(): NodeElement
    {
        if ($this->triggerButtonId !== null) {
            // XPath rather than a CSS id selector: UI5 ids start with underscores and contain
            // characters that would have to be escaped in CSS, while an XPath literal needs no
            // escaping at all.
            $fresh = $this->getSession()->getPage()->find('xpath', '//*[@id=' . $this->xpathLiteral($this->triggerButtonId) . ']');
            if ($fresh !== null) {
                return $fresh;
            }
            // The id is gone - the widget was re-rendered under a new one. Resolve from scratch.
            $this->triggerButtonId = null;
        }

        $el = $this->getNodeElement();
        if ($el->getTagName() === 'button' && $el->getAttribute('aria-haspopup') === 'menu') {
            $trigger = $el;
        } else {
            $container = $this->getMenuButtonContainer();
            $trigger = $container->find('css', 'button[aria-haspopup="menu"]')
                ?? $container->find('css', 'button[aria-haspopup="true"]')
                ?? $container->find('css', 'button[aria-haspopup]');
        }
        if ($trigger === null) {
            throw new RuntimeException('Cannot find the trigger button of menu `' . trim($this->getMenuButtonContainer()->getText() ?? '') . '`: no button with aria-haspopup="menu" inside the menu container.');
        }

        $id = $trigger->getAttribute('id');
        $this->triggerButtonId = ($id === null || $id === '') ? null : $id;
        return $trigger;
    }

    /**
     * Returns the DataTable node that owns this MenuButton, or null if the button is
     * not inside a DataTable.
     *
     * Why this exists:
     * Menu entries whose action requires selected rows can only be triggered after a
     * row is selected in the owning table. The MenuButton sits in the DataTable
     * toolbar, so the table node is resolved by walking up to the closest
     * .exfw-DataTable ancestor and building the proper node for it.
     *
     * @return UI5DataTableNode|null
     */
    protected function getOwningDataTableNode(): ?UI5DataTableNode
    {
        $el = $this->getMenuButtonContainer();
        while ($el = $el->getParent()) {
            if ($el->hasClass('exfw-DataTable')) {
                $node = UI5FacadeNodeFactory::createFromNodeElement($el, $this->getSession(), $this->getBrowser());
                return $node instanceof UI5DataTableNode ? $node : null;
            }
        }
        return null;
    }

    /**
     * Ensures the menu popover for this specific MenuButton is open.
     *
     * WHY it is idempotent: the entry loop calls this again after every row walk and before every
     * click attempt, and a MenuButton toggles - a second click would close exactly the popover the
     * caller is about to read. The state is therefore settled and checked BEFORE every click, so a
     * poll that arrives too early can no longer close the menu again.
     *
     * WHY the wait is state-driven and not time-driven: waitForPendingOperations() only knows about
     * document.readyState, jQuery.active and the UI5 BusyIndicator - a popover touches none of them,
     * so before this change the only synchronisation was a 300 ms sleep. That sleep held in the
     * debugger and lost the race at full speed.
     *
     * WHY the trigger is re-resolved inside the loop: the toolbar re-renders whenever an entry's
     * action opened and closed a dialog, and a handle taken before that render clicks nothing at all.
     *
     * @return void
     * @throws RuntimeException If the menu does not open
     */
    protected function openMenu(): void
    {
        if ($this->isMenuOpen()) {
            return;
        }
        
        // A modal dialog left on screen swallows every click on the toolbar behind it, so polling the
        // trigger would burn all attempts and then blame the trigger. Say what is actually in the way.
        if ($this->getSession()->getPage()->find('css', '.sapMDialog.sapMDialogOpen') !== null) {
            throw new RuntimeException('Menu `' . $this->getCaption() . '` cannot be opened: a modal dialog is still open in front of it.');
        }

        $attempt = 0;
        while ($attempt < self::MENU_OPEN_ATTEMPTS) {
            $attempt++;
            $trigger = $this->getTriggerButton();
            // Clicking a disabled button does nothing, so the loop would spend all its attempts and
            // then blame the popover. Name the actual state instead.
            if ($trigger->hasAttribute('disabled')
                || $trigger->getAttribute('aria-disabled') === 'true'
                || $trigger->hasClass('sapMBtnDisabled')
            ) {
                throw new RuntimeException('Menu `' . $this->getCaption() . '` cannot be opened: ' . $this->describeDisabledTrigger());
            }

            $trigger->click();
            if ($this->isMenuOpen()) {
                // Only now check for errors: an entry list can be built from a lazily loaded model,
                // and an error raised during the open must fail the step rather than the entry.
                $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
                return;
            }

            // Settled as CLOSED means the click never reached the button - a re-render moved it, or
            // an overlay ate the event. Drop the cached id so the next attempt resolves it anew.
            $this->triggerButtonId = null;
        }

        throw new RuntimeException('Menu `' . $this->getCaption() . '` did not open after ' . $attempt . ' attempts on its trigger button.');
    }

    /**
     * Explains why the trigger button is disabled, as far as it can be told from the page.
     *
     * WHY it exists: the menu of a DataTable toolbar is disabled by the table's selection state, so
     * a selection left over from a previously tested entry looks exactly like a broken menu. Naming
     * the selection turns a dead-end error message into a lead.
     *
     * @return string
     */
    private function describeDisabledTrigger(): string
    {
        try {
            $table = $this->getOwningDataTableNode();
            if ($table !== null) {
                return 'its trigger button is disabled ('
                    . count($table->getSelectedRowNumbers()) . ' row(s) currently selected in the owning table).';
            }
        } catch (\Throwable $e) {
            // The reason is a nicety - never let resolving it replace the real error.
        }
        return 'its trigger button is disabled.';
    }

    /**
     * Reads the current open state of THIS MenuButton's popover straight from UI5.
     *
     * WHY the popup and not the DOM: sap.ui.core.Popup is the only place that distinguishes "opening"
     * from "closed". aria-expanded and the rendered <ul> both lag behind the open animation, so at
     * full speed a poll right after the click sees a closed menu, the caller clicks again, and that
     * second click toggles the popover shut - which is precisely the failure this method removes. The
     * DOM branch at the end is only reached when no popup is attainable (UI5 not loaded, or a render
     * without aria-controls), where a rendered guess still beats no answer.
     *
     * WHY it is scoped through the trigger button: entry ids do not follow a single scheme and a
     * popover left open by a DIFFERENT menu carries the same classes, so "a visible menu list" would
     * happily report the wrong popup as this button's own.
     *
     * @return string One of the POPUP_STATE_* values, or the raw UI5 state during an animation
     */
    private function getMenuOpenState(): string
    {
        $trigger = $this->getTriggerButton();
        $triggerId = $trigger->getAttribute('id');
        if ($triggerId === null || $triggerId === '') {
            // No id, no JS lookup. Less precise, but failing here would be worse than guessing.
            return $trigger->getAttribute('aria-expanded') === 'true' ? self::POPUP_STATE_OPEN : self::POPUP_STATE_CLOSED;
        }

        $idJs = json_encode($triggerId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $state = $this->getFromJavascript(<<<JS
(function(){
    var btn = document.getElementById($idJs);
    if (! btn) { return 'CLOSED'; }
    var sMenuId = btn.getAttribute('aria-controls');
    if (window.sap && sap.ui && typeof sap.ui.getCore === 'function') {
        var oCore = sap.ui.getCore();
        var oCtrl = sMenuId ? oCore.byId(sMenuId) : null;
        if (! oCtrl) {
            // sap.m.MenuButton renders an inner button; the menu hangs off the outer control.
            var oBtn = oCore.byId(btn.id) || oCore.byId(btn.id.replace(/-internalBtn\$/, ''));
            if (oBtn && typeof oBtn.getMenu === 'function') { oCtrl = oBtn.getMenu(); }
        }
        // Walk up until the control owning the sap.ui.core.Popup is reached. The nesting differs
        // between phone (Dialog) and desktop (Popover/unified.Menu), so the owner is searched for
        // rather than assumed. The counter bounds a control tree that is cyclic in some versions.
        for (var o = oCtrl, i = 0; o && i < 10; o = (typeof o.getParent === 'function' ? o.getParent() : null), i++) {
            var oPopup = o.oPopup || (typeof o.getPopup === 'function' ? o.getPopup() : null);
            if (oPopup && typeof oPopup.getOpenState === 'function') {
                return String(oPopup.getOpenState());
            }
        }
    }
    if (btn.getAttribute('aria-expanded') === 'true') { return 'OPEN'; }
    var el = sMenuId ? document.getElementById(sMenuId) : document.querySelector('ul.sapMMenuList[role="menu"]');
    if (! el) { return 'CLOSED'; }
    var cs = window.getComputedStyle(el);
    if (! cs || cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity || '1') <= 0) { return 'CLOSED'; }
    var rect = el.getBoundingClientRect();
    return (rect && (rect.width > 0 || rect.height > 0)) ? 'OPEN' : 'CLOSED';
})();
JS
        );

        return is_string($state) ? strtoupper($state) : self::POPUP_STATE_CLOSED;
    }

    /**
     * Polls until the popover has finished opening or closing and returns the settled state.
     *
     * WHY this replaces every sleep in this class: a fixed delay is either too short (the failure
     * this fixes) or wastes that time on every single menu entry. Polling the popup's own state
     * returns as soon as the animation is through and is the same answer a human would give by
     * looking at the screen. A settled popup answers on the first round trip, so the timeout is only
     * ever spent on a genuinely stuck animation.
     *
     * @param int $timeoutSeconds
     * @return string One of the POPUP_STATE_* values, or the last unsettled state on timeout
     */
    private function waitForSettledMenuState(int $timeoutSeconds = self::MENU_ANIMATION_TIMEOUT): string
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            $state = $this->getMenuOpenState();
            if ($state === self::POPUP_STATE_OPEN || $state === self::POPUP_STATE_CLOSED) {
                return $state;
            }
            // 100 ms keeps the number of synchronous CDP round trips per animation in the tens.
            usleep(100000);
        } while (microtime(true) < $deadline);

        return $state;
    }

    /**
     * Tells whether THIS MenuButton's popover is currently open.
     *
     * WHY it may block: an answer given mid-animation is worthless to every caller of this method -
     * they either click the trigger or read the entries, and both are wrong while the popover moves.
     *
     * @return bool
     */
    private function isMenuOpen(): bool
    {
        return $this->waitForSettledMenuState() === self::POPUP_STATE_OPEN;
    }

    /**
     * Closes this MenuButton's popover if it is still open.
     *
     * WHY it waits for CLOSED instead of firing and forgetting: while the popover fades out it keeps
     * swallowing clicks, so a caller that immediately selects a table row would only dismiss the
     * leftover popup instead of selecting anything - and the check that follows blames the row.
     *
     * @return void
     * @throws RuntimeException If the popover is still open after the close was requested
     */
    protected function closeMenuIfOpen(): void
    {
        if (! $this->isMenuOpen()) {
            return;
        }

        for ($attempt = 0; $attempt < 2; $attempt++) {
            // Toggle the trigger to close; MenuButton opens/closes on the same button.
            $this->getTriggerButton()->click();
            if ($this->waitForSettledMenuState() === self::POPUP_STATE_CLOSED) {
                return;
            }
            // Same reasoning as in openMenu(): a click that changed nothing points at a stale handle.
            $this->triggerButtonId = null;
        }

        throw new RuntimeException('Menu `' . $this->getCaption() . '` did not close - every following click would be swallowed by it.');
    }

    /**
     * Opens the menu and clicks a single entry by its visible caption.
     *
     * Why this exists:
     * User-facing steps need to trigger one specific menu entry ("open menu X, click
     * item Y") without running the full validation sweep. Entries are located inside
     * this button's open popover and matched by caption - never by an id prefix,
     * because entry ids do not follow a single scheme (some entries render with their
     * own id outside the MenuButton subtree). A disabled entry fails loudly rather than
     * clicking an inert item - the caller is expected to select the required row first.
     *
     * @param string $caption Visible caption of the entry to click
     * @return void
     * @throws RuntimeException If the entry is not found or is disabled
     */
    public function clickItem(string $caption): void
    {
        $this->openMenu();

        $menu = $this->getOpenMenuElement();

        // Prefer an exact caption match; fall back to the first partial match so
        // ambiguous captions do not beat an exact hit.
        $exact = null;
        $partial = null;
        foreach ($menu->findAll('css', 'li.sapMMenuItem') as $li) {
            if (!$li->isVisible()) {
                continue;
            }
            $label = $this->getItemLabel($li);
            if ($label === '') {
                continue;
            }
            if (strcasecmp($label, $caption) === 0) {
                $exact = $li;
                break;
            }
            if ($partial === null && stripos($label, $caption) !== false) {
                $partial = $li;
            }
        }

        $item = $exact ?? $partial;
        if ($item === null) {
            $this->closeMenuIfOpen();
            throw new RuntimeException('Menu item "' . $caption . '" not found in menu "' . $this->getCaption() . '".');
        }
        if ($item->getAttribute('aria-disabled') === 'true') {
            $this->closeMenuIfOpen();
            throw new RuntimeException('Menu item "' . $caption . '" is disabled. Select a row first if the action requires one.');
        }

        $item->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
    }

    /**
     * Returns the open menu list element controlled by THIS MenuButton's trigger.
     *
     * Why this is scoped via aria-controls instead of an id prefix:
     * Entry ids do not follow a single scheme - some entries (e.g. a directly defined
     * create/"Neu" button) render with their own id outside the MenuButton subtree, so
     * an `<containerId>_Menu_` prefix cannot address every entry. The trigger's
     * aria-controls points at the exact menu it opened, which scopes the search to this
     * button's popover regardless of item id scheme, so a popover left open by a
     * different menu is never matched.
     *
     * @throws RuntimeException If this menu's popover cannot be resolved
     * @return NodeElement
     */
    private function getOpenMenuElement(): NodeElement
    {
        $page = $this->getSession()->getPage();

        $menuId = $this->getTriggerButton()->getAttribute('aria-controls');
        if ($menuId !== null && $menuId !== '') {
            $menu = $page->findById($menuId);
            if ($menu !== null && $menu->isVisible() && $menu->find('css', 'li.sapMMenuItem') !== null) {
                return $menu;
            }
        }

        // Fallback for renders that omit aria-controls: the visible menu list on the page.
        $menu = $page->find('css', 'ul.sapMMenuList[role="menu"]');
        if ($menu !== null && $menu->isVisible()) {
            return $menu;
        }

        throw new RuntimeException('Menu `' . $this->getCaption() . '` is open but its popover element could not be resolved.');
    }

    /**
     * Extracts the caption of a menu entry.
     *
     * Why this exists:
     * A menu entry wraps its label in a nested <div class="sapMMenuItemText"> next to a
     * decorative icon, so the dedicated text node is read first and the trimmed element
     * text is used only as a fallback.
     *
     * @param NodeElement $item
     * @return string
     */
    private function getItemLabel(NodeElement $item): string
    {
        $textNode = $item->find('css', '.sapMMenuItemText');
        if ($textNode !== null) {
            return trim($textNode->getText() ?? '');
        }
        return trim($item->getText() ?? '');
    }
}