<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\Elements\DateParsingTrait;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\Assert;

/**
 *
 * @method \exface\Core\Widgets\Input getWidget()
 */
class UI5InputNode extends UI5AbstractNode
{

    use DateParsingTrait;

    /**
     * Reports whether the user is prevented from changing this input's value.
     *
     * WHY BOTH PROPERTIES: UI5 controls can be enabled but read-only, and selects,
     * checkboxes and segmented buttons do not all expose the same properties.
     * Embedded editors and custom inputs may use an HTML control without either
     * property, so their rendered editing elements determine the state instead.
     * An unrecognised rendering must fail rather than silently report "enabled".
     *
     * @return bool
     */
    public function checkDisabled(): bool
    {
        $idJs = json_encode($this->getElementId(), JSON_THROW_ON_ERROR);
        return $this->getFromJavascript(<<<JS
            (function(sId) {
                var oControl = sap.ui.getCore().byId(sId);
                var oRoot = document.getElementById(sId);
                if (!oRoot) {
                    throw new Error('Input element not found: ' + sId);
                }
                var bHasEnabled = oControl && typeof oControl.getEnabled === 'function';
                var bHasEditable = oControl && typeof oControl.getEditable === 'function';
                if ((bHasEnabled && !oControl.getEnabled())
                    || (bHasEditable && !oControl.getEditable())) {
                    return true;
                }
                if (bHasEnabled || bHasEditable) {
                    return false;
                }
                var sSelector = 'input:not([type="hidden"]), textarea, select, [contenteditable], '
                    + '[role="textbox"], [role="checkbox"], [role="combobox"], '
                    + '[role="switch"], [role="slider"], [role="spinbutton"], [role="radio"]';
                var aInputs = Array.prototype.slice.call(oRoot.querySelectorAll(sSelector));
                if (oRoot.matches(sSelector)) {
                    aInputs.unshift(oRoot);
                }
                if (aInputs.length === 0) {
                    throw new Error('Cannot determine whether input is editable: ' + sId);
                }
                return aInputs.every(function(oInput) {
                    return oInput.matches(':disabled') || oInput.readOnly === true
                        || oInput.closest('[disabled], [readonly], [aria-disabled="true"], [aria-readonly="true"]') !== null
                        || (oInput.hasAttribute('contenteditable') && !oInput.isContentEditable);
                });
            })($idJs)
            JS
        );
    }

    /**
     * Finds a captioned input in the dialog body or header without duplicating field lookup.
     *
     * Input and InputDate expose a native input, while InputText exposes a textarea;
     * the existing value reader handles both and preserves the displayed date format.
     * Restricting this entry point to those types prevents returning a Display node
     * through the input API.
     *
     * @param UI5DialogNode $dialog Dialog to search
     * @param string $caption Model caption of the field
     * @param string $type Input, InputDate or InputText
     * @return UI5InputNode|null
     */
    public static function findByCaption(UI5DialogNode $dialog, string $caption, string $type = 'Input'): ?self
    {
        if (!in_array($type, ['Input', 'InputDate', 'InputText'], true)) {
            throw new RuntimeException('Unsupported input field type "' . $type . '": expected Input, InputDate or InputText.');
        }
        return $dialog->findFieldByCaption($caption, $type);
    }

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