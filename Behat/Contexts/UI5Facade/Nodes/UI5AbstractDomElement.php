<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    private $domNode = null;
    public function __construct(NodeElement $nodeElement)
    {
        $this->domNode = $nodeElement;
    }

    public function getNodeElement() : NodeElement
    {
        return $this->domNode;
    }
}