<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;

class UI5InputNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        return '';
    }
    
    public function setValue($value) : FacadeNodeInterface
    {
        if ($inputEl = $this->findNativeDomElement()) {
            $inputEl->setValue($value);
        }
        return $this;
    }

    public function setValueEmpty() : FacadeNodeInterface
    {
        return $this->setValue('');
    }

    /**
     * {@inheritDoc}
     * @see UI5AbstractNode::reset()
     */
    public function reset() : FacadeNodeInterface
    {
        return $this->setValueEmpty();
    }
    
    protected function findNativeDomElement() : ?NodeElement
    {
        $widgetNodeElement = $this->getNodeElement();
        switch (true) {
            case $node = $widgetNodeElement->find('css', 'input'):
            case $node = $widgetNodeElement->find('css', 'checkbox'):
            case $node = $widgetNodeElement->find('css', 'textarea'):
                return $node;
        }
        return null;
    }
}