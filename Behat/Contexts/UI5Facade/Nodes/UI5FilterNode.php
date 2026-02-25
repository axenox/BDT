<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;

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
        $filterNode = $this->getNodeElement();
        // Check for ComboBox or MultiComboBox input
        $comboBox = $filterNode->find('css', '.sapMComboBoxBase, .sapMMultiComboBox');
        if ($comboBox) {
            // Handle ComboBox input
            $this->setValueOfComboBox($comboBox, $value);
            return $this;
        }

        // Check for Select input
        $select = $filterNode->find('css', '.sapMSelect');
        if ($select) {
            // Handle Select input
            $this->setValueOfSelect($select, $value);
            return $this;
        }

        // Check for standard input field
        $targetInput = $filterNode->find('css', 'input.sapMInputBaseInner');
        if ($targetInput) {
            // Set the value for standard input
            $targetInput->setValue($value);
            return $this;
        }
    }


    /**
     * Handles input into a ComboBox control
     * Clicks the dropdown arrow and selects the option with matching text
     * 
     * @param NodeElement $comboBox The ComboBox control
     * @param string $value The value to select from dropdown
     * @return void
     * @throws \RuntimeException If ComboBox arrow or option can't be found
     */
    public function setValueOfComboBox(NodeElement $comboBox, string $value): void
    {
        // Find the dropdown arrow button
        $arrow = $comboBox->find('css', '.sapMInputBaseIconContainer');
        if (!$arrow) {
            throw new \RuntimeException("Could not find ComboBox dropdown arrow");
        }

        // Click to open the dropdown
        $arrow->click();
        // TODO for getPage() to work, we probably need to pass the page to the constructor of each FacadeNode
        // Find the option with matching text
        $item = $this->getPage()->find(
            'css',
            ".sapMSelectList li:contains('{$value}'), " .
            ".sapMComboBoxItem:contains('{$value}'), " .
            ".sapMMultiComboBoxItem:contains('{$value}')"
        );

        if (!$item) {
            throw new \RuntimeException("Could not find option '{$value}' in ComboBox list");
        }

        $item->click();
    }

    /**
     * Handles input into a Select control
     * Selects the option with matching text from dropdown
     * 
     * @param NodeElement $select The Select control
     * @param string $value The value to select
     * @return void
     * @throws \RuntimeException If option can't be found
     */
    public function setValueOfSelect(NodeElement $select, string $value): void
    {
        // Find the option with matching text
        $item = $this->getPage()->find('css', ".sapMSelectList li:contains('{$value}')");

        if (!$item) {
            throw new \RuntimeException("Could not find option '{$value}' in Select list");
        }

        $item->click();
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
}