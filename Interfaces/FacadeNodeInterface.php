<?php
namespace axenox\BDT\Interfaces;

use Behat\Mink\Element\NodeElement;
use exface\Core\Interfaces\Model\UiPageInterface;

interface FacadeNodeInterface
{
    // Retrieves the underlying Mink NodeElement for the current UI node
    public function getNodeElement(): NodeElement;
    // Extracts and returns the caption of the UI node

    public function getCaption(): string;

    // Determines the specific type of widget or UI component
    public function getWidgetType(): ?string;

    // Checks if the UI node can capture  focus

    public function capturesFocus(): bool;
    
    //checks the functionality of the node
    public function itWorksAsExpected(UiPageInterface $page): void;
}