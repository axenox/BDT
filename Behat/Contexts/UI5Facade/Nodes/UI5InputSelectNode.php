<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;

class UI5InputSelectNode extends UI5InputNode
{
    public function setValueVisible($value, bool $validate = true) : FacadeNodeInterface
    {
        $node = $this->getNodeElement();
        if ($node->hasClass('sapMComboBoxBase')) {
            return $this->setValueOfComboBox($value);
        } else {
            return $this->setValueOfSelect($value);
        }
    }

    /**
     * Handles input into a ComboBox control
     * Clicks the dropdown arrow and selects the option with matching text
     *
     * @param string $value The value to select from dropdown
     * @return FacadeNodeInterface
     * @throws \RuntimeException If ComboBox arrow or option can't be found
     */
    protected function setValueOfComboBox(string $value): FacadeNodeInterface
    {
        //if value is empty reset the filter
        if (empty($value)) {
            if ($this->getNodeElement()->hasClass('sapMMultiComboBox')) {
                return $this->resetMultiComboBox();
            }
            return $this->resetComboBox();
        }
        
        // Find the dropdown arrow button
        $arrow = $this->getNodeElement()->find('css', '.sapMInputBaseIconContainer');
        if (!$arrow) {
            throw new FacadeNodeException($this, "Could not find ComboBox dropdown arrow");
        }

        // Click to open the dropdown
        $arrow->click();

        $lists = $this->getBrowser()->getPage()->findAll('css', '.sapMList');
        if (empty($lists)) {
            throw new FacadeNodeException($this, "Could not find ComboBox dropdown list");
        }

        // take the last opened one
        $list = end($lists);

        // Find the option with matching text
        $item = $list->find('named', ['content', $value]);

        if (!$item) {
            throw new FacadeNodeException($this, "Could not find option '{$value}' in ComboBox list");
        }

        $item->click();
        return $this;
    }
    
    protected function resetMultiComboBox(): FacadeNodeInterface
    {
        $tokens = $this->getNodeElement()->findAll('css', '.sapMTokenizer .sapMTokenIcon');

        foreach ($tokens as $deleteButton) {
            $deleteButton->click();
        }

        return $this;
    }

    protected function resetComboBox(): FacadeNodeInterface
    {
        $nodeElement = $this->getNodeElement();

        $xpath = $nodeElement->getXpath();

        $this->getSession()->executeScript("
            var xpath = " . json_encode($xpath) . ";
            var domRef = document.evaluate(xpath, document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue;
            if (!domRef) throw new Error('Element not found');
            
            var closest = domRef.closest('[data-sap-ui]');
            if (!closest) throw new Error('SAP UI5 control not found');
            
            var control = sap.ui.getCore().byId(closest.id);
            if (!control) throw new Error('SAP UI5 with control ID not found: ' + closest.id);
            
            control.setValue('');
            control.fireChange({ value: '' });
        ");

        return $this;
    }

    /**
     * Handles input into a Select control
     * Selects the option with matching text from dropdown
     *
     * @param string $value The value to select
     * @return FacadeNodeInterface
     * @throws \RuntimeException If option can't be found
     */
    protected function setValueOfSelect(string $value): FacadeNodeInterface
    {
        // Find the option with matching text
        $item = $this->getNodeElement()->find('css', ".sapMSelectList li:contains('{$value}')");

        if (!$item) {
            throw new FacadeNodeException($this, "Could not find option '{$value}' in Select list");
        }

        $item->click();
        return $this;
    }
}