<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;

class UI5InputComboTableNode extends UI5InputNode
{
    public function setValueEmpty() : FacadeNodeInterface
    {
        parent::setValueEmpty();
        
        // Multi-select combos require special handling to clear all selected values, as they use tokens to display them
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true) {
            $id = $this->getNodeElement()->getAttribute('id');
            $this->getSession()->executeScript("sap.ui.getCore().byId('$id')?.destroyTokens();");
        }
        return $this;
    }
}