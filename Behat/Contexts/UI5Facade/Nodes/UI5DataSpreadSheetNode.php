<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use Behat\Mink\Element\NodeElement;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WidgetInterface;

/**
 * Gives DataSpreadSheet checks the jExcel DOM semantics that UI5DataTableNode cannot provide.
 *
 * WHY THIS NODE EXISTS: DataSpreadSheet derives from Data, so the convention-based factory used
 * to fall back to UI5DataNode. Its generic checks either skipped filtering or treated missing
 * sap.ui.table selectors as empty results. Keeping the orchestration in UI5DataTableNode while
 * replacing only its DOM primitives prevents those false-green checks.
 *
 * WHY ROW SELECTION IS SUPPORTED: the UI5 facade's generic selected-row hook returns no rows for
 * this widget, but spreadsheet actions do not use that hook. JExcelTrait builds their input by
 * filtering the renderer data with `jspreadsheet('getSelectedRows', true)`. A live SpreadSheetTest
 * run confirmed that a renderer selection reaches row-bound actions as their input record.
 */
class UI5DataSpreadSheetNode extends UI5DataTableNode
{
    private const JEXCEL_ID_SUFFIX = '_jexcel';
    private ?string $spreadsheetElementId = null;
    private ?string $loadedRowCountSourceId = null;

    /**
     * Resolves the widget model from the facade id while getElementId() keeps returning the DOM id.
     *
     * WHY THIS OVERRIDE: UI5's HTML control and DOM helpers use the inner `_jexcel` id, while the
     * page model stores the widget under the base id. Normalising only at the model boundary keeps
     * both contracts intact.
     *
     * @return WidgetInterface
     */
    public function getWidget(): WidgetInterface
    {
        if ($this->widget === null) {
            $this->widget = $this->getWidgetFromElementId($this->getFacadeElementId());
        }
        return $this->widget;
    }

    /**
     * Returns the concrete jExcel container id instead of a surrounding UI5 page wrapper.
     *
     * WHY THIS OVERRIDE: live acceptance proved the inherited `.sapUiTable` lookup resolves the
     * DynamicPage wrapper on this renderer, so both renderer API candidates collapse to the wrong
     * id. The spreadsheet container is the element that owns both `table.jexcel` and `_jexcel` id.
     *
     * @return string
     */
    public function getElementId(): string
    {
        if ($this->spreadsheetElementId !== null) {
            return $this->spreadsheetElementId;
        }
        $root = $this->getNodeElement();
        $container = $root->hasClass('exf-spreadsheet-container')
            ? $root
            : $root->find('css', '.exf-spreadsheet-container');
        $id = $container?->getAttribute('id');
        if ($id === null || $id === '') {
            throw new RuntimeException('Cannot find the DataSpreadSheet grid container id.');
        }
        $this->spreadsheetElementId = $id;
        return $this->spreadsheetElementId;
    }

    /**
     * Exposes spreadsheet rows through the same node wrappers used by inherited row checks.
     *
     * @return DataColumnNode[]
     */
    public function getRowNodes(): array
    {
        $nodes = [];
        foreach ($this->getTableRows() as $row) {
            $nodes[] = new DataColumnNode($row, $this->getSession(), $this->getBrowser());
        }
        return $nodes;
    }

    /**
     * Exposes jExcel headers in their coordinate order so inherited column checks stay reusable.
     *
     * @return UI5HeaderColumnNode[]
     */
    public function getHeaderColumnNodes(): array
    {
        $nodes = [];
        foreach ($this->getSpreadsheetHeaderCells() as $cell) {
            $nodes[] = new UI5HeaderColumnNode($cell, $this->getSession(), $this->getBrowser());
        }
        return $nodes;
    }

    /**
     * Maps jExcel's data-x coordinates to the descriptor contract used by table filtering.
     *
     * @return array<int, array{caption: string, index: int, colId: string|null, visible: bool}>
     */
    protected function getRenderedColumns(): array
    {
        $columns = [];
        foreach ($this->getSpreadsheetHeaderCells() as $header) {
            $columnIndex = $header->getAttribute('data-x');
            if ($columnIndex === null || $columnIndex === '') {
                throw new RuntimeException('DataSpreadSheet header has no data-x column coordinate.');
            }
            $columns[] = [
                'caption' => trim($header->getText()),
                'index' => (int) $columnIndex,
                'colId' => $columnIndex,
                'visible' => $header->isVisible(),
            ];
        }
        return $columns;
    }

