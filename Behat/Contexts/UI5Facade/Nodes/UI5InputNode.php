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
            // Run normalizeDateToIso first: a genuinely unparseable value still fails loudly with a precise
            // "Cannot parse date value" message, and the common case (years inside the 2-digit pivot window)
            // passes here on an exact full-year match.
            $expectedIso = $this->normalizeDateToIso($expectedValue, $this->getCaption(), $isDateTime);
            $actualIso   = $this->normalizeDateToIso($newVal, $this->getCaption(), $isDateTime);
            if ($expectedIso === $actualIso) {
                return $this;
            }
            // Full-year forms differ. A 2-digit-year date input cannot echo a value's century, so a source
            // value like year 202 ("0202-07-01") legitimately shows as "01.07.02" and reads back as 2002.
            // Re-compare at the precision the field actually displays before failing: this avoids a false
            // failure without masking a real day/month/2-digit-year mismatch.
            Assert::assertTrue(
                $this->datesEqualAtDisplayPrecision($expectedValue, $newVal),
                "Expected date `$expectedValue` (normalized `$expectedIso`) does not match actual `$newVal` (normalized `$actualIso`) in filter '{$this->getCaption()}'"
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