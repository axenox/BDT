<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;

class UI5FilterNode extends UI5AbstractNode
{
    public function getCaption() : string
    {
        $label = $this->getNodeElement()->find('css', '.sapMLabel bdi');
        return trim($label->getText() ?? '');
    }

    public function setValue(string $value) : FacadeNodeInterface
    {
        $filterNode = $this->getNodeElement();
        // Check for ComboBox or MultiComboBox input
        $comboBox = $filterNode->find('css', '.sapMComboBoxBase, .sapMMultiComboBox');
        if ($comboBox) {
            // Handle ComboBox input
            $this->handleComboBoxInput($comboBox, $value);
            return $this;
        }

        // Check for Select input
        $select = $filterNode->find('css', '.sapMSelect');
        if ($select) {
            // Handle Select input
            $this->handleSelectInput($select, $value);
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
    public function handleComboBoxInput(NodeElement $comboBox, string $value): void
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
    public function handleSelectInput(NodeElement $select, string $value): void
    {
        // Find the option with matching text
        $item = $this->getPage()->find('css', ".sapMSelectList li:contains('{$value}')");

        if (!$item) {
            throw new \RuntimeException("Could not find option '{$value}' in Select list");
        }

        $item->click();
    }
}