    /**
    * Returns the jExcel body rows currently exposed as rendered elements.
     *
    * WHY THIS METHOD DOES NOT JUDGE THE COUNT: the renderer API is authoritative for how many
    * business rows were loaded. This DOM projection only exposes whichever row elements exist,
    * including an empty list, for inherited checks that need rendered cells.
     *
     * @return NodeElement[]
     */
    public function getTableRows(): array
    {
        return $this->getSpreadsheetTable()->findAll('css', 'tbody > tr');
    }

    /**
     * Counts server-loaded rows instead of jExcel's optional editable spare row.
     *
     * WHY THE DOM COUNT IS WRONG: when adding rows is enabled, jExcel appends a blank spare row
     * that is not part of the loaded result. Reporting it would turn an empty result into one row
     * and make filter verification inspect editor scaffolding as business data.
     *
     * @return int
     */
    public function getLoadedRowCount(): int
    {
        $count = $this->evaluateRendererScript(
            'return element.exfWidget.getDataLastLoaded().length;',
            'read loaded row count'
        );
        if (! is_numeric($count)) {
            throw new RuntimeException('DataSpreadSheet renderer returned a non-numeric loaded row count.');
        }
        $this->loadedRowCountSourceId = $this->getElementId();
        return (int) $count;
    }

    /**
     * Makes the renderer API endpoint visible in the existing filter substep log.
     *
     * @return string|null
     */
    protected function getLoadedRowCountDiagnostic(): ?string
    {
        return $this->loadedRowCountSourceId === null
            ? null
            : 'DataSpreadSheet renderer API answered at `' . $this->loadedRowCountSourceId . '`';
    }

    /**
     * Keeps inherited verification in the spreadsheet's single-table row coordinate space.
     *
     * @return NodeElement[]
     */
    protected function getAllTableRows(): array
    {
        $loadedRowCount = $this->getLoadedRowCount();
        if ($loadedRowCount < 1) {
            return [];
        }
        $rows = $this->getTableRows();
        if (count($rows) < $loadedRowCount) {
            throw new RuntimeException(
                'DataSpreadSheet reports ' . $loadedRowCount . ' loaded rows, but only ' . count($rows) . ' are rendered.'
            );
        }
        return array_slice($rows, 0, $loadedRowCount);
    }

    /**
     * Adds a row to the current contiguous jSpreadsheet selection.
     *
     * WHY THE RENDERER API: row-bound spreadsheet actions read `getSelectedRows(true)`, so DOM
     * classes or a synthetic cell click are not authoritative. Updating the renderer selection
     * also makes the subsequent overflow action receive the selected record.
        *
        * WHY THIS NARROWS THE INHERITED CONTRACT: one rectangular selection cannot represent
        * non-contiguous rows, so such additions fail explicitly instead of selecting intervening
        * records. The overflow popover need not be closed because no underlying DOM click occurs;
        * selection is applied directly through the renderer API.
     *
     * @param int $rowNumber
     * @return void
     */
    public function selectRow(int $rowNumber): void
    {
        $selectedRows = $this->getSelectedRowNumbers();
        if (in_array($rowNumber, $selectedRows, true)) {
            return;
        }
        $selectedRows[] = $rowNumber;
        sort($selectedRows);
        $this->applyRowSelection($selectedRows);
    }

    /**
     * Refuses a state flip that this renderer cannot express safely through this low-level hook.
     *
     * WHY THIS DOES NOT CLICK A CELL: editable cells can enter edit mode without establishing the
    * renderer selection consumed by actions. Callers must state the complete desired rectangle
    * through selectRow() or ensureExactlySelectedRows() instead of relying on click semantics.
     *
     * @param int $rowNumber
     * @return void
     */
    protected function toggleRowSelection(int $rowNumber): void
    {
        throw new RuntimeException(
            'DataSpreadSheet cannot toggle one row independently; set the desired contiguous selection explicitly.'
        );
    }

