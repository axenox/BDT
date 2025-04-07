<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

class UI5DialogNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);;
    }

    public function capturesFocus() : bool
    {
        return true;
    }
}