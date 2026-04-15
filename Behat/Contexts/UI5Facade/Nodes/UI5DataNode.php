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
use exface\Core\Interfaces\Widgets\iHaveFilters;
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
class UI5DataNode extends UI5AbstractNode
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

    protected function getLoadedRowCount(): ?int
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

    protected function findFilterHeaderContainer(): ?NodeElement
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
    protected function hasFilters(NodeElement $container): bool
    {
        return $container->find('css', '.exfw-Filter, .exfw-RangeFilter') !== null;
    }

    protected function hasHeader(): bool
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

    /**
     *
     * @param LogBookInterface $logbook
     * @return void
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $widget = $this->getWidget();
        $logbook->addLine($this->buildMessageLookingAt(true));
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');
        
        $result = $this->runAsSubstep(
            function(SubstepResult $result) use ($widget) {
                return $this->checkTableWorksAsExpected($widget, $result->getLogbook());
            }, 
            $this->buildMessageLookingAt(false), 
            null, 
            $logbook
        );

        return $result;
    }
    
    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $logbook->addIndent(1);

        // Filters
        $filterResult = $this->checkHeaderFiltersWorkAsExpected($dataWidget, $logbook);
        $failed = $filterResult->isFailed();
        
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
            $buttonsResult = $this->checkButtonsWorkAsExpected($dataWidget, $logbook);
            $failed = $failed === false ? $buttonsResult->isFailed() : $failed;
        }

        $logbook->addIndent(-1);
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkHeaderFiltersWorkAsExpected(iHaveFilters $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $failed = false;
        $skippedFilters = [];
        $hasHeader = $this->hasHeader();
        foreach ($dataWidget->getFilters() as $filter) {
            if ($filter->isHidden()) {
                // will be used as a filter to get a valid value
                $this->hiddenFilters[] = $filter;
                continue;
            }

            // TODO how need to test filter in the configurator dialog too!
            if (! $hasHeader) {
                $logbook->addLine('Skipping filter ' . $filter->getCaption() . ' - hidden headers not yet supported');
                $skippedFilters['Hidden headers not yet supported'][] = $filter->getCaption();
                continue;
            }

            if (/* fiter not supported */ false) {
                $logbook->addLine('Filtering ' . $filter->getCaption() . ' skipped');
                $skippedFilters['Filter not supported'][] = $filter->getCaption();
            }
            $filterNode = $this->getBrowser()->getFilterByCaption($filter->getCaption());
            $substepResult = $this->runAsSubstep(
                function (SubstepResult $result) use ($filter, $dataWidget, $filterNode) {
                    return $this->checkFilterWorksAsExpected($filter, $dataWidget, $filterNode, $result);
                },
                'Filtering `' . $filter->getCaption() . '`',
                static::CATEGORY_FILTERING,
                $logbook
            );
            $filterNode->reset();
            $this->getBrowser()->clearWidgetHighlights();
            if ($substepResult->isFailed()) {
                $failed = true;
            }
        }

        foreach ($skippedFilters as $reason => $captions) {
            // TODO Mark skipped filters with SKIPPED result code to make visible, that something is not good
            $this->logSubstep('Skipped filters: ' . implode(', ', $captions), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_FILTERING);
        }
        $this->reset();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkButtonsWorkAsExpected(iHaveButtons $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
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

            $buttonNodeElement = $this->getBrowser()->findButtonByCaption($buttonWidget->getCaption(), $this->getNodeElement());
            if ($buttonNodeElement !== null) {
                $buttonNode = UI5FacadeNodeFactory::createFromWidgetType($buttonWidget->getWidgetType(), $buttonNodeElement, $this->getSession(), $this->getBrowser());

                if (!$buttonNode->checkDisabled()) {
                    // Press the button in a substep
                    $substepResult = $this->runAsSubstep(
                        function() use ($buttonNode, $logbook) {
                            return $buttonNode->checkWorksAsExpected($logbook);
                        },
                        'Clicking ' . $buttonWidget->getCaption(),
                        'Dialogs',
                        $logbook
                    );                    

                    // Say the buttons test is failed if at least one button fails
                    if ($substepResult->isFailed()) {
                        $failed = true;
                    }
                }
                else {
                    $skippedButtons['Button cannot be enabled'][] = $buttonWidget->getCaption();
                    $logbook->addLine('Skipping button ' . $this->getCaption() . ' because there is no row to enable it');
                }
            }
        }

        // Log a SKIPPED substep for every reason to skip buttons
        foreach ($skippedButtons as $reason => $buttons) {
            $this->logSubstep('Skipped buttons: ' . implode(', ', $buttons), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_BUTTONS);
        }
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkFilterWorksAsExpected(iFilterData $filter, iShowData $dataWidget, UI5FilterNode $filterNode, SubstepResult $result) : SubstepResult
    {
        $logbook = $result->getLogbook();
        $logbook->addLine('Filtering`' . $filter->getCaption() . '`');
        
        // Find and highlight the filter
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
            $filterVal = $this->findValuesInDataSource($filterAttr, $filter, $dataWidget->getMetaObject());
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
    
    protected function findColumnWithAttribute(iHaveColumns $dataWidget, MetaAttributeInterface $attribute, LogBookInterface $logbook) : ?DataColumn
    {
        foreach ($dataWidget->getColumns() as $i => $column) {
            if ($column->isHidden()) {
                continue;
            }
            if ($column->getAttribute()->is($attribute) || $this->endsWith($column->getAttributeAlias(), $attribute->getAliasWithRelationPath())) {
                return $column;
            }
        }
        return null;
    }

    protected function findValueInColumn(DataColumn $column, LogBookInterface $logbook): ?string
    {
        return null;
    }

    protected function getVisibleColumnIndex(DataColumn $column) : ?int
    {
        $i = 0;
        foreach ($column->getdataWidget()->getColumns() as $col) {
            if ($col->isHidden()) {
                continue;
            }
            if ($column === $col) {
                return $i;
            }
            $i++;
        }
        return null;
    }

    protected function findValuesInDataSource(MetaAttributeInterface $attr, Filter $filterWidget, MetaObject $metaObject, $limit = 3, string $sort = null): array
    {
        $inputWidget = $filterWidget->getInputWidget();
        $values = [];
        $rowIndex = 0;
        if ($inputWidget instanceof InputComboTable) {
            $textAttr = $inputWidget->getTextAttribute(); // This gives us what we need to type into the filter (e.g. Name)
            $tableObj = $inputWidget->getTableObject(); // Both attributes above belong to this object, NOT the object of the filter widget
            while(count($values) < $limit && $rowIndex < 100) {
                $val = $this->findValueInDataSourceQuery($tableObj, $textAttr, $textAttr->getAlias(), $sort, $rowIndex);
                if ($val !== null && !in_array($val, $values, true)) {
                    if($this->checkTheValueFromTable($metaObject, $inputWidget->getAttributeAlias() . '__' . $textAttr->getAlias(), $val)) {
                        $values[] = $val;
                    }
                }
                $rowIndex++;
                if ($rowIndex > 100){
                    break;
                }
            }
            return $values;
        }
        
        // if it is not relation return the value that is found
        if (!$attr->isRelation()) {
            $returnColumn = $attr->getAlias();
            while(empty($values)) {
                $val = $this->findValueInDataSourceQuery($inputWidget->getMetaObject(), $attr, $returnColumn, $sort, $rowIndex);
                $datatype = $attr->getDataType();
                // if the datatype is EnumDataType return its label
                if ($datatype instanceof EnumDataTypeInterface) {
                    foreach ($datatype->getLabels() as $key => $label) {
                        if ($key === (int)$val) {
                            $foundLabel = $label;
                            break;
                        }
                    }
                }
                if ($inputWidget instanceof InputSelect) {
                    $foundLabel = ($inputWidget->getSelectableOptions())[$val];
                }
                if ($val !== null && $this->checkTheValueFromTable($metaObject, $returnColumn, $val)) {
                    $values[] = (
                        $datatype instanceof EnumDataTypeInterface
                        || $inputWidget instanceof InputSelect
                    )
                        ? $foundLabel
                        : $val;
                }
                $rowIndex++;
                if ($rowIndex > 100){
                    break;
                }
            }
            return $values;
        }
        
        // if it is a relation find the label of the found uid
        $rel = $attr->getRelation();
        $rightObj = $rel->getRightObject();
        $returnColumn = $attr->getName() . '__' . $rightObj->getLabelAttribute()->getName();
        while(empty($values))
        {
            $val =  $this->findValueInDataSourceQuery($attr->getObject(), $attr, $returnColumn , $sort, $rowIndex);
            if ($val !== null && $this->checkTheValueFromTable($metaObject, $returnColumn, $val)) {
                $values[] = $val;
            }
            $rowIndex++;
            if ($rowIndex > 100){
                break;
            }
        }
        return $values;

    }

    protected function findValueInDataSourceQuery(MetaObject $metaObject, MetaAttributeInterface $attr, string $returnColumn = null, string $sort = null, $rowIndex = 0)
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

    protected function checkTheValueFromTable(MetaObject $metaObject, string $returnColumn, string $returnValue): bool
    {
        $ds = DataSheetFactory::createFromObject($metaObject);
        foreach ($this->hiddenFilters as $hiddenFilter) {
            if ($hiddenFilter->getMetaObject()->isExactly($ds->getMetaObject())) {
                $ds->getFilters()->addConditionFromString(
                    $hiddenFilter->getAttributeAlias(),
                    $hiddenFilter->getValue(),
                    $hiddenFilter->getComparator()
                );
            }
        }
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

    protected function getInputDataType()
    {
        return $this->inputDataType;
    }

    protected function setInputDataType(DataTypeInterface $dataType): void
    {
        $this->inputDataType = $dataType;
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
    
    protected function buildMessageLookingAt(bool $markdown) : string
    {
        $widget = $this->getWidget();
        $mainObject = $widget->getMetaObject();
        if (! empty($this->getCaption())) {
            if ($markdown) {
                $msg = '`' . $this->getCaption() . '`';
            } else {
                $msg = '"' . $this->getCaption() . '"';
            } 
        } else {
            if ($markdown) {
                $msg = '[' . MarkdownDataType::escapeString($mainObject->__toString()) . '](' . DocsFacade::buildUrlToDocsForMetaObject($mainObject) . ')';
            } else {
                $msg = $mainObject->__toString();
            }
        }
        return 'Looking at ' . $widget->getWidgetType() . ' ' . $msg;
    }

    /**
     * Tries to set a filter value and retries once with a fresh data-source value
     * if UI5 rejects the first attempt (valueState=Error or validation mismatch).
     *
     * Returns the accepted value on success, or null if no value could be set.
     */
    protected function trySetFilterValue(
        UI5FilterNode $filterNode,
        iFilterData $filter,
        MetaAttributeInterface $filterAttr,
        iShowData $dataWidget,
        LogBookInterface $logbook
    ): ?string {
        $candidates = [];

        $col = $this->findColumnWithAttribute($dataWidget, $filterAttr, $logbook);
        if ($col !== null) {
            $val = $this->findValueInColumn($col, $logbook);
            if (trim($val ?? '') !== '') {
                $candidates[] = $val;
            }
        }

        if ($filter instanceof Filter) {
            $dbValues = $this->findValuesInDataSource(
                $filterAttr,
                $filter,
                $dataWidget->getMetaObject(),
                3
            );
            foreach ($dbValues as $dbVal) {
                if (!in_array($dbVal, $candidates, true)) {
                    $candidates[] = $dbVal;
                }
            }
        }

        foreach ($candidates as $i => $val) {
            try {
                $filterNode->setValueEmpty(false);
                $filterNode->setValueVisible($val);

                if ($i > 0) {
                    $logbook->continueLine(' (retry with value `' . $val . '`)');
                }
                return $val;

            } catch (\Throwable $e) {
                if ($filter->getInputWidget() instanceof iSupportLazyLoading) {
                    $currentVal = $filterNode->getValueVisible();
                    if (!empty($currentVal) && stripos($currentVal, $val) !== false) {
                        $logbook->continueLine(' (autosuggested to `' . $currentVal . '`)');
                        return $currentVal;
                    }
                }

                $logbook->continueLine(' value `' . $val . '` rejected');
                try { $filterNode->setValueEmpty(false); } catch (\Throwable $ignored) {}
            }
        }

        return null;
    }

    /**
     * Finds two distinct values from the data source to use as from/to range bounds.
     *
     * Fetches the value at rowIndex=0 as "from" and rowIndex=1 as "to".
     * If both rows return the same value, "to" is nudged one row further
     * until a different value is found or the limit is reached.
     *
     * Returns ['from' => string, 'to' => string] or null if no values found.
     *
     * @return array{from: string, to: string}|null
     */
    protected function findRangeValuesInDataSource(
        MetaAttributeInterface $attr,
        Filter $filterWidget,
        MetaObject $metaObject
    ): ?array {
        // Reuse existing single-value finder at two different row offsets
        $toVal = $this->findValuesInDataSource($attr, $filterWidget, $metaObject, 'ASC');
        if (empty($toVal)) {
            return null;
        }
        $toVal = $toVal[0];

        // Find a "to" value that is >= "from" by reading further rows
        $fromVal    = null;
        $rowIndex = 1;
        while ($rowIndex <= 100) {
            $candidate = $this->findValueInDataSourceQuery(
                $filterWidget->getInputWidget()->getMetaObject(),
                $attr,
                $attr->getAlias(),
                'DESC',
                $rowIndex - 1   // DESC so we get a value on the other end of the range
            );
            if (trim($candidate ?? '') !== '' && $candidate !== $toVal) {
                $fromVal = $candidate;
                break;
            }
            $rowIndex++;
        }

        // If we couldn't find a distinct "from", use the same value — range filter
        // with from=to still tests that the filter works (exact match range)
        $fromVal = $fromVal ?? $toVal;

        return ['from' => $fromVal, 'to' => $toVal];
    }
}