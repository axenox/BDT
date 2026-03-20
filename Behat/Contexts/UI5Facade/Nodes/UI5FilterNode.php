<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Widgets\Filter;
use exface\UI5Facade\Facades\Elements\UI5Input;

/**
 * Represents a UI5 Filter Node for handling various types of filter inputs
 * 
 * This class provides methods to interact with different types of UI5 filter controls like:
 * - ComboBox
 * - MultiComboBox
 * - Select
 * - Standard Input
 * 
 * It supports finding and setting values for different filter input types 
 * commonly found in SAP UI5 applications.
 */
class UI5FilterNode extends UI5AbstractNode
{
    private $inputNode = null;
    
    /**
     * Retrieves the caption (label) of the filter node
     * 
     * Attempts to find the label text within a UI5 filter control
     * using a specific CSS selector for label elements.
     * 
     * @return string Trimmed label text, or empty string if not found
     */
    public function getCaption(): string
    {
        $label = $this->getNodeElement()->find('css', '.sapMLabel bdi');
        return trim($label->getText() ?? '');
    }

    /**
     * Sets the value for a filter input based on its control type
     * 
     * Dynamically detects and handles different UI5 input control types:
     * - ComboBox/MultiComboBox
     * - Select
     * - Standard Input
     * 
     * @param string $value The value to set in the filter
     * @return FacadeNodeInterface The current filter node instance 
     */
    public function setValue(string $value): FacadeNodeInterface
    {
        $this->getInputNode()->setValue($value);  
        return $this;
    }

    /**
     * Gets the current page from the session
     * 
     * @return NodeElement The current page element
     */
    public function getPage()
    {
        // Directly get the page with session
        return $this->getSession()->getPage();
    }

    public function setValueEmpty()
    {
        $this->getInputNode()->setValueEmpty();
        return $this;
    }

    /**
     * {@inheritDoc}
     * @see UI5AbstractNode::reset()
     */
    public function reset() : FacadeNodeInterface
    {
        return $this->setValueEmpty();
    }
    
    public function getInputNode() : UI5InputNode
    {
        if ($this->inputNode === null) {
            $inputEl = $this->getNodeElement()->find('css', "#{$this->getNodeElement()->getAttribute('id')} .exfw");
            $this->inputNode = UI5FacadeNodeFactory::createFromNodeElement($inputEl, $this->getSession(), $this->getBrowser());

            /*
            $filterNode = $this->getNodeElement();
            switch (true) {
                // Check for Select and ComboBox or MultiComboBox input
                case $node = $filterNode->find('css', '.sapMComboBoxBase, .sapMMultiComboBox'):
                case $node = $filterNode->find('css', '.sapMSelect'):
                    $this->inputNode = new UI5InputSelectNode($node, $this->getSession(), $this->getBrowser());
                    break;
                // Check for standard input field
                case $node = $filterNode->find('css', '.sapMInput'):
                    $this->inputNode = new UI5InputNode($node, $this->getSession(), $this->getBrowser());
                    break;
                default:
                    throw new FacadeNodeException($this, "Could not find filter input node");
            }*/
        }
        return $this->inputNode;
    }
}