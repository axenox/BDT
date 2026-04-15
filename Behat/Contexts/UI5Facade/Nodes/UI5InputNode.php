<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

/**
 *
 * @method \exface\Core\Widgets\Input getWidget()
 */
class UI5InputNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        return '';
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
                $this->normalizeDateToIso($expectedValue, $isDateTime),
                $this->normalizeDateToIso($newVal, $isDateTime),
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

    /**
     * Normalizes various date or datetime strings to a comparable ISO format.
     *
     * Date formats supported:    Y-m-d, d.m.Y, d/m/Y, m/d/Y
     * Datetime formats supported: Y-m-d H:i, d.m.Y H:i, d.m.Y, H:i:s suffix is stripped
     *                             because the UI never shows seconds.
     *
     * @param bool $includeTime When true, normalizes to "Y-m-d H:i"; otherwise "Y-m-d"
     */
    private function normalizeDateToIso(string $value, bool $includeTime = false): string
    {
        $value = trim($value);

        if ($includeTime) {
            $formats = [
                'd.m.Y H:i:s',  // with seconds: "15.06.2025 14:30:00"
                'd.m.Y H:i',    // UI display: "15.06.2025 14:30"
                'Y-m-d H:i:s',  // ISO with seconds: "2025-06-15 14:30:00"
                'Y-m-d H:i',    // ISO: "2025-06-15 14:30"
                'd/m/Y H:i',
                'm/d/Y H:i',
            ];
            foreach ($formats as $format) {
                $dt = \DateTime::createFromFormat('!' . $format, $value);
                if ($dt !== false && $dt->format($format) === $value) {
                    return $dt->format('Y-m-d H:i'); // always normalize to H:i, no seconds
                }
            }
        }
        
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'] as $format) {
            $dt = \DateTime::createFromFormat('!' . $format, $value);
            if ($dt !== false && $dt->format($format) === $value) {
                return $dt->format('Y-m-d');
            }
        }
        throw new \InvalidArgumentException("Cannot parse date value `$value` in filter '{$this->getCaption()}'");
    }
}