<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\NodeElement;
use exface\Core\CommonLogic\Model\MetaObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iFilterData;
use exface\Core\Interfaces\Widgets\iHaveButtons;
use exface\Core\Interfaces\Widgets\iHaveColumns;
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Interfaces\Widgets\iSupportLazyLoading;
use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\Filter;
use exface\Core\Widgets\InputComboTable;
use exface\Core\Widgets\InputSelect;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\ExpectationFailedException;

/**
 * @method \exface\Core\Widgets\DataTable getWidget()
 */
class UI5DataTableNode extends UI5DataNode
{
    
    public function getCaption(): string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);
    }

    public function capturesFocus(): bool
    {
        return false;
    }

    public function getRowNodes(): array
    {
        $columns = [];
        foreach ($this->getNodeElement()->findAll('css', '.sapUiTableTr, .sapMListTblRow') as $column) {
            $columns[] = new DataColumnNode($column, $this->getSession(), $this->getBrowser());
        }
        return $columns;
    }

    /**
     * Returns header "column" nodes (one per visible column) in UI order.
     * 
     * @return array
     */
    public function getHeaderColumnNodes(): array
    {
        /* @var $nodes \axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5HeaderColumnNode[] */
        $nodes = [];

        // Scope: table container
        $table = $this->getNodeElement();

        // Select header cells only (exclude dummy/selection)
        $headerCells = $table->findAll(
            'css',
            '.sapUiTableColHdrCnt .sapUiTableColHdrTr td[role="columnheader"]:not(.sapUiTableCellDummy)'
        );

        // Keep natural order via data-sap-ui-colindex
        usort($headerCells, function ($a, $b) {
            $ia = (int)$a->getAttribute('data-sap-ui-colindex');
            $ib = (int)$b->getAttribute('data-sap-ui-colindex');
            return $ia <=> $ib;
        });

        foreach ($headerCells as $cell) {
            $nodes[] = new UI5HeaderColumnNode($cell, $this->getSession(), $this->getBrowser());
        }

        return $nodes;
    }

    private function getLoadedRowCount(): ?int
    {
       return count($this->getBrowser()->getTableRows($this->getNodeElement()));        
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

    public function getElementId() : string
    {
        // Detect sap.ui.table.Table
        $innerNode = $this->find('css', '.sapUiTable');
        if ($innerNode) {
            return $innerNode->getAttribute('id');
        }
        // Detect sap.m.Table
        $innerNode = $this->find('css', '.sapMTable');
        if ($innerNode) {
            return $innerNode->getAttribute('id');
        }
        throw new FacadeNodeException($this, 'Cannot get find facade element id for widget "' . $this->getWidgetType() . '"');
    }

    /**
     *
     * @param TableNode $fields
     * @param LogBookInterface $logbook
     */
    public function itWorksAsShown(TableNode $fields, LogBookInterface $logbook): void
    {
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget();
        
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');
        $expectedButtons = [];
        $expectedFilters = [];
        $expectedColumns = [];
        foreach ($fields->getHash() as $row) {
            // Find input by caption
            if(!empty($row['Filter Caption'])) {
                $expectedFilters[] = $row['Filter Caption'];
            }
            if(!empty($row['Button Caption'])) {
                $expectedButtons[] = $row['Button Caption'];
            }
            if(!empty($row['Column Caption'])) {
                $expectedColumns[] = $row['Column Caption'];
            }
        }

        if (!empty($expectedColumns)) {
            $actualColumns = array_map(
                fn($c) => trim($c->getCaption()),
                array_filter($widget->getColumns(), fn($c) => !$c->isHidden())
            );
            $expectedColumns = array_filter(array_unique($expectedColumns));
            $actualColumns = array_filter(array_unique($actualColumns));
            $missingColumns = array_diff($expectedColumns, $actualColumns);
            $extraColumns   = array_diff($actualColumns, $expectedColumns);
            Assert::assertEmpty($missingColumns, 'Missing columns: ' . implode(', ', $missingColumns));
            Assert::assertEmpty($extraColumns,   'Unexpected columns: ' . implode(', ', $extraColumns));

        }

        if (!empty($expectedFilters)) {
            $actualFilters = array_map(
                fn($f) => trim($f->getCaption()),
                array_filter($widget->getFilters(), fn($f) => !$f->isHidden())
            );
            $expectedFilters = array_filter(array_unique($expectedFilters));
            $actualFilters = array_filter(array_unique($actualFilters));
            $missingFilters = array_diff($expectedFilters, $actualFilters);
            $extraFilters   = array_diff($actualFilters, $expectedFilters);
            Assert::assertEmpty($missingFilters, 'Missing filters: ' . implode(', ', $missingFilters));
            Assert::assertEmpty($extraFilters,   'Unexpected filters: ' . implode(', ', $extraFilters));

        }

        if (!empty($actualColumns)) {
            $actualButtons = array_map(
                fn($b) => trim($b->getCaption()),
                array_filter($widget->getButtons(), fn($b) => !$b->isHidden() && !$b->isDisabled())
            );
            $expectedButtons = array_filter(array_unique($expectedButtons));
            $actualButtons = array_filter(array_unique($actualButtons));
            $missingButtons = array_diff($expectedButtons, $actualButtons);
            $extraButtons   = array_diff($actualButtons, $expectedButtons);
            Assert::assertEmpty($missingButtons, 'Missing buttons: ' . implode(', ', $missingButtons));
            Assert::assertEmpty($extraButtons,   'Unexpected buttons: ' . implode(', ', $extraButtons));
        }

        $this->checkWorksAsExpected($logbook);
    }

    
    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $parentResult = parent::checkTableWorksAsExpected($dataWidget, $logbook);
        
        $failed = false;
        $logbook->addIndent(1);

        /*
        // Test column caption filters
        foreach ($widget->getColumns() as $column) {
            if ($column->isHidden() || !$column->isFilterable()) {
                continue;
            }
            $columnNode = $this->getColumnByCaption($column->getAttribute()->getName());
            $columnAttr = $column->getAttribute();
            $filterVal = $this->getAnyValue($columnAttr);
            $this->filterColumn($columnNode->getCaption(), $filterVal);
            $this->getBrowser()->verifyTableContent($this->getNodeElement(), [
                ['column' => $columnAttr->getName(), 'value' => $filterVal, 'comparator' => ComparatorDataType::EQUALS]
            ]);
            $this->resetFilterColumn($columnNode->getCaption());
        }
        */

        $logbook->addIndent(-1);
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkFilterWorksAsExpected(iFilterData $filter, iShowData $dataWidget, SubstepResult $result) : SubstepResult
    {
        $logbook = $result->getLogbook();
        $logbook->addLine('Filtering`' . $filter->getCaption() . '`');
        
        // Find and highlight the filter
        $filterNode = $this->getBrowser()->getFilterByCaption($filter->getCaption());
        $this->getBrowser()->highlightWidget(
            $filterNode->getNodeElement(),
            $filter->getWidgetType(),
            0
        );
        
        // Get a valid value for filtering
        $filterAttr = $filter->getAttribute();
        
        
        // Look for a value it the table
        // Verify the first DataTable contains the expected text in the specified column
        // sometimes column captions are not the same as filter captions
        $columnCaption = null;
        $column = $this->findColumnWithAttribute($dataWidget, $filterAttr, $logbook);
        if ($column !== null) {
            $filterVal = $this->findValueInColumn($column, $logbook);
            $columnCaption = $column->getCaption();
        }
        
        // Look for a value in the data source
        if (trim($filterVal ?? '') === '') {
            $filterVal = $this->findValueInDataSource($filterAttr, $filter, $dataWidget->getMetaObject());
            if ($filterVal !== null) {
                $logbook->continueLine(' with value `' . $filterVal . '` found in data source');
            }
        }

        if (trim($filterVal ?? '') === '') {
            $logbook->continueLine(' no value found!');
            return SubstepResult::createSkipped('No value found for filter `' . $filter->getCaption() . '`', $logbook);
        }
        
        // Set the filter value
        try {
            $filterNode->setValueVisible($filterVal);
        } catch (FacadeNodeException|ExpectationFailedException $e) {
            $currentVal = $filterNode->getValueVisible();
            if (($filter instanceof Filter) && $filter->getInputWidget() instanceof iSupportLazyLoading) {
                if (stripos($currentVal, $filterVal) !== false) {
                    $filterVal = $currentVal;
                    $logbook->continueLine(' (changed to `' . $filterVal . '` because it was autosuggested)');
                } 
            } 
            if ($filterVal !== $currentVal) {
                throw new FacadeNodeException($this, 'Failed to set filter value for filter `' . $filter->getCaption() . '`. Tried value: `' . $filterVal . '` - got `' . $currentVal . '` when validating.', null, $e);
            }
        }
        
        $this->triggerSearch();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        $loadedRowCount = $this->getLoadedRowCount();

        $logbook->continueLine(' - found `' . $loadedRowCount . '` rows');

        // See if our 
        if ($columnCaption !== null) {
            $this->getBrowser()->verifyTableContent($this->getNodeElement(), [
                ['column' => $columnCaption, 'value' => $filterVal, 'comparator' => $filter->getComparator(), 'dataType' => $this->getInputDataType()]
            ]);
        }
        
        $filterNode->reset();
        $logbook->continueLine(' - resetting filter');
        
        $result->setTitle($result->getTitle() . ' with value "' . $filterVal . '"');
        return $result;
    }

    protected function checkTheValueFromTable(MetaObject $metaObject, string $returnColumn, string $returnValue): bool
    {
        $ds = DataSheetFactory::createFromObject($metaObject);
        $ds->getFilters()->addConditionFromString($returnColumn, $returnValue, ComparatorDataType::EQUALS);
        $ds->dataRead(1, 1);
        return $ds->dataCount() > 0;

    }

    /**
     * @param string $caption
     * @return UI5HeaderColumnNode
     */
    public function getColumnByCaption(string $caption) :UI5HeaderColumnNode
    {
        foreach ($this->getHeaderColumnNodes() as $node) {
            if (trim($node->getCaption()) === trim($caption)) {
                return $node;
            }
        }
        throw new FacadeNodeException($this, "Column '$caption' not found (visible header).");
    }

    /**
     * Filters the given caption of the column with the given value
     *
     * @param string $caption
     * @param string $value
     */
    public function filterColumn(string $caption, string $value): void
    {
        $headerNode = $this->getColumnByCaption($caption);
        $headerEl   = $headerNode->getNodeElement();
        Assert::assertNotNull($headerEl, "Header element for '$caption' not found.");

        $headerNode->clickHeader();

        // Locate menu and input
        $page  = $this->getSession()->getPage();
        $menu  = $page->find('css', '.sapUiTableColumnMenu.sapUiMnu');
        Assert::assertNotNull($menu, "Column menu did not appear for '$caption'.");
        $input = $menu->find('css', 'li.sapUiMnuTfItm input.sapUiMnuTfItemTf');
        Assert::assertNotNull($input, "Filter input not found for '$caption'.");

        // Type value and trigger UI5 filter behavior
        $inputId = $input->getAttribute('id');
        $this->getSession()->executeScript("
            (function() {
                var el = document.getElementById('$inputId');
                if (!el) return;
                el.focus();
                el.value = " . json_encode($value) . ";
                el.dispatchEvent(new Event('input', {bubbles:true}));
                el.dispatchEvent(new Event('change', {bubbles:true}));
                // Simulate Enter keydown/up before blur occurs
                var e1 = new KeyboardEvent('keydown', {key:'Enter', code:'Enter', keyCode:13, which:13, bubbles:true});
                el.dispatchEvent(e1);
                var e2 = new KeyboardEvent('keyup', {key:'Enter', code:'Enter', keyCode:13, which:13, bubbles:true});
                el.dispatchEvent(e2);
            })();
        ");

        // Let UI5 apply the filter before menu auto-closes
        $this->getSession()->wait(1000, 'true');
    }

    protected function resetFilterColumn(string $caption) :void
    {
        $this->filterColumn($caption, "");
    }
    
    protected function findValueInColumn(DataColumn $column, LogBookInterface $logbook)
    {
        $columnCaption = $column->getCaption();
        $i = $this->getVisibibleColumnIndex($column);

        $rows = $this->getBrowser()->getTableRows($this->getNodeElement());
        $cellValue = null;
        foreach ($rows as $row) {
            $cellValue = $this->getBrowser()->extractCellValueFromRow($row, $i);
            if ($cellValue !== null) {
                break;
            }
        }
        $filterVal = $cellValue;

        $this->setInputDataType($column->getDataType());
        if ($column->hasAggregator() && $column->getAggregator()->isList()) {
            $aggr = $column->getAggregator();
            $delimiter = $aggr->getArguments()[0] ?? null;
            if ($delimiter === null) {
                if ($column->isBoundToAttribute()) {
                    $delimiter = $column->getAttribute()->getValueListDelimiter();
                } else {
                    $delimiter = EXF_LIST_SEPARATOR;
                }
            }
            $filterVal = explode($delimiter, $filterVal)[0];
            $logbook->continueLine(' with value `' . $filterVal . '` found in table column `' . $columnCaption . '`');
        }
        return $filterVal;
    }

    /**
     * check if the text ends with suffix 
     * if the text ends with __LABEL first cut this part and checks the rest
     * 
     * @param string $text
     * @param string $suffix
     * @return bool
     */
    function endsWith(string $text, string $suffix): bool
    {
        if (str_contains($text, ':')) {
            $text = strstr($text, ':', true);
        }
        
        if (str_ends_with($text, '__LABEL')) {
            $text = substr($text, 0, -strlen('__LABEL'));
        }
        else if (str_ends_with(strtolower($text), '__name')) {
            $text = substr($text, 0, -strlen('__name'));
        }

        return str_ends_with($text, $suffix);
    }

    /**
     * check if the text ends with suffix 
     * if the text ends with __LABEL first cut this part and checks the rest
     * 
     * @param string $text
     * @param string $suffix
     * @return bool
     */
    function endsWith(string $text, string $suffix): bool
    {
        if (str_contains($text, ':')) {
            $text = strstr($text, ':', true);
        }
        
        if (str_ends_with($text, '__LABEL')) {
            $text = substr($text, 0, -strlen('__LABEL'));
        }
        else if (str_ends_with(strtolower($text), '__name')) {
            $text = substr($text, 0, -strlen('__name'));
        }

        return str_ends_with($text, $suffix);
    }
}