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
     * Applies the requested sort through the header menu because clicking a grid header only
     * opens that menu; it does not toggle sorting.
     *
     * Accepts ascending/descending and the existing asc/desc shorthand.
     * Waits for pending operations before checking the resulting header state.
     * 
     * @param string $direction
     * @param int $retries
     * @throws \Exception
     */
    public function sort(string $direction, int $retries = 3): void
    {
        $direction = self::normalizeSortDirection($direction);

        // Already in desired state?
        if ($this->isSorted($direction)) {
            return;
        }

        for ($i = 0; $i < $retries; $i++) {
            $this->clickHeader();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

            $item = null;
            foreach ($this->getSession()->getPage()->findAll('css', '.sapUiTableColumnMenu.sapUiMnu') as $menu) {
                if ($menu->isVisible()) {
                    $item = $menu->find('css', '[id$="-' . $direction . '"]');
                    break;
                }
            }
            if (!$item || !$item->isVisible()) {
                throw new RuntimeException("Sort action '$direction' not found for column '{$this->getCaption()}'.");
            }
            $item->click();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

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

    /**
     * Resolves scenario wording and existing shorthand consistently so sorting and validation
     * cannot interpret the same requested order differently.
     *
     * @param string $direction ascending, descending, asc or desc
     * @return string asc or desc
     */
    public static function normalizeSortDirection(string $direction): string
    {
        switch (strtolower(trim($direction))) {
            case 'ascending':
            case 'asc':
                return 'asc';
            case 'descending':
            case 'desc':
                return 'desc';
        }
        throw new RuntimeException("Invalid sort direction '$direction'. Use 'ascending' or 'descending'.");
    }
}