<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use Behat\Gherkin\Node\TableNode;
use exface\Core\CommonLogic\Model\MetaObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Widgets\Filter;
use exface\Core\Widgets\InputComboTable;
use exface\Core\Widgets\InputSelect;
use PHPUnit\Framework\Assert;

class UI5DataTableNode extends UI5AbstractNode
{

    /* @var $hiddenFilters \exface\Core\Widgets\Filter[] */
    private array $hiddenFilters = [];
    private DataTypeInterface $inputDataType;

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

    private function getLoadedRowCount(WidgetInterface $widget): ?int
    {
        $id = $this->getElementIdFromWidget($widget);
        $script = <<<JS
(function() {
    var table = sap.ui.getCore().byId('$id');
    if (!table) return -1;

    var model = table.getModel();
    if (!model) return -2;

    var data = model.getData();
    if (!data || !data.rows) return -3;

    return data.rows.length;
})();
JS;

        return (int)$this->getSession()->evaluateScript($script);
        
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
    public function convertOrdinalToIndex(string $ordinal): int
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
        return $nodeElement->find($selector, $locator);
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
     *
     * @param TableNode $fields
     * @param LogBookInterface $logbook
     */
    public function itWorksAsShown(TableNode $fields, LogBookInterface $logbook): void
    {
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget();
        $elementId = $this->getElementIdFromWidget($widget);
        
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

        $this->itWorksAsExpected($logbook);
    }

    /**
     *
     * @param LogBookInterface $logbook
     * @return void
     */
    public function itWorksAsExpected(LogBookInterface $logbook) :void
    {
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget();
        $mainObject = $widget->getMetaObject();
        $lineNumber = count($logbook->getLinesInSection());
        $tableCaption = !empty($this->getCaption()) ? 
            '`' .$this->getCaption() . '`' : 
            '[' . MarkdownDataType::escapeString($mainObject->__toString()) . '](' . DocsFacade::buildUrlToDocsForMetaObject($mainObject) . ')' ;

        $logbook->addLine('Looking at DataTable ' . $tableCaption);
        $logbook->addIndent(1);

        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');

        // Test regular filters
        foreach ($widget->getFilters() as $filter) {
            if ($filter->isHidden()) {
                // will be used as a filter to get a valid value
                $this->hiddenFilters[] = $filter;
                continue;
            }
            // Get a valid value for filtering
            $filterAttr = $filter->getAttribute();
            $filterVal = $this->getAnyValue($filterAttr, $filter, $mainObject);
            $filterNode = $this->getBrowser()->getFilterByCaption($filter->getCaption());
            $substepName = 'Filtering`' . $filter->getCaption() . '` with value `' . $filterVal . '`';
            $logbook->addLine($substepName);
            $lineNumber++;
            $filterNode->setValue($filterVal);
            if ($filterAttr->isRelation()) {
                $this->getSession()->wait(1000);
            }
            $this->getBrowser()->getEventDispatcher()->dispatch(new BeforeSubstep($substepName, 'Filtering'));
            $this->triggerSearch();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(false,true,true);
            $loadedRowCount = $this->getLoadedRowCount($widget);
            $this->getBrowser()->getEventDispatcher()->dispatch(new AfterSubstep($substepName, 'Filtering'));
            
            $logbook->removeLine(null, $lineNumber);
            $logbook->addLine('Filtering`' . $filter->getCaption() . '` with value `' . $filterVal . '` - found `' . $loadedRowCount . '` rows');
            
            // Verify the first DataTable contains the expected text in the specified column
            // sometimes column captions are not the same as filter captions
            $columnCaption = null;
            foreach ($widget->getColumns() as $column) {
                if ($column->isHidden() || !$column->isFilterable()) {
                    continue;
                }
                if($column->getAttribute() === $filterAttr) {
                    $columnCaption = $column->getCaption();
                    break;
                }
            }
            if ($columnCaption !== null) {
                $this->getBrowser()->verifyTableContent($this->getNodeElement(), [
                    ['column' => $columnCaption, 'value' => $filterVal, 'comparator' => $filter->getComparator(), 'dataType' => $this->getInputDataType()]
                ]);
            }
            $this->triggerReset();
            $logbook->removeLine(null, $lineNumber);
            $logbook->addLine('Filtering`' . $filter->getCaption() . '` with value `' . $filterVal . '` - found `unknown` rows - resetting configurator');
        }
        $logbook->addIndent(-1);
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

    protected function getAnyValue(MetaAttributeInterface $attr, Filter $filterWidget, MetaObject $metaObject, string $sort = null)
    {
        $inputWidget = $filterWidget->getInputWidget();
        $returnValue = null;
        $rowIndex = 0;
        if ($inputWidget instanceof InputComboTable) {
            $textAttr = $inputWidget->getTextAttribute(); // This gives us what we need to type into the filter (e.g. Name)
            $tableObj = $inputWidget->getTableObject(); // Both attributes above belong to this object, NOT the object of the filter widget
            while($returnValue === null) {
                $foundValue = $this->findValue($tableObj, $textAttr, $textAttr->getAlias(), $sort, $rowIndex);
                if ($foundValue !== null && $this->checkTheValueFromTable($metaObject, $inputWidget->getAttributeAlias() . '__' . $textAttr->getAlias(), $foundValue)) {
                    $returnValue = $foundValue;
                }
                $rowIndex++;
                if ($rowIndex > 100){
                    break;
                }
            }
            return $returnValue;
        }
        
        // if it is not relation return the value that is found
        if (!$attr->isRelation()) {
            $returnColumn = $attr->getAlias();
            while($returnValue === null) {
                $foundValue = $this->findValue($inputWidget->getMetaObject(), $attr, $returnColumn, $sort, $rowIndex);
                $datatype = $attr->getDataType();
                // if the datatype is EnumDataType return its label
                if ($datatype instanceof EnumDataTypeInterface) {
                    foreach ($datatype->getLabels() as $key => $label) {
                        if ($key === (int)$foundValue) {
                            $foundLabel = $label;
                            break;
                        }
                    }
                }
                if ($inputWidget instanceof InputSelect) {
                    $foundLabel = ($inputWidget->getSelectableOptions())[$foundValue];
                }
                if ($foundValue !== null && $this->checkTheValueFromTable($metaObject, $returnColumn, $foundValue)) {
                    $returnValue = (
                        $datatype instanceof EnumDataTypeInterface
                        || $inputWidget instanceof InputSelect
                    )
                        ? $foundLabel
                        : $foundValue;
                }
                $rowIndex++;
                if ($rowIndex > 100){
                    break;
                }
            }
            return $returnValue;
        }
        
        // if it is a relation find the label of the found uid
        $rel = $attr->getRelation();
        $rightObj = $rel->getRightObject();
        $returnColumn = $attr->getName() . '__' . $rightObj->getLabelAttribute()->getName();
        while($returnValue === null)
        {
            $foundValue =  $this->findValue($attr->getObject(), $attr, $returnColumn , $sort, $rowIndex);
            if ($foundValue !== null && $this->checkTheValueFromTable($metaObject, $returnColumn, $foundValue)) {
                $returnValue = $foundValue;
            }
            $rowIndex++;
            if ($rowIndex > 100){
                break;
            }
        }
        return $returnValue;

    }

    private function findValue(MetaObject $metaObject, MetaAttributeInterface $attr, string $returnColumn = null, string $sort = null, $rowIndex = 0)
    {
        $ds = DataSheetFactory::createFromObject($metaObject);
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
        $ds->dataRead(1, $rowIndex);
        if ($ds->getColumn($returnColumn) !== null && $ds->getColumn($returnColumn)) {
            $this->setInputDataType($ds->getColumn($returnColumn)->getDataType());
            return $ds->getColumn($returnColumn)->getValuesNormalized()[0];
        }
        $this->setInputDataType($ds->getColumn($attr->getAlias())->getDataType());
        return $ds->getColumn($attr->getAlias())->getValuesNormalized()[0];
    }

    private function checkTheValueFromTable(MetaObject $metaObject, string $returnColumn, string $returnValue): bool
    {
        $ds = DataSheetFactory::createFromObject($metaObject);
        $ds->getFilters()->addConditionFromString($returnColumn, $returnValue, ComparatorDataType::EQUALS);
        $ds->dataRead(1, 1);
        return $ds->dataCount() > 0;

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
        $button = $this->findVisibleButtonByCaption($buttonCaption, $this->getNodeElement());

        Assert::assertNotNull($button, sprintf('Button %s was not found.', $buttonCaption));
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

    private function getInputDataType()
    {
        return $this->inputDataType;
    }

    private function setInputDataType(DataTypeInterface $dataType): void
    {
        $this->inputDataType = $dataType;
    }
}