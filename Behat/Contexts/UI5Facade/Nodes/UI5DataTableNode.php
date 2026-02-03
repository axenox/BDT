<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

class UI5DataTableNode extends UI5AbstractNode
{

    /* @var $hiddenFilters \exface\Core\Widgets\Filter[] */
    private array $hiddenFilters = [];
    
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
    
    public function getWidget() : ?WidgetInterface
    {
        $innerNode = $this->find('css', '.sapUiTable');
        if ($innerNode) {
            $page = $this->getBrowser()->getPageCurrent();
            $widgetId = $innerNode->getAttribute('id');
            $widgetId = StringDataType::substringAfter( $widgetId, ltrim($page->getUid(), '0') . '__');
            return $page->getWidget($widgetId);
        }
        return null;
    }

    /**
     * @param UiPageInterface $page
     * @param TableNode $fields
     */
    public function itWorksAsShown(\Behat\Gherkin\Node\TableNode $fields)
    {
        $page = $this->getBrowser()->getPageCurrent();
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget($page);
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
        $this->itWorksAsExpected($page);
    }

    /**
     * @param UiPageInterface $page
     * @return void
     */
    public function itWorksAsExpected() :void
    {
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget();
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');

        // Test regular filters
        foreach ($widget->getFilters() as $i => $filter) {
            if ($filter->isHidden()) {
                // will be used as a filter to get a valid value
                $this->hiddenFilters[] = $filter;
                continue;
            }
            // Get a valid value for filtering
            $filterAttr = $filter->getAttribute();
            $filterVal = $this->getAnyValue($filterAttr);
            $filterNode = $this->getBrowser()->getFilterByCaption($filterAttr->getName());

            $filterNode->setValue($filterVal);
            if ($filterAttr->isRelation()) {
                $this->getSession()->wait(1000);
            }
            $this->triggerSearch();
            // Verify the first DataTable contains the expected text in the specified column
            $this->getBrowser()->verifyTableContent($this->getNodeElement(), [
                ['column' => $filterAttr->getName(), 'value' => $filterVal, 'comparator' => $filter->getComparator()]
            ]);

            $this->triggerReset();
        }
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
    }
    protected function getAnyValue(MetaAttributeInterface $attr, string $sort = null)
    {        
        // if it is not relation return the value that is found
        if (!$attr->isRelation()) {
            $returnValue =  $this->findValue($attr, $attr->getAlias(), $sort);
            $datatype = $attr->getDataType();
            // if the datatype is EnumDataType return its label
            if ($datatype instanceof EnumDataTypeInterface) {
                foreach ($datatype->getLabels() as $key => $label) {
                    if($key === $returnValue){
                        $returnValue = $label;
                        break;
                    }
                }
            }
            return $returnValue;
        }
        // if it is a relation find the label of the found uid
        $rel = $attr->getRelation();
        $rightObj = $rel->getRightObject();
        return $this->findValue($attr, $attr->getName() . '__' . $rightObj->getLabelAttribute()->getName(), $sort);
        
    }
    
    private function findValue(MetaAttributeInterface $attr, string $returnColumn = null, string $sort = null)
    {
        $ds = DataSheetFactory::createFromObject($attr->getObject());
        $ds->getColumns()->addFromAttribute($attr);
        foreach ($this->hiddenFilters as $hiddenFilter) {
            if($hiddenFilter->getMetaObject()->isExactly($ds->getMetaObject())) {
                $ds->getFilters()->addConditionFromString(
                    $hiddenFilter->getAttributeAlias(),
                    $hiddenFilter->getValue(),
                    $hiddenFilter->getComparator()
                );
            }
        }
        if ($returnColumn !== null) {
            $ds->getColumns()->addFromExpression($returnColumn);
        }
        
        if ($sort !== null) {
            $ds->getSorters()->addFromString($attr->getAlias(), $sort);
        }
        
        $ds->getFilters()->addConditionForAttributeIsNotNull($attr);
        $ds->dataRead(1, 1);
        return $ds->getRows()[0][$returnColumn ? $returnColumn : $attr->getAlias()];
    }

    protected function triggerSearch(): void
    {
        $this->clickButtonByCaption('ACTION.READDATA.SEARCH');
    }
    
    protected function triggerReset(): void
    {
        $this->clickButtonByCaption('ACTION.RESETWIDGET.NAME');
    }
    
    protected function clickButtonByCaption(string $caption): void
    {
        $buttonCaption = $this->getBrowser()
            ->getWorkbench()
            ->getCoreApp()
            ->getTranslator($this->getBrowser()->getLocale())
            ->translate($caption);
        $button = $this->find('named', ['button', $buttonCaption]);
        $this->getBrowser()->highlightWidget(
            $button,
            'Button',
            0
        );
        try {
            $button->click();
            $this->getBrowser()->clearWidgetHighlights();
        } catch (\Exception $e) {
            throw $e;
        }
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
        throw new \RuntimeException("Column '$caption' not found (visible header).");
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

    private function resetFilterColumn(string $caption) :void
    {
        $this->filterColumn($caption, "");
    }
}