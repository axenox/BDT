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
use exface\Core\CommonLogic\Model\Expression;
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

    /**
     * Runs a single "works as expected" category (filters-only or buttons-only) with the
     * exact same scaffolding checkWorksAsExpected() uses for the full run.
     *
     * WHY this helper exists: the "The filters work as expected" and "The buttons work as
     * expected" steps must behave identically to the combined "It works as expected" step -
     * same "Looking at ..." logbook header, same null-widget guard and the same runAsSubstep
     * wrapping so a failure is captured as a substep with a screenshot - but exercise only
     * one category. Centralising that boilerplate here keeps the two new steps consistent
     * with the combined one and avoids duplicating the guard/logbook/substep plumbing.
     *
     * @param callable(iShowData, LogBookInterface): TestResultInterface $categoryCheck
     *        Category-specific check to run against the resolved data widget.
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    private function checkCategoryWorksAsExpected(callable $categoryCheck, LogBookInterface $logbook) : TestResultInterface
    {
        $widget = $this->getWidget();
        $logbook->addLine($this->buildMessageLookingAt(true));
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');

        return $this->runAsSubstep(
            function (SubstepResult $result) use ($widget, $categoryCheck) {
                $lb = $result->getLogbook();
                $lb->addIndent(1);
                $categoryResult = $categoryCheck($widget, $lb);
                $lb->addIndent(-1);
                return $categoryResult->isFailed()
                    ? SubstepResult::createFailed(null, $lb)
                    : SubstepResult::createPassed($lb);
            },
            $this->buildMessageLookingAt(false),
            null,
            $logbook
        );
    }

    /**
     * Verifies that ONLY the header filters of the focused data widget work as expected.
     *
     * WHY separate from checkWorksAsExpected(): scenarios need to assert filters and buttons
     * independently - e.g. a page whose buttons are known-broken but whose filters must stay
     * green, or pinning down a filter regression without also running the slower full button
     * sweep. This is the entry point for the "The filters work as expected" step and reuses
     * checkHeaderFiltersWorkAsExpected() verbatim so both steps produce identical results.
     * A widget without filters passes trivially, mirroring how the combined check would
     * simply iterate an empty filter list.
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkFiltersWorkAsExpected(LogBookInterface $logbook) : TestResultInterface
    {
        return $this->checkCategoryWorksAsExpected(
            function (iShowData $widget, LogBookInterface $lb) {
                if (! $widget instanceof iHaveFilters) {
                    $lb->addLine('Widget has no filters to check');
                    return SubstepResult::createPassed($lb);
                }
                return $this->checkHeaderFiltersWorkAsExpected($widget, $lb);
            },
            $logbook
        );
    }

    /**
     * Verifies that ONLY the toolbar/menu buttons of the focused data widget work as expected.
     *
     * WHY separate from checkWorksAsExpected(): see checkFiltersWorkAsExpected(). This is the
     * entry point for the "The buttons work as expected" step. The "Only" suffix is required
     * because the internal per-widget worker method is already named checkButtonsWorkAsExpected()
     * (and PHP has no signature-based overloading), so the public step entry point needs a
     * distinct name. A widget that is not an iHaveButtons instance passes trivially, mirroring
     * how the combined check skips the button phase for such widgets.
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkButtonsWorkAsExpectedOnly(LogBookInterface $logbook) : TestResultInterface
    {
        return $this->checkCategoryWorksAsExpected(
            function (iShowData $widget, LogBookInterface $lb) {
                if (! $widget instanceof iHaveButtons) {
                    $lb->addLine('Widget has no buttons to check');
                    return SubstepResult::createPassed($lb);
                }
                return $this->checkButtonsWorkAsExpected($widget, $lb);
            },
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

        // The button toolbar may still be re-rendering when we get here: the filter
        // tests just above reset the data widget, which makes the table reload its
        // data and re-create the toolbar buttons. Wait for those pending operations
        // to settle before iterating the buttons, otherwise a button NodeElement
        // grabbed now can go stale a moment later and trigger a
        // "Tag matching xpath //BUTTON[@id=..] not found" error.
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);

        foreach ($dataWidget->getButtons() as $buttonWidget) {
            if ($buttonWidget->isHidden()) {
                continue;
            }

            // Resolve the button by its own widget id (stale-element resilient). Button
            // widgets that share a caption but have no rendered, visible button of their
            // own resolve to null here and are skipped, so the same physical button is
            // never tested twice.
            $buttonNode = $this->resolveButtonNode($buttonWidget);
            if ($buttonNode === null) {
                $skippedButtons['Button not visible'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button `' . $buttonWidget->getCaption() . '` because not visible in UI');
                continue;
            }

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

    /**
     * Locates the DOM node for the given button widget and wraps it in a facade node,
     * retrying if the underlying element goes stale.
     *
     * The button is located by its own widget id (via getElementIdFromWidget()), not by
     * caption. Several button widgets can share a caption - for example a visible toolbar
     * button and a button that is only bound to a double-click and therefore not rendered
     * as its own visible button. A caption lookup would resolve all of them to the same
     * physical button and test it repeatedly. Using the unique widget id, each widget
     * maps to its own element; widgets without a rendered, visible button of their own
     * simply resolve to null and are skipped by the caller.
     *
     * While iterating over the buttons of a data widget, UI5 can also re-render the toolbar
     * (for example when the table finishes a background data reload after the filter tests).
     * When that happens, a NodeElement fetched a moment earlier no longer resolves and the
     * WebDriver throws a "Tag matching xpath //BUTTON[@id=..] not found" error. Such stale
     * errors are treated as "not ready yet" and retried a few times.
     *
     * @param \exface\Core\Interfaces\WidgetInterface $buttonWidget
     * @return FacadeNodeInterface|null
     */
    protected function resolveButtonNode($buttonWidget) : ?FacadeNodeInterface
    {
        $expectedId = $this->getElementIdFromWidget($buttonWidget);
        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $buttonNodeElement = $this->getSession()->getPage()->findById($expectedId);
                if ($buttonNodeElement === null || ! $buttonNodeElement->isVisible()) {
                    return null;
                }
                $buttonNode = UI5FacadeNodeFactory::createFromWidgetType($buttonWidget->getWidgetType(), $buttonNodeElement, $this->getSession(), $this->getBrowser());
                // Touch the element once so a stale handle surfaces here (inside the
                // retry loop) rather than later in checkDisabled()/click().
                $buttonNode->checkDisabled();
                return $buttonNode;
            } catch (\Throwable $e) {
                // Only retry stale-element races - re-throw anything else immediately.
                if (! $this->isStaleElementError($e) || $attempt >= $attempts) {
                    throw $e;
                }
                // The toolbar is still re-rendering - let it settle and try again.
                $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
            }
        }
        return null;
    }

    /**
     * Returns true if the given throwable is a "stale element" error, i.e. the
     * previously located DOM node was replaced by UI5 before it could be used.
     *
     * These surface from the WebDriver as messages like
     * "Tag matching xpath //BUTTON[@id=..] not found" or "stale element reference".
     *
     * @param \Throwable $e
     * @return bool
     */
    protected function isStaleElementError(\Throwable $e) : bool
    {
        $current = $e;
        while ($current !== null) {
            $msg = $current->getMessage();
            if (stripos($msg, 'Tag matching xpath') !== false
                || stripos($msg, 'stale element') !== false
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }
        return false;
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
        // A calculated attribute has no stored literal to read (see isCalculatedAttribute): return no
        // candidates so the caller skips this filter instead of looping up to 100 empty reads and then
        // throwing on the formula value. Both the filter's own attribute and the attribute that really
        // supplies the value are checked - for an InputComboTable these are two different attributes.
        if ($this->isCalculatedAttribute($attr)
            || $this->isCalculatedAttribute($this->getFilterValueAttribute($filterWidget))
        ) {
            return [];
        }

        $inputWidget = $filterWidget->getInputWidget();
        $values = [];
        $rowIndex = 0;
        $foundLabel = null;
        if (($inputWidget instanceof InputComboTable)) {
            // This gives us what we need to type into the filter (e.g. Name) - resolved centrally so
            // this branch and the calculated-attribute guards can never pick different attributes.
            $textAttr = $this->getFilterValueAttribute($filterWidget);
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
            // isRelation() is only true when the attribute itself is a foreign key. A plain attribute
            // reached through a relation path (e.g. "Rel__Name") is NOT a relation, so it lands here and
            // must still carry its relation path, exactly like the InputComboTable branch above already does.
            $returnColumn = $attr->getAliasWithRelationPath();
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
        // Nothing readable exists for a calculated attribute: the data sheet returns its formula
        // definition, and normalizing that into the declared data type throws "Cannot convert ... to
        // a number", killing the whole filter substep before the caller can react. Bail out before
        // spending a database read on a value that cannot be used as a filter literal.
        if ($this->isCalculatedAttribute($attr)) {
            return null;
        }
        $ds = DataSheetFactory::createFromObject($metaObject);
        $ds->getColumns()->addFromAttribute($attr);
        foreach ($this->hiddenFilters as $hiddenFilter) {
            if ($hiddenFilter->getMetaObject()->isExactly($ds->getMetaObject())) {
                $hiddenFilterValue = $this->getHiddenFilterValue($hiddenFilter);
                if ($hiddenFilterValue !== null && trim($hiddenFilterValue) !== '') {
                    $ds->getFilters()->addConditionFromString(
                        $hiddenFilter->getAttributeAlias(),
                        $hiddenFilterValue,
                        $hiddenFilter->getComparator()
                    );
                }
            }
        }
        if ($returnColumn !== null) {
            $ds->getColumns()->addFromExpression($returnColumn);
        }

        if ($sort !== null) {
            // Sorters resolve strictly against the sheet object, so they need the full relation path.
            // getAlias() drops it and addFromString() then throws "no matching attribute could be found"
            // for any filter attribute that lives behind a relation (e.g. "Name" on TrasseDashboard).
            // getAliasWithRelationPath() equals getAlias() for direct attributes, so this is safe for both.
            $ds->getSorters()->addFromString($attr->getAliasWithRelationPath(), $sort);
        }

        $ds->getFilters()->addConditionForAttributeIsNotNull($attr);
        $ds->dataRead(1, $rowIndex);

        $col = ($returnColumn !== null ? $ds->getColumn($returnColumn) : null)
            // addFromAttribute() above keys the column by its relation-path alias; the fallback lookup
            // must use the same key or it returns null for relation-path attributes.
            ?? $ds->getColumn($attr->getAliasWithRelationPath());
        if ($col === null) {
            return null;
        }
        $this->setInputDataType($col->getDataType());

        // Fail soft on normalization: even a non-calculated attribute can hold a value that does not
        // parse into its declared data type. Return null (so the caller can try the next row or skip
        // the filter) instead of letting the exception escape the substep. Also guard [0]: dataRead
        // may have returned zero rows, in which case the normalized array is empty.
        try {
            return $col->getValuesNormalized()[0] ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function checkTheValueFromTable(MetaObject $metaObject, string $returnColumn, string $returnValue): bool
    {
        $ds = DataSheetFactory::createFromObject($metaObject);
        foreach ($this->hiddenFilters as $hiddenFilter) {
            if ($hiddenFilter->getMetaObject()->isExactly($ds->getMetaObject())) {
                $hiddenFilterValue = $this->getHiddenFilterValue($hiddenFilter);
                if ($hiddenFilterValue !== null && trim($hiddenFilterValue) !== '') {
                    $ds->getFilters()->addConditionFromString(
                        $hiddenFilter->getAttributeAlias(),
                        $hiddenFilterValue,
                        $hiddenFilter->getComparator()
                    );
                }
            }
        }
        $ds->getFilters()->addConditionFromString($returnColumn, $returnValue, ComparatorDataType::EQUALS);
        $ds->dataRead(1, 1);
        return $ds->dataCount() > 0;
    }

    /**
     * Reads the configured value of a hidden filter directly from the widget model, or null when that
     * value cannot be used as a filter literal.
     *
     * WHY THIS EXISTS: $this->hiddenFilters is populated only from filters whose isHidden() is true.
     * Such a filter is never rendered in the visible table header, and its DOM node id resolves to the
     * DataTableConfigurator tab id, which only exists in the DOM once the configurator dialog is opened -
     * something an automated run never does. Resolving it through the node factory therefore ALWAYS
     * failed with "Cannot find node with id ..._DataTableConfigurator_Tab_Filter", an exception that was
     * caught and swallowed on every read but still logged, producing constant log noise. Since the DOM
     * value is structurally unreachable for these filters, the effective value is taken from the widget
     * model instead.
     *
     * WHY THE CALCULATION GUARD: a hidden filter's model value is not always a stored literal. It can be
     *  - a formula (e.g. "=Now()"), or
     *  - a widget link / reference (e.g. "=TabelleAnfragen!Id"), used when this table is filtered by the
     *    selected row of another table on the same page.
     * Both start with a single "=" and are recognised by Expression::detectCalculation() (formulas via
     * detectFormula(), widget links via detectReference()). Handing such an expression to
     * addConditionFromString normalizes it against the attribute's data type and throws
     * "Cannot convert ... to a number" - the same failure family as a calculated attribute - aborting the
     * whole filter substep. Note that detectFormula() alone is NOT enough here: a widget link has no "("
     * so it is not a formula, which is exactly why "=TabelleAnfragen!Id" slipped through and blew up.
     * There is no literal to filter by in either case, so return null; both callers already treat null as
     * "do not add this hidden filter condition", which keeps the value-sourcing read and its
     * checkTheValueFromTable validation consistent (both skip the same condition).
     */
    protected function getHiddenFilterValue(Filter $hiddenFilter) : ?string
    {
        $value = $hiddenFilter->getValue();

        // Skip any non-literal value - both formulas ("=Now()") and widget links ("=OtherTable!Id").
        // detectCalculation() covers both (anything starting with a single "="), whereas detectFormula()
        // would only catch formulas and let widget-link references through.
        if (is_string($value) && Expression::detectCalculation($value)) {
            return null;
        }

        return $value;
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
        // Lower bound: smallest value in the column (ASC, first row). findValuesInDataSource also
        // confirms the value is actually filterable via checkTheValueFromTable.
        $fromVal = $this->findValuesInDataSource($attr, $filterWidget, $metaObject, 3, 'ASC');
        if (empty($fromVal)) {
            return null;
        }
        $fromVal = $fromVal[0];

        // Upper bound: largest value in the column (DESC, offset 0). Walk further rows until a value
        // distinct from the lower bound is found, so a column whose top rows share one value does not
        // collapse the range to from == to by coincidence.
        $toVal    = null;
        $rowIndex = 0;
        while ($rowIndex < 100) {
            $candidate = $this->findValueInDataSourceQuery(
                $filterWidget->getInputWidget()->getMetaObject(),
                $attr,
                // Pass the relation-path alias so the upper-bound column is added and read under the same
                // key addFromAttribute() uses; the bare alias would read back as null for relation attrs.
                $attr->getAliasWithRelationPath(),
                'DESC',
                $rowIndex
            );
            if (trim($candidate ?? '') !== '' && $candidate !== $fromVal) {
                $toVal = $candidate;
                break;
            }
            $rowIndex++;
        }

        // A column with a single distinct value still yields a valid exact-match range (from == to),
        // which exercises the filter.
        $toVal = $toVal ?? $fromVal;

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

        throw new RuntimeException('No filter found with caption `' . $filterCaption . '`');
    }

    public function getFilters(int $min = 1, int $max = null): array
    {
        $container = $this->findFilterHeaderContainer();
        $filterNodes = [];

        if ($container !== null) {
            // WHY ONE COMBINED SELECTOR INSTEAD OF ONE PASS PER CSS CLASS: findAll() returns its
            // matches in document order, but running a separate pass per class concatenated all
            // plain filters first and appended every range filter afterwards. The resulting list
            // was therefore NOT the visual order as soon as a widget mixed both filter kinds, and
            // an element carrying both classes would even have been counted twice. A single
            // combined selector yields each filter exactly once, in the order the user sees it.
            foreach ($container->findAll('css', '.exfw-Filter, .exfw-RangeFilter') as $el) {
                if (! $el->isVisible()) {
                    continue;
                }
                // Derive the widget type from the element itself rather than from the selector
                // that matched it - that is the only way a single pass can still build the
                // correct node class for each filter.
                $filterNodes[] = UI5FacadeNodeFactory::createFromNodeElement(
                    $el,
                    $this->getSession(),
                    $this->getBrowser()
                );
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
     * Returns the captions of the currently rendered filters in DOM (visual) order.
     *
     * WHY THIS BELONGS ON THE NODE AND NOT IN THE BEHAT CONTEXT: "which filters does this
     * widget render and in which order" is knowledge about the widget, not about Gherkin.
     * Keeping it here guarantees that every caller - step definitions as well as the node's
     * own check* methods - reads the filter order through the exact same traversal that
     * getFilters() uses, so a step can never drift away from what the node considers a filter.
     *
     * @return string[] Trimmed, non-empty filter captions in UI order.
     */
    public function getRenderedFilterCaptionsInOrder(): array
    {
        $captions = [];
        // min = 0: a widget legitimately may render no filters at all, and the assertions
        // below must be able to state exactly that instead of getFilters() aborting the step
        // with "too few filters found".
        foreach ($this->getFilters(0) as $filterNode) {
            $caption = trim($filterNode->getCaption());
            if ($caption !== '') {
                $captions[] = $caption;
            }
        }
        return $captions;
    }

    /**
     * Asserts that the given filters are rendered in the stated left-to-right order.
     *
     * WHY THIS EXISTS: pins the visual filter order after a personalisation or layout change,
     * which the presence-only filter check cannot detect.
     *
     * @param string[] $expectedCaptions Filter captions in the expected order.
     */
    public function assertFiltersDisplayedInOrder(array $expectedCaptions): void
    {
        $this->assertCaptionsDisplayedInOrder(
            $expectedCaptions,
            $this->getRenderedFilterCaptionsInOrder(),
            'filter'
        );
    }

    /**
     * Asserts that none of the listed filters are rendered in this widget.
     *
     * WHY THIS EXISTS: verifying that a role or personalisation actually HIDES a filter is a
     * negative expectation the positive filter check cannot express.
     *
     * @param string[] $unexpectedCaptions Filter captions expected to be absent.
     */
    public function assertFiltersNotDisplayed(array $unexpectedCaptions): void
    {
        $this->assertCaptionsNotDisplayed(
            $unexpectedCaptions,
            $this->getRenderedFilterCaptionsInOrder(),
            'filter'
        );
    }

    /**
     * Asserts that every expected caption is present in $actual and that the expected captions
     * appear in $actual in the given relative order.
     *
     * WHY RELATIVE ORDER (SUBSEQUENCE) RATHER THAN STRICT FULL-LIST EQUALITY: a widget usually
     * renders more filters/columns than a single scenario declares, so requiring the actual list
     * to equal the expected list verbatim would break the assertion whenever an unrelated column
     * is added. Checking that the listed items appear in the stated order among the rendered ones
     * keeps the assertion focused on what the author declared.
     *
     * WHY IT IS GENERIC OVER "captions": columns and filters need byte-identical semantics and
     * failure messages. One implementation prevents two copies from drifting apart.
     *
     * @param string[] $expected
     * @param string[] $actual
     * @param string   $itemLabel Singular noun used in failure messages (e.g. "column").
     */
    protected function assertCaptionsDisplayedInOrder(array $expected, array $actual, string $itemLabel): void
    {
        // Report a missing item explicitly first: it is a clearer failure than the order check
        // turning the same problem into a confusing "wrong order" message.
        foreach ($expected as $item) {
            Assert::assertContains(
                $item,
                $actual,
                sprintf(
                    '%s "%s" is not displayed. Displayed %ss: %s',
                    ucfirst($itemLabel),
                    $item,
                    $itemLabel,
                    implode(', ', $actual)
                )
            );
        }

        // Walk the actual list once, advancing through the expected list whenever the next
        // expected item is met. Consuming all expected items means their relative order holds.
        $cursor = 0;
        foreach ($actual as $actualItem) {
            if ($cursor < count($expected) && $actualItem === $expected[$cursor]) {
                $cursor++;
            }
        }

        Assert::assertSame(
            count($expected),
            $cursor,
            sprintf(
                'The %ss are not displayed in the expected order. Expected order: %s. Actual order: %s',
                $itemLabel,
                implode(', ', $expected),
                implode(', ', $actual)
            )
        );
    }

    /**
     * Asserts that none of the listed captions occur in $actual.
     *
     * WHY IT SITS NEXT TO assertCaptionsDisplayedInOrder(): both the column and the filter
     * absence assertions need the identical message format, so they share one implementation
     * for the same reason the order check does.
     *
     * @param string[] $unexpected
     * @param string[] $actual
     * @param string   $itemLabel Singular noun used in failure messages (e.g. "filter").
     */
    protected function assertCaptionsNotDisplayed(array $unexpected, array $actual, string $itemLabel): void
    {
        foreach ($unexpected as $item) {
            Assert::assertNotContains(
                $item,
                $actual,
                sprintf(
                    '%s "%s" is displayed but was expected to be absent. Displayed %ss: %s',
                    ucfirst($itemLabel),
                    $item,
                    $itemLabel,
                    implode(', ', $actual)
                )
            );
        }
    }

    /**
     * Returns the attribute that actually supplies the literal value this filter is driven by.
     *
     * WHY THIS EXISTS: the attribute of a Filter widget is not always the attribute whose value ends
     * up in the input. For an InputComboTable the filter itself is bound to the relation (a UID/foreign
     * key with a plain data address), while what is typed - and what the table cell shows - is the TEXT
     * attribute configured via `text_attribute_alias`, which is typically a label assembled by a formula
     * or an SQL concatenation. Any decision about "is there a stored literal to filter by?" must
     * therefore be taken on this attribute; taking it on the filter attribute lets every combo filter
     * through unnoticed. findValuesInDataSource() already resolved exactly this attribute inline, so
     * keeping the resolution in one place stops the skip decision and the value sourcing from
     * disagreeing about which attribute they are talking about.
     */
    protected function getFilterValueAttribute(iFilterData $filter) : MetaAttributeInterface
    {
        // Only a Filter widget exposes an input widget - other iFilterData implementations are
        // filtered by their own attribute.
        if ($filter instanceof Filter) {
            $inputWidget = $filter->getInputWidget();
            if ($inputWidget instanceof InputComboTable) {
                return $inputWidget->getTextAttribute();
            }
        }
        return $filter->getAttribute();
    }

    /**
     * Detects whether an attribute's value is produced by a calculation instead of being stored
     * literally in the data source - either by an ExFace formula or by an SQL expression sitting
     * in the data address.
     *
     * WHY THIS EXISTS: the works-as-expected filter routine sources a filter test value by reading a
     * real value for the attribute from the data source, and later verifies the table cells against
     * that literal. A calculated attribute has no such stored value - the data sheet either hands back
     * the attribute's own formula definition (e.g. "=TabelleAnfragen!Id") as the "value", which then
     * throws "Cannot convert ... to a number" while being normalized into the declared data type, or
     * the cell is assembled by the database at read time (e.g. a concatenated label) so it can never
     * equal the single literal we filtered by. Both cases must be recognised up front and skipped,
     * otherwise the substep fails for a widget that actually works.
     *
     * WHY THE HEURISTICS BELOW: a data address is either a plain column name or an expression. The
     * bracket-pair rule is the very same criterion the core SQL query builders use to decide whether
     * a data address is passed through as SQL, so it covers CONCAT()/COALESCE()/CASE and sub-selects.
     * The operator rule catches concatenations written without a function call, which the bracket rule
     * alone would miss.
     */
    protected function isCalculatedAttribute(MetaAttributeInterface $attr) : bool
    {
        $dataAddress = $attr->getDataAddress();
        if (! is_string($dataAddress)) {
            return false;
        }
        $dataAddress = trim($dataAddress);
        if ($dataAddress === '') {
            return false;
        }

        // ExFace formula in the data address, e.g. "=Concatenate(FIRST_NAME, ' ', LAST_NAME)".
        if (Expression::detectFormula($dataAddress)) {
            return true;
        }

        // SQL statement instead of a plain column: function calls like CONCAT(...)/COALESCE(...),
        // CASE expressions and sub-selects all carry a bracket pair, while a column name never does.
        if (mb_strpos($dataAddress, '(') !== false && mb_strpos($dataAddress, ')') !== false) {
            return true;
        }

        // Concatenation without a function call: "FIRST_NAME + ' ' + LAST_NAME" (MS SQL) or
        // "FIRST_NAME || ' ' || LAST_NAME" (MySQL/Oracle). Neither a string literal quote nor these
        // operators can occur inside a plain - even quoted or schema-qualified - column name, so their
        // presence always means the value is computed.
        if (preg_match("/(\\|\\||\\+|')/", $dataAddress) === 1) {
            return true;
        }

        return false;
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