<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use axenox\BDT\Interfaces\FacadeNodeInterface;

class UI5ButtonNode extends UI5AbstractNode implements FacadeNodeInterface
{

    /**
     * Constructor
     *
     * @param NodeElement $nodeElement
     * @param Session $session
     * @param UI5Browser $browser
     */
    public function __construct(NodeElement $nodeElement, Session $session, UI5Browser $browser)
    {
        // Call upper level constructor
        parent::__construct($nodeElement, $session, $browser);
    }

    public function click(): void
    {

        $this->getNodeElement()->click();

        // check exf-dialog-close class for action
        if ($this->isDialogCloseButton()) {
            $this->unfocusAfterClose();
        }
    }

    /**
     * Check if it has dialog close button class
     * 
     * @return bool
     */
    public function isDialogCloseButton(): bool
    {
        return $this->getNodeElement()->hasClass('exf-dialog-close');
    }

    public function getCaption(): string
    {
        // Take Button caption
        return trim($this->getNodeElement()->getText() ?? '');
    }

    private function unfocusAfterClose(): void
    {
        // Call unfocus method on Browser
        $this->getSession()->evaluateScript('
            if (window.unfocusDialog) {
                window.unfocusDialog();
            }
        ');
    }
}