<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Gherkin\Node\TableNode;
use Behat\Mink\Element\NodeElement;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\DataTypes\BooleanDataType;
use exface\Core\DataTypes\ColorDataType;
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
     * Returns the rendered columns as an ordered list of descriptors, one per header,
     * left-to-right as the user sees them.
     *
     * WHY THIS IS THE SINGLE SOURCE OF TRUTH: every column-oriented concern - order
     * checks, "column not displayed" checks, resolving a caption to the DOM colId used
     * to read cell values across the fixed/scroll split, and "is this header rendered"
     * - needs the very same header scan across the two UI5 table variants (sap.ui.table
     * grid and sap.m.Table list). Scanning the DOM once here and deriving everything
     * else from it removes the near-identical header loops this class used to carry.
     *
     * @return array<int, array{caption: string, index: int, colId: string|null, visible: bool}>
     */
    private function getRenderedColumns(): array
    {
        $columns = [];

        // sap.ui.table (grid): the header is rendered in BOTH the fixed and the scroll
        // table, so deduplicate on data-sap-ui-colid, then order by the logical column index.
        $rawHeaderCells = $this->getNodeElement()->findAll(
            'css',
            '.sapUiTableColHdrCnt .sapUiTableHeaderDataCell[data-sap-ui-colid]:not(.sapUiTableCellDummy)'
        );
        $seenColIds = [];
        $uniqueHeaders = [];
        foreach ($rawHeaderCells as $cell) {
            $id = $cell->getAttribute('data-sap-ui-colid');
            if ($id !== null && !isset($seenColIds[$id])) {
                $seenColIds[$id] = true;
                $uniqueHeaders[] = $cell;
            }
        }
        usort($uniqueHeaders, static fn($a, $b) =>
            (int) $a->getAttribute('data-sap-ui-colindex') <=> (int) $b->getAttribute('data-sap-ui-colindex')
        );
        foreach ($uniqueHeaders as $cell) {
            $label = $cell->find('css', 'label') ?? $cell;
            $columns[] = [
                'caption' => trim($label->getText()),
                'index'   => count($columns),
                'colId'   => $cell->getAttribute('data-sap-ui-colid'),
                'visible' => $cell->isVisible(),
            ];
        }

        if (! empty($columns)) {
            return $columns;
        }

        // sap.m.Table (responsive list): headers already sit in visual order and carry no
        // data-sap-ui-colid, so the index is the DOM position and the cell lookup is index-based.
        foreach ($this->getNodeElement()->findAll('css', '.sapMListTblHeader .sapMColumnHeader') as $header) {
            $columns[] = [
                'caption' => trim($header->getText()),
                'index'   => count($columns),
                'colId'   => null,
                'visible' => $header->isVisible(),
            ];
        }

        return $columns;
    }

    /**
     * Returns the captions of the visible rendered columns in left-to-right UI order.
     *
     * Thin projection over getRenderedColumns() for the order/visibility steps: only
     * visible, non-empty captions are what the user actually sees in the table.
     *
     * @return string[]
     */
    public function getRenderedColumnCaptionsInOrder(): array
    {
        $captions = [];
        foreach ($this->getRenderedColumns() as $col) {
            if ($col['visible'] && $col['caption'] !== '') {
                $captions[] = $col['caption'];
            }
        }
        return $captions;
    }

    /**
     * Resolves a column caption to its [index, colId] in the rendered header, or
     * [null, null] when the column is not rendered.
     *
     * WHY IT MATCHES REGARDLESS OF VISIBILITY: it preserves the original
     * verifyTableContent() behaviour, which located a column by caption without a
     * visibility check. The value-reading callers rely on the same tolerant match.
     *
     * @param string $columnName
     * @return array{0: int|null, 1: string|null} [columnIndex, colId]
     */
    private function resolveRenderedColumn(string $columnName): array
    {
        $columnName = trim($columnName);
        foreach ($this->getRenderedColumns() as $col) {
            if ($col['caption'] === $columnName) {
                return [$col['index'], $col['colId']];
            }
        }
        return [null, null];
    }

    /**
     * Returns the cell values of a single named column across every table row.
     *
     * WHY THIS EXISTS: the "column contains value" step needs to inspect one column's
     * actual cell contents. Doing so requires the same header resolution and the same
     * fixed/scroll row traversal verifyTableContent() already relies on. Exposing the
     * raw values here lets the step choose its own matching semantics (presence in at
     * least one row) instead of verifyTableContent()'s stricter all-rows-must-match rule.
     *
     * @param string $columnCaption
     * @throws RuntimeException When the column is not rendered in the table.
     * @return string[] Trimmed cell values, one entry per row (empty cells yield "").
     */
    public function getColumnCellValues(string $columnCaption): array
    {
        [$columnIndex, $colId] = $this->resolveRenderedColumn($columnCaption);
        if ($columnIndex === null) {
            throw new RuntimeException('Column `' . $columnCaption . '` not found in table');
        }

        $values = [];
        foreach ($this->getAllTableRows() as $row) {
            $values[] = trim((string) $this->extractCellValueFromRow($row, $columnIndex, $colId));
        }

        return $values;
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
     * action model.
     *
     * Why the selection is made exclusive instead of additive:
     * This used to only check whether row 1 was selected. When a previously tested button
     * left another row selected (the readiness loop walks rows until one enables the
     * button), row 1 was not selected, so row 1 was added on top - two selected rows, and
     * the action failed with "please select exactly 1 record". Reducing the selection to
     * exactly the required rows makes the precondition independent of whatever the
     * previous button left behind.
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
        $loadedRowCount = $this->getLoadedRowCount();
        if ($loadedRowCount < 1) {
            return false;
        }
        // Some actions require more than one row. Never ask for more rows than the first
        // page actually holds - clicking a non-existent row selector would throw instead
        // of letting the action report its own, far more readable error.
        $requiredRowCount = min($action->getInputRowsMin(), $loadedRowCount);
        $this->ensureExactlySelectedRows(range(1, $requiredRowCount));
        return true;
    }

    protected function getLoadedRowCount(): ?int
    {
        return count($this->getTableRows());
    }

    /**
     * Makes sure the given (1-based) row is selected, leaving every other row untouched.
     *
     * Why this is idempotent:
     * The row selector is a toggle - clicking an already selected row deselects it. Callers
     * that simply want "row N selected" (e.g. the "I select table row" step) would otherwise
     * have to track the current state themselves, and getting that wrong silently turns a
     * selection into a deselection. Checking first makes the method safe to call repeatedly.
     * Rows other than N are deliberately left alone, so scenarios that select several rows
     * on purpose keep working - use ensureExactlySelectedRows() when an exclusive selection
     * is needed.
     *
     * @param int $rowNumber 1-based row number
     * @return void
     */
    public function selectRow(int $rowNumber)
    {
        // An overflow popover left open by a preceding button lookup swallows the next click on the
        // page underneath it, so the row selector click would only close the popover instead of
        // selecting the row. This is the single choke point through which every row click of this
        // node goes (selectEachRowUntil, ensureExactlyOneRowSelected, the "I select table row" step),
        // so closing it here covers all of them at once.
        $this->closeOverflowMenuIfOpened();
        
        if (! $this->isRowSelected($rowNumber)) {
            $this->toggleRowSelection($rowNumber);
        }
    }

    /**
     * Clicks the row selector of the given (1-based) row, flipping its selection state.
     *
     * Why this is separated from selectRow():
     * Clicking is the only way to change the selection like a user would, but a click means
     * "toggle", not "select". Keeping the raw toggle private and exposing intent-named
     * methods (selectRow / ensureExactlySelectedRows) on top of it prevents call sites from
     * accidentally deselecting a row they meant to select.
     *
     * Why getTableRows() is used instead of an own DOM query:
     * The previous implementation matched `.sapUiTableTr, .sapMListTblRow` directly, which
     * also matches the rows of the fixed-column table. That list is longer than - and in a
     * different order from - the one getLoadedRowCount() and every other row helper works
     * with, so row number N could point at a different physical row depending on which
     * helper asked. Sharing one row list keeps row numbers, selection state and click
     * targets in a single consistent index space.
     *
     * @param int $rowNumber 1-based row number
     * @throws RuntimeException if the row does not exist
     * @return void
     */
    protected function toggleRowSelection(int $rowNumber): void
    {
        $rowIndex = $this->convertOrdinalToIndex($rowNumber);
        $rows = $this->getTableRows();
        Assert::assertNotEmpty($rows, 'No rows found in table');

        if (count($rows) < $rowIndex + 1) {
            throw new RuntimeException("Row {$rowNumber} not found. Only " . count($rows) . ' rows available.');
        }

        $row = $rows[$rowIndex];
        $rowSelector = $row->find('css', '.sapUiTableRowSelectionCell');
        if ($rowSelector) {
            $rowSelector->click();
        } else {
            $firstCell = $row->find('css', 'td.sapUiTableCell, .sapMListTblCell');
            Assert::assertNotNull($firstCell, "Could not find a clickable cell in row {$rowNumber}");
            $firstCell->click();
        }
    }

    /**
     * Returns the 1-based numbers of all rows currently marked as selected.
     *
     * Why this exists:
     * Selection used to be probed one row at a time, so the only question that actually
     * matters before triggering a row-bound action - "how many rows are selected right
     * now?" - could not be answered without N separate DOM round-trips, and every caller
     * re-invented its own bookkeeping. Reading the full selection state at once, from the
     * same row list toggleRowSelection() clicks on, is what makes an exclusive selection
     * possible at all.
     *
     * Why the CSS class check is paired with aria-selected:
     * sap.ui.table marks a selected row with `sapUiTableRowSel`, sap.m.Table with
     * `sapMLIBSelected`. Both also expose `aria-selected`, which survives theme and UI5
     * version changes, so it serves as a second, renaming-proof source of truth. Missing a
     * selected row here is the worst possible failure mode: nothing gets deselected and the
     * action ends up seeing two selected records.
     *
     * @return int[] 1-based row numbers in ascending order
     */
    public function getSelectedRowNumbers(): array
    {
        $selected = [];
        $rowNumber = 0;
        foreach ($this->getTableRows() as $row) {
            $rowNumber++;
            $classes = (string) $row->getAttribute('class');
            if (strpos($classes, 'sapUiTableRowSel') !== false
                || strpos($classes, 'sapMLIBSelected') !== false
                || $row->getAttribute('aria-selected') === 'true'
            ) {
                $selected[] = $rowNumber;
            }
        }
        return $selected;
    }

    /**
     * Tells whether the given (1-based) row is currently marked as selected.
     *
     * Why this delegates:
     * Selection detection lives in exactly one place (getSelectedRowNumbers()), so a table
     * type or UI5 version that renders selection differently only has to be taught there.
     * The previous version ran its own jQuery snippet against a row list that did not match
     * the one used for clicking, which meant "row 2 is selected" and "click row 2" could
     * refer to two different rows in tables with fixed columns.
     *
     * @param int $rowNumber 1-based row number
     * @return bool
     */
    public function isRowSelected(int $rowNumber): bool
    {
        return in_array($this->convertOrdinalToIndex($rowNumber) + 1, $this->getSelectedRowNumbers(), true);
    }

    public function selectEachRowUntil(callable $predicate): bool
    {
        $count = $this->getLoadedRowCount();
        if ($count < 1) {
            return false;
        }
        for ($rowNumber = 1; $rowNumber <= $count; $rowNumber++) {
            // Exclusive selection instead of remembering the previous row: the previously
            // tried row is not necessarily the only other selected one - a selection left
            // over from an earlier button survives into this loop and would add up to two
            // selected rows, which is exactly the state the predicate is meant to test
            // against a single row.
            $this->ensureExactlySelectedRows([$rowNumber]);
            if ($predicate($rowNumber) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reduces the table selection to exactly the given (1-based) rows - no more, no less.
     *
     * Why this replaces ensureExactlyOneRowSelected():
     * The "select exactly one record" precondition can be violated in two ways - nothing is
     * selected (the selection was silently dropped by a toolbar re-render), or a row from an
     * earlier button is still selected and the new one is added on top. Both are the same
     * problem seen from different sides: no caller owned the *whole* selection state. This
     * method does, and it takes a row list rather than a single row so actions requiring
     * several input rows are covered by the same code path.
     *
     * Why the deselect loop is driven by getSelectedRowNumbers():
     * Iterating 1..getLoadedRowCount() and probing each row was not only N times slower, it
     * also silently skipped selected rows whose number fell outside the counted range - the
     * exact case that left two rows selected in tables with fixed columns.
     *
     * @param int[] $rowNumbers 1-based row numbers that must end up selected
     * @return void
     */
    public function ensureExactlySelectedRows(array $rowNumbers): void
    {
        if ($this->getLoadedRowCount() < 1) {
            return;
        }
        // Toggle off everything that must not stay selected first: a leftover selection can
        // never survive into the action this way, no matter which step produced it.
        foreach ($this->getSelectedRowNumbers() as $selectedRowNumber) {
            if (! in_array($selectedRowNumber, $rowNumbers, true)) {
                $this->toggleRowSelection($selectedRowNumber);
            }
        }
        foreach ($rowNumbers as $rowNumber) {
            $this->selectRow($rowNumber);
        }
    }

    /**
     * Tells whether a failed substep failed because the action asked for a different
     * number of selected rows (e.g. "Bitte genau 1 Datensatz auswählen!").
     *
     * Why this is translation-driven instead of a hard-coded string:
     * The message is emitted client-side by UI5 in the active UI language, so it is
     * matched against the translated SELECT_EXACTLY / SELECT_AT_LEAST / SELECT_AT_MOST
     * core messages rather than a fixed German literal, keeping the retry locale-safe.
     *
     * @param SubstepResult $result
     * @param LogBookInterface $logbook
     * @return bool
     */
    public function isRowSelectionError(SubstepResult $result, LogBookInterface $logbook): bool
    {
        if (! $result->isFailed()) {
            return false;
        }
        $message = (string) $result->getReason();
        if ($message === '') {
            return false;
        }
        $patterns = $this->getRowSelectionErrorPatterns();
        if ($patterns === []) {
            // No pattern could be resolved: either the message keys are missing from the core
            // translations or the placeholder name changed. Silently returning false would
            // disable the whole retry without any trace, so the condition is made visible.
            $logbook->addLine(
                '**WARNING:** No row-selection error patterns could be resolved for locale `'
                . $this->getBrowser()->getLocale() . '` - the row-selection retry is inactive.'
            );
            return false;
        }
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Builds the regex patterns that identify a row-selection error message in the
     * current UI language, one per plural form of each relevant core message.
     *
     * The `%number%` placeholder is replaced by a sentinel before translation and then
     * turned into a `\d+` matcher, so any required row count matches regardless of how
     * the translator resolves placeholders.
     *
     * @return string[]
     */
    private function getRowSelectionErrorPatterns(): array
    {
        $translator = $this->getWorkbench()->getCoreApp()->getTranslator($this->getBrowser()->getLocale());
        $keys = [
            'MESSAGE.SELECT_EXACTLY_X_ROWS',
            'MESSAGE.SELECT_AT_LEAST_X_ROWS',
            'MESSAGE.SELECT_AT_MOST_X_ROWS'
        ];
        $sentinel = "\x01NUM\x01";
        $patterns = [];
        foreach ($keys as $key) {
            foreach ([1, 2] as $pluralNumber) {
                $translated = $translator->translate($key, ['%number%' => $sentinel], $pluralNumber);
                if ($translated === '' || $translated === $key || strpos($translated, $sentinel) === false) {
                    continue;
                }
                $regex = str_replace(preg_quote($sentinel, '/'), '\d+', preg_quote($translated, '/'));
                $patterns['/' . $regex . '/u'] = '/' . $regex . '/u';
            }
        }
        return array_values($patterns);
    }

    /**
     * Runs a button-click substep and, if it fails because the action reported a
     * row-selection error, re-selects a single row and retries the click exactly once.
     *
     * Why this exists:
     * The row precondition is satisfied up-front via ensureRowSelectedForAction(), but
     * the toolbar re-renders when data reloads and can silently drop the selection
     * between the precondition and the actual click, so the action still fails asking
     * for "genau 1 Datensatz". This safety net recovers from that race deterministically
     * instead of failing the button. A single retry is enough: if the selection is lost
     * again the failure is real and must surface.
     *
     * @param callable $runClickSubstep Returns the SubstepResult of the click.
     * @param LogBookInterface $logbook
     * @param callable|null $beforeReselect Optional hook run before re-selecting a row
     *                                      (e.g. a MenuButton closing its modal popover
     *                                      so the row selector is clickable).
     * @return SubstepResult
     */
    public function retryClickIfRowSelectionLost(callable $runClickSubstep, LogBookInterface $logbook, ?callable $beforeReselect = null): SubstepResult
    {
        $result = $runClickSubstep();
        if (! $this->isRowSelectionError($result, $logbook)) {
            return $result;
        }
        if ($this->getLoadedRowCount() < 1) {
            return $result;
        }
        $logbook->addLine('Action reported a row-selection error (e.g. "Bitte genau 1 Datensatz auswählen!") - re-selecting a single row and retrying the click once.');
        if ($beforeReselect !== null) {
            $beforeReselect();
        }
        $this->ensureExactlySelectedRows([1]);
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
        return $runClickSubstep();
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

        if ($column === null) {
            $logbook->continueLine(' - filter `' . $filterAttr->getName() . '` has no corresponding column in the table, skipping content verification');
            return SubstepResult::createSkipped(
                'Filter `' . $filterAttr->getName() . '` has no corresponding column in the table, skipping content verification',
                $logbook
            );
        }
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

        $filterVal = $this->trySetFilterValue($filterNode, $filter, $filterAttr, $dataWidget, $logbook, $column);
        if ($filterVal !== null) {
            $logbook->continueLine(' with value `' . $filterVal . '` found in data source');
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

        $this->verifyTableContent([
            ['column' => $columnCaption, 'value' => $filterVal, 'comparator' => $filter->getComparator(), 'dataType' => $this->getInputDataType()]
        ]);

        $logbook->continueLine(' - resetting filter');

        $result->setTitle($result->getTitle() . ' with value "' . $filterVal . '"');
        return $result;
    }

    /**
     * Asserts that the given columns are rendered in the stated left-to-right order.
     *
     * WHY THIS EXISTS: pins the visual column order (e.g. after a personalisation change),
     * which the presence-only column check cannot detect.
     *
     * @param string[] $expectedCaptions Column captions in the expected order.
     */
    public function assertColumnsDisplayedInOrder(array $expectedCaptions): void
    {
        $this->assertCaptionsDisplayedInOrder(
            $expectedCaptions,
            $this->getRenderedColumnCaptionsInOrder(),
            'column'
        );
    }

    /**
     * Asserts that none of the listed columns are rendered in this table.
     *
     * WHY THIS EXISTS: verifying that a role or personalisation actually HIDES a column is a
     * negative expectation the positive column check cannot express.
     *
     * @param string[] $unexpectedCaptions Column captions expected to be absent.
     */
    public function assertColumnsNotDisplayed(array $unexpectedCaptions): void
    {
        $this->assertCaptionsNotDisplayed(
            $unexpectedCaptions,
            $this->getRenderedColumnCaptionsInOrder(),
            'column'
        );
    }

    /**
     * Returns the colours actually rendered in a single named column, one entry per table row.
     *
     * WHY IT READS COMPUTED STYLES AND NOT THE MODEL: a `color_scale` in the model only says which
     * colour SHOULD be used. Whether it arrives on screen depends on the facade, the theme and the
     * control that ended up rendering the cell (plain text, ObjectStatus, icon). Asking the browser
     * for the computed style is the only way to test what the user really sees.
     *
     * WHY BACKGROUNDS ARE READ FROM THE CONTENT ONLY, NOT FROM THE CELL: a selected row paints the
     * table cells in the theme's (blue) selection colour. Taking that background into account would
     * make any "highlighted in blue" check pass as soon as a row happens to be selected. Colour
     * scales always paint the control inside the cell, never the cell itself, so descendants are
     * both the correct and the safe scope.
     *
     * WHY ONLY ELEMENTS CARRYING TEXT: the colour of a wrapper that renders no text says nothing
     * about the value - including those would drown the real colour in a list of inherited defaults.
     *
     * @param string $columnCaption
     * @throws RuntimeException When the column is not rendered or the table DOM cannot be located.
     * @return array<int, array{value: string, text_colors: string[], background_colors: string[]}>
     */
    public function getColumnCellColors(string $columnCaption): array
    {
        [$columnIndex, $colId] = $this->resolveRenderedColumn($columnCaption);
        if ($columnIndex === null) {
            throw new RuntimeException('Column `' . $columnCaption . '` not found in table');
        }

        $rootIdJs = json_encode($this->getElementId(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $colIdJs = json_encode($colId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $colIndexJs = json_encode($columnIndex);

        $json = $this->getFromJavascript(<<<JS
(function(sRootId, sColId, iColIdx){
    var oRoot = document.getElementById(sRootId);
    if (! oRoot) { return null; }

    var fnCarriesText = function(oEl) {
        // Icons render their glyph via a CSS pseudo element, so they have no text node at all -
        // yet their `color` IS the colour of the value (boolean columns are rendered this way).
        if (oEl.classList && oEl.classList.contains('sapUiIcon')) { return true; }
        for (var i = 0; i < oEl.childNodes.length; i++) {
            var oNode = oEl.childNodes[i];
            if (oNode.nodeType === 3 && oNode.nodeValue.trim() !== '') { return true; }
        }
        return false;
    };
    var fnPush = function(aList, sColor) {
        if (sColor && aList.indexOf(sColor) === -1) { aList.push(sColor); }
    };

    var aResult = [];
    var oSeenRows = {};
    var iAnonymous = 0;
    var aRows = oRoot.querySelectorAll('tr.sapUiTableTr.sapUiTableContentRow, tr.sapMListTblRow');

    Array.prototype.forEach.call(aRows, function(oRow){
        if (oRow.getAttribute('aria-hidden') === 'true') { return; }
        if (oRow.classList.contains('sapUiTableRowHidden')) { return; }
        if (oRow.classList.contains('sapUiTableRowFirstFixedBottom')) { return; }

        var oCell = sColId
            ? oRow.querySelector('td[data-sap-ui-colid="' + sColId + '"]')
            : (oRow.querySelectorAll('.sapUiTableCell, .sapMListTblCell')[iColIdx] || null);
        // Frozen columns split the grid into a fixed and a scrollable table, so every logical row
        // is rendered twice - but the requested column exists in exactly one of the two halves.
        if (oCell === null) { return; }

        var sRowKey = oRow.getAttribute('data-sap-ui-rowindex');
        if (sRowKey === null) { sRowKey = 'anonymous-' + (iAnonymous++); }
        if (oSeenRows[sRowKey] === true) { return; }
        oSeenRows[sRowKey] = true;

        var aTextColors = [];
        var aBackgroundColors = [];
        var aInner = oCell.querySelectorAll('*');
        Array.prototype.forEach.call(aInner, function(oEl){
            var oStyle = window.getComputedStyle(oEl);
            if (! oStyle) { return; }
            fnPush(aBackgroundColors, oStyle.backgroundColor);
            if (fnCarriesText(oEl)) { fnPush(aTextColors, oStyle.color); }
        });
        // Fallback for cells rendering their value as a bare text node without any wrapper.
        if (aTextColors.length === 0 && fnCarriesText(oCell)) {
            var oCellStyle = window.getComputedStyle(oCell);
            if (oCellStyle) { fnPush(aTextColors, oCellStyle.color); }
        }

        aResult.push({
            value: (oCell.innerText || oCell.textContent || '').replace(/\s+/g, ' ').trim(),
            text_colors: aTextColors,
            background_colors: aBackgroundColors
        });
    });

    return JSON.stringify(aResult);
})($rootIdJs, $colIdJs, $colIndexJs)
JS
        );

        if (! is_string($json)) {
            throw new RuntimeException('Cannot read the colors of column `' . $columnCaption . '`: table DOM not found');
        }

        return json_decode($json, true) ?? [];
    }

    /**
     * Asserts that every non-empty value of a named column is rendered in the given colour.
     *
     * WHY A COLOUR FAMILY AND NOT AN EXACT VALUE: the shade a facade produces for "blue" depends on
     * the UI5 theme and on whether the model used a semantic colour or an explicit one. Pinning the
     * exact value would turn every theme update into a mass test failure, while what the scenario
     * actually states is only "this value stands out in blue". Where the shade does matter, the
     * family can be narrowed down by a lightness qualifier - `light blue` and `dark blue` both
     * require blue, but only one of them accepts a pale tone.
     *
     * Empty cells are skipped - they carry no value that could be highlighted. If the column has no
     * non-empty value at all, the assertion fails: a check that silently verifies nothing is worse
     * than a red test.
     *
     * @param string $columnCaption Caption of the column to inspect.
     * @param string $color         Colour family with an optional lightness qualifier (`blue`,
     *                              `light blue`, `dark green`, ...), HTML colour name or hex value.
     * @throws RuntimeException When the colour name cannot be interpreted.
     */
    public function assertColumnValuesColored(string $columnCaption, string $color): void
    {
        $spec = ColorDataType::parseColorSpec($color);
        if ($spec === null) {
            throw new RuntimeException(
                'Cannot check the color of column `' . $columnCaption . '`: "' . $color
                . '" is not a known color. Use a color family with an optional lightness (e.g. "blue" '
                . 'or "light blue"), an HTML color name (e.g. "DodgerBlue") or a hex value (e.g. "#0a6ed1").'
            );
        }

        $checked = 0;
        $mismatches = [];
        foreach ($this->getColumnCellColors($columnCaption) as $cell) {
            if ($cell['value'] === '') {
                continue;
            }
            $checked++;
            $colors = array_merge($cell['text_colors'], $cell['background_colors']);
            foreach ($colors as $rendered) {
                if (ColorDataType::isColorInFamily($rendered, $color)) {
                    continue 2;
                }
            }
            $mismatches[] = '"' . $cell['value'] . '" (' . (empty($colors) ? 'no color' : implode(', ', $colors)) . ')';
        }

        Assert::assertGreaterThan(
            0,
            $checked,
            'Cannot check the color of column "' . $columnCaption . '": the column has no values.'
        );
        Assert::assertEmpty(
            $mismatches,
            'Not every value in column "' . $columnCaption . '" is highlighted in ' . $spec['name'] . '. '
            . 'Values rendered in another color: ' . implode(' | ', $mismatches)
        );
    }

    /**
     * Opens the toolbar overflow ("...") menu of THIS table and returns the opened popover.
     *
     * WHY IT RETURNS THE POPOVER: the step that follows this one always wants to act on an entry of
     * that menu. Handing back the resolved container means the follow-up step never has to search
     * the page for "a popover" and can never act on the menu of the neighbouring table.
     *
     * @throws RuntimeException If no overflow button is rendered, if it is rendered but hidden, or
     *         if clicking it does not open the menu.
     * @return NodeElement The opened overflow popover.
     */
    public function clickOverflowButton(): NodeElement
    {
        $button = $this->findOverflowButton();
        if ($button === null) {
            throw new RuntimeException(
                'Overflow button not found for table `' . $this->getCaption() . '`'
            );
        }

        // Distinguish "not rendered" from "rendered but hidden": UI5 keeps the overflow button in the
        // DOM and only shows it once the toolbar actually overflows. Clicking a hidden button does
        // nothing at all, which would otherwise surface as the misleading "menu did not open" below.
        if (! $this->isElementVisibleInBrowser($button)) {
            throw new RuntimeException(
                'Overflow button of table `' . $this->getCaption()
                . '` exists but is not visible - the toolbar is wide enough to show all buttons'
            );
        }

        $this->getBrowser()->highlightWidget($button, 'Button', 0);
        $button->click();

        $menu = $this->waitForOverflowMenu($button);
        if ($menu === null) {
            // Retry once: the first click is occasionally swallowed while UI5 is still attaching the
            // toolbar press handler, and a second click then opens the menu.
            $button->click();
            $menu = $this->waitForOverflowMenu($button);
        }

        if ($menu === null) {
            throw new RuntimeException(
                'Overflow button of table `' . $this->getCaption()
                . '` was clicked, but its overflow menu did not become visible'
            );
        }

        return $menu;
    }

    /**
     * Clicks an entry of this table's toolbar overflow menu, opening the menu first if needed.
     *
     * WHY IT OPENS THE MENU ITSELF: a scenario reads "click X in the overflow menu" as one action.
     * Requiring a separate opening step would make the assertion depend on step ordering and would
     * break as soon as UI5 closes the popover on its own (e.g. after a re-render).
     *
     * WHY THE POPOVER IS PASSED AS SCOPE: getWidgetScope() stops at the nearest `role="dialog"`
     * ancestor, and the overflow popover carries exactly that role - so the button search is
     * guaranteed to stay inside this table's menu and can never reach the toolbar behind it.
     *
     * @param string $caption Caption of the entry, exactly as rendered in the menu.
     * @throws RuntimeException If the menu holds no visible entry with that caption.
     */
    public function clickOverflowMenuItem(string $caption): void
    {
        $menu = $this->clickOverflowButton();

        // isTranslated = true: the caption comes from the scenario and is already written the way
        // the user sees it, so it must not be run through the translator again.
        $item = $this->findVisibleButtonByCaption($caption, true, $menu);

        if ($item === null) {
            throw new RuntimeException(
                'No entry `' . $caption . '` in the overflow menu of table `' . $this->getCaption() . '`'
            );
        }

        $this->getBrowser()->highlightWidget($item, 'Button', 0);
        $item->click();
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);
    }

    /**
     * Waits until the overflow popover belonging to the given overflow button is visible.
     *
     * WHY THE POPOVER IS DERIVED FROM THE BUTTON ID: UI5 names both after the toolbar that owns
     * them - `<toolbarId>-overflowButton` opens `<toolbarId>-popover`. Every other way of finding
     * the popup is ambiguous on a page with several tables, because the popovers carry generic ids
     * (`__toolbar0-popover`, `__toolbar1-popover`) and identical CSS classes, and UI5 keeps a once
     * opened popover in the DOM afterwards. Searching for "a popover" would therefore happily match
     * the leftover popup of the OTHER table and let the following step click the wrong entry.
     *
     * WHY IT POLLS FOR VISIBILITY INSTEAD OF EXISTENCE: for the same reason - the element exists in
     * the DOM from the first open onwards, so its mere presence proves nothing about this click.
     *
     * @param NodeElement $overflowButton
     * @param int $timeoutSeconds
     * @return NodeElement|null Null when the menu did not become visible in time.
     */
    protected function waitForOverflowMenu(NodeElement $overflowButton, int $timeoutSeconds = 5): ?NodeElement
    {
        $buttonId = (string) $overflowButton->getAttribute('id');
        $toolbarId = substr($buttonId, 0, -strlen(self::OVERFLOW_BUTTON_ID_SUFFIX));
        $menuId = $toolbarId . self::OVERFLOW_MENU_ID_SUFFIX;

        $page = $this->getSession()->getPage();
        $deadline = microtime(true) + $timeoutSeconds;

        do {
            // XPath rather than a CSS id selector: UI5 ids start with underscores and contain
            // characters that would have to be escaped in CSS, and an XPath literal needs no escaping.
            $menu = $page->find('xpath', '//*[@id=' . $this->xpathLiteral($menuId) . ']');
            if ($menu !== null && $this->isElementVisibleInBrowser($menu)) {
                return $menu;
            }
            // 100 ms is a compromise: short enough to not add noticeable latency to a passing step,
            // long enough to keep the number of synchronous CDP round trips per wait in the tens.
            usleep(100000);
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Locates the toolbar overflow button belonging to THIS table.
     *
     * WHY IT MAY HAVE TO LOOK BEYOND getNodeElement(): depending on the facade template the
     * `.exfw-DataTable` element is sometimes the sapUiTable itself, with the toolbar rendered as a
     * sibling above it. getWidgetScope() resolves the nearest ancestor holding both.
     *
     * WHY THE COUNT CHECK: widening the scope is only safe as long as it stays inside ONE table. On
     * a split layout the nearest rendered widget root can span several tables, and silently taking
     * the first overflow button found there would operate on the neighbouring table - the exact kind
     * of failure a test cannot notice, because the menu does open, just for the wrong widget.
     * Refusing an ambiguous scope turns that into a visible, explainable failure.
     *
     * @throws RuntimeException If the widened scope contains more than one overflow button.
     * @return NodeElement|null
     */
    protected function findOverflowButton(): ?NodeElement
    {
        // The table element first: when the toolbar IS inside it, this is unambiguous by definition.
        $button = $this->getNodeElement()->find('css', self::CSS_OVERFLOW_BUTTON);
        if ($button !== null) {
            return $button;
        }

        $scope = $this->getWidgetScope($this->getNodeElement());
        $buttons = $scope->findAll('css', self::CSS_OVERFLOW_BUTTON);

        if (count($buttons) > 1) {
            throw new RuntimeException(
                'Cannot tell which overflow button belongs to table `' . $this->getCaption()
                . '`: its table element has none and the surrounding widget scope contains '
                . count($buttons) . ' of them'
            );
        }

        return $buttons[0] ?? null;
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
        // Delegate to the single header scan so "is this column rendered?" can never
        // drift from the order/lookup logic that reads the very same headers. Matches
        // regardless of visibility, preserving the original behaviour of this method.
        $caption = trim($caption);
        foreach ($this->getRenderedColumns() as $col) {
            if ($col['caption'] === $caption) {
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

            switch (true) {
                case $action === null:
                    $skippedButtons['Button has no action'][] = $buttonWidget->getCaption();
                    $logbook->addLine('Skipping button ' . $buttonWidget->getCaption() . ' because it has no action');
                    continue 2;
                case $action->getInputRowsMin() > 0:
                    $this->ensureRowSelectedForAction($action);
                    break;
                default:
                    continue 2;
            }

            // The button may be shown only for rows whose data is valid for its action
            // (e.g. `hidden_if_input_invalid`). That is a per-row DOM-level hidden state,
            // not a `disabled` flag, so the readiness gate must re-resolve the button for
            // each selected row and require it to be visible AND enabled -
            // resolveButtonNode() returns null exactly when the button is hidden or absent
            // for the current row. The matching node is captured so the click below uses
            // the fresh, visible element instead of one that went stale when the toolbar
            // re-rendered on row selection.
            $readyNode = null;
            $ready = $this->selectEachRowUntil(function() use ($buttonWidget, &$readyNode) {
                $candidate = $this->resolveButtonNode($buttonWidget);
                if ($candidate === null || $candidate->checkDisabled()) {
                    return false;
                }
                $readyNode = $candidate;
                return true;
            });
            if (! $ready || $readyNode === null) {
                $skippedButtons['Button not visible'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button `' . $buttonWidget->getCaption() . '` because no loaded row shows it as a visible, enabled button (e.g. hidden_if_input_invalid)');
                continue;
            }
            $buttonNode = $readyNode;
            $urlBeforeClick = $this->getSession()->getCurrentUrl();
            if (!$buttonNode->checkDisabled()) {
                // Re-resolve the button on every attempt so the retry (below) never
                // clicks an element that went stale when the toolbar re-rendered.
                $runClick = function() use ($buttonWidget, $readyNode, $logbook, $urlBeforeClick) {
                    $node = $this->resolveButtonNode($buttonWidget) ?? $readyNode;
                    return $this->runAsSubstep(
                        function() use ($node, $logbook) {
                            return $node->checkWorksAsExpected($logbook);
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
                };

                // Press the button; if the action still reports a lost row selection,
                // re-select a row and retry the click once.
                $substepResult = $this->retryClickIfRowSelectionLost($runClick, $logbook);

                // Say the buttons test is failed if at least one button fails
                if ($substepResult->isFailed()) {
                    $failed = true;
                }
            }
            else {
                $skippedButtons['Button cannot be enabled'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button ' . $buttonWidget->getCaption() . ' because there is no row to enable it');
            }
        }
        // Leave the table in a predictable state for whatever runs after the button checks:
        // exactly one selected row. The previous version re-clicked row 1 unconditionally
        // with a variable that was always 1, so it either toggled the only selected row OFF
        // or added row 1 on top of a row the readiness loop had left selected - the double
        // selection that makes the next row-bound action fail with "select exactly 1 record".
        if ($this->getLoadedRowCount() > 0) {
            $this->ensureExactlySelectedRows([1]);
        }
        // Leave no popover behind for the next check of this scenario: the button loop above may have
        // opened one to reach an overflowed button and, if the last button did not close it by being
        // clicked, it would still be on screen when the next widget is inspected.
        $this->closeOverflowMenuIfOpened();

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

        // Resolve the DOM column id via the shared header scan so a frozen column's
        // cells can be read across the fixed/scroll table boundary that UI5 creates.
        [, $colId] = $this->resolveRenderedColumn($columnCaption);

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
            // Check each expected content item
            foreach ($expectedContent as $content) {
                $columnName = $content['column'];
                $searchValue = trim($content['value'], '"\'');
                $rawCmp = $content['comparator'] ?? '[';
                /** @var DataTypeInterface $inputDataType */
                $inputDataType = $content['dataType'] ?? new StringDataType(SelectorFactory::createDataTypeSelector($this->getWorkbench(), static::class));

                // Resolve the column against the rendered headers (both table variants,
                // fixed/scroll split handled) via the shared header scan.
                [$columnIndex, $colId] = $this->resolveRenderedColumn($columnName);
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