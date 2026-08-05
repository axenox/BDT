<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
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
     * Constructor
     *
     * @param NodeElement $nodeElement
     * @param Session $session
     * @param UI5Browser $browser
     */
    public function __construct(NodeElement $nodeElement, Session $session, UI5Browser $browser)
    {
        parent::__construct($nodeElement, $session, $browser);
    }

    /**
     * Resolves the ExFace widget behind this MenuButton.
     *
     * Why this override exists:
     * The node may be constructed from the internal <button> element, whose id
     * carries a UI5 render suffix ("-internalBtn") that is not part of the ExFace
     * widget id and would make page->getWidget() fail. The `.exfw-MenuButton`
     * container id is the clean widget id, so the widget is always resolved from
     * the container instead.
     *
     * @return WidgetInterface
     */
    public function getWidget() : WidgetInterface
    {
        $container = $this->getMenuButtonContainer();
        return $this->getWidgetFromElementId($container->getAttribute('id'));
    }

    /**
     * Opens the menu and validates every visible entry as its own button action.
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $widget = $this->getWidget();
        if (! $widget instanceof iHaveButtons) {
            // Nothing to validate if the model does not expose child buttons.
            return SubstepResult::createSkipped('MenuButton `' . $this->getCaption() . '` has no child buttons', $logbook);
        }

        $failed = false;
        foreach ($widget->getButtons() as $entryWidget) {
            if ($entryWidget->isHidden()) {
                continue;
            }

            $entryId = $this->getElementIdFromWidget($entryWidget);
            $table = $this->getOwningDataTableNode();

            // Open the menu and read the entry's enabled state from aria-disabled
            // (the <li> carries no `disabled` attribute, so UI5ButtonNode::checkDisabled
            // cannot be used here).
            $this->openMenu();
            $entryEl = $this->getSession()->getPage()->findById($entryId);
            $isDisabled = $entryEl === null || ! $entryEl->isVisible()
                || UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser())->checkDisabled();

            // Some actions enable only for specific rows. If the entry is disabled,
            // walk the first-page rows until one enables it. The popover is modal, so
            // the menu must be closed to select a row and reopened to re-check.
            if ($isDisabled && $table !== null) {
                $this->closeMenuIfOpen();
                $ready = $table->selectEachRowUntil(function() use ($entryId, &$entryEl) {
                    $this->openMenu();
                    $entryEl = $this->getSession()->getPage()->findById($entryId);
                    if ($entryEl === null || ! $entryEl->isVisible()) {
                        $this->closeMenuIfOpen();
                        return false;
                    }
                    // Single source of truth for "disabled": the <li> re-renders on each
                    // reopen, so the node is rebuilt from the fresh element before asking.
                    $entryNode = UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser());
                    $enabled = ! $entryNode->checkDisabled();
                    if (! $enabled) {
                        $this->closeMenuIfOpen();
                    }
                    return $enabled;
                });
                if (! $ready) {
                    $logbook->addLine('Skipping menu item `' . $entryWidget->getCaption() . '` - no row on the first page enables it');
                    $this->closeMenuIfOpen();
                    continue;
                }
            }

            if ($entryEl === null || ! $entryEl->isVisible()) {
                $logbook->addLine('Skipping menu item `' . $entryWidget->getCaption() . '` - not present/visible in the open menu');
                $this->closeMenuIfOpen();
                continue;
            }

            // The <li> is tagged `exfw exfw-DataButton`, so the factory resolves the
            // proper button node; its id round-trips to the child DataButton widget.
            $entryNode = UI5FacadeNodeFactory::createFromNodeElement($entryEl, $this->getSession(), $this->getBrowser());
            $urlBeforeClick = $this->getSession()->getCurrentUrl();

            $result = $this->runAsSubstep(
                function() use ($entryNode, $logbook) {
                    return $entryNode->checkWorksAsExpected($logbook);
                },
                'Clicking menu item "' . $entryWidget->getCaption() . '"',
                'Dialogs',
                $logbook,
                function() use ($urlBeforeClick) {
                    if ($this->getSession()->getCurrentUrl() !== $urlBeforeClick) {
                        $this->getBrowser()->navigateToPreviousPage();
                    }
                }
            );
            if ($result->isFailed()) {
                $failed = true;
            }
        }

        // Leave no popover open for the buttons that follow this one in the toolbar.
        $this->closeMenuIfOpen();

        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
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
     * @throws RuntimeException If the entry is not found or is disabled
     * @return void
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
            if (! $li->isVisible()) {
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

    /**
     * Returns the `.exfw-MenuButton` container, whether the node was built from the
     * container itself or from its internal trigger <button>.
     *
     * Why this exists:
     * Widget resolution and entry-id prefixing both need the clean container id;
     * depending on the construction path the node element may be the container or
     * the inner button, so both cases are normalized here.
     *
     * @throws RuntimeException If no MenuButton container can be located
     * @return NodeElement
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
     * Returns the clickable <button> that toggles the menu popover.
     *
     * @throws RuntimeException If no trigger button is found
     * @return NodeElement
     */
    protected function getTriggerButton(): NodeElement
    {
        $el = $this->getNodeElement();
        if ($el->getTagName() === 'button' && $el->getAttribute('aria-haspopup') === 'menu') {
            return $el;
        }
        $container = $this->getMenuButtonContainer();
        $trigger = $container->find('css', 'button[aria-haspopup="menu"]') ?? $container->find('css', 'button');
        if ($trigger === null) {
            throw new RuntimeException('Cannot find the trigger button of menu `' . trim($container->getText() ?? '') . '`.');
        }
        return $trigger;
    }

    /**
     * Ensures the menu popover for this specific MenuButton is open.
     *
     * Why this polls and is id-scoped:
     * The popover renders asynchronously, so opening is polled. Entries are matched
     * by the container-id prefix (`<containerId>_Menu_`) so a popover left open by a
     * different menu is never mistaken for this one, which also makes re-entry
     * idempotent - if this menu is already open, no extra click is issued.
     *
     * @throws RuntimeException If the menu does not open
     * @return void
     */
    protected function openMenu(): void
    {
        $trigger = $this->getTriggerButton();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            if ($this->isMenuOpen()) {
                return;
            }
            // Re-issue the click on every attempt (not only the first): a single click
            // can be swallowed while UI5 is still finishing a previous open/close
            // animation, leaving the menu shut with no chance to recover.
            $trigger->click();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
            $this->getSession()->wait(300);
        }

        throw new RuntimeException('Menu `' . $this->getCaption() . '` did not open after clicking its trigger button.');
    }

    /**
     * Tells whether THIS MenuButton's popover is currently open.
     *
     * Why this is state-driven instead of id-prefix based:
     * Menu entry ids are not guaranteed to start with `<containerId>_Menu_`. Some
     * entries (e.g. a directly defined create/"Neu" button) render with their own id
     * outside the MenuButton subtree, so detecting "open" by guessing item ids yields
     * false negatives: the popover is visibly open while the guessed prefix matches no
     * <li>. UI5 sets aria-expanded="true" on the trigger button of the open menu, which
     * is a stable signal scoped to exactly this button, regardless of item id scheme.
     *
     * @return bool
     */
    private function isMenuOpen(): bool
    {
        $expanded = $this->getTriggerButton()->getAttribute('aria-expanded');
        if ($expanded === 'true') {
            return true;
        }
        if ($expanded === 'false') {
            return false;
        }
        // Older UI5 renders may omit aria-expanded; fall back to a visible menu list.
        $menu = $this->getSession()->getPage()->find('css', 'ul.sapMMenuList[role="menu"]');
        return $menu !== null && $menu->isVisible();
    }

    /**
     * Closes this MenuButton's popover if it is still open.
     *
     * @return void
     */
    protected function closeMenuIfOpen(): void
    {
        if ($this->isMenuOpen()) {
            // Toggle the trigger to close; MenuButton opens/closes on the same button.
            $this->getTriggerButton()->click();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
        }
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
}