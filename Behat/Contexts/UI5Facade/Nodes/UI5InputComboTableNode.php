<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use PHPUnit\Framework\Assert;

/**
 * @author Andrej Kabachnik
 * @method \exface\Core\Widgets\InputComboTable getWidget()
 */
class UI5InputComboTableNode extends UI5InputNode
{
    public function getValueVisible()
    {
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true) {
            // sap.m.MultiInput does not write anything in the underlying <input> and does not return
            // anything in its own `getValue()` - we need to go through the tokens of the internal tokenizer
            // instead
            $delim = $widget->getMultiSelectTextDelimiter();
            $val = $this->getFromJavascript(<<<JS
            
            (function(oInput, sDelim){
                var aTokens = oInput.getTokens();
                var sVal = '';
                aTokens.forEach(function(oToken) {
                    sVal += (sVal === '' ? '' : sDelim) + oToken.getText();
                });
                return sVal; // Remove trailing delimiter
            })(sap.ui.getCore().byId('{$this->getElementId()}'), '{$delim}')
JS
            );
        } else {
            $val = parent::getValueVisible();
        }
        return $val;
    }

    public function setValueVisible($value, bool $validate = true): FacadeNodeInterface
    {
        parent::setValueVisible($value, false);
        $this->waitWhileBusy();
        
        if ($dropdownFirstRowNode = $this->getBrowser()->getPage()->find('css', "#{$this->getElementId()}-popup-table tbody tr:first-of-type")) {
            $dropdownFirstRowNode->click();
            $this->waitWhileBusy(5);
        }

        // Check if UI5 marked the input as invalid (red border = valueState "Error")
        $this->checkValueStateNotError();
        
        if ($validate) {
            $this->checkValueEquals($value);
        }
        
        return $this;
    }

    public function setValueEmpty(bool $validate = true) : FacadeNodeInterface
    {
        parent::setValueEmpty(false);
        
        // Multi-select combos require special handling to clear all selected values, as they use tokens to display them
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true) {
            $id = $this->getNodeElement()->getAttribute('id');
            $this->getSession()->executeScript("sap.ui.getCore().byId('$id')?.destroyTokens();");
        }
        
        if ($validate) {
            $this->checkValueEquals('');
        }
        
        return $this;
    }

    public function waitWhileBusy(int|float $timeoutSeconds = 10) : FacadeNodeInterface
    {
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, false, true);
        $this->getSession()->wait(
            $timeoutSeconds * 1000,
            <<<JS
            (function() {
                if (sap.ui.getCore().byId('{$this->getElementId()}') === undefined) {
                    return false;
                }
                return sap.ui.getCore().byId('{$this->getElementId()}').isBusy() === false;
            })()
JS
        );
        return $this;
    }

    /**
     * Asserts that the UI5 control does not have valueState "Error".
     *
     * SAP UI5 sets valueState to "Error" when the typed value does not match
     * any entry in the combo table (red border + tooltip message).
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    private function checkValueStateNotError(): void
    {
        $elementId = $this->getElementId();
        $valueState = $this->getFromJavascript(<<<JS
        (function() {
            var control = sap.ui.getCore().byId('{$elementId}');
            return control ? control.getValueState() : null;
        })()
    JS);

        Assert::assertNotEquals(
            'Error',
            $valueState,
            "Input '{$this->getCaption()}': value was rejected by UI5 (valueState=Error). " .
            "The typed value does not exist in the combo table list."
        );
    }
}