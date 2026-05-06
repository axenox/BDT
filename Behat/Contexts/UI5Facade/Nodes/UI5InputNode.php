<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\Elements\DateParsingTrait;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

/**
 *
 * @method \exface\Core\Widgets\Input getWidget()
 */
class UI5InputNode extends UI5AbstractNode
{

    use DateParsingTrait;
    public function getCaption() : string
    {
        $label = $this->getNodeElement()->find(
            'xpath',
            'ancestor::div[contains(@class,"sapUiVltCell")]'
            . '/preceding-sibling::div[contains(@class,"sapUiVltCell")][1]'
            . '//bdi'
        );

        return $label !== null ? trim($label->getText()) : '';
    }
    
    public function getValueVisible()
    {
        $val = null;
        if ($inputDomNode = $this->findNativeDomNode()) {
            $val = $inputDomNode->getValue();
        }
        return $val;
    }
    
    public function setValueVisible($value, bool $validate = true) : FacadeNodeInterface
    {
        if ($inputDomNode = $this->findNativeDomNode()) {
            $inputDomNode->setValue($value);
        }
        
        if ($validate) {
            $this->checkValueEquals($value);
        }
        return $this;
    }

    public function setValueEmpty(bool $validate = true) : FacadeNodeInterface
    {
        return $this->setValueVisible('', $validate);
    }

    public function checkValueEquals($expectedValue) : FacadeNodeInterface
    {
        $newVal = $this->getValueVisible() ?? '';
        $el = $this->getNodeElement();

        if ($el->hasClass('exfw-InputDate') || $el->hasClass('exfw-InputDateTime')) {
            $isDateTime = $el->hasClass('exfw-InputDateTime');
            Assert::assertSame(
                $this->normalizeDateToIso($expectedValue, $this->getCaption(), $isDateTime),
                $this->normalizeDateToIso($newVal, $this->getCaption(), $isDateTime),
                "Expected date `$expectedValue` does not match actual `$newVal` in filter '{$this->getCaption()}'"
            );
            return $this;
        }
        
        Assert::assertEquals($expectedValue, $newVal, "Expected value `$expectedValue` does not match actual value `$newVal` in InputComboTable '{$this->getCaption()}'");
        return $this;
    }
    
    public function checkValueEmpty() : FacadeNodeInterface
    {
        return $this->checkValueEquals('');
    }

    /**
     * {@inheritDoc}
     * @see UI5AbstractNode::reset()
     */
    public function reset() : FacadeNodeInterface
    {
        return $this->setValueEmpty();
    }

    /**
     * Returns a Mink NodeElement for the native HTML form element - e.g. <input>, <checkbox>, <textarea> or similar.
     * 
     * Returns NULL if this node does not have a native HTML form element.
     * 
     * @return NodeElement|null
     */
    protected function findNativeDomNode() : ?NodeElement
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