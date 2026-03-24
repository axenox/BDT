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
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Interfaces\Widgets\iSupportLazyLoading;
use exface\Core\Widgets\Filter;
use exface\Core\Widgets\InputComboTable;
use exface\Core\Widgets\InputSelect;
use PHPUnit\Framework\Assert;

class UI5DataTableNode extends UI5AbstractNode
{
    const CATEGORY_FILTERING = 'Filtering';
    const CATEGORY_BUTTONS = 'Buttons';

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

    private function findFilterHeaderContainer(): ?NodeElement
    {
        $page = $this->getSession()->getPage();
        $table = $this->getNodeElement();

        $tableId = $table->getAttribute('id');
        if (!$tableId) {
            return null;
        }

        /**
         * Approach 1: Traverse up to the nearest Dynamic Page Wrapper.
         * In modern UI5, tables and headers are usually siblings within a 'sapFDynamicPage' article.
         */
        $wrapper = $table->find('xpath', "ancestor::article[contains(@class, 'sapFDynamicPage')]");
        if ($wrapper) {
            $header = $wrapper->find('css', 'header.sapFDynamicPageTitleWrapper + div section.sapFDynamicPageHeader');
            if ($header && $this->hasFilters($header)) {
                return $header;
            }
        }

        /**
         * Approach 2: Direct lookup using the sticky placeholder ID convention.
         * tableId: {prefix}__table -> stickyId: {prefix}__table_DynamicPageWrapper-stickyPlaceholder
         */
        $stickyId = $tableId . '_DynamicPageWrapper-stickyPlaceholder';
        $headerBySticky = $page->find('css', '#' . $stickyId . ' .sapFDynamicPageHeader');
        if ($headerBySticky && $this->hasFilters($headerBySticky)) {
            return $headerBySticky;
        }

        /**
         * Approach 3: Fallback using ID prefix matching.
         * Useful when the table ID and wrapper ID share a common prefix but different suffixes.
         */
        $prefix = preg_replace('/__[^_]+$/', '', $tableId);
        if ($prefix) {
            $fallback = $page->find('css', "article[id^='$prefix'][id$='_DynamicPageWrapper'] .sapFDynamicPageHeader");
            if ($fallback && $this->hasFilters($fallback)) {
                return $fallback;
            }
        }

        return null;
    }

    /**
     * checks the Header if it has filters
     */
    private function hasFilters(NodeElement $container): bool
    {
        return $container->find('css', '.exfw-Filter, .exfw-RangeFilter') !== null;
    }

    private function hasHeader(): bool
    {
        return $this->findFilterHeaderContainer() !== null;
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

    /**
     * Delegate the find method to the underlying node element
     * 
     * @param $selector
     * @param $locator
     * @return \Behat\Mink\Element\NodeElement|false|mixed|null
     */
    public function find($selector, $locator)
    {
        $nodeElement = $this->getNodeElement();
        return $nodeElement->find($selector, $locator);
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

        $this->checkWorksAsExpected($logbook);
    }

    /**
     *
     * @param LogBookInterface $logbook
     * @return void
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        /* @var $widget \exface\Core\Widgets\DataTable */
        $widget = $this->getWidget();
        $mainObject = $widget->getMetaObject();
        if (! empty($this->getCaption())) {
            $tableMd = '`' . $this->getCaption() . '`';
            $tableCaption = $this->getCaption();
        } else {
            $tableMd = '[' . MarkdownDataType::escapeString($mainObject->__toString()) . '](' . DocsFacade::buildUrlToDocsForMetaObject($mainObject) . ')';
            $tableCaption = $mainObject->__toString();
        }

        $logbook->addLine('Looking at ' . $widget->getWidgetType() . ' ' . $tableMd);
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');
        
        $result = $this->runAsSubstep(
            function(SubstepResult $result) use ($widget) {
                return $this->checkTableWorksAsExpected($widget, $result->getLogbook());
            }, 
            'Looking at ' . $widget->getWidgetType() . ' ' . $tableCaption, 
            null, 
            $logbook
        );

        return $result;
    }
    
    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $failed = false;
        $logbook->addIndent(1);

