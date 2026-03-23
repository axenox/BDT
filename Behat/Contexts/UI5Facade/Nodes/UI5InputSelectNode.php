<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;

class UI5InputSelectNode extends UI5InputNode
{
    public function setValueVisible($value, bool $validate = true) : FacadeNodeInterface
    {
        $node = $this->getNodeElement();
        if ($node->hasClass('.sapMComboBoxBase')) {
            return $this->setValueOfComboBox($value);
        } else {
            return $this->setValueOfSelect($value);
        }
        return $this;
    }


    /**
     * Handles input into a ComboBox control
     * Clicks the dropdown arrow and selects the option with matching text
     *
     * @param string $value The value to select from dropdown
     * @return void
     * @throws \RuntimeException If ComboBox arrow or option can't be found
     */
    protected function setValueOfComboBox(string $value): FacadeNodeInterface
    {
        // Find the dropdown arrow button
        $arrow = $this->getNodeElement()->find('css', '.sapMInputBaseIconContainer');
        if (!$arrow) {
            throw new FacadeNodeException($this, "Could not find ComboBox dropdown arrow");
        }

        // Click to open the dropdown
        $arrow->click();

        $lists = $this->getPage()->findAll('css', '.sapMList');
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

    /**
     * Handles input into a Select control
     * Selects the option with matching text from dropdown
     *
     * @param NodeElement $select The Select control
     * @param string $value The value to select
     * @return void
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