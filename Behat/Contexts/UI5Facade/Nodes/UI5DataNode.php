<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\Elements\DateParsingTrait;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\CommonLogic\Model\MetaObject;
use exface\Core\CommonLogic\Model\RelationPath;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
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
use exface\Core\Exceptions\RuntimeException;

/**
 * @method \exface\Core\Widgets\DataTable getWidget()
 */
class UI5DataNode extends UI5AbstractNode
{

    use DateParsingTrait;
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
     * @return TestResultInterface
     */
    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        $widget = $this->getWidget();
        $logbook->addLine($this->buildMessageLookingAt(true));
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');
        
        return $this->runAsSubstep(
            function(SubstepResult $result) use ($widget) {
                return $this->checkTableWorksAsExpected($widget, $result->getLogbook());
            }, 
            $this->buildMessageLookingAt(false), 
            null, 
            $logbook
        );
    }
    
    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $logbook->addIndent(1);

        // Filters
        $filterResult = $this->checkHeaderFiltersWorkAsExpected($dataWidget, $logbook);
        $failed = $filterResult->isFailed();
        
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
            $filterNode = $this->findFilterByCaption($filter->getCaption());
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
        $failed = false;
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

        // Log a SKIPPED substep for every reason to skip buttons
        foreach ($skippedButtons as $reason => $buttons) {
            $this->logSubstep('Skipped buttons: ' . implode(', ', $buttons), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_BUTTONS);
        }
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }
    
    protected function checkFilterWorksAsExpected(iFilterData $filter, iShowData $dataWidget, UI5FilterNode $filterNode, SubstepResult $result) : SubstepResult
    {
        $logbook = $result->getLogbook();
        return SubstepResult::createSkipped('No function defined for this widget `' . $this->getWidgetType() . '`', $logbook);
    }
    
    protected function findColumnWithAttribute(iHaveColumns $dataWidget, MetaAttributeInterface $attribute, LogBookInterface $logbook) : ?DataColumn
    {
        foreach ($dataWidget->getColumns() as $i => $column) {
            switch (true) {
                case $column->isHidden():
                    continue 2;
                case $column->getAttribute()->is($attribute):
                // TODO replace endsWith() with proper detection of LABELs
                case  $this->endsWith($column->getAttributeAlias(), $attribute->getAliasWithRelationPath()):
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
        if (($inputWidget instanceof InputComboTable)) {
            $textAttr = $inputWidget->getTextAttribute(); // This gives us what we need to type into the filter (e.g. Name)
            if ($inputWidget->isRelation()) {
                $textAttrAliasFromFilter = RelationPath::join($inputWidget->getAttributeAlias(), $textAttr->getAliasWithRelationPath());
            } else {
                $textAttrAliasFromFilter = $textAttr->getAliasWithRelationPath();
            }
            $comboTableObj = $inputWidget->getTableObject(); // Both attributes above belong to this object, NOT the object of the filter widget
            while(count($values) < $limit && $rowIndex < 100) {
                $val = $this->findValueInDataSourceQuery($comboTableObj, $textAttr, $textAttr->getAliasWithRelationPath(), $sort, $rowIndex);
                if ($val !== null && !in_array($val, $values, true)) {
                    if($this->checkTheValueFromTable($metaObject, $textAttrAliasFromFilter, $val)) {
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
        $returnColumn = RelationPath::join($attr->getName(),  $rightObj->getLabelAttributeAlias());
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
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    protected function getInputDataType(): DataTypeInterface
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

    public function findFilterByCaption(string $filterCaption): UI5FilterNode
    {
        $filterNodes = $this->getFilters();
        foreach ($filterNodes as $filterNode) {
            if ($filterNode->getCaption() !== $filterCaption) {
                continue;
            }
            
            return $filterNode;
        }

        throw new \RuntimeException('No filter found with caption "' . $filterCaption . '"');
    }

    public function getFilters(int $min = 1, int $max = null): array
    {
        $container = $this->findFilterHeaderContainer();
        $filterNodes = [];

        if ($container !== null) {
            foreach (['.exfw-Filter', '.exfw-RangeFilter'] as $cssClass) {
                // Extract the widget type name from the CSS class (e.g. 'exfw-Filter' -> 'Filter')
                $widgetType = substr($cssClass, strlen('.exfw-'));
                foreach ($container->findAll('css', $cssClass) as $el) {
                    if ($el->isVisible()) {
                        $filterNodes[] = UI5FacadeNodeFactory::createFromWidgetType(
                            $widgetType,
                            $el,
                            $this->getSession(),
                            $this->getBrowser()
                        );
                    }
                }
            }
        }

        switch (true) {
            case count($filterNodes) < $min:
                throw new RuntimeException("Too few filters found: expecting {$min} but found " . count($filterNodes));
            case $max !== null && count($filterNodes) > $max:
                throw new RuntimeException("Too many filters found: expecting {$max} but found " . count($filterNodes));
        }

        return $filterNodes;
    }


    /**
     * Parses German (1.234,56) or Anglo-Saxon (1,234.56) number strings to float.
     * Returns null if unparseable.
     */
    public function parseNumberFlexible(string $value): ?float
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        // German: dot = thousands, comma = decimal
        if (preg_match('/^\d{1,3}(\.\d{3})*(,\d+)?$/', $value)) {
            return (float) str_replace(['.', ','], ['', '.'], $value);
        }
        // Anglo-Saxon: comma = thousands, dot = decimal
        if (preg_match('/^\d{1,3}(,\d{3})*(\.\d+)?$/', $value)) {
            return (float) str_replace(',', '', $value);
        }
        // Plain number: "42", "3.14", "-7,5"
        $plain = str_replace(',', '.', $value);
        return is_numeric($plain) ? (float) $plain : null;
    }

    public function normalizeBool(?string $value): ?bool
    {
        $v = mb_strtolower(trim((string)$value));

        if (in_array($v, ['1', 'true', 'ja', 'yes', 'evet'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'nein', 'no', 'hayır', ''], true)) {
            return false;
        }

        return null;
    }

    public function normalizeText(?string $s): string
    {
        $s = (string)$s;
        $s = trim($s);
        $s = preg_replace('/\s+/u', ' ', $s);
        return mb_strtolower($s);
    }

}