        // Filters
        $skippedFilters = [];
        if (! $this->hasHeader()) {
            $logbook->addLine('Filtering skipped - hidden headers not yet supported');
            foreach ($dataWidget->getFilters() as $filter) {
                $skippedFilters['Hidden headers not yet supported'][] = $filter->getCaption();
            }
        } else {
            // Test regular filters
            foreach ($dataWidget->getFilters() as $filter) {
                if ($filter->isHidden()) {
                    // will be used as a filter to get a valid value
                    $this->hiddenFilters[] = $filter;
                    continue;
                }
                if (/* fiter not supported */ false) {
                    $logbook->addLine('Filtering ' . $filter->getCaption() . ' skipped');
                    $skippedFilters['Filter not supported'][] = $filter->getCaption();
                }
                $substepResult = $this->runAsSubstep(
                    function(SubstepResult $result) use ($filter, $dataWidget) {
                        return $this->checkFilterWorksAsExpected($filter, $dataWidget, $result);
                    },
                    'Filtering `' . $filter->getCaption() . '`',
                    static::CATEGORY_FILTERING,
                    $logbook
                );
                if ($substepResult->isFailed()) {
                    $failed = true;
                }
            }
        }
        
        foreach ($skippedFilters as $reason => $captions) {
            // TODO Mark skipped filters with SKIPPED result code to make visible, that something is not good
            $this->logSubstep('Skipped filters: ' . implode(', ', $captions), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_FILTERING);
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
        
        // TODO Sorters
        
        // Buttons
        if ($dataWidget instanceof iHaveButtons) {
            $skippedButtons = [];
            foreach ($dataWidget->getButtons() as $buttonWidget) {
                if ($buttonWidget->isHidden()) {
                    continue;
                }
                
                // Make sure, the button is visible
                $buttonNodeElement = $this->getBrowser()->findButtonByCaption($buttonWidget->getCaption(), $this->getNodeElement());
                if ($buttonNodeElement === null) {
                    $skippedButtons['Button not visible'][] = $buttonWidget->getCaption();
                    $logbook->addLine('Skipping button `' . $buttonWidget->getCaption() . '` because not visible in UI');
                    continue;
                }
                
                // Make sure the action has everything it needs from the data widget
                $action = $buttonWidget->getAction();
                switch (true) {
                    case $action === null:
                        $skippedButtons['Button has no action'][] = $buttonWidget->getCaption();
                        $logbook->addLine('Skipping button ' . $this->getCaption() . ' because it has no action');
                        continue 2;
                    case $action->getInputRowsMin() > 0:
                        $skippedButtons['Button requires input data'][] = $buttonWidget->getCaption();
                        $logbook->addLine('Skipping button ' . $this->getCaption() . ' because it requires ' . $action->getInputRowsMin() . ' lines of input');
                        continue 2;
                }
                
                // Press the button in a substep
                $substepResult = $this->runAsSubstep(
                    function (SubstepResult $result) use ($dataWidget, $buttonWidget, $buttonNodeElement) {
                        $buttonNode = UI5FacadeNodeFactory::createFromWidgetType($buttonWidget->getWidgetType(), $buttonNodeElement, $this->getSession(), $this->getBrowser());
                        return $buttonNode->checkWorksAsExpected($result->getLogbook());
                    },
                    'Clicking button `' . $buttonWidget->getCaption() . '`',
                    static::CATEGORY_BUTTONS,
                    $logbook
                );
                
                // Say the buttons test is failed if at least one button fails
                if ($substepResult->isFailed()) {
                    $failed = true;
                }
            }
            
            // Log a SKIPPED substep for every reason to skip buttons
            foreach ($skippedButtons as $reason => $buttons) {
                $this->logSubstep('Skipped buttons: ' . implode(', ', $buttons), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_BUTTONS);
            }
        }

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
        // Verify the first DataTable contains the expected text in the specified column
        // sometimes column captions are not the same as filter captions
        $columnCaption = null;
        $filterVal = null;
        foreach ($dataWidget->getColumns() as $i => $column) {
            if ($column->isHidden()) {
                continue;
            }
            if ($column->getAttribute()->is($filterAttr)) {
                $columnCaption = $column->getCaption();
                break;
            }
            if (str_contains($column->getAttributeAlias(), $filter->getAttributeAlias()))
            {
                $columnCaption = $column->getCaption();
                $filterVal = $this->getValueFromTable($i);
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
                }
                $this->setInputDataType($column->getDataType());
                $logbook->continueLine(' with value `' . $filterVal . '` found in table column `' . $columnCaption . '`');
                break;
            }
        }
        
