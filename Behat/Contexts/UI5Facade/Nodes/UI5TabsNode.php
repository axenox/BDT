<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Widgets\Tabs;

/**
 * Node for the `Tabs` widget.
 *
 * WHY this node exists: the facade renders tabs in more than one way. In a maximized dialog they
 * become sections of a sap.uxap.ObjectPageLayout and the `Tabs` widget gets no control of its own;
 * in a small dialog they become a sap.m.IconTabBar. What stays the same is that every `Tab` carries
 * exactly the id the facade generated for it, so the inherited container walk finds them - it only
 * needs the surrounding element as its search scope and an inactive tab opened first.
 *
 * @method \exface\Core\Widgets\Tabs getWidget()
 */
class UI5TabsNode extends UI5ContainerNode
{
    /**
     * The `Tabs` widget itself may have no element - see the class description.
     *
     * {@inheritDoc}
     * @see FacadeNodeInterface::usesOwnDomElement()
     */
    public function usesOwnDomElement(): bool
    {
        return false;
    }

    /**
     * Opens the tab before letting the inherited container check look for its contents.
     *
     * WHY: an IconTabBar renders the contents of the selected tab only - the elements of every other
     * tab are simply not in the DOM yet. Without opening it first, a perfectly healthy tab would be
     * reported as a missing DOM element.
     *
     * WHY findTabByCaption() and not goToTab(): goToTab() asserts that the tab exists and would abort
     * the whole check with a raw assertion error instead of the logged failure the inherited method
     * produces. Scoping the search to this node's element also keeps nested tabs and dialogs left
     * open in the background from matching the same caption.
     *
     * {@inheritDoc}
     */
    protected function checkChildWorksAsExpected(WidgetInterface $childWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $caption = $childWidget->getCaption();
        if ($caption !== null && $caption !== '') {
            $tabHeader = $this->getBrowser()->findTabByCaption($caption, $this->getNodeElement());
            if ($tabHeader !== null && ! $tabHeader->hasClass('sapMITBSelected')) {
                $tabHeader->click();
                $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
            }
        }
        return parent::checkChildWorksAsExpected($childWidget, $logbook);
    }
}