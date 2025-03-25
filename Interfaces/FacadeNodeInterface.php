<?php
namespace axenox\BDT\Interfaces;

use Behat\Mink\Element\NodeElement;

interface FacadeNodeInterface
{
    public function getNodeElement() : NodeElement;
    public function getCaption() : string;
}