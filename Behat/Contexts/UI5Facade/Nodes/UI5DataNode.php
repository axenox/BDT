<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Common\ErrorManager;
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
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\DocsFacade;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataTypes\DataTypeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\ExpressionInterface;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\Model\UiScreenInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\Widgets\iFilterData;
use exface\Core\Interfaces\Widgets\iHaveButtons;
use exface\Core\Interfaces\Widgets\iHaveColumns;
use exface\Core\Interfaces\Widgets\iHaveFilters;
use exface\Core\Interfaces\Widgets\iShowData;
use exface\Core\Interfaces\Widgets\iSupportLazyLoading;
use exface\Core\Interfaces\Widgets\WidgetLinkInterface;
use exface\Core\Widgets\DataColumn;
use exface\Core\Widgets\DataList;
use exface\Core\Widgets\DataTable;
use exface\Core\Widgets\DataTree;
use exface\Core\Widgets\Filter;
use exface\Core\Widgets\InputComboTable;
use exface\Core\Widgets\InputSelect;
use exface\Core\Widgets\RangeFilter;
use PHPUnit\Framework\Assert;
use Throwable;

/**
 * @method DataTable getWidget()
 */
class UI5DataNode extends UI5AbstractNode
{

    use DateParsingTrait;

    /* @var $hiddenFilters Filter[] */
    private array $hiddenFilters = [];
    private DataTypeInterface $inputDataType;

    /**
     * Resolved master values for this detail's linked filters, keyed by "<filterId>|<boundary>".
     *
     * WHY THE BOUNDARY IS PART OF THE KEY: a RangeFilter carries two links (from/to) under one filter
     * id, so a filter-id-only key would let one boundary overwrite the other.
     */
    private array $resolvedLinkedFilterValues = [];

    private const MASTER_ROW_ATTEMPT_CAP = 10;
    // 100ms (not 50) halves the CDP round-trips per poll and is still safe: the detail read is
    // recorded on completion and the record is durable, so a wider interval only adds detection
    // latency (bounded by the timeout), never a miss - the +65/+114ms figures are the read/busy
    // START, not completion.
    private const DETAIL_LOAD_POLL_INTERVAL_MS = 100;
    private const DETAIL_LOAD_POLL_TIMEOUT_MS = 2000;

    public function capturesFocus(): bool
    {
        return false;
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
        $number = (int)str_replace('.', '', $ordinal);
        // Convert to zero-based index
        return $number - 1;
    }

