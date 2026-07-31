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

    /**
     * Validates that a UI5 input's current value equals an expected value, using a
     * comparison strategy appropriate to the widget's data type.
     *
     * WHY THIS EXISTS: date/datetime inputs cannot be compared as plain strings — the
     * field re-renders whatever is typed into its own locale/precision format, so the
     * echoed-back value rarely matches the typed one character-for-character. This method
     * routes date/datetime widgets through a display-precision date comparison and every
     * other widget through a direct value comparison, so callers get a single, correct
     * "does the field hold what I set?" check regardless of widget type.
     */
    public function checkValueEquals($expectedValue) : FacadeNodeInterface
    {
        $newVal = $this->getValueVisible() ?? '';
        $el = $this->getNodeElement();

        if ($el->hasClass('exfw-InputDate') || $el->hasClass('exfw-InputDateTime')) {
            // A date filter reset clears the field, so the expected/actual value is an empty string.
            // An empty value is not a parseable date, and datesEqualAtDisplayPrecision() returns false
            // for it — routing empties through the date comparison would report a mismatch for a
            // legitimately cleared filter. When either side is empty we compare the raw (trimmed)
            // strings directly: two empties match (filter cleared), and an empty-vs-date case fails
            // as a clean assertion.
            $expectedTrimmed = trim((string) $expectedValue);
            $actualTrimmed   = trim((string) $newVal);
            if ($expectedTrimmed === '' || $actualTrimmed === '') {
                Assert::assertSame(
                    $expectedTrimmed,
                    $actualTrimmed,
                    "Expected date `$expectedValue` does not match actual `$newVal` in filter '{$this->getCaption()}'"
                );
                return $this;
            }

            // Compare at the precision the input can physically display, not at full-year precision.
            // A UI5 date input configured with a 2-digit year (dd.MM.yy) cannot echo back a value's
            // century: sourcing a real filter value whose year falls outside the 2-digit pivot window
            // (e.g. "0202-07-01") makes the field show "01.07.02", which reads back as year 2002.
            // A full-year ISO comparison (normalizeDateToIso + assertSame) would then flag a mismatch
            // for a field that in fact accepted the value. datesEqualAtDisplayPrecision() re-renders the
            // expected value in the field's own display format (learned from the actual value), so it
            // still catches a genuine day/month/2-digit-year mismatch while tolerating a century the
            // field never displayed. The datetime case is handled automatically, since the format is
            // derived from the actual value rather than a fixed flag.
            Assert::assertTrue(
                $this->datesEqualAtDisplayPrecision($expectedTrimmed, $actualTrimmed),
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