        // If no filter value found yet, search the data source
        if (trim($filterVal ?? '') === '') {
            $filterVal = $this->getAnyValue($filterAttr, $filter, $dataWidget->getMetaObject());
            if ($filterVal !== null) {
                $logbook->continueLine(' with value `' . $filterVal . '` found in data source');
            }
        }

        if (trim($filterVal ?? '') === '') {
            return SubstepResult::createSkipped('No value found for filter `' . $filter->getCaption() . '`', $logbook);
            $logbook->continueLine(' no value found!');
        }
        
        // Set the filter value
        try {
            $filterNode->setValueVisible($filterVal);
        } catch (FacadeNodeException $e) {
            $currentVal = $filterNode->getValueVisible();
            if (($filter instanceof Filter) && $filter->getInputWidget() instanceof iSupportLazyLoading) {
                if (stripos($currentVal, $filterVal) !== false) {
                    $filterVal = $currentVal;
                } 
            } 
            if ($filterVal !== $currentVal) {
                throw new FacadeNodeException($this, 'Failed to set filter value for filter `' . $filter->getCaption() . '`. Tried value: `' . $filterVal . '` - got `' . $currentVal . '` when validating.', null, $e);
            }
        }
        
        
        
        $this->triggerSearch();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        $loadedRowCount = $this->getLoadedRowCount($dataWidget);

        $logbook->continueLine(' - found `' . $loadedRowCount . '` rows');

        // See if our 
        if ($columnCaption !== null) {
            $this->getBrowser()->verifyTableContent($this->getNodeElement(), [
                ['column' => $columnCaption, 'value' => $filterVal, 'comparator' => $filter->getComparator(), 'dataType' => $this->getInputDataType()]
            ]);
        }
        
        $filterNode->reset();
        $logbook->continueLine(' - resetting filter');
        
        $result->setTitle($logbook->getLineActive() ?? $result->getTitle());
        return $result;
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
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false,true,true);
    }

    public function reset(): FacadeNodeInterface
    {
        if ($this->hasHeader()) {
            $this->clickButtonByCaption('ACTION.RESETWIDGET.NAME');
        } else {
            $this->logSubstep('Skipped resetting ' . $this->getWidgetType(), StepStatusDataType::SKIPPED, 'Hidden headers not supported yet');
        }
        return $this;
    }

    protected function clickButtonByCaption(string $caption): void
    {
        $buttonCaption = $this->getBrowser()
            ->getWorkbench()
            ->getCoreApp()
            ->getTranslator($this->getBrowser()->getLocale())
            ->translate($caption);
        $button = $this->findVisibleButtonByCaption($buttonCaption, true, $this->getNodeElement());

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

    private function getValueFromTable(int $columnIndex): ?string
    {
        $rows = $this->getBrowser()->getTableRows($this->getNodeElement());
        $cellValue = null;
        foreach ($rows as $row) {
            $cellValue = $this->getBrowser()->extractCellValueFromRow($row, $columnIndex);
            if ($cellValue !== null) {
                break;
            }
        }
        return $cellValue;
    }

    public function getWidgetType() : ?string
    {
        if (null !== $thisElementClass = UI5FacadeNodeFactory::findWidgetType($this->getNodeElement())) {
            return $thisElementClass;
        }
        $panel = UI5FacadeNodeFactory::findParentWithWidgetClass($this->getNodeElement());
        if ($panel !== null) {
            return UI5FacadeNodeFactory::findWidgetType($panel);
        }
        throw new FacadeNodeException($this, 'Cannot find widget inside of DOM node "' . $this->getNodeElement()->getXpath() . '"');
    }
}