    /**
     *
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $widget = $this->getWidget();
        $logbook->addLine($this->buildMessageLookingAt(true));
        Assert::assertNotNull($widget, 'DataTable widget not found for this node.');

        return $this->runAsSubstep(
            function (SubstepResult $result) use ($widget) {
                return $this->checkTableWorksAsExpected($widget, $result->getLogbook());
            },
            $this->buildMessageLookingAt(false),
            null,
            $logbook
        );
    }

    protected function buildMessageLookingAt(bool $markdown): string
    {
        $widget = $this->getWidget();
        $mainObject = $widget->getMetaObject();
        if (!empty($this->getCaption())) {
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

    public function getCaption(): string
    {
        $label = $this->getNodeElement()->getAttribute('aria-label');
        if ($label === null || $label === '') {
            return '';
        }
        $firstLine = strstr($label, "\n", true);
        return trim($firstLine === false ? $label : $firstLine);
    }

    public function getWidgetType(): ?string
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

    protected function checkTableWorksAsExpected(iShowData $dataWidget, LogBookInterface $logbook): TestResultInterface
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

    /**
     * Prepares linked masters before deciding whether this detail has enough data to test its filters.
     *
     * WHY PREPARATION COMES FIRST: a master-detail table can be empty by design until its master has
     * exactly one selected row. Judging that state first reports a false skip and never exercises the
     * detail filters; preparation also supplies the proven linked value used to scope source queries.
     *
     * @param iHaveFilters $dataWidget
     * @param LogBookInterface $logbook
     * @return TestResultInterface
     */
    protected function checkHeaderFiltersWorkAsExpected(iHaveFilters $dataWidget, LogBookInterface $logbook): TestResultInterface
    {
        $dependencies = $this->getLinkedFilterDependencies($dataWidget);
        $this->logLinkedFilterDependencies($dependencies, $logbook);
        $failed = false;
        $skippedFilters = [];
        $hasHeader = $this->hasHeader();
        if ($hasHeader) {
            $preparation = $this->prepareMasterSelection($dependencies, $dataWidget->getId(), $logbook);
            if ($preparation['status'] === 'skipped') {
                $reason = $preparation['reason'] ?? 'Master selection preparation was skipped';
                $logbook->addLine('Filtering skipped - ' . $reason);
                $this->logSubstep('Filtering skipped', StepStatusDataType::SKIPPED, $reason, static::CATEGORY_FILTERING);
                return SubstepResult::createSkipped($reason, $logbook);
            }
            if ($preparation['status'] === 'failed') {
                $reason = $preparation['reason'] ?? 'Master selection preparation failed';
                $logbook->addLine('Filtering failed - ' . $reason);
                $this->logSubstep('Filtering failed', StepStatusDataType::FAILED, $reason, static::CATEGORY_FILTERING);
                return SubstepResult::createFailed(null, $logbook);
            }
        }
        if ($hasHeader && null !== $initialStateSkipReason = $this->getFilterSkipReasonForInitialState($dataWidget)) {
            $logbook->addLine('Filtering skipped - ' . $initialStateSkipReason);
            $this->logSubstep('Filtering skipped', StepStatusDataType::SKIPPED, $initialStateSkipReason, static::CATEGORY_FILTERING);
            return SubstepResult::createSkipped($initialStateSkipReason, $logbook);
        }
        
        foreach ($dataWidget->getFilters() as $filter) {
            if ($filter->isHidden()) {
                // will be used as a filter to get a valid value
                $this->hiddenFilters[] = $filter;
                continue;
            }

            try {
                if (($hiddenIf = $filter->getHiddenIf()) !== null && $hiddenIf->evaluate()) {
                    $logbook->continueLine(' - skipped: hidden_if evaluates to true for the current user');
                    $skippedFilters['Conditionally hidden'][] = $filter->getCaption();
                    continue;
                }
                if (($disabledIf = $filter->getDisabledIf()) !== null && $disabledIf->evaluate()) {
                    $logbook->continueLine(' - skipped: disabled_if evaluates to true for the current user');
                    $skippedFilters['Conditionally disabled'][] = $filter->getCaption();
                    continue;
                }
            } catch (Throwable $e) {
                $logbook->continueLine(' - skipped: conditional visibility could not be evaluated: ' . $e->getMessage());
                $skippedFilters['Conditional visibility not evaluable'][] = $filter->getCaption();
                continue;
            }

            // TODO how need to test filter in the configurator dialog too!
            if (!$hasHeader) {
                $logbook->addLine('Skipping filter ' . $filter->getCaption() . ' - hidden headers not yet supported');
                $skippedFilters['Hidden headers not yet supported'][] = $filter->getCaption();
                continue;
            }

            // Decide up front whether this filter can be driven by a stored literal at all. The value is
            // supplied by getFilterValueAttribute() - for an InputComboTable that is the text attribute,
            // not the filter's relation - and that attribute is often a label built by a formula or an SQL
            // concatenation. Such a value is assembled at read time, so no literal exists that could be
            // typed into the filter and then be found again cell-by-cell in the table. This runs before
            // findColumnWithAttribute() so we do not scan the rendered headers, read the data source and
            // drive the whole filter round-trip only to throw the outcome away afterwards.
            $valueAttr = $this->getFilterValueAttribute($filter);
            if ($this->isCalculatedAttribute($valueAttr)) {
                $logbook->continueLine(' - skipped: `' . $valueAttr->getAliasWithRelationPath()
                    . '` is calculated (formula or SQL expression in its data address), no literal value to filter by');
                $skippedFilters['Filter not supported because it is a calculated attribute'][] = $filter->getCaption();
                continue;
            }

            $filterNode = $this->findFilterByCaption($filter->getCaption());
            $substepResult = $this->runAsSubstep(
                function (SubstepResult $result) use ($filter, $dataWidget, $filterNode) {
                    return $this->checkFilterWorksAsExpected($filter, $dataWidget, $filterNode, $result);
                },
                'Filtering `' . $filter->getCaption() . '`',
                static::CATEGORY_FILTERING,
                $logbook,
                null,
                $this->buildSubstepCoverageIdentity($dataWidget, $filter)
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

    /**
     * Detects every header filter of this data widget whose value is a live widget link to another
     * widget on the same page - the master-detail dependency that leaves the detail empty until a
     * row is selected in the master.
     *
     * WHY THE FILTER MODEL AND NOT A UXON SCAN: these links are not always written as "=master!col"
     * in the page. Dashboard::linkChildFilterToSource() creates them programmatically while the
     * widget tree is built, so only the model reports them reliably. Filter::getValueWidgetLink()
     * (and, for a RangeFilter, its from/to links) is the single source of truth. The WidgetLink
     * static cache (getLinksToWidget()/getLinksOnPage()) is deliberately NOT used - both have known
     * defects (empty result when a direct id bucket exists; a missing key guard).
     *
     * WHY IT NEVER THROWS: this is observational detection. A model that cannot resolve a link must
     * never turn a filter check into an error, so every read is guarded and a failure is recorded as
     * an entry carrying an "error" key instead of propagating.
     *
     * @param iHaveFilters $dataWidget
     * @return array<int,array<string,mixed>>
     */
    protected function getLinkedFilterDependencies(iHaveFilters $dataWidget): array
    {
        $dependencies = [];
        // The detail's own UI screen, resolved once from the widget that owns the filters - a master
        // is a live selection dependency only when it sits on the same screen (see logging); a link
        // into the page behind a dialog is prefill, not a selection.
        $detailScreenKey = $this->getScreenKey($this->resolveUiScreen($dataWidget));
        foreach ($dataWidget->getFilters() as $filter) {
            // RangeFilter extends Filter, so this guard keeps range filters in - only non-Filter
            // iFilterData implementations (which expose no value link) are dropped.
            if (! $filter instanceof Filter) {
                continue;
            }
            $boundaries = [];
            try {
                if ($filter instanceof RangeFilter) {
                    // A RangeFilter has no single value link - each boundary carries its own.
                    $boundaries['from'] = [$filter->getValueFromWidgetLink(), $filter->getValueFromExpression()];
                    $boundaries['to'] = [$filter->getValueToWidgetLink(), $filter->getValueToExpression()];
                } else {
                    $boundaries[''] = [$filter->getValueWidgetLink(), $filter->getValueExpression()];
                }
            } catch (Throwable $e) {
                $dependencies[] = [
                    'filter' => $filter,
                    'caption' => $filter->getCaption(),
                    'error' => $e->getMessage(),
                ];
                continue;
            }
            foreach ($boundaries as $boundary => $pair) {
                [$link, $expr] = $pair;
                // Value::getValueWidgetLink() caches FALSE for non-references; guard against that
                // bool leaking out - isOnlyIfNotEmpty() on a bool would be a fatal Error on every
                // table. A plain null check would let FALSE through.
                if (! $link instanceof WidgetLinkInterface) {
                    continue;
                }
                $entry = [
                    'filter' => $filter,
                    'caption' => $filter->getCaption(),
                    'attribute_alias' => $filter->getAttributeAlias(),
                    'boundary' => $boundary,
                    'hidden' => $filter->isHidden(),
                    'optional' => $link->isOnlyIfNotEmpty(),
                    'magic_ref' => $this->detectMagicReference($expr),
                    'target_widget_id' => $link->getTargetWidgetId(),
                    'target_column_id' => $link->getTargetColumnId(),
                    'target_widget_type' => null,
                    'target_page_alias' => null,
                    'same_screen' => null,
                ];
                // Resolving the target widget/page can throw for a broken or cross-page link; keep
                // the cheap id/column data above and note the resolution failure without aborting.
                try {
                    $entry['target_page_alias'] = $link->getTargetPage()->getAliasWithNamespace();
                    $targetWidget = $link->getTargetWidget();
                    $entry['target_widget_type'] = $targetWidget->getWidgetType();
                    $entry['target_widget'] = $targetWidget;
                    // Compare by a page-alias + container-id key, not object identity: getTargetWidget()
                    // may load the target page as a separate instance, so === would read "different
                    // screen" for widgets that are in fact on the same one.
                    $targetScreenKey = $this->getScreenKey($this->resolveUiScreen($targetWidget));
                    $entry['same_screen'] = ($detailScreenKey !== null && $targetScreenKey !== null)
                        ? ($detailScreenKey === $targetScreenKey)
                        : null;
                } catch (Throwable $e) {
                    $entry['error'] = $e->getMessage();
                }
                $dependencies[] = $entry;
            }
        }
        return $dependencies;
    }

    /**
     * Resolves the UI screen (page, dialog or popup) a widget belongs to.
     *
     * WHY IT MIRRORS SubstepCoverageIdentity: "same screen" must be decided the same way coverage
    * decides it - a widget that is itself a screen answers for itself, otherwise the widget's
    * screen API resolves its page, dialog or popup container.
     *
     * @param WidgetInterface $widget
     * @return UiScreenInterface|null
     */
    protected function resolveUiScreen(WidgetInterface $widget): ?UiScreenInterface
    {
        if ($widget instanceof UiScreenInterface) {
            return $widget;
        }
        return $widget->getUiScreen();
    }

    /**
     * Builds a stable identity key for a UI screen: the page alias plus, for a dialog/popup, its
     * container widget id.
     *
     * WHY A KEY AND NOT OBJECT IDENTITY: WidgetLink::getTargetWidget() can load the target page as a
     * separate instance, so two widgets on the same screen would be different objects and === would
     * wrongly report different screens - which would make the later preparation reject every master.
     *
     * @param UiScreenInterface|null $screen
     * @return string|null
     */
    protected function getScreenKey(?UiScreenInterface $screen): ?string
    {
        if ($screen === null) {
            return null;
        }
        // A dialog/popup screen is itself a widget; a page screen is a UiPageInterface, not a widget.
        if ($screen instanceof WidgetInterface) {
            return $screen->getPage()->getAliasWithNamespace() . '|' . $screen->getId();
        }
        if ($screen instanceof UiPageInterface) {
            return $screen->getAliasWithNamespace() . '|';
        }
        return null;
    }

    /**
     * Resolves the master node for a detected dependency and decides whether v1 can prepare it.
     *
     * WHY A DOM-RENDERED MASTER IS REQUIRED, NOT JUST A MODEL MATCH: a widget can exist in the page
     * model yet not be on screen (a tab not shown, a role-hidden panel). A row cannot be selected in
     * a master that is not rendered, so a model-only match is rejected here instead of failing later.
     *
     * WHY ONLY EXACTLY DataTable IN v1: DataList (sap.m.List) and DataTree (sap.ui.table.TreeTable)
     * extend DataTable but render rows differently, and UI5DataTableNode's selection affordances are
     * only verified for sap.ui.table.Table / sap.m.Table. Charts and inputs are not row-selectable.
     * Everything rejected here keeps today's behaviour - the detail is tested unprepared.
     *
     * @param array<string,mixed> $dep One entry from getLinkedFilterDependencies().
     * @return array{node: UI5DataTableNode|null, reason: string|null}
     */
    protected function resolveSupportedMasterNode(array $dep): array
    {
        if (isset($dep['error'])) {
            return ['node' => null, 'reason' => 'target widget not resolvable: ' . $dep['error']];
        }
        if (($dep['same_screen'] ?? null) !== true) {
            $where = ($dep['same_screen'] ?? null) === false ? 'a different UI screen' : 'an undetermined UI screen';
            return ['node' => null, 'reason' => 'master is on ' . $where . ' - the link is prefill, not a live selection'];
        }
        if (($dep['magic_ref'] ?? null) !== null) {
            return ['node' => null, 'reason' => 'magic reference ' . $dep['magic_ref'] . ' - resolves by context (prefill/input), not a page selection'];
        }
        $master = $dep['target_widget'] ?? null;
        if (! $master instanceof WidgetInterface) {
            return ['node' => null, 'reason' => 'master widget instance not available'];
        }
        if ($master === $this->getWidget()) {
            return ['node' => null, 'reason' => 'self-reference - the filter links to its own data widget'];
        }
        if ($master instanceof DataList || $master instanceof DataTree) {
            return ['node' => null, 'reason' => 'master is a ' . $master->getWidgetType() . ' (DataTable subclass) - row selection not verified in v1'];
        }
        if (! $master instanceof DataTable) {
            return ['node' => null, 'reason' => 'master is a ' . $master->getWidgetType() . ' - only DataTable masters are prepared in v1'];
        }
        // Model match confirmed; require the master to be on screen before it can be prepared.
        $nodeId = $this->getBrowser()->getElementIdFromWidget($master);
        $domNode = ! empty($nodeId) ? $this->getSession()->getPage()->findById($nodeId) : null;
        if ($domNode === null) {
            return ['node' => null, 'reason' => 'master widget is in the model but not rendered in the DOM (id "' . $nodeId . '")'];
        }
        $node = UI5FacadeNodeFactory::createFromNodeElement($domNode, $this->getSession(), $this->getBrowser(), $master);
        if (! $node instanceof UI5DataTableNode) {
            return ['node' => null, 'reason' => 'resolved node is a ' . get_class($node) . ', not a data table node'];
        }
        return ['node' => $node, 'reason' => null];
    }

    /**
     * Returns the magic reference token (~self/~parent/~input/~data) used by a value expression, or
     * null for an explicit widget id.
     *
     * WHY IT MATTERS: a magic link such as "=~input!UID" on a dialog table resolves to a widget on
     * the page behind the dialog - that is prefill, not a live master selection, and the later
     * preparation must not treat it as one. The resolved WidgetLink no longer shows the magic form
     * (setWidgetId() has already replaced it with the concrete id), so the raw expression string is
     * the only place this is still visible.
     *
     * @param ExpressionInterface|null $expr
     * @return string|null
     */
    protected function detectMagicReference(?ExpressionInterface $expr): ?string
    {
        if ($expr === null) {
            return null;
        }
        $raw = (string) $expr;
        foreach ([
            WidgetLinkInterface::REF_SELF,
            WidgetLinkInterface::REF_PARENT,
            WidgetLinkInterface::REF_INPUT,
            WidgetLinkInterface::REF_DATA,
        ] as $ref) {
            if (strpos($raw, $ref) !== false) {
                return $ref;
            }
        }
        return null;
    }

    /**
     * Writes the detected master-detail filter dependencies to the logbook.
     *
    * WHY ONE OUTER GUARD: this runs at the start of the filter phase of EVERY data widget in the
    * suite. An uncaught logging or classification failure here would fail every filter phase, so it
    * is downgraded to a single logbook line plus an ErrorManager entry (same pattern as
    * buildSubstepCoverageIdentity()). The per-link inner guards stay for individual broken links.
     *
     * WHY LOGBOOK ONLY, NOT logSubstep(): an informational substep is persisted as a PASSED
     * run_step row and would inflate the pass count. This output exists to reveal, on real pages,
     * which detail widgets depend on a master selection before any selection behaviour is added, so
     * it must stay in the free-text log.
     *
     * @param array<int,array<string,mixed>> $dependencies
     * @param LogBookInterface $logbook
     * @return void
     */
    protected function logLinkedFilterDependencies(array $dependencies, LogBookInterface $logbook): void
    {
        try {
            if (empty($dependencies)) {
                return;
            }
            $logbook->addLine('Detected ' . count($dependencies) . ' linked filter dependency(ies) - this data widget is filtered by another widget on the page:');
            $logbook->addIndent(1);
            try {
                foreach ($dependencies as $dep) {
                    // A boundary-read failure produced only a caption + error and no target id.
                    if (isset($dep['error']) && ! isset($dep['target_widget_id'])) {
                        $logbook->addLine('filter `' . ($dep['caption'] ?? '?') . '`: widget link not resolvable - ' . $dep['error']);
                        continue;
                    }
                    $boundary = ($dep['boundary'] ?? '') === '' ? '' : ' (' . $dep['boundary'] . ')';
                    $magic = $dep['magic_ref'] !== null ? ', magic ' . $dep['magic_ref'] : '';
                    $sameScreen = $dep['same_screen'] === null ? 'unknown' : ($dep['same_screen'] ? 'yes' : 'no');
                    $suffix = isset($dep['error']) ? ' - target unresolved: ' . $dep['error'] : '';
                    $logbook->addLine(sprintf(
                        'filter `%s`%s [%s%s%s]: driven by `%s!%s` (master `%s` on page `%s`, same screen: %s)%s',
                        $dep['caption'],
                        $boundary,
                        $dep['hidden'] ? 'hidden' : 'visible',
                        $dep['optional'] ? ', optional' : '',
                        $magic,
                        $dep['target_widget_id'],
                        $dep['target_column_id'] ?? '',
                        $dep['target_widget_type'] ?? 'unresolved',
                        $dep['target_page_alias'] ?? '?',
                        $sameScreen,
                        $suffix
                    ));
                    // Step 3: classify whether v1 can prepare this master (logging only, no behaviour change).
                    $logbook->addIndent(1);
                    try {
                        $classification = $this->resolveSupportedMasterNode($dep);
                        $logbook->addLine($classification['reason'] === null
                            ? 'master supported: DataTable rendered, ready for preparation'
                            : 'master not prepared: ' . $classification['reason']);
                    } catch (Throwable $e) {
                        $logbook->addLine('master classification failed: ' . $e->getMessage());
                    } finally {
                        $logbook->addIndent(-1);
                    }
                }
            } finally {
                $logbook->addIndent(-1);
            }
        } catch (Throwable $e) {
            $logbook->addLine('Could not log linked filter dependencies: ' . $e->getMessage());
            // logException() can itself throw when the monitor filegroup is full (see runAsSubstep()).
            try {
                ErrorManager::getInstance()->logException($e, $this->getBrowser()->getWorkbench());
            } catch (Throwable $ignored) {
            }
        }
    }

    /**
     * Selects a master row for every supported linked filter so this detail has data, and records the
     * resolved link values for the filter phase. Returns a tri-state so the caller can skip or fail.
     *
     * WHY TRI-STATE AND NOT A BOOLEAN: three outcomes must be told apart. OK = every supported master
     * is selected and this detail is populated. SKIPPED = a precondition the tester owns (empty master,
     * or no master row yields detail data within the cap) - the detail cannot be judged, but nothing is
     * broken. FAILED = the link itself is broken or a click was lost (the detail filtered by a value
     * that is not the selected master value) - a real defect that must surface, never a skip.
     *
    * The filter phase calls this only when a header exists and before judging the detail's initial
    * state. The button phase remains separate because it must re-prepare after filter reset and is
    * wired in a later step.
     *
        * @param array<int,array<string,mixed>> $dependencies
        * @param string $filterDataWidgetId Widget id of the model used to detect the dependencies.
     * @param LogBookInterface $logbook
     * @return array{status:string,reason:?string} status is 'ok', 'skipped' or 'failed'
     */
    protected function prepareMasterSelection(array $dependencies, string $filterDataWidgetId, LogBookInterface $logbook): array
    {
        // A stale value from a previously checked widget must never leak into this detail.
        $this->resolvedLinkedFilterValues = [];

        // v1 prepares only table-type details - they own getLoadedRowCount(); other detail kinds keep
        // today's behaviour (tested unprepared).
        if (! $this instanceof UI5DataTableNode) {
            return ['status' => 'ok', 'reason' => null];
        }

        if (empty($dependencies)) {
            return ['status' => 'ok', 'reason' => null];
        }

        // UI5DataElementTrait sends the ExFace widget id as params.element; the UI5 DOM/control id
        // is a different, view-prefixed value and cannot be used as the read-hook key.
        $detailRequestElementId = $this->getWidget()->getId();
        $logbook->addLine('Master-detail preparation ids: node widget `' . $detailRequestElementId
            . '`, filter data widget `' . $filterDataWidgetId . '`');
        foreach ($dependencies as $dep) {
            $master = $this->resolveSupportedMasterNode($dep);
            if ($master['node'] === null) {
                // Unsupported masters keep today's behaviour - the detail is tested unprepared.
                $logbook->addLine('Master not prepared for filter `' . ($dep['caption'] ?? '?') . '`: ' . $master['reason']);
                continue;
            }
            $result = $this->prepareOneMaster($dep, $master['node'], $detailRequestElementId, $logbook);
            if ($result['status'] !== 'ok') {
                // A skip or failure on one dependency stops preparation: this detail cannot be populated
                // consistently, so the caller must not proceed as if it were prepared.
                return $result;
            }
        }

        return ['status' => 'ok', 'reason' => null];
    }

    /**
     * Drives one master node until this detail shows rows for the selected row, then proves the link.
     *
     * WHY THE PROOF IS AN ASSERTION, NOT A ROW FILTER: "detail shows rows" decides which master row to
     * keep; whether the detail actually filtered by the selected master value decides whether the link
     * works. A value mismatch is therefore FAILED (broken link or lost click), never a reason to try
     * the next row and never a skip.
     *
     * @param array<string,mixed> $dep
     * @param UI5DataTableNode $master
        * @param string $detailRequestElementId ExFace widget id sent as the request's element parameter.
     * @param LogBookInterface $logbook
     * @return array{status:string,reason:?string}
     */
    private function prepareOneMaster(array $dep, UI5DataTableNode $master, string $detailRequestElementId, LogBookInterface $logbook): array
    {
        $this->installReadHook();
        $this->clearDetailReadRecord($detailRequestElementId);

        if ($master->getLoadedRowCount() < 1) {
            return ['status' => 'skipped', 'reason' => 'empty master `' . $dep['target_widget_id'] . '` - no row to select'];
        }

        $targetColumnId = $dep['target_column_id'] ?? '';
        $attributeAlias = $dep['attribute_alias'] ?? '';
        $filterKey = $this->buildResolvedValueKey($dep);
        $failure = null;

        $selectedIndices = [];
        $firstVisibleIndex = 0;
        try {
            // Reuse the model-side access that reads the linked value. A selection left by the
            // master's own button phase would make the same first-row selection a no-op, emit no
            // change event and leave the detail unread - exactly the container-traversal failure.
            $master->getSelectedRowRawValue($targetColumnId, $selectedIndices, $firstVisibleIndex, false);
        } catch (Throwable $e) {
            return ['status' => 'failed', 'reason' => 'could not read the master selection before preparing the detail: ' . $e->getMessage()];
        }
        $attemptLimit = min($master->getLoadedRowCount(), self::MASTER_ROW_ATTEMPT_CAP);
        $selectedRenderedRows = [];
        foreach ($selectedIndices as $selectedIndex) {
            $renderedRow = $selectedIndex - $firstVisibleIndex + 1;
            if ($renderedRow >= 1 && $renderedRow <= $attemptLimit) {
                $selectedRenderedRows[] = $renderedRow;
            }
        }
        $rowOrder = [];
        for ($rowNumber = 1; $rowNumber <= $attemptLimit; $rowNumber++) {
            if (! in_array($rowNumber, $selectedRenderedRows, true)) {
                $rowOrder[] = $rowNumber;
            }
        }
        foreach ($selectedRenderedRows as $selectedRow) {
            $rowOrder[] = $selectedRow;
        }
        $logbook->addLine('Master `' . $dep['target_widget_id'] . '` element `' . $master->getElementId()
            . '`, selected model indices before walk: [' . implode(', ', $selectedIndices)
            . '], rendered attempt order: [' . implode(', ', $rowOrder) . ']');
        if (empty($rowOrder) || count($selectedRenderedRows) === count($rowOrder)) {
            return ['status' => 'skipped', 'reason' => 'master `' . $dep['target_widget_id']
                . '` has no unselected row inside the rendered attempt window'];
        }

        $found = $master->selectEachRowUntil(function (int $rowNumber) use ($master, $dep, $targetColumnId, $attributeAlias, $filterKey, $detailRequestElementId, $logbook, &$failure): bool {
            $logbook->addLine('Master-detail preparation attempting rendered row ' . $rowNumber);
            $priorSeq = $this->getDetailReadSeq($detailRequestElementId);

            // Model-side value plus the exactly-one-selection assertion (throws for 0 or >1 selected),
            // which the DOM-based selection read cannot give reliably for a recycled table.
            try {
                $masterValue = $master->getSelectedRowRawValue($targetColumnId);
            } catch (Throwable $e) {
                $failure = $e->getMessage();
                return true; // stop the walk - this is a FAILED condition, not "try next row"
            }
            if ($masterValue === null) {
                return false;
            }

            $read = $this->pollDetailReadAfter($detailRequestElementId, $priorSeq);
            if ($read === null) {
                // No NEW read. This cannot prove the first attempt because preparation cleared the
                // record. It remains reachable on a later row with the same linked value as its
                // predecessor, where the facade may suppress an identical request; only that exact
                // last-value match is accepted.
                $read = $this->readDetailRecord($detailRequestElementId);
                $recordedElementIds = '[' . implode(', ', $this->getRecordedReadElementIds()) . ']';
                if ($read === null) {
                    $failure = 'the detail did not reload after selecting a master row within '
                        . self::DETAIL_LOAD_POLL_TIMEOUT_MS . 'ms and no read was recorded for element `'
                        . $detailRequestElementId . '` at all - recorded element ids: ' . $recordedElementIds;
                    return true;
                }
                $lastVals = $this->extractLinkedFilterValues($read['params'], $attributeAlias);
                if (count($lastVals) === 0) {
                    $failure = 'a detail read was recorded for element `' . $detailRequestElementId
                        . '` but it carries no condition on `' . $attributeAlias . '` - recorded element ids: '
                        . $recordedElementIds . '; broken link wiring';
                    return true;
                }
                if (! (count($lastVals) === 1 && (string) $lastVals[0] === (string) $masterValue)) {
                    $failure = 'the detail did not reload after selecting a master row within '
                        . self::DETAIL_LOAD_POLL_TIMEOUT_MS . 'ms and its last request filtered `' . $attributeAlias
                        . '` by "' . implode('","', $lastVals) . '" rather than the selected "'
                        . $masterValue . '" - recorded element ids: ' . $recordedElementIds
                        . '; lost click or broken apply_on_change';
                    return true;
                }
                // else: redundant-refresh skip - the detail is already scoped to this value.
            }

            $detailValues = $this->extractLinkedFilterValues($read['params'], $attributeAlias);
            if (count($detailValues) === 0) {
                $failure = 'the detail request carries no condition on `' . $attributeAlias . '` - cannot verify the link';
                return true;
            }
            if (count($detailValues) > 1) {
                // More than one condition on the same alias with differing values: a wrong-condition
                // match must not pass silently as the proof.
                $failure = 'the detail request carries ' . count($detailValues) . ' differing conditions on `'
                    . $attributeAlias . '` ("' . implode('","', $detailValues) . '") - cannot verify which reflects the master selection';
                return true;
            }
            if ((string) $detailValues[0] !== (string) $masterValue) {
                $failure = 'the detail filtered `' . $attributeAlias . '` by "' . $detailValues[0]
                    . '" but the selected master value is "' . $masterValue . '" - broken link or lost click';
                return true;
            }

            // Count only after pending operations settle, with one short retry: the model can be
            // replaced a beat before the DOM/model fully settles, and counting too early reads 0 and
            // would skip a perfectly good master row.
            $rowCount = $this->getDetailLoadedRowCountWithRetry();
            if ($rowCount > 0) {
                $this->resolvedLinkedFilterValues[$filterKey] = $masterValue;
                $logbook->addLine('Prepared detail via master `' . $dep['target_widget_id'] . '` row ' . $rowNumber
                    . ' (linked value "' . $masterValue . '", ' . $rowCount . ' detail rows)');
                return true;
            }
            return false; // value matches but this master row has no detail data - try the next one
        }, self::MASTER_ROW_ATTEMPT_CAP, $rowOrder);

        if ($failure !== null) {
            return ['status' => 'failed', 'reason' => $failure];
        }
        if (! $found) {
            return ['status' => 'skipped', 'reason' => 'no row in master `' . $dep['target_widget_id']
                . '` produced detail data within ' . self::MASTER_ROW_ATTEMPT_CAP . ' attempts'];
        }
        return ['status' => 'ok', 'reason' => null];
    }

    /**
     * Builds the resolved-value key for a dependency, distinguishing a RangeFilter's from/to links.
     *
     * @param array<string,mixed> $dep
     * @return string
     */
    private function buildResolvedValueKey(array $dep): string
    {
        $filterId = $dep['filter'] instanceof Filter ? $dep['filter']->getId() : '';
        return $filterId . '|' . ($dep['boundary'] ?? '');
    }

    /**
     * Installs the one-time XHR hook that records the last completed read per element id.
     *
     * WHY A PAGE HOOK: right after a master row click everything BDT polls is idle (the detail read
     * fires tens of ms later), so only the detail's own completed read is a reliable "loaded because of
     * this selection" signal - and its request parameters (POST body or GET URL) carry the linked
    * value the detail filtered by, which is the proof source. WHY IT IS SAFE: the guard makes repeat
    * installs a no-op, the wrapper is pure pass-through (always calls the original open/send, records
    * inside try/catch), and preparation deletes its detail-specific record because these globals can
    * survive SPA navigation.
     *
     * @return void
     */
    private function installReadHook(): void
    {
        $this->getFromJavascript(<<<JS
(function(){
    if (window.__bdtReadHookInstalled) { return; }
    window.__bdtReadHookInstalled = true;
    window.__bdtReadSeq = 0;
    window.__bdtLastReads = {};
    // Collect element/action and every "data..." field (the read carries them as nested form
    // parameters, e.g. data[filters][conditions][1][expression]=... , not one JSON "data" param).
    var fnCollect = function(sQuery, oOut){
        if (! sQuery) { return; }
        new URLSearchParams(sQuery).forEach(function(sVal, sKey){
            if (sKey === 'element' || sKey === 'action' || sKey === 'data' || sKey.indexOf('data[') === 0) {
                oOut[sKey] = sVal;
            }
        });
    };
    var fnRecord = function(oXhr){
        try {
            var oParams = {};
            // Reads go out as POST (params in body) or GET (params in the URL); merge both.
            var sUrlQuery = (oXhr.__bdtUrl && oXhr.__bdtUrl.indexOf('?') >= 0) ? oXhr.__bdtUrl.split('?').slice(1).join('?') : '';
            fnCollect(sUrlQuery, oParams);
            fnCollect(oXhr.__bdtBody, oParams);
            if (oParams['element'] && oParams['action']) {
                // Atomic single-object write so a poll never pairs a new seq with old params.
                window.__bdtLastReads[oParams['element']] = { seq: ++window.__bdtReadSeq, params: oParams };
            }
        } catch (e) { /* never break app AJAX */ }
    };
    var fnOrigOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url){
        try { this.__bdtUrl = (typeof url === 'string') ? url : String(url); } catch (e) {}
        return fnOrigOpen.apply(this, arguments);
    };
    var fnOrigSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body){
        try {
            this.__bdtBody = (typeof body === 'string') ? body : '';
            var oXhr = this;
            oXhr.addEventListener('readystatechange', function(){
                if (oXhr.readyState === 4) { fnRecord(oXhr); }
            });
        } catch (e) { /* never break app AJAX */ }
        return fnOrigSend.apply(this, arguments);
    };
})()
JS);
    }

    /**
     * Removes the captured read for one detail before a new master-selection preparation starts.
     *
     * WHY DELETE INSTEAD OF ONLY REMEMBERING THE SEQUENCE: the hook globals survive SPA navigation.
     * A record from the previous page can therefore carry a sequence that appears current and, when
     * no new request arrives, falsely prove that this detail reloaded for the selected master.
     *
    * @param string $elementId ExFace widget id sent as the request's element parameter.
     * @return void
     */
    private function clearDetailReadRecord(string $elementId): void
    {
        $idJs = json_encode($elementId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->getFromJavascript("(function(sEl){ var oReads = window.__bdtLastReads||{}; Object.keys(oReads).forEach(function(sKey){ if (sKey === sEl || sKey.slice(-('__' + sEl).length) === '__' + sEl) { delete oReads[sKey]; } }); })($idJs)");
    }

    /**
     * Reads the currently recorded read for an element id as ['seq'=>int,'params'=>array], or null.
     *
    * WHY THE SUFFIX FALLBACK: current UI5 requests use the exact ExFace widget id. Older or alternate
    * emitters may use a view-prefixed UI5 id, so an exact miss may match one key ending in "__" plus
    * the widget id. Multiple suffix matches are rejected because choosing one could prove the wrong
    * detail and create a false green.
    *
    * @param string $elementId ExFace widget id sent as the request's element parameter.
     * @return array{seq:int,params:array<string,string>}|null
     */
    private function readDetailRecord(string $elementId): ?array
    {
        $idJs = json_encode($elementId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $json = $this->getFromJavascript("(function(sEl){ var oReads = window.__bdtLastReads||{}, r = oReads[sEl]; if (!r) { var sSuffix = '__' + sEl, aKeys = Object.keys(oReads).filter(function(sKey){ return sKey.slice(-sSuffix.length) === sSuffix; }); r = aKeys.length === 1 ? oReads[aKeys[0]] : null; } return r ? JSON.stringify(r) : null; })($idJs)");
        if (! is_string($json) || $json === '') {
            return null;
        }
        $rec = json_decode($json, true);
        if (! is_array($rec) || ! isset($rec['seq'])) {
            return null;
        }
        return ['seq' => (int) $rec['seq'], 'params' => is_array($rec['params'] ?? null) ? $rec['params'] : []];
    }

    /**
     * Lists the element ids currently captured by the read hook for temporary live diagnostics.
     *
     * WHY THIS IS LOGGED ON TIMEOUT: it distinguishes a missing detail request from a lookup-key
     * mismatch without accepting a record from another widget and creating a false green.
     *
     * @return string[]
     */
    private function getRecordedReadElementIds(): array
    {
        $json = $this->getFromJavascript('JSON.stringify(Object.keys(window.__bdtLastReads||{}))');
        $keys = json_decode((string) $json, true);
        return is_array($keys) ? array_map('strval', $keys) : [];
    }

    /**
     * Returns the sequence number of the last completed read recorded for the given element id, or 0.
     *
     * @param string $elementId
     * @return int
     */
    private function getDetailReadSeq(string $elementId): int
    {
        $rec = $this->readDetailRecord($elementId);
        return $rec === null ? 0 : $rec['seq'];
    }

    /**
     * Polls until a read newer than $priorSeq is recorded for the element, or the timeout elapses.
     *
     * @param string $elementId
     * @param int $priorSeq
     * @return array{seq:int,params:array<string,string>}|null The newer read, or null on timeout.
     */
    private function pollDetailReadAfter(string $elementId, int $priorSeq): ?array
    {
        $deadline = microtime(true) + self::DETAIL_LOAD_POLL_TIMEOUT_MS / 1000;
        do {
            $rec = $this->readDetailRecord($elementId);
            if ($rec !== null && $rec['seq'] > $priorSeq) {
                return $rec;
            }
            usleep(self::DETAIL_LOAD_POLL_INTERVAL_MS * 1000);
        } while (microtime(true) < $deadline);
        return null;
    }

    /**
     * Counts this detail's loaded rows after pending operations settle, with one short retry.
     *
     * @return int
     */
    private function getDetailLoadedRowCountWithRetry(): int
    {
        // Only table-type details reach here (guarded in prepareMasterSelection); the instanceof also
        // narrows $this for getLoadedRowCount(), which is declared on UI5DataTableNode.
        if (! $this instanceof UI5DataTableNode) {
            return 0;
        }
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        $count = $this->getLoadedRowCount();
        if ($count > 0) {
            return $count;
        }
        usleep(self::DETAIL_LOAD_POLL_INTERVAL_MS * 1000);
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
        return $this->getLoadedRowCount();
    }

    /**
     * Returns the distinct values the detail filtered a given attribute by, from a recorded read's
     * flat form parameters.
     *
     * WHY A FLAT KEY LIST, NOT json_decode: the read request does not carry one JSON "data" param but
     * nested form fields, e.g. `data[filters][conditions][1][expression]=AngebotsAnfrage` and
     * `data[filters][conditions][1][value]=1463`. A condition's attribute lives under a key ending in
     * `[expression]` (or `[attribute_alias]`); its value is the sibling key with the same prefix and a
     * `[value]` suffix, which ties value to expression at any nesting depth.
     *
     * WHY DISTINCT VALUES, NOT THE FIRST: more than one condition can carry the same alias. Returning
     * the first would let a wrong-condition match pass silently as the proof; the caller instead fails
     * when several differing values are found.
     *
     * @param array<string,string> $params The recorded read parameters (element/action plus data...).
     * @param string $attributeAlias The detail filter's attribute alias.
     * @return string[] Distinct values, in first-seen order.
     */
    private function extractLinkedFilterValues(array $params, string $attributeAlias): array
    {
        if ($attributeAlias === '') {
            return [];
        }
        $values = [];
        foreach ($params as $key => $val) {
            foreach (['[expression]', '[attribute_alias]'] as $suffix) {
                if (substr($key, -strlen($suffix)) !== $suffix || (string) $val !== $attributeAlias) {
                    continue;
                }
                $siblingKey = substr($key, 0, -strlen($suffix)) . '[value]';
                $v = array_key_exists($siblingKey, $params) ? (string) $params[$siblingKey] : '';
                $values[$v] = $v; // keyed for distinctness
            }
        }
        return array_values($values);
    }

    /**
     * Returns a reason to skip every header filter of this widget, judged ONCE on the widget's
     * initial state before any filter is touched - or null if the filters can be tested.
     *
     * WHY A HOOK ON THE GENERIC DATA NODE: the filter loop lives here and is shared by every data
     * widget, but "does the widget show any data at all" can only be answered by nodes that know
     * how to count their rows (tables, spreadsheets). The generic node knows no such precondition
     * and therefore never skips.
     *
     * WHY ONLY ONCE, BEFORE THE LOOP: between two filter checks the widget is not reset via the
     * Reset button (that would cost one extra reload per filter) - only the filter value is
     * emptied, without a new search. The table therefore still displays the result of the
     * PREVIOUS filter when the next one starts, so a row count taken inside the loop would
     * describe that previous result. Every search, however, runs with only the current filter
     * on top of the initial state, which makes the initial state the correct reference.
     *
     * @param iHaveFilters $dataWidget
     * @return string|null
     */
    protected function getFilterSkipReasonForInitialState(iHaveFilters $dataWidget): ?string
    {
        return null;
    }

    protected function hasHeader(): bool
    {
        return $this->findFilterHeaderContainer() !== null;
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
     * Delegate the find method to the underlying node element
     *
     * @param $selector
     * @param $locator
     * @return NodeElement|false|mixed|null
     */
    public function find($selector, $locator)
    {
        $nodeElement = $this->getNodeElement();
        return $nodeElement->find($selector, $locator);
    }

    /**
     * checks the Header if it has filters
     */
    protected function hasFilters(NodeElement $container): bool
    {
        return $container->find('css', '.exfw-Filter, .exfw-RangeFilter') !== null;
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
            foreach ($container->findAll('css', '.exfw-Filter, .exfw-RangeFilter, .exf-spinner-filter, .exf-spinner-range') as $el) {
                if (!$el->isVisible()) {
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

    protected function checkFilterWorksAsExpected(iFilterData $filter, iShowData $dataWidget, UI5FilterNode $filterNode, SubstepResult $result): SubstepResult
    {
        $logbook = $result->getLogbook();
        return SubstepResult::createSkipped('No function defined for this widget `' . $this->getWidgetType() . '`', $logbook);
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
        if ($button === null) {
            // On a narrow toolbar even the standard buttons (Search, Reset) are moved into the
            // overflow popover, which would fail the assertion below although the button exists.
            $button = $this->findButtonInOverflowByCaption($buttonCaption, true);
        }
        Assert::assertNotNull($button, sprintf('Button %s was not found.', $buttonCaption));
        $this->getBrowser()->highlightWidget(
            $button,
            'Button',
            0
        );
        try {
            $button->click();
            $this->getBrowser()->clearWidgetHighlights();
        } catch (Throwable $e) {
            throw $e;
        }
    }

    protected function checkButtonsWorkAsExpected(iHaveButtons $dataWidget, LogBookInterface $logbook): TestResultInterface
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
                    function () use ($buttonNode, $logbook) {
                        return $buttonNode->checkWorksAsExpected($logbook);
                    },
                    'Clicking ' . $buttonWidget->getCaption(),
                    static::CATEGORY_BUTTONS,
                    $logbook,
                    null,
                    $this->buildSubstepCoverageIdentity($dataWidget, $buttonWidget, $buttonWidget->getAction())
                );

                // Say the buttons test is failed if at least one button fails
                if ($substepResult->isFailed()) {
                    $failed = true;
                }
            } else {
                $skippedButtons['Button cannot be enabled'][] = $buttonWidget->getCaption();
                $logbook->addLine('Skipping button ' . $buttonWidget->getCaption() . ' because there is no row to enable it');
            }

        }

        // Log a SKIPPED substep for every reason to skip buttons
        foreach ($skippedButtons as $reason => $buttons) {
            $this->logSubstep('Skipped buttons: ' . implode(', ', $buttons), StepStatusDataType::SKIPPED, $reason, static::CATEGORY_BUTTONS);
        }
        // Leave no popover behind for the next check of this scenario - see the button loop above.
        $this->closeOverflowMenuIfOpened();
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
     * @param WidgetInterface $buttonWidget
     * @return FacadeNodeInterface|null
     */
    protected function resolveButtonNode($buttonWidget): ?FacadeNodeInterface
    {
        $expectedId = $this->getBrowser()->getElementIdFromWidget($buttonWidget);
        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $buttonNodeElement = $this->getSession()->getPage()->findById($expectedId);
                if ($buttonNodeElement === null || !$buttonNodeElement->isVisible()) {
                    // Not in the toolbar - but "not in the toolbar" is not the same as "not there".
                    // UI5 moves the buttons that do not fit into the overflow popover, and before that
                    // popover is opened for the first time they are not rendered at all. Without this
                    // fallback every overflowed button is silently reported as "not visible in UI" and
                    // never tested - a false green that grows with every button added to a toolbar.
                    $buttonNodeElement = $this->findElementInOverflowById($expectedId);
                }
                if ($buttonNodeElement === null) {
                    return null;
                }
                $buttonNode = UI5FacadeNodeFactory::createFromWidgetType($buttonWidget->getWidgetType(), $buttonNodeElement, $this->getSession(), $this->getBrowser(), $buttonWidget);
                // Touch the element once so a stale handle surfaces here (inside the retry loop)
                // rather than later in checkWorksAsExpected()/checkDisabled()/click(). checkDisabled()
                // alone is NOT enough for this: it is a no-op stub for several node types (e.g.
                // UI5MenuButtonNode never reads the DOM there), so a handle that is about to go stale
                // - most commonly because an overflow popover is still settling its just-moved content
                // when it is grabbed - sailed straight through and only surfaced minutes later as a raw
                // "Tag matching xpath ... not found" deep inside the button's own check.
                $buttonNode->getNodeElement()->getAttribute('id');
                $buttonNode->checkDisabled();
                return $buttonNode;
            } catch (Throwable $e) {
                // Only retry stale-element races - re-throw anything else immediately.
                if (!$this->isStaleElementError($e) || $attempt >= $attempts) {
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
     * @param Throwable $e
     * @return bool
     */
    protected function isStaleElementError(Throwable $e): bool
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
    public function checkFiltersWorkAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        return $this->checkCategoryWorksAsExpected(
            function (iShowData $widget, LogBookInterface $lb) {
                if (!$widget instanceof iHaveFilters) {
                    $lb->addLine('Widget has no filters to check');
                    return SubstepResult::createPassed($lb);
                }
                return $this->checkHeaderFiltersWorkAsExpected($widget, $lb);
            },
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
    private function checkCategoryWorksAsExpected(callable $categoryCheck, LogBookInterface $logbook): TestResultInterface
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
    public function checkButtonsWorkAsExpectedOnly(LogBookInterface $logbook): TestResultInterface
    {
        return $this->checkCategoryWorksAsExpected(
            function (iShowData $widget, LogBookInterface $lb) {
                if (!$widget instanceof iHaveButtons) {
                    $lb->addLine('Widget has no buttons to check');
                    return SubstepResult::createPassed($lb);
                }
                return $this->checkButtonsWorkAsExpected($widget, $lb);
            },
            $logbook
        );
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
     * @param string $itemLabel Singular noun used in failure messages (e.g. "column").
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
     * Asserts that none of the listed captions occur in $actual.
     *
     * WHY IT SITS NEXT TO assertCaptionsDisplayedInOrder(): both the column and the filter
     * absence assertions need the identical message format, so they share one implementation
     * for the same reason the order check does.
     *
     * @param string[] $unexpected
     * @param string[] $actual
     * @param string $itemLabel Singular noun used in failure messages (e.g. "filter").
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
            return (float)str_replace(['.', ','], ['', '.'], $value);
        }
        // Anglo-Saxon: comma = thousands, dot = decimal
        if (preg_match('/^\d{1,3}(,\d{3})*(\.\d+)?$/', $value)) {
            return (float)str_replace(',', '', $value);
        }
        // Plain number: "42", "3.14", "-7,5"
        $plain = str_replace(',', '.', $value);
        return is_numeric($plain) ? (float)$plain : null;
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

    protected function getVisibleColumnIndex(DataColumn $column): ?int
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

    protected function triggerSearch(): void
    {
        $this->clickButtonByCaption('ACTION.READDATA.SEARCH');
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(false, true, true);
    }

    protected function getInputDataType(): DataTypeInterface
    {
        return $this->inputDataType;
    }

    protected function setInputDataType(DataTypeInterface $dataType): void
    {
        $this->inputDataType = $dataType;
    }

    /**
     * Tries to set a filter value and retries once with a fresh data-source value
     * if UI5 rejects the first attempt (valueState=Error or validation mismatch).
     *
     * Returns the accepted value on success, or null if no value could be set. For filters with
     * a value list (autosuggest, combo table, select) the returned value is the one the widget
     * really ended up with after picking an entry from the list - it may differ from the value
     * that was typed (e.g. the typed text was only a part of the selected entry). Callers should
     * verify the table content against the returned value, not against their own candidate.
     *
     * @param UI5FilterNode $filterNode
     * @param iFilterData $filter
     * @param MetaAttributeInterface $filterAttr
     * @param iShowData $dataWidget
     * @param LogBookInterface $logbook
     * @param DataColumn|null $column The column showing the filter attribute, if the caller
     * already found it - saves searching for it again here.
     * @return string|null
     */
    protected function trySetFilterValue(
        UI5FilterNode          $filterNode,
        iFilterData            $filter,
        MetaAttributeInterface $filterAttr,
        iShowData              $dataWidget,
        LogBookInterface       $logbook,
        ?DataColumn            $column = null
    ): ?string
    {
        $candidates = [];

        $col = $column ?? $this->findColumnWithAttribute($dataWidget, $filterAttr, $logbook);
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

                // Widgets with a value list (combo table, autosuggest, select) do not necessarily keep
                // the typed text: setValueVisible() picks a matching entry from the suggestion list and
                // the widget then shows the full text of that entry - e.g. typing `Mus` may end up as
                // `Mustermann GmbH`. Verifying the table against the typed text would fail in that case,
                // so take over whatever the widget really shows now.
                if ($filter->getInputWidget() instanceof iSupportLazyLoading) {
                    $selectedVal = $filterNode->getValueVisible();
                    if (is_string($selectedVal) && trim($selectedVal) !== '' && $selectedVal !== $val) {
                        $logbook->continueLine(' (selected `' . $selectedVal . '` from the list)');
                        return $selectedVal;
                    }
                }

                return $val;

            } catch (Throwable $e) {
                if ($filter->getInputWidget() instanceof iSupportLazyLoading) {
                    $currentVal = $filterNode->getValueVisible();
                    if (!empty($currentVal) && stripos($currentVal, $val) !== false) {
                        $logbook->continueLine(' (autosuggested to `' . $currentVal . '`)');
                        return $currentVal;
                    }
                }

                $logbook->continueLine(' value `' . $val . '` rejected');
                try {
                    $filterNode->setValueEmpty(false);
                } catch (Throwable $ignored) {
                }
            }
        }

        return null;
    }

    protected function findColumnWithAttribute(iHaveColumns $dataWidget, MetaAttributeInterface $attribute, LogBookInterface $logbook): ?DataColumn
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
        } else if (str_ends_with(strtolower($text), '__name')) {
            $text = substr($text, 0, -strlen('__name'));
        }

        return str_ends_with($text, $suffix);
    }

    protected function findValueInColumn(DataColumn $column, LogBookInterface $logbook): ?string
    {
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
            while (count($values) < $limit && $rowIndex < 100) {
                $val = $this->findValueInDataSourceQuery($comboTableObj, $textAttr, $textAttr->getAliasWithRelationPath(), $sort, $rowIndex);
                if ($val !== null && !in_array($val, $values, true)) {
                    if ($this->checkTheValueFromTable($metaObject, $textAttrAliasFromFilter, $val)) {
                        $values[] = $val;
                    }
                }
                $rowIndex++;
                if ($rowIndex > 100) {
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
            while (empty($values)) {
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
                if ($rowIndex > 100) {
                    break;
                }
            }
            return $values;
        }

        // if it is a relation find the label of the found uid
        $rel = $attr->getRelation();
        $rightObj = $rel->getRightObject();
        $returnColumn = RelationPath::join($attr->getName(), $rightObj->getLabelAttributeAlias());
        while (empty($values)) {
            $val = $this->findValueInDataSourceQuery($attr->getObject(), $attr, $returnColumn, $sort, $rowIndex);
            if ($val !== null && $this->checkTheValueFromTable($metaObject, $returnColumn, $val)) {
                $values[] = $val;
            }
            $rowIndex++;
            if ($rowIndex > 100) {
                break;
            }
        }
        return $values;

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
    protected function isCalculatedAttribute(MetaAttributeInterface $attr): bool
    {
        $dataAddress = $attr->getDataAddress();
        if (!is_string($dataAddress)) {
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
    protected function getFilterValueAttribute(iFilterData $filter): MetaAttributeInterface
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
        } catch (Throwable $e) {
            return null;
        }
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
    * A prepared widget link is the exception: prepareMasterSelection() has proved the exact raw value
    * sent by the detail request and stores it under the same filter-id/boundary key used here. Returning
    * that value scopes both callers to the selected master. An unresolved link still returns null, so
    * unsupported or skipped preparation preserves the previous behaviour instead of inventing an empty
    * filter value.
     */
    protected function getHiddenFilterValue(Filter $hiddenFilter): ?string
    {
        $value = $hiddenFilter->getValue();

        // Skip any non-literal value - both formulas ("=Now()") and widget links ("=OtherTable!Id").
        // detectCalculation() covers both (anything starting with a single "="), whereas detectFormula()
        // would only catch formulas and let widget-link references through.
        if (is_string($value) && Expression::detectCalculation($value)) {
            $resolvedKey = $this->buildResolvedValueKey([
                'filter' => $hiddenFilter,
                'boundary' => '',
            ]);
            if (array_key_exists($resolvedKey, $this->resolvedLinkedFilterValues)) {
                return (string) $this->resolvedLinkedFilterValues[$resolvedKey];
            }
            return null;
        }

        return $value;
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
        Filter                 $filterWidget,
        MetaObject             $metaObject
    ): ?array
    {
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
        $toVal = null;
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

}