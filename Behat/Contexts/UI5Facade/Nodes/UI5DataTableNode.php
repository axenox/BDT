<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use PHPUnit\Framework\Assert;

class UI5DataTableNode extends UI5AbstractNode
{
    public function getCaption(): string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);
        ;
    }

    public function capturesFocus(): bool
    {
        return false;
    }

    public function getRowNodes(): array
    {
        $columns = [];
        foreach ($this->getNodeElement()->findAll('css', '.sapUiTableTr, .sapMListTblRow') as $column) {
            $columns[] = new DataColumnNode($column);
        }
        return $columns;
    }

    public function selectRow(int $rowNumber)
    {
        $rowIndex = $this->convertOrdinalToIndex($rowNumber);

        // Find the rows
        $rows = $this->getNodeElement()->findAll('css', '.sapUiTableTr, .sapMListTblRow');
        Assert::assertNotEmpty($rows, "No rows found in table");

        if (count($rows) < $rowIndex + 1) {
            throw new \RuntimeException("Row {$rowNumber} not found. Only " . count($rows) . " rows available.");
        }

        $row = $rows[$rowIndex];

        // Selecting process
        $rowSelector = $row->find('css', '.sapUiTableRowSelectionCell');
        if ($rowSelector) {
            $rowSelector->click();
        } else {
            $firstCell = $row->find('css', 'td.sapUiTableCell, .sapMListTblCell');
            Assert::assertNotNull($firstCell, "Could not find a clickable cell in row {$rowNumber}");
            $firstCell->click();
        }
    }

    public function isRowSelected(int $rowNumber): bool
    {
        $rowIndex = $this->convertOrdinalToIndex($rowNumber);
        $tableId = $this->getNodeElement()->getAttribute('id');
        $isSelected = $this->getSession()->evaluateScript(
            "return jQuery('#{$tableId} .sapUiTableTr, #{$tableId} .sapMListTblRow').eq({$rowIndex}).hasClass('sapUiTableRowSel');"
        );
        return $isSelected;
    }


    /**
     * Converts ordinal numbers like "1." to zero-based indices
     * 
     * @param string $ordinal The ordinal number (e.g., "1.", "2.")
     * @return int Zero-based index
     */
    public function convertOrdinalToIndex($ordinal)
    {
        // Remove any trailing period and convert to integer
        $number = (int) str_replace('.', '', $ordinal);
        // Convert to zero-based index
        return $number - 1;
    }

    public function find($selector, $locator)
    {
        // Delegate the find method to the underlying node element
        $nodeElement = $this->getNodeElement();
        if ($nodeElement) {
            return $nodeElement->find($selector, $locator);
        }

        return null;
    }
    
    public function getWidget(UiPageInterface $page) : ?WidgetInterface
    {
        $innerNode = $this->find('css', '.sapUiTable');
        if ($innerNode) {
            $widgetId = $innerNode->getAttribute('id');
            return $page->getWidget($widgetId);
        }
        return null;
    }
    
    public function testWorksAsExpected(UiPageInterface $page)
    {
        $widget = $this->getWidget($page);
        // TODO
    }
}