<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use exface\Core\Exceptions\RuntimeException;

class UI5HeaderColumnNode extends UI5AbstractNode
{
    /**
     * Returns the visible caption of the header.
     * Priority: <label.sapUiLbl> → .sapMText/.sapMLabel → cell text.
     * 
     * @return string
     */
    public function getCaption(): string
    {
        $label = $this->getNodeElement()->find('css', 'label.sapUiLbl');
        if (!$label) {
            $label = $this->getNodeElement()->find('css', '.sapMText, .sapMLabel');
        }

        return $label ? trim($label->getText()) : trim($this->getNodeElement()->getText());
    }

    /**
     * Clicks on header (inner container if present) to toggle sort state.
     */
    public function clickHeader(): void
    {
        $target = $this->getNodeElement()->find('css', '.sapUiTableCellInner') ?: $this->getNodeElement();
        $target->click();
    }

    /**
     * Returns current sort state derived from aria-sort.
     * - 'ascending' | 'descending' | 'none'
     * 
     * @return string
     */
    public function getSortState(): string
    {
        $aria = (string)$this->getNodeElement()->getAttribute('aria-sort');
        if ($aria === 'ascending' || $aria === 'descending') {
            return $aria;
        }
        return 'none';
    }

    /**
     * Ensures the column is sorted in the desired direction ('asc' | 'desc').
     * Tries up to $retries clicks, waiting after each click.
     * 
     * @param string $direction
     * @param int $retries
     * @throws \Exception
     */
    public function sort(string $direction, int $retries = 3): void
    {
        $direction = strtolower($direction);
        if (!in_array($direction, ['asc', 'desc'], true)) {
            throw new RuntimeException("Invalid sort direction '$direction'. Use 'asc' or 'desc'.");
        }

        // Already in desired state?
        if ($this->isSorted($direction)) {
            return;
        }

        for ($i = 0; $i < $retries; $i++) {
            $this->clickHeader();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations();

            if ($this->isSorted($direction)) {
                return;
            }
        }

        $cap = $this->getCaption();
        throw new RuntimeException("Failed to set sorting '$direction' on column '$cap'.");
    }

    /**
     * True if current sort state matches requested direction.
     * 
     * @param string $direction
     * @return bool
     */
    public function isSorted(string $direction): bool
    {
        $state = $this->getSortState();
        return ($direction === 'asc'  && $state === 'ascending')
            || ($direction === 'desc' && $state === 'descending');
    }
}