    /**
     * Replaces the current spreadsheet selection with the requested contiguous rows.
     *
     * WHY CONTIGUOUS ONLY: this jSpreadsheet version represents selection as one rectangle.
     * Pretending to support disjoint rows would silently include records between them in actions.
     *
     * @param int[] $rowNumbers
     * @return void
     */
    public function ensureExactlySelectedRows(array $rowNumbers): void
    {
        $this->applyRowSelection($rowNumbers);
    }

    /**
     * Walks rows with one renderer row-count and column-extent snapshot.
     *
     * WHY THIS OVERRIDE: the inherited walk applies one selection per row. Resolving renderer
     * bounds for every attempt multiplies CDP round-trips and can mix coordinates from different
     * renders; one snapshot keeps the complete walk in one coordinate space.
     *
     * @param callable $predicate
     * @return bool
     */
    public function selectEachRowUntil(callable $predicate): bool
    {
        $loadedRowCount = $this->getLoadedRowCount();
        if ($loadedRowCount < 1) {
            return false;
        }
        $lastColumnIndex = $this->getLastRenderedColumnIndex();
        for ($rowNumber = 1; $rowNumber <= $loadedRowCount; $rowNumber++) {
            $this->applyRowSelection([$rowNumber], $loadedRowCount, $lastColumnIndex);
            if ($predicate($rowNumber) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reads selected rows from the same renderer API used to build action input data.
     *
     * WHY NOT DOM CLASSES: jSpreadsheet selection styling is cell-based and can be restored after
     * blur, while actions consume the renderer's selected-row indexes directly.
     *
     * @return int[] 1-based row numbers
     */
    public function getSelectedRowNumbers(): array
    {
        $selectedIndexes = $this->evaluateRendererScript(
            "return spreadsheet.jspreadsheet('getSelectedRows', true);",
            'read selected rows'
        );
        if (! is_array($selectedIndexes)) {
            throw new RuntimeException(
                'DataSpreadSheet renderer returned invalid selected rows. Observed: '
                . $this->formatObservation([
                    'sourceId' => $this->getElementId(),
                    'returnedValue' => $selectedIndexes,
                ])
            );
        }
        return array_map(static fn($rowIndex) => (int) $rowIndex + 1, $selectedIndexes);
    }

    /**
     * Closes colour checks explicitly until jExcel colour semantics are implemented.
     *
     * @param string $columnCaption
     * @return array
     */
    public function getColumnCellColors(string $columnCaption): array
    {
        throw new RuntimeException('Column colour checks are not supported for DataSpreadSheet widgets.');
    }

    /**
     * Reads a jExcel cell by its data-x coordinate instead of sap.ui.table cell classes.
     *
     * @param NodeElement $row
     * @param int $columnIndex
     * @param string|null $colId jExcel data-x coordinate supplied by header resolution
     * @return string|null
     */
    public function extractCellValueFromRow(NodeElement $row, int $columnIndex, ?string $colId = null): ?string
    {
        $coordinate = $colId ?? (string) $columnIndex;
        $cell = $row->find('css', 'td[data-x="' . $coordinate . '"]');
        if ($cell === null) {
            throw new RuntimeException('Cannot find DataSpreadSheet cell at column coordinate ' . $coordinate . '.');
        }

        $checkbox = $cell->find('css', 'input[type="checkbox"]');
        if ($checkbox !== null) {
            return $checkbox->isChecked() ? '1' : '0';
        }

        $value = trim($cell->getText());
        return $value === '' ? null : $value;
    }

    /**
     * Reports which rendered cells contradict a column's read-only contract.
     *
     * WHY THIS BELONGS HERE: the read-only step used header and cell positions from raw jExcel
     * markup. Hidden columns and cloned headers make those positions diverge; resolving the caption
     * to the renderer's data-x coordinate keeps the check in the same coordinate space as reads.
     *
     * @param string $columnCaption
     * @return int[] 1-based row numbers whose cells are editable
     */
    public function getEditableRowNumbers(string $columnCaption): array
    {
        [$columnIndex, $colId] = $this->resolveRenderedColumn($columnCaption);
        if ($columnIndex === null) {
            throw new RuntimeException('Column `' . $columnCaption . '` not found in DataSpreadSheet.');
        }

        $editableRows = [];
        $coordinate = $colId ?? (string) $columnIndex;
        foreach ($this->getTableRows() as $rowIndex => $row) {
            $cell = $row->find('css', 'td[data-x="' . $coordinate . '"]');
            if ($cell === null) {
                throw new RuntimeException('Cannot find DataSpreadSheet cell at column coordinate ' . $coordinate . '.');
            }
            if (! $cell->hasClass('readonly')) {
                $editableRows[] = $rowIndex + 1;
            }
        }
        return $editableRows;
    }

    /**
     * Enters one value through the editor owned by a resolved spreadsheet cell.
     *
     * WHY THIS BELONGS HERE: activating editors and locating dropdowns are jExcel interactions,
     * not Behat step orchestration. Resolving both row and data-x inside this node also prevents a
     * fallback write from escaping into another spreadsheet on the same page.
     *
     * @param int|null $rowNumber 1-based row number, or null for the last rendered row
     * @param string $columnCaption
     * @param string $value
     * @return void
     */
    public function setCellValue(?int $rowNumber, string $columnCaption, string $value): void
    {
        [$columnIndex, $colId] = $this->resolveRenderedColumn($columnCaption);
        if ($columnIndex === null) {
            throw new RuntimeException('Column `' . $columnCaption . '` not found in DataSpreadSheet.');
        }

        $rows = $this->getTableRows();
        if ($rows === []) {
            throw new RuntimeException('No rows found in DataSpreadSheet.');
        }
        $rowIndex = $rowNumber === null ? count($rows) - 1 : $rowNumber - 1;
        if ($rowIndex < 0 || ! isset($rows[$rowIndex])) {
            throw new RuntimeException('Row `' . ($rowNumber ?? 'last') . '` not found in DataSpreadSheet.');
        }

        $coordinate = $colId ?? (string) $columnIndex;
        $cell = $rows[$rowIndex]->find('css', 'td[data-x="' . $coordinate . '"]');
        if ($cell === null) {
            throw new RuntimeException('Cannot find DataSpreadSheet cell at column coordinate ' . $coordinate . '.');
        }
        $cell->doubleClick();

        $editor = $cell->find('css', 'input, textarea, [contenteditable]');
        if ($editor !== null) {
            $editor->setValue($value);
            $this->getSession()->executeScript(<<<JS
var editor = document.activeElement;
if (editor) {
    editor.dispatchEvent(new Event('input', {bubbles: true}));
    editor.dispatchEvent(new Event('change', {bubbles: true}));
}
JS
            );
            $dropdownItem = $this->getSession()->getPage()->find(
                'xpath',
                "//div[contains(@class,'jdropdown') or contains(@class,'jexcel_dropdown')]//div[text()="
                . json_encode($value)
                . ']'
            );
            if ($dropdownItem !== null) {
                $dropdownItem->click();
            }
            return;
        }

        $elementIdJs = json_encode($this->getElementId());
        $coordinateJs = json_encode($coordinate);
        $valueJs = json_encode($value);
        $this->getSession()->executeScript(<<<JS
var spreadsheet = document.getElementById({$elementIdJs});
var row = spreadsheet ? spreadsheet.querySelectorAll('table.jexcel tbody > tr')[{$rowIndex}] : null;
var cell = row ? row.querySelector('td[data-x="' + {$coordinateJs} + '"]') : null;
if (cell) {
    cell.textContent = {$valueJs};
}
JS
        );
    }

    /**
     * Applies one rectangular row selection and persists it for the renderer's blur restoration.
     *
     * WHY `_exfSelection` IS UPDATED TOO: opening an overflow menu blurs the spreadsheet. The
     * renderer restores its cached coordinates on blur, so changing only the live selection can
     * revert to an older row immediately before the action reads its input.
     *
     * @param int[] $rowNumbers 1-based contiguous row numbers
     * @return void
     */
    private function applyRowSelection(
        array $rowNumbers,
        ?int $loadedRowCount = null,
        ?int $lastColumnIndex = null
    ): void
    {
        $rowNumbers = array_values(array_unique(array_map('intval', $rowNumbers)));
        sort($rowNumbers);
        if ($rowNumbers === []) {
            throw new RuntimeException('DataSpreadSheet row selection cannot be empty.');
        }
        $loadedRowCount = $loadedRowCount ?? $this->getLoadedRowCount();
        $firstRow = $rowNumbers[0];
        $lastRow = $rowNumbers[count($rowNumbers) - 1];
        if ($firstRow < 1 || $lastRow > $loadedRowCount) {
            throw new RuntimeException(
                'DataSpreadSheet row ' . $lastRow . ' not found. Only ' . $loadedRowCount . ' rows are loaded.'
            );
        }
        if ($rowNumbers !== range($firstRow, $lastRow)) {
            throw new RuntimeException('DataSpreadSheet supports only contiguous row selections.');
        }
        $lastColumnIndex = $lastColumnIndex ?? $this->getLastRenderedColumnIndex();
        $firstRowIndex = $firstRow - 1;
        $lastRowIndex = $lastRow - 1;
        $selectedIndexes = $this->evaluateRendererScript(<<<JS
    spreadsheet.jspreadsheet('updateSelectionFromCoords', 0, firstRowIndex, lastColumnIndex, lastRowIndex);
    spreadsheet.data('_exfSelection', {
        timeLastUpdated: new Date().getTime(),
        x1: 0,
        y1: firstRowIndex,
        x2: lastColumnIndex,
        y2: lastRowIndex
    });
    element.exfWidget.refreshConditionalProperties();
    return spreadsheet.jspreadsheet('getSelectedRows', true);
JS
            ,
            'apply row selection',
            compact('firstRowIndex', 'lastRowIndex', 'lastColumnIndex')
        );
        $expectedIndexes = range($firstRowIndex, $lastRowIndex);
        if (! is_array($selectedIndexes) || array_map('intval', $selectedIndexes) !== $expectedIndexes) {
            throw new RuntimeException(
                'DataSpreadSheet renderer did not retain the requested row selection. Observed: '
                . $this->formatObservation([
                    'sourceId' => $this->getElementId(),
                    'expectedIndexes' => $expectedIndexes,
                    'selectedIndexes' => $selectedIndexes,
                ])
            );
        }
    }

    /**
     * Returns the largest rendered data-x coordinate used by the rectangular selection API.
     *
     * WHY MAX INSTEAD OF COUNT: hidden or renderer-managed columns can leave coordinate gaps, so
     * a descriptor count minus one can stop the selection before the real last column.
     *
     * @return int
     */
    private function getLastRenderedColumnIndex(): int
    {
        $coordinates = array_column($this->getRenderedColumns(), 'index');
        if ($coordinates === []) {
            throw new RuntimeException('Cannot select a DataSpreadSheet row because no columns are rendered.');
        }
        return max($coordinates);
    }

    /**
     * Converts the inner renderer id to the UI5 control and widget-model id.
     *
     * WHY ONE NORMALIZER: model lookup, renderer candidates and busy-state lookup must agree on
     * where the `_jexcel` suffix ends instead of each stripping it independently.
     *
     * @return string
     */
    private function getFacadeElementId(): string
    {
        $elementId = $this->getElementId();
        return substr($elementId, -strlen(self::JEXCEL_ID_SUFFIX)) === self::JEXCEL_ID_SUFFIX
            ? substr($elementId, 0, -strlen(self::JEXCEL_ID_SUFFIX))
            : $elementId;
    }

    /**
     * Runs one operation against the cached renderer owner and reports its current API shape.
     *
     * WHY EVERY OPERATION RECHECKS: a UI5 rerender can replace the DOM element while retaining its
     * id. A failed script must reveal whether the element, plugin, exfWidget or required methods
     * disappeared instead of collapsing every cause into one generic message.
     *
     * @param string $operationBody JavaScript body with element and spreadsheet in scope
     * @param string $operationName Human-readable operation for failures
     * @param array<string, int> $arguments Integer JavaScript variables exposed to the operation
     * @return mixed
     */
    private function evaluateRendererScript(string $operationBody, string $operationName, array $arguments = []): mixed
    {
        $elementIdJs = json_encode($this->getElementId(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $argumentsJs = json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->getSession()->evaluateScript(<<<JS
(function(elementId, args) {
    var element = document.getElementById(elementId);
    var jQueryAvailable = typeof window.jQuery === 'function';
    var spreadsheet = element && jQueryAvailable ? window.jQuery(element) : null;
    var observation = {
        id: elementId,
        elementExists: !!element,
        jQueryAvailable: jQueryAvailable,
        pluginAvailable: !!spreadsheet && typeof spreadsheet.jspreadsheet === 'function',
        exfWidgetAvailable: !!element && !!element.exfWidget,
        getDataLastLoadedAvailable: !!element && !!element.exfWidget && typeof element.exfWidget.getDataLastLoaded === 'function',
        pluginMethodsUsed: ['getSelectedRows', 'updateSelectionFromCoords'],
        refreshConditionalPropertiesAvailable: !!element && !!element.exfWidget && typeof element.exfWidget.refreshConditionalProperties === 'function'
    };
    if (!observation.elementExists || !observation.pluginAvailable || !observation.getDataLastLoadedAvailable
        || !observation.refreshConditionalPropertiesAvailable) {
        return {ok: false, observation: observation};
    }
    var firstRowIndex = args.firstRowIndex;
    var lastRowIndex = args.lastRowIndex;
    var lastColumnIndex = args.lastColumnIndex;
    try {
        return {ok: true, value: (function() {
{$operationBody}
        })(), observation: observation};
    } catch (error) {
        observation.errorName = error && error.name ? error.name : null;
        observation.errorMessage = error && error.message ? error.message : String(error);
        return {ok: false, observation: observation};
    }
}({$elementIdJs}, {$argumentsJs}));
JS
        );
        if (! is_array($result) || ! ($result['ok'] ?? false)) {
            throw new RuntimeException(
                'Cannot ' . $operationName . ' through DataSpreadSheet renderer API. Observed: '
                . $this->formatObservation($result['observation'] ?? $result)
            );
        }
        return $result['value'] ?? null;
    }

    /**
     * Serializes browser observations without losing which candidate or API was missing.
     *
     * WHY JSON: the diagnostic contains nested candidate facts that a flat generic exception hid.
     *
     * @param mixed $observation
     * @return string
     */
    private function formatObservation(mixed $observation): string
    {
        $json = json_encode($observation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '(observation could not be encoded)' : $json;
    }

    /**
     * Locates the concrete jExcel table and turns a missing renderer into an explicit failure.
     *
     * @return NodeElement
     */
    private function getSpreadsheetTable(): NodeElement
    {
        $container = $this->getSession()->getPage()->findById($this->getElementId());
        if ($container === null) {
            throw new RuntimeException(
                'Cannot find DataSpreadSheet renderer container `' . $this->getElementId() . '` after UI5 rerender.'
            );
        }
        $table = $container->find('css', 'table.jexcel');
        if ($table === null) {
            throw new RuntimeException('Cannot find DataSpreadSheet jExcel grid.');
        }
        return $table;
    }

    /**
     * Returns only the main jExcel headers, excluding the cloned fixed-footer header.
     *
     * @return NodeElement[]
     */
    private function getSpreadsheetHeaderCells(): array
    {
        $headers = $this->getSpreadsheetTable()->findAll(
            'css',
            'thead:not(.footer) > tr:first-child > td[data-x]'
        );
        if ($headers === []) {
            throw new RuntimeException('Cannot find headers in DataSpreadSheet jExcel grid.');
        }
        usort($headers, static fn(NodeElement $left, NodeElement $right) =>
            (int) $left->getAttribute('data-x') <=> (int) $right->getAttribute('data-x')
        );
        return $headers;
    }
}