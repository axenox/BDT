<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use exface\Core\Interfaces\Model\UiPageInterface;

class UI5ContainerNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        // TODO
        return '';
    }

    public function itWorksAsExpected(UiPageInterface $page): void
    {
        $childWidgetNodes = $this->getNodeElement()->findAll('css', '.exfw');
        foreach ($childWidgetNodes as $childWidgetNode) {
            $widgetType = $this->getBrowser()->getNodeWidgetType($childWidgetNode);
            $node = UI5FacadeNodeFactory::createFromNodeElement($widgetType, $childWidgetNode, $this->getSession(), $this->getBrowser());
            if($this->getNodeElement()->getAttribute('id')=== $childWidgetNode->getAttribute('id') ) {
                continue;
            }
            $node->itWorksAsExpected($page);
        }
    }
}