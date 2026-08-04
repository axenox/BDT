<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\NodeElement;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\NumberEnumDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\SelectorFactory;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Widgets\iFilterData;
use exface\Core\Interfaces\Widgets\iHaveButtons;
use exface\Core\Interfaces\Widgets\iHaveColumns;
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Widgets\DataColumn;
use PHPUnit\Framework\Assert;

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
        return true;
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

    /**
     * Ensures the row-selection precondition of the given action is satisfied before
     * the action is triggered.
     *
     * Why this exists:
     * Actions bound to table rows (getInputRowsMin() > 0) fail with a "please select a
     * row" error unless a row is selected first. Centralizing this here - instead of
     * reacting to the error at each call site - lets every caller (toolbar buttons,
     * menu-button entries, ...) satisfy the precondition deterministically from the
     * action model. Re-selecting an already selected row is avoided, because clicking a
     * selected row selector toggles it back off.
     *
     * @param ActionInterface $action
     * @return bool True if the precondition is satisfied (or not required); false if a
     *              row is required but the table has no rows to select.
     */
    public function ensureRowSelectedForAction(ActionInterface $action): bool
    {
        if ($action->getInputRowsMin() < 1) {
            return true;
        }
        if ($this->getLoadedRowCount() < 1) {
            return false;
        }
        if (! $this->isRowSelected(1)) {
            $this->selectRow(1);
        }
        return true;
    }

    protected function getLoadedRowCount(): ?int
    {
        return count($this->getTableRows());
    }

    public function selectRow(int $rowNumber)
    {
        $rowIndex = $this->convertOrdinalToIndex($rowNumber);

        // Find the rows
        $rows = $this->getNodeElement()->findAll('css', '.sapUiTableTr, .sapMListTblRow');
        Assert::assertNotEmpty($rows, "No rows found in table");

        if (count($rows) < $rowIndex + 1) {
            throw new RuntimeException("Row {$rowNumber} not found. Only " . count($rows) . " rows available.");
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
     * Walks the rows of the first page, selecting one at a time, until the given
     * predicate is satisfied.
     *
     * Why this exists:
     * Some actions are enabled only for specific rows, so callers must try rows until
     * the target becomes actionable. Centralizing the row iteration here keeps the
     * selection mechanics (toggle-off the previous row, select exactly one) in the
     * DataTable, while the caller decides - via the predicate - what "actionable"
     * means (e.g. a toolbar button becoming enabled, or a menu entry losing its
     * aria-disabled state). Exactly one row is kept selected at a time, because
     * clicking a selected row selector toggles it back off.
     *
     * @param callable $predicate Called after each row is selected; receives the
     *                            1-based row number and returns true to stop.
     * @return bool True if the predicate was satisfied on some row; false if no row
     *              on the first page satisfied it (or the table is empty).
     */
    public function selectEachRowUntil(callable $predicate): bool
    {
        $count = $this->getLoadedRowCount();
        if ($count < 1) {
            return false;
        }
        $previous = null;
        for ($rowNumber = 1; $rowNumber <= $count; $rowNumber++) {
            if ($previous !== null && $this->isRowSelected($previous)) {
                $this->selectRow($previous);
            }
            if (! $this->isRowSelected($rowNumber)) {
                $this->selectRow($rowNumber);
            }
            $previous = $rowNumber;
            if ($predicate($rowNumber) === true) {
                return true;
            }
        }
        return false;
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
    public function itWorksAsShown(TableNode $fields, LogBookInterface $logbook): TestResultInterface
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

        if (!empty($expectedButtons)) {
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

        return $this->checkWorksAsExpected($logbook);
    }


    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $parentResult = parent::checkTableWorksAsExpected($dataWidget, $logbook);

        /*
        $logbook->addIndent(1);

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

        $logbook->addIndent(-1);
        */
        return $parentResult->isFailed() ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
    }

    protected function checkFilterWorksAsExpected(iFilterData $filter, iShowData $dataWidget, UI5FilterNode $filterNode, SubstepResult $result) : SubstepResult
    {
        $logbook = $result->getLogbook();
        $logbook->addLine('Filtering `' . $filter->getCaption() . '`');

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
            $columnCaption = $column->getCaption();

            // Columns defined in the page with visibility "optional" (or "hidden") are
            // rendered by the UI5 facade with `visible: false` (see UI5DataConfigurator),
            // so their header never appears in the DOM. verifyTableContent() could not find
            // such a column and would fail with "Column '...' not found in table". Since the
            // column is intentionally not shown, we skip the content verification for this
            // filter instead of failing the step.
            if ($column->isHidden() || $column->getVisibility() === EXF_WIDGET_VISIBILITY_OPTIONAL) {
                $logbook->continueLine(' - column `' . $columnCaption . '` is optional/hidden, skipping content verification');
                return SubstepResult::createSkipped(
                    'Column `' . $columnCaption . '` for filter `' . $filter->getCaption() . '` is optional/hidden and is not rendered in the table',
                    $logbook
                );
            }
            // A calculated/formula column has no stored literal value: its cells are derived at
            // render time (e.g. a concatenated label). The value we sourced comes from the filter's
            // attribute and never equals such a derived cell, so verifying cell-by-cell reports 0/N
            // matched even though the filter works and rows were returned. We detect a calculated
            // attribute the same way the framework already detects formula values: a calculated
            // attribute carries its formula in its data address (Expression::detectFormula). This
            // mirrors the formula-VALUE skip above — there is no reliable literal to assert against.
            $columnAttr        = ($column !== null && $column->isBoundToAttribute()) ? $column->getAttribute() : null;
            $columnDataAddress = $columnAttr !== null ? $columnAttr->getDataAddress() : null;
            if (is_string($columnDataAddress) && Expression::detectFormula($columnDataAddress)) {
                $logbook->continueLine(' - skipped verification: column `' . $columnCaption . '` is a calculated attribute');
                $result->setTitle($result->getTitle() . ' (column not verified: calculated attribute)');
                return SubstepResult::createSkipped(
                    'Column `' . $columnCaption . '` for filter `' . $filter->getCaption()
                    . '` is a calculated attribute; the filter was applied but its column cannot be verified by a literal value',
                    $logbook
                );
            }
        }

        if ($filterNode instanceof UI5RangeFilterNode) {
            $range = $this->findRangeValuesInDataSource($filterAttr, $filter, $dataWidget->getMetaObject());

            if ($columnCaption === null) {
                $logbook->continueLine(' no column found!');
                return SubstepResult::createSkipped(
                    'No column found for range filter `' . $filter->getCaption() . '`',
                    $logbook
                );
            }
            
            if ($range === null) {
                $logbook->continueLine(' no value found!');
                return SubstepResult::createSkipped(
                    'No value found for range filter `' . $filter->getCaption() . '`',
                    $logbook
                );
            }

            $logbook->continueLine(' with range `' . $range['from'] . '` – `' . $range['to'] . '`');
            $filterNode->setRangeVisible($range['from'], $range['to']);

            $this->triggerSearch();
            $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
            $loadedRowCount = $this->getLoadedRowCount();
            $logbook->continueLine(' - found `' . $loadedRowCount . '` rows');

            $result->setTitle($result->getTitle() . ' with range "' . $range['from'] . '" – "' . $range['to'] . '"');
            $this->verifyTableContent([
                ['column' => $columnCaption, 'value' => $range['from'], 'comparator' => '>=', 'dataType' => $this->getInputDataType()]
            ]);
            $this->verifyTableContent([
                ['column' => $columnCaption, 'value' => $range['to'], 'comparator' => '<=', 'dataType' => $this->getInputDataType()]
            ]);            
            
            return $result;
        }

        $filterVal = null;
        if ($column !== null) {
            $filterVal = $this->trySetFilterValue($filterNode, $filter, $filterAttr, $dataWidget, $logbook);
            if ($filterVal !== null) {
                $logbook->continueLine(' with value `' . $filterVal . '` found in data source');
            }
        }

        // Skip filters whose extracted test value is an unevaluated formula (e.g. "=TabelleAnfragen!Id").
        // Such values come from calculated attributes that have no concrete row value, so the data source
        // yields the attribute's formula definition instead of a literal. Pushing that formula into a
        // numeric filter makes the core value parser throw "Cannot convert ... to a number", which BDT
        // then reports as a filter failure even though the widget itself is fine. There is no reliable
        // literal to filter a calculated attribute by, so the correct outcome is to skip this filter
        // rather than fail it. (parseArgument only resolves "[#...#]" placeholders, not a bare "=" formula,
        // so an unwrapped formula value would otherwise reach the filter unresolved.)
        if (is_string($filterVal) && Expression::detectFormula($filterVal)) {
            $logbook->continueLine(' skipped: filter value is a formula `' . $filterVal . '` (calculated attribute, no literal value to filter by)');
            return SubstepResult::createSkipped(
                'Filter `' . $filter->getCaption() . '` has a formula value `' . $filterVal . '` and cannot be filtered by a literal',
                $logbook
            );
        }
        
        if (trim($filterVal ?? '') === '') {
            $logbook->continueLine(' no value found!');
            return SubstepResult::createSkipped('No value found for filter `' . $filter->getCaption() . '`', $logbook);
        }

        $this->triggerSearch();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        $loadedRowCount = $this->getLoadedRowCount();

        $logbook->continueLine(' - found `' . $loadedRowCount . '` rows');


        // See if our 
        if ($columnCaption === null) {
            $logbook->continueLine(' - No column found');
            return SubstepResult::createSkipped('No column found for filter `' . $filter->getCaption() . '`', $logbook);
        }

        $this->verifyTableContent([
            ['column' => $columnCaption, 'value' => $filterVal, 'comparator' => $filter->getComparator(), 'dataType' => $this->getInputDataType()]
        ]);

        $logbook->continueLine(' - resetting filter');

        $result->setTitle($result->getTitle() . ' with value "' . $filterVal . '"');
        return $result;
    }

    /**
     * {@inheritDoc}
     *
     * Refines the generic, model-only matching of the parent (see
     * UI5DataNode::findColumnWithAttribute) using the actually rendered table headers.
     *
     * The parent returns the FIRST column whose attribute matches the filter attribute - either
     * exactly, or via the LABEL/relation-path heuristic (endsWith). When several columns can match
     * the same filter attribute - e.g. a foreign-key column plus the related LABEL column, or two
     * columns showing the same relation under different captions - that first match can be a column
     * that is not rendered as a header in the DOM, even though a matching, rendered column exists.
     * The caption of the non-rendered column is then handed to verifyTableContent(), which fails
     * with "Column '...' not found in table" although the filter itself worked. This is exactly the
     * "the code thinks it found the column, but the column is not actually in the table" problem.
     *
     * This override collects every model candidate and returns the best one, preferring in order:
     *   1. an exact attribute match whose caption is actually rendered as a header,
     *   2. a fuzzy (LABEL/relation) match whose caption is rendered,
     *   3. an exact match (even if not rendered),
     *   4. a fuzzy match (even if not rendered).
     * So the returned column is the one the content verification can locate whenever such a column
     * exists, while the previous behaviour is preserved as the fallback when nothing is rendered.
     *
     * @see UI5DataNode::findColumnWithAttribute()
     *
     * @param iHaveColumns $dataWidget
     * @param MetaAttributeInterface $attribute
     * @param LogBookInterface $logbook
     * @return DataColumn|null
     */
    protected function findColumnWithAttribute(iHaveColumns $dataWidget, MetaAttributeInterface $attribute, LogBookInterface $logbook) : ?DataColumn
    {
        $exactMatch = null;
        $exactRendered = null;
        $fuzzyMatch = null;
        $fuzzyRendered = null;

        foreach ($dataWidget->getColumns() as $column) {
            // Hidden and non-attribute columns can never be verified against a filter value.
            if ($column->isHidden() || ! $column->isBoundToAttribute()) {
                continue;
            }

            $rendered = $this->isColumnHeaderRendered($column->getCaption());
            switch (true) {
                // Exact attribute match points at the column that literally shows this filter's attribute.
                case $column->getAttribute()->is($attribute):
                    $exactMatch = $exactMatch ?? $column;
                    if ($rendered && $exactRendered === null) {
                        $exactRendered = $column;
                    }
                    break;
                // Fuzzy LABEL/relation match is only a fallback (e.g. filter on a foreign key while the
                // table shows the related LABEL).
                // TODO replace endsWith() with proper detection of LABELs
                case $this->endsWith($column->getAttributeAlias(), $attribute->getAliasWithRelationPath()):
                    $fuzzyMatch = $fuzzyMatch ?? $column;
                    if ($rendered && $fuzzyRendered === null) {
                        $fuzzyRendered = $column;
                    }
                    break;
            }
        }

        return $exactRendered ?? $fuzzyRendered ?? $exactMatch ?? $fuzzyMatch;
    }

    /**
     * Tells whether a column with the given caption is actually rendered as a header in the table
     * DOM, covering both sap.ui.table (frozen/scroll split) and sap.m.Table layouts.
     *
     * Used by findColumnWithAttribute() to prefer a column the content verification can actually
     * locate: a column can be present in the widget model yet never appear as a visible header
     * (e.g. two model columns bound to the same relation, only one of which is rendered).
     *
     * @param string $caption
     * @return bool
     */
    protected function isColumnHeaderRendered(string $caption) : bool
    {
        $caption = trim($caption);

        // sap.ui.table headers carry the caption in a <label> inside the header cell.
        $headerCells = $this->getNodeElement()->findAll(
            'css',
            '.sapUiTableColHdrCnt .sapUiTableHeaderDataCell[data-sap-ui-colid]:not(.sapUiTableCellDummy)'
        );
        foreach ($headerCells as $cell) {
            $label = $cell->find('css', 'label') ?? $cell;
            if (trim($label->getText()) === $caption) {
                return true;
            }
        }

        // sap.m.Table headers (no data-sap-ui-colid).
        foreach ($this->getNodeElement()->findAll('css', '.sapMListTblHeader .sapMColumnHeader') as $header) {
            if (trim($header->getText()) === $caption) {
                return true;
            }
        }

        return false;
    }

    protected function checkButtonsWorkAsExpected(iHaveButtons $dataWidget, LogBookInterface $logbook) : TestResultInterface
    {
        $skippedButtons = [];
        $failed = false;

        // The toolbar may still be re-rendering when we get here, because the filter
        // tests just above reset the data widget and made the table reload its data.
        // Wait for those pending operations to settle before touching the buttons,
        // otherwise a button element grabbed now goes stale a moment later and
        // triggers a "Tag matching xpath //BUTTON[@id=..] not found" error.
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);

        $rowNumber = null;
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

            // Make sure the action has everything it needs from the data widget
            $action = $buttonWidget->getAction();
            // A MenuButton exposes no action of its own - its menu entries carry the
            // actions. Route it to its node (UI5MenuButtonNode) so every entry is
            // validated, instead of skipping it below as "Button has no action".
            if ($action === null && $buttonWidget instanceof iHaveButtons) {
                $menuNode = $buttonNode;
                $menuResult = $this->runAsSubstep(
                    function() use ($menuNode, $logbook) {
                        return $menuNode->checkWorksAsExpected($logbook);
                    },
                    'Checking menu "' . $buttonWidget->getCaption() . '"',
                    'Dialogs',
                    $logbook
                );
                if ($menuResult->isFailed()) {
                    $failed = true;
                }
                continue;
            }
            
            $rowNumber = 1;
            switch (true) {
                case $action === null:
                    $skippedButtons['Button has no action'][] = $buttonWidget->getCaption();
                    $logbook->addLine('Skipping button ' . $this->getCaption() . ' because it has no action');
                    continue 2;
                case $action->getInputRowsMin() > 0:
                    $this->ensureRowSelectedForAction($action);
                    break;
                default:
                    continue 2;
            }

            // Selecting a row changes the ~input data behind the toolbar, which can
            // re-evaluate the button's hidden_if/disabled_if and re-render (or remove)
            // the button. Any button element fetched before the selection is now stale,
            // so always re-resolve the button node after changing the row selection.
            $buttonNode = $this->resolveButtonNode($buttonWidget);
            if ($buttonNode === null) {
                $skippedButtons['Button not visible'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button `' . $buttonWidget->getCaption() . '` because it is no longer shown after selecting a row');
                continue;
            }

            $this->selectEachRowUntil(fn() => ! $buttonNode->checkDisabled());
            $buttonNode = $this->resolveButtonNode($buttonWidget);
            if ($buttonNode === null) {
                $skippedButtons['Button not visible'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button `' . $buttonWidget->getCaption() . '` because it is no longer shown after selecting rows');
                continue;
            }
            $urlBeforeClick = $this->getSession()->getCurrentUrl();
            if (!$buttonNode->checkDisabled()) {
                // Press the button in a substep
                $substepResult = $this->runAsSubstep(
                    function() use ($buttonNode, $logbook) {
                        return $buttonNode->checkWorksAsExpected($logbook);
                    },
                    'Clicking "' . $buttonWidget->getCaption() . '"',
                    'Dialogs',
                    $logbook,
                    function() use ($urlBeforeClick) {
                        // If the dialog caused a full-page navigation (large dialogs rendered as
                        // separate pages), go back. If only a popup error appeared without navigation
                        // (URL unchanged), dismissErrorDialogIfPresent() in runAsSubstep's catch
                        // block already handled it — navigating back here would be wrong.
                        $urlAfterError = $this->getSession()->getCurrentUrl();
                        if ($urlAfterError !== $urlBeforeClick) {
                            $this->getBrowser()->navigateToPreviousPage();
                        }
                    }
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
        if($rowNumber !== null) {
            $this->selectRow($rowNumber);            
        }

        // Log a SKIPPED substep for every reason to skip buttons
        foreach ($skippedButtons as $reason => $buttons) {
            $this->logSubstep('Skipped buttons: ' . implode(', ', $buttons), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_BUTTONS);
        }
        return $failed ? SubstepResult::createFailed(null, $logbook) : SubstepResult::createPassed($logbook);
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

    protected function findValueInColumn(DataColumn $column, LogBookInterface $logbook): ?string
    {
        $columnCaption = $column->getCaption();
        $i = $this->getVisibleColumnIndex($column);

        // Resolve the DOM column id so that extractCellValueFromRow can cross the
        // fixed/scroll table boundary that UI5 creates for frozen columns.
        $colId = null;
        $headerCells = $this->getNodeElement()->findAll(
            'css',
            '.sapUiTableColHdrCnt .sapUiTableHeaderDataCell[data-sap-ui-colid]:not(.sapUiTableCellDummy)'
        );
        foreach ($headerCells as $cell) {
            $label = $cell->find('css', 'label') ?? $cell;
            if (trim($label->getText()) === $columnCaption) {
                $colId = $cell->getAttribute('data-sap-ui-colid');
                break;
            }
        }

        $rows = $this->getTableRows();
        $cellValue = null;
        foreach ($rows as $row) {
            $cellValue = $this->extractCellValueFromRow($row, $i, $colId);
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
     * Returns the data rows of the table, without duplicates.
     *
     * When a sapUiTable has frozen columns, UI5 renders two separate <table> elements:
     *   - table.sapUiTableCtrlFixed  – contains only the frozen columns
     *   - table.sapUiTableCtrlScroll – contains only the scrollable columns
     * Both carry the same row count (same data-sap-ui-rowindex values) but different cells.
     * Selecting from both tables would therefore count every logical row twice.
     *
     * We always take rows from the scroll table (which is always present).
     * Cells that belong to frozen columns are retrieved on demand via findCellByColId(),
     * which walks up from the row's data-sap-ui-rowindex and searches the whole table DOM.
     *
     * @return NodeElement[]
     */
    public function getTableRows(): array
    {
        // Prefer scroll-table rows to avoid double-counting when fixed columns are present.
        $scrollRows = $this->getNodeElement()->findAll(
            'css',
            'table.sapUiTableCtrlScroll .sapUiTableTr.sapUiTableContentRow[role="row"]:not(.sapUiTableRowHidden):not(.sapUiTableRowFirstFixedBottom)'
        );
        if (!empty($scrollRows)) {
            return $scrollRows;
        }

        // Fallback for tables without a fixed/scroll split (e.g. sap.m.Table or single-table grids).
        return $this->getNodeElement()->findAll(
            'css',
            '.sapUiTableCtrl .sapUiTableTr.sapUiTableContentRow[role="row"]:not(.sapUiTableRowHidden):not(.sapUiTableRowFirstFixedBottom), ' .
            '.sapMListTblRow'
        );
    }

    /**
     * Verifies table content against expected values
     * Checks if specified column contains expected text
     *
     * @param array $expectedContent Array of expected content (column => text pairs)
     * @return void
     * @throws RuntimeException If verification fails
     */
    public function verifyTableContent(array $expectedContent): void
    {
        try {
            // Build a deduplicated, sorted list of header cells.
            // When fixed columns are present UI5 renders the column headers in BOTH the
            // fixed table and the scroll table.  We deduplicate on data-sap-ui-colid so
            // that every logical column appears exactly once.
            $rawHeaderCells = $this->getNodeElement()->findAll(
                'css',
                '.sapUiTableColHdrCnt .sapUiTableHeaderDataCell[data-sap-ui-colid]:not(.sapUiTableCellDummy)'
            );

            $seenColIds    = [];
            $uniqueHeaders = [];   // NodeElement[] – the header <td> cells, unique
            foreach ($rawHeaderCells as $cell) {
                $id = $cell->getAttribute('data-sap-ui-colid');
                if ($id !== null && !isset($seenColIds[$id])) {
                    $seenColIds[$id] = true;
                    $uniqueHeaders[] = $cell;
                }
            }
            usort($uniqueHeaders, static fn($a, $b) =>
                (int)$a->getAttribute('data-sap-ui-colindex') <=> (int)$b->getAttribute('data-sap-ui-colindex')
            );

            // Check each expected content item
            foreach ($expectedContent as $content) {
                $columnName = $content['column'];
                $searchValue = trim($content['value'], '"\'');
                $rawCmp = $content['comparator'] ?? '[';
                /** @var DataTypeInterface $inputDataType */
                $inputDataType = $content['dataType'] ?? new StringDataType(SelectorFactory::createDataTypeSelector($this->getWorkbench(), static::class));

                // Resolve column by caption from the deduplicated header list.
                $columnIndex = null;
                $colId       = null;
                foreach ($uniqueHeaders as $index => $headerCell) {
                    $label = $headerCell->find('css', 'label') ?? $headerCell;
                    if (trim($label->getText()) === $columnName) {
                        $columnIndex = $index;
                        $colId       = $headerCell->getAttribute('data-sap-ui-colid');
                        break;
                    }
                }

                // Fallback: sap.m.Table headers (no data-sap-ui-colid).
                if ($columnIndex === null) {
                    $mHeaders = $this->getNodeElement()->findAll('css', '.sapMListTblHeader .sapMColumnHeader');
                    foreach ($mHeaders as $index => $header) {
                        if (trim($header->getText()) === $columnName) {
                            $columnIndex = $index;
                            break;
                        }
                    }
                }

                Assert::assertNotNull($columnIndex, "Column '$columnName' not found in table");

                // Check table cells - get rows from all available tables (both fixed and scroll)
                $rows = $this->getAllTableRows();
                $considered = 0;
                $matches = 0;
                $firstFailures = []; // collect first few failures for better error messages
                foreach ($rows as $row) {
                    // Pass $colId so extractCellValueFromRow can cross fixed/scroll boundaries.
                    $cellText = $this->extractCellValueFromRow($row, $columnIndex, $colId);
                    $considered++;

                    $ok = $this->compareCell($cellText, $searchValue, $rawCmp, $inputDataType);

                    if ($ok) {
                        $matches++;
                    } else {
                        if (count($firstFailures) < 3) {
                            $firstFailures[] = $cellText;
                        }
                    }
                }

                Assert::assertSame(
                    $considered,
                    $matches,
                    "Not all rows of the table fits the column '{$columnName}'. {$matches}/{$considered} matched. First mismatches: " . implode(' | ', $firstFailures)
                );
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to verify table content. " . $e->getMessage(),
                null,
                $e
            );
        }
    }

    /**
     * Returns all table rows including those from both fixed and scrollable table sections.
     * Handles the case where UI5 splits tables into fixed and scroll tables.
     *
     * @return NodeElement[]
     */
    private function getAllTableRows(): array
    {
        $allRows = [];
        $seenRowIndices = [];

        // Get rows from the scroll table (preferred, contains most/all rows)
        $scrollRows = $this->getNodeElement()->findAll(
            'css',
            'table.sapUiTableCtrlScroll .sapUiTableTr.sapUiTableContentRow[role="row"]:not(.sapUiTableRowHidden):not(.sapUiTableRowFirstFixedBottom)'
        );
        foreach ($scrollRows as $row) {
            $rowIndex = $row->getAttribute('data-sap-ui-rowindex');
            if ($rowIndex !== null) {
                $seenRowIndices[$rowIndex] = true;
                $allRows[] = $row;
            }
        }

        // Get rows from the fixed table (may contain rows not in scroll table)
        $fixedRows = $this->getNodeElement()->findAll(
            'css',
            'table.sapUiTableCtrlFixed .sapUiTableTr.sapUiTableContentRow[role="row"]:not(.sapUiTableRowHidden):not(.sapUiTableRowFirstFixedBottom)'
        );
        foreach ($fixedRows as $row) {
            $rowIndex = $row->getAttribute('data-sap-ui-rowindex');
            if ($rowIndex !== null && !isset($seenRowIndices[$rowIndex])) {
                $seenRowIndices[$rowIndex] = true;
                $allRows[] = $row;
            }
        }

        // If no rows found in both, try the generic selector
        if (empty($allRows)) {
            return $this->getTableRows();
        }

        // Sort by row index to maintain order
        usort($allRows, function ($a, $b) {
            $indexA = (int)($a->getAttribute('data-sap-ui-rowindex') ?? -1);
            $indexB = (int)($b->getAttribute('data-sap-ui-rowindex') ?? -1);
            return $indexA <=> $indexB;
        });

        return $allRows;
    }


    /**
     * returns the cell value from requested index of the column and the row.
     *
     * When $colId is supplied the cell is located by its data-sap-ui-colid attribute,
     * which works correctly even when frozen columns split the table into two <table>
     * elements (sapUiTableCtrlFixed / sapUiTableCtrlScroll).  The index-based fallback
     * is retained for callers that do not yet supply a column id.
     *
     * @param NodeElement $row
     * @param int $columnIndex  (used only when $colId is null)
     * @param string|null $colId  data-sap-ui-colid value of the target column
     * @return string|null
     */
    public function extractCellValueFromRow(NodeElement $row, int $columnIndex, ?string $colId = null): ?string
    {
        if ($row->getAttribute('aria-hidden') === 'true') {
            return null;
        }

        // --- colId-based lookup (preferred when fixed columns split the table) ---
        if ($colId !== null) {
            $cell = $this->findCellByColId($row, $colId);
            if ($cell === null) {
                return null;
            }
            $cellText = $this->extractCellText($cell);
            return $cellText !== '' ? $cellText : null;
        }

        // --- Legacy index-based lookup ---
        $cells = $row->findAll('css', '.sapUiTableCell, .sapMListTblCell');
        if (count($cells) === 0) {
            return null;
        }
        if (!isset($cells[$columnIndex])) {
            return null;
        }

        $cell     = $cells[$columnIndex];
        $cellText = $this->extractCellText($cell);

        if ($cellText === '') {
            return null;
        }
        return $cellText;
    }

    /**
     * Finds a table cell by its data-sap-ui-colid attribute.
     *
     * First the current row element is searched.  If nothing is found there (e.g. the
     * requested column lives in the other half of a frozen-column table) the method uses
     * the row's data-sap-ui-rowindex to search the whole table DOM, covering both the
     * fixed-column table and the scroll-column table.
     *
     * @param NodeElement $row
     * @param string $colId
     * @return NodeElement|null
     */
    private function findCellByColId(NodeElement $row, string $colId): ?NodeElement
    {
        // Fast path: cell is already in this row element.
        $cell = $row->find('css', 'td[data-sap-ui-colid="' . $colId . '"]');
        if ($cell !== null) {
            return $cell;
        }

        // Slow path: the cell belongs to the other table part (fixed ↔ scroll split).
        $rowIndex = $row->getAttribute('data-sap-ui-rowindex');
        if ($rowIndex === null) {
            return null;
        }

        return $this->getNodeElement()->find(
            'css',
            '[data-sap-ui-rowindex="' . $rowIndex . '"] td[data-sap-ui-colid="' . $colId . '"]'
        );
    }

    /**
     * Strict comparator :
     * - == / !=, <>: string comparison only (no numeric/date coercion).
     * - >, <, >=, <=: strict numeric or strict ISO date compare. If parsing fails, returns false.
     *
     * A test failed because for input combo the search text itself contains a comma.
     * As a result, the system interpreted that single text value as two separate filter values (split at the comma).
     * That means what we expected to search for (one complete string) did not match what was actually applied
     * (two partial strings), so the “expected vs. found” comparison failed.
     *
     */
    private function compareCell(?string $cellText, $expected, string $cmp, DataTypeInterface $dataType): bool
    {
        $cellText = (string)$cellText;
        switch (true) {
            case $dataType instanceof NumberEnumDataType:
                $left  = $this->normalizeText($cellText);
                $right = $this->normalizeText((string)$expected);
                break;

            case $dataType instanceof NumberDataType:
                $left  = $this->parseNumberFlexible($cellText);
                $right = $this->parseNumberFlexible((string) $expected);
                if ($left === null || $right === null) {
                    return false;
                }
                break;

            case $dataType instanceof DateDataType:
                $left  = $this->parseDateFlexible($cellText);
                $right = $this->parseDateFlexible((string) $expected);
                if ($left === null || $right === null) {
                    return false;
                }
                break;

            case $dataType instanceof BooleanDataType:
                $left  = $this->normalizeBool($cellText);
                $right = $this->normalizeBool($expected);
                break;

            default:
                $left  = $this->normalizeText($cellText);
                $right = $this->normalizeText((string)$expected);
        }

        switch ($cmp) {
            // UNIVERSAL not-like
            case '!=':
            case '<>':
                return $left !== $right;

            case '==':
                return $left === $right;

            case '>':
                return $left > $right;

            case '<':
                return $left < $right;

            case '>=':
                return $left >= $right;

            case '<=':
                return $left <= $right;
            // IN '['
            default:
                return stripos((string)$left, (string)$right) !== false;
        }
    }

    /**
     * Extracts robust text from a cell by reading common UI5 text carriers and stripping HTML/nbsp.
     */
    private function extractCellText(NodeElement $cell): string
    {
        // 1) Special-case: sap.m.ProgressIndicator
        $pi = $cell->find('css', '[role="progressbar"].sapMPI');
        if ($pi) {
            // Prefer aria-valuetext if present (most reliable business text)
            $vt = trim((string)$pi->getAttribute('aria-valuetext'));
            if ($vt !== '') {
                return $vt;
            }
            // Fall back to left/right texts
            $left  = $pi->find('css', '.sapMPITextLeft');
            $right = $pi->find('css', '.sapMPITextRight');
            $parts = [];
            if ($left)  { $t = trim($left->getText());  if ($t !== '') $parts[] = $t; }
            if ($right) { $t = trim($right->getText()); if ($t !== '') $parts[] = $t; }
            if (!empty($parts)) {
                return implode(' ', $parts);
            }
            // As a last resort use title (often a descriptive tooltip)
            $title = trim((string)$pi->getAttribute('title'));
            if ($title !== '') {
                return $title;
            }
            // If nothing found, return empty
            return '';
        }

        // 2) Common UI5 text carriers (labels, text, link, object status, etc.)
        $candidates = $cell->findAll('css', implode(', ', [
            '.sapMText', '.sapMLabel', '.sapMLnk', '.sapMLink',
            '.sapMObjectNumber', '.sapMObjectIdentifierTitle', '.sapMObjectIdentifierText',
            '.sapMObjStatusText', '.sapMObjStatus .sapMObjStatusText',
            '.sapMPITextLeft', '.sapMPITextRight',
            'input', 'textarea', 'select'
        ]));

        $parts = [];
        foreach ($candidates as $el) {
            $t = trim($el->getText());
            if ($t !== '') { $parts[] = $t; }
        }
        if (!empty($parts)) {
            return trim(implode(' ', $parts));
        }

        // Fallback: strip inner HTML (helps with &nbsp;)
        $html = $cell->getHtml();
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = str_replace("\xc2\xa0", ' ', $html);
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($html)));
        return $text;
    }
}