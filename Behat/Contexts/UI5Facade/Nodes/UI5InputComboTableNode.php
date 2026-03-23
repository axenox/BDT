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
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        
        if ($dropdownFirstRowNode = $this->getBrowser()->getPage()->find('css', "#{$this->getElementId()}-popup-table tbody tr:first-of-type")) {
            $dropdownFirstRowNode->click();
        }
        
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
}