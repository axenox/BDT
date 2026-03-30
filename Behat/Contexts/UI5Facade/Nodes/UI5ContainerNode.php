<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;

/**
 * @method \exface\Core\Widgets\Container getWidget()
 */
class UI5ContainerNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        // TODO
        return '';
    }

    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $childWidgets = $this->getWidget()->getWidgets();
        $failed = false;
        foreach ($childWidgets as $childWidget) {
            if ($childWidget->isHidden()) {
                continue;
            }
            $childWidgetElement = $this->getNodeElement()->find('css', '#' . $this->getElementIdFromWidget($childWidget));
            if ($childWidgetElement === null) {
                $caption = $childWidget->getCaption();
                if (! $caption) {
                    $caption = 'with id "' . $childWidget->getId() . '"';
                } else {
                    $caption = '"' . $caption . '"';
                }
                $this->logSubstep('Looking at ' . $childWidget->getWidgetType() . ' ' . $caption, StepStatusDataType::FAILED, 'Cannot find DOM element');
                $failed = true;
                continue;
            }
            $node = UI5FacadeNodeFactory::createFromWidgetType($childWidget->getWidgetType(), $childWidgetElement, $this->getSession(), $this->getBrowser());
            $childResult = $node->checkWorksAsExpected($logbook);
            if ($childResult->isFailed()) {
                $failed = true;
            }
        }
        $result = $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
        return $result;
    }

    /**
     * Determines whether the given node is nested inside another widget.
     *  * This check is crucial to prevent redundant testing of widgets that are already
     *  managed by a parent widget (e.g., filters within a DataTable). It traverses
     *  up the DOM tree from the current node:
     *  - If it encounters another element with the '.exfw' class before reaching
     *  this container, the node is considered "nested" and should be skipped.
     *  - This ensures that each widget's 'itWorksAsExpected' is only triggered
     *  once by its immediate logical parent.
     * 
     * @param $childNode
     * @return bool
     */
    private function isNodeInsideAnotherWidget($childNode): bool
    {
        $parent = $childNode->getParent();
        while ($parent !== null && $parent->getAttribute('id') !== $this->getNodeElement()->getAttribute('id')) {
            if ($parent->hasClass('exfw')) {
                return true;
            }
            $parent = $parent->getParent();
        }
        return false;
    }
}