<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use PHPUnit\Framework\Assert;

/**
 * @author Andrej Kabachnik
 * @method \exface\Core\Widgets\InputComboTable getWidget()
 */
class UI5InputComboTableNode extends UI5InputNode
{
    /**
     * Returns the visible label text for this input.
     *
     * The SAP UI5 form layout places the label in a sibling sapUiVltCell rather than
     * inside the InputComboTable's own DOM subtree. This method therefore walks up the
     * DOM to the enclosing sapUiVlt container and reads the first <bdi> label text found
     * there, which matches the pattern used by UI5FilterNode::getCaption().
     *
     * Returns an empty string when no label element can be found (e.g. standalone usage
     * outside a form layout).
     *
     * @return string Trimmed label text, or empty string if not found
     */
    public function getCaption(): string
    {
        $label = $this->getNodeElement()->find(
            'xpath',
            'ancestor::div[contains(@class,"sapUiVlt")]//span[contains(@class,"sapMLabel")]//bdi'
        );
        return $label !== null ? trim($label->getText()) : '';
    }
    
    public function getValueVisible()
    {
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true) {
            // sap.m.MultiInput does not write anything in the underlying <input> and does not return
            // anything in its own `getValue()` - we need to go through the tokens of the internal tokenizer
            // instead
            $delim = $widget->getMultiSelectTextDelimiter();
            $val = $this->getFromJavascript(<<<JS
            
            (function(oInput, sDelim){
                var aTokens = oInput.getTokens();
                var sVal = '';
                aTokens.forEach(function(oToken) {
                    sVal += (sVal === '' ? '' : sDelim) + oToken.getText();
                });
                return sVal; // Remove trailing delimiter
            })(sap.ui.getCore().byId('{$this->getElementId()}'), '{$delim}')
JS
            );
        } else {
            $val = parent::getValueVisible();
        }
        return $val;
    }

    /**
     * Types the given value and picks a matching entry from the suggestion list.
     *
     * The suggestions are loaded asynchronously, so the list is not there right after typing. This
     * method waits for the list to appear and then selects the entry matching the typed value best:
     * an exact match if there is one, otherwise the first suggestion. Without this selection the
     * combo table would keep the raw text and UI5 would mark the input as invalid.
     *
     * @param string $value
     * @param bool $validate
     * @return FacadeNodeInterface
     */
    public function setValueVisible($value, bool $validate = true): FacadeNodeInterface
    {
        parent::setValueVisible($value, false);
        $this->waitWhileBusy();

        if ($suggestionNode = $this->waitForSuggestion((string) $value)) {
            $suggestionNode->click();
            $this->waitWhileBusy(5);
        }

        // Check if UI5 marked the input as invalid (red border = valueState "Error")
        $this->checkValueStateNotError();
        
        if ($validate) {
            $this->checkValueEquals($value);
        }
        
        return $this;
    }

    /**
     * Waits for the suggestion list to be rendered and returns the entry to select - NULL if there
     * are no suggestions at all within the timeout.
     *
     * WHY THIS EXISTS: the suggestions are fetched from the server, so simply looking for the popup
     * right after typing often finds nothing yet and no entry gets selected at all.
     *
     * @param string $value
     * @param int|float $timeoutSeconds
     * @return NodeElement|null
     */
    protected function waitForSuggestion(string $value, int|float $timeoutSeconds = 10): ?NodeElement
    {
        $deadline = microtime(true) + $timeoutSeconds;
        do {
            if ($node = $this->findSuggestion($value)) {
                return $node;
            }
            usleep(250000);
        } while (microtime(true) < $deadline);
        return null;
    }

    /**
     * Returns the suggestion entry matching the given value best or NULL if the list is not (yet) there.
     *
     * Entries are matched in this order: a row with a cell equal to the value, a row containing the
     * value, the first row. The last two cases mean the widget will end up with a different text than
     * the one typed - that is intentional, as picking a real entry is better than leaving the input
     * with a value UI5 will reject.
     *
     * @param string $value
     * @return NodeElement|null
     */
    protected function findSuggestion(string $value): ?NodeElement
    {
        $rows = $this->findSuggestionNodes();
        if (empty($rows)) {
            return null;
        }

        $needle = $this->normalizeSuggestionText($value);
        $firstRow = null;
        $containingRow = null;
        foreach ($rows as $row) {
            try {
                if (! $row->isVisible()) {
                    continue;
                }
                $rowText = $this->normalizeSuggestionText($row->getText());
                $cellTexts = [];
                foreach ($row->findAll('css', 'td, .sapMSLITitle, .sapMSLIDiv') as $cell) {
                    $cellTexts[] = $this->normalizeSuggestionText($cell->getText());
                }
            } catch (\Throwable $e) {
                // The popup may re-render while we are reading it, making elements stale. Such rows
                // are simply skipped - the next polling cycle will see the re-rendered list.
                continue;
            }

            if ($rowText === '') {
                continue;
            }
            if ($firstRow === null) {
                $firstRow = $row;
            }
            if ($rowText === $needle || in_array($needle, $cellTexts, true)) {
                return $row;
            }
            if ($containingRow === null && $needle !== '' && mb_strpos($rowText, $needle) !== false) {
                $containingRow = $row;
            }
        }

        return $containingRow ?? $firstRow;
    }

    /**
     * Returns all DOM nodes representing selectable entries of the suggestion popup.
     *
     * Depending on the widget configuration UI5 renders the suggestions either as a table
     * (`-popup-table`) or as a list (`-popup-list`), so both are looked up here. Header rows and the
     * "no data" placeholder are excluded because they cannot be selected.
     *
     * @return NodeElement[]
     */
    protected function findSuggestionNodes(): array
    {
        $id = $this->getElementId();
        $page = $this->getBrowser()->getPage();
        $selectors = [
            // Attribute selectors instead of `#id` because UI5 element ids may contain characters
            // that would have to be escaped in a CSS id selector
            '[id="' . $id . '-popup-table"] tbody tr.sapMListTblRow',
            '[id="' . $id . '-popup-table"] tbody tr',
            '[id="' . $id . '-popup-list"] li.sapMSLI',
            '[id="' . $id . '-popup-list"] li'
        ];
        foreach ($selectors as $selector) {
            $nodes = $page->findAll('css', $selector);
            $nodes = array_values(array_filter($nodes, function (NodeElement $node) {
                $nodeId = (string) $node->getAttribute('id');
                if (str_ends_with($nodeId, '-nodata') || str_ends_with($nodeId, '-trigger')) {
                    return false;
                }
                return ! $node->hasClass('sapMListTblHeader')
                    && ! $node->hasClass('sapMListNoData')
                    && ! $node->hasClass('sapMGHLI');
            }));
            if (! empty($nodes)) {
                return $nodes;
            }
        }
        return [];
    }

    /**
     * Normalizes a text for comparing typed values with suggestion texts: trims, collapses
     * whitespace (UI5 separates table cells with tabs and line breaks) and lowercases.
     *
     * @param string|null $text
     * @return string
     */
    protected function normalizeSuggestionText(?string $text): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $text)));
    }

    public function setValueEmpty(bool $validate = true) : FacadeNodeInterface
    {
        parent::setValueEmpty(false);
        
        // Multi-select combos require special handling to clear all selected values, as they use tokens to display them
        $widget = $this->getWidget();
        if ($widget->getMultiSelect() === true) {
            $id = $this->getNodeElement()->getAttribute('id');
            $this->getSession()->executeScript("sap.ui.getCore().byId('$id')?.destroyTokens();");
        }
        
        if ($validate) {
            $this->checkValueEquals('');
        }
        
        return $this;
    }

    public function waitWhileBusy(int|float $timeoutSeconds = 10) : FacadeNodeInterface
    {
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, false, true);
        $this->getSession()->wait(
            $timeoutSeconds * 1000,
            <<<JS
            (function() {
                if (sap.ui.getCore().byId('{$this->getElementId()}') === undefined) {
                    return false;
                }
                return sap.ui.getCore().byId('{$this->getElementId()}').isBusy() === false;
            })()
JS
        );
        return $this;
    }

    /**
     * Asserts that the UI5 control does not have valueState "Error".
     *
     * SAP UI5 sets valueState to "Error" when the typed value does not match
     * any entry in the combo table (red border + tooltip message).
     *
     * @throws \PHPUnit\Framework\AssertionFailedError
     */
    private function checkValueStateNotError(): void
    {
        $elementId = $this->getElementId();
        $valueState = $this->getFromJavascript(<<<JS
        (function() {
            var control = sap.ui.getCore().byId('{$elementId}');
            return control ? control.getValueState() : null;
        })()
    JS);

        Assert::assertNotEquals(
            'Error',
            $valueState,
            "Input '{$this->getCaption()}': value was rejected by UI5 (valueState=Error). " .
            "The typed value does not exist in the combo table list."
        );
    }
}