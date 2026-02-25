<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\DataTypes\StepStatusDataType;
use exface\Core\Interfaces\Debug\LogBookInterface;

class UI5ContainerNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        // TODO
        return '';
    }

    public function checkWorksAsExpected(LogBookInterface $logbook) : int
    {
        $childWidgetNodes = $this->getNodeElement()->findAll('css', '.exfw');
        $result = StepStatusDataType::PASSED;
        foreach ($childWidgetNodes as $childWidgetNode) {
            if($this->getNodeElement()->getAttribute('id')=== $childWidgetNode->getAttribute('id') ) {
                continue;
            }
            if ($this->isNodeInsideAnotherWidget($childWidgetNode)) {
                continue;
            }
            $widgetType = $this->getBrowser()->getNodeWidgetType($childWidgetNode);
            $node = UI5FacadeNodeFactory::createFromNodeElement($widgetType, $childWidgetNode, $this->getSession(), $this->getBrowser());
            $childResult = $node->checkWorksAsExpected($logbook);
            $result = max($result, $childResult);
        }
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