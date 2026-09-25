<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Widgets\DataCards;

/**
 * @method DataCards getWidget()
 */
class UI5DataCardsNode extends UI5DataTableNode
{
    private const GRID_LIST_DOM_ID_SUFFIX = '-listUl';

    /**
     * Returns the UI5 control id of the GridList that owns card data and selection state.
     *
     * WHY THIS OVERRIDES THE TABLE LOOKUP: DataCards inherits DataTable behavior but renders a
     * sap.f.GridList, so the parent searches only for sap.ui.table and sap.m.Table controls and
     * throws before checkWorksAsExpected() can begin.
     *
     * WHY THE DOM SUFFIX IS REMOVED: UI5 renders the GridList's inner ul as `<control-id>-listUl`.
     * That DOM id is neither registered in sap.ui.getCore() nor present in the page widget model;
     * both use the base control id. Returning the generated ul id made widget lookup search for a
     * non-existent `DataCards-listUl` widget.
     *
     * @return string
     */
    public function getElementId(): string
    {
        $gridList = $this->getNodeElement()->find('css', 'ul.sapFGridListDefault');
        if ($gridList === null) {
            throw new RuntimeException('Cannot find the sap.f.GridList control inside DataCards.');
        }
        $elementId = (string) $gridList->getAttribute('id');
        if ($elementId === '') {
            throw new RuntimeException('The sap.f.GridList control inside DataCards has no element id.');
        }
        return str_ends_with($elementId, self::GRID_LIST_DOM_ID_SUFFIX)
            ? substr($elementId, 0, -strlen(self::GRID_LIST_DOM_ID_SUFFIX))
            : $elementId;
    }

    /**
     * Keeps DataCards in the same automated filter and button check as DataTable widgets.
     *
     * WHY AN EXPLICIT OVERRIDE: DataCards uses different row markup, but its widget contract still
     * exposes the same filters and row-bound buttons. The renderer-specific methods below adapt
     * that markup so delegating here runs the proven DataTable check instead of a reduced card-only
     * check that could silently miss those controls.
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        return parent::checkWorksAsExpected($logbook);
    }

    /**
     * Returns the loaded GridList items in the same 1-based order users see on screen.
     *
     * WHY THIS REPLACES TABLE ROW DISCOVERY: DataCards inherits the DataTable widget contract but
     * renders records as sap.m.CustomListItem elements, so the parent tr selectors always report an
     * empty widget and skip every row-bound check.
     *
     * @return NodeElement[]
     */
    public function getTableRows(): array
    {
        return $this->getNodeElement()->findAll(
            'css',
            'ul.sapFGridListDefault > li.sapMLIB:not(.sapMListNoData):not(.sapMGHLI)'
        );
    }

    /**
     * Exposes card terminology while retaining the parent's idempotent selection behavior.
     *
     * WHY A CARD-NAMED METHOD: Gherkin authors select an item, not a table row. Keeping that
     * vocabulary at the context boundary avoids leaking the internal DataTable inheritance into
     * scenarios while preserving one implementation of selection and read-back verification.
     *
     * @param int $itemNumber 1-based item number
     * @return void
     */
    public function selectItem(int $itemNumber): void
    {
        $this->selectRow($itemNumber);
    }

    /**
     * Reports selection using card terminology for the explicit Behat assertion.
     *
     * WHY THIS DELEGATES: GridList items use the same sapMLIBSelected and aria-selected markers
     * already read by the parent. A second detector would risk disagreeing with selectItem().
     *
     * @param int $itemNumber 1-based item number
     * @return bool
     */
    public function isItemSelected(int $itemNumber): bool
    {
        return $this->isRowSelected($itemNumber);
    }

    /**
     * Resolves the rendered card field index from the widget model because cards have no headers.
     *
     * WHY MODEL ORDER IS AUTHORITATIVE: UI5DataCards builds one field per configured column in
     * exactly that order. The inherited header lookup cannot work because GridList deliberately
     * renders no table header, which made automated filter verification fail before reading data.
     *
     * @param string $columnName
     * @return array{0: int|null, 1: null}
     */
    protected function resolveRenderedColumn(string $columnName): array
    {
        $columnName = trim($columnName);
        foreach ($this->getWidget()->getColumns() as $index => $column) {
            if (trim($column->getCaption()) === $columnName) {
                return [$index, null];
            }
        }
        return [null, null];
    }

    /**
     * Reads a configured field from one rendered card item.
     *
     * WHY THE CARD FLEX ITEMS ARE USED: DataCards renders every configured column as one direct
     * child of the exf-datacard VBox. Looking for table cells returns nothing and would make every
     * filter result appear empty even when the cards visibly contain the expected values.
     *
     * @param NodeElement $row
     * @param int $columnIndex
     * @param string|null $colId Unused because cards do not render table column ids.
     * @return string|null
     */
    public function extractCellValueFromRow(NodeElement $row, int $columnIndex, ?string $colId = null): ?string
    {
        $fields = $row->findAll('css', '.exf-datacard > .sapMFlexItem');
        $field = $fields[$columnIndex] ?? null;
        if ($field === null || ! $field->isVisible()) {
            return null;
        }
        $value = trim((string) $field->getText());
        return $value === '' ? null : $value;
    }

    /**
     * Chooses the actual GridList selection control for multi- and single-select cards.
     *
     * WHY THIS CANNOT USE TABLE AFFORDANCES: GridList selection controls live inside each li and
     * have no td selector cell. Multi-select must click its checkbox; single-select has no checkbox
     * and is selected by pressing the list item itself.
     *
     * @param NodeElement $row
     * @return array{target: NodeElement|null, description: string|null, explicit: bool, hidden: string[]}
     */
    protected function analyzeRowSelection(NodeElement $row): array
    {
        foreach (['.sapMLIBSelectM .sapMCb', '.sapMLIBSelectM'] as $selector) {
            $checkbox = $row->find('css', $selector);
            if ($checkbox === null) {
                continue;
            }
            if (! $checkbox->isVisible()) {
                return [
                    'target' => null,
                    'description' => null,
                    'explicit' => true,
                    'hidden' => ['sap.f.GridList multi-select checkbox'],
                ];
            }
            return [
                'target' => $checkbox,
                'description' => 'sap.f.GridList multi-select checkbox',
                'explicit' => true,
                'hidden' => [],
            ];
        }

        if ($row->isVisible()) {
            return [
                'target' => $row,
                'description' => 'sap.f.GridList item body',
                'explicit' => false,
                'hidden' => [],
            ];
        }

        return [
            'target' => null,
            'description' => null,
            'explicit' => false,
            'hidden' => ['sap.f.GridList item body'],
        ];
    }
}