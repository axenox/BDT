<?php
namespace axenox\BDT\Facades\MarkdownPrinters;

use axenox\BDT\BDTApp;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\BinaryDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\CommonLogic\Filesystem\DataSourceFileInfo;
use exface\Core\Interfaces\Filesystem\FileInfoInterface;
use exface\Core\Contexts\DebugContext;
use exface\Core\Facades\HttpFileServerFacade;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\AbstractMarkdownPrinter;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Facades\MarkdownPrinterInterface;
use exface\Core\Interfaces\WorkbenchInterface;

abstract class AbstractBdtMarkdownPrinter extends AbstractMarkdownPrinter implements MarkdownPrinterInterface
{
    private const TAG_SEARCH_PAGE_SIZE = 100;
    protected WorkbenchInterface $workbench;
    private string $tagOrUid;
    private ?array $reportData = null;

    /**
     * Creates a printer for one BDT run UID or scenario tag.
     *
     * @param WorkbenchInterface $workbench
     * @param string $tagOrUid
     */
    public function __construct(WorkbenchInterface $workbench, string $tagOrUid)
    {
        $this->workbench = $workbench;
        $this->tagOrUid = trim($tagOrUid);
        if ($this->tagOrUid === '') {
            throw new \InvalidArgumentException('A BDT run UID or scenario tag is required.');
        }
    }

    /**
     * Resolves a BDT run by UID or scenario tag and renders its full run tree.
     *
     * A tag resolves to the newest run containing an exact scenario tag match.
     *
     * @return string
     */
    protected function getRunMarkdown(): string
    {
        [$run, $scenarios, $steps] = $this->getReportData();
        return $this->renderRun($run, $scenarios, $steps);
    }

    /**
     * Returns unique screenshot files directly usable as GenAI message or tool-result attachments.
     * File-object access preserves existing data-source authorization; missing files fail explicitly.
     *
     * @return FileInfoInterface[]
     */
    public function getFiles(): array
    {
        [, , $steps] = $this->getReportData();
        $files = [];
        foreach ($steps as $step) {
            $path = (string) ($step['screenshot_path'] ?? '');
            if (trim($path) === '') {
                continue;
            }
            $path = $this->normalizeScreenshotPath($path);
            if (isset($files[$path])) {
                continue;
            }
            $file = DataSourceFileInfo::fromObjectSelectorAndUID(
                $this->workbench,
                'axenox.BDT.run_step_screenshot',
                $path
            );
            if (!$file->exists()) {
                throw new \OutOfBoundsException('Screenshot file not found: ' . $path);
            }
            $files[$path] = $file;
        }
        return array_values($files);
    }

    /**
     * Loads one snapshot so Markdown and attachments resolve the same run even for a tag selector.
     */
    private function getReportData(): array
    {
        if ($this->reportData !== null) {
            return $this->reportData;
        }
        $tagOrUid = $this->tagOrUid;

        if ($this->isRunUid($tagOrUid)) {
            $runUid = strtolower($tagOrUid);
        } else {
            $tag = $this->normalizeTag($tagOrUid);
            $runUid = $this->findLatestRunUidByTag($tag);
        }

        $run = $this->readRun($runUid);
        if ($run === null) {
            throw new \OutOfBoundsException('No BDT test run found for "' . $tagOrUid . '".');
        }

        $scenarios = $this->readScenarios($runUid);
        $steps = $this->readSteps($runUid);

        return $this->reportData = [$run, $scenarios, $steps];
    }

    /**
     * Checks whether a value has the standard ExFace UID format.
     *
     * @param string $value
     * @return bool
     */
    protected function isRunUid(string $value): bool
    {
        return preg_match('/^0x[0-9a-f]{32}$/i', $value) === 1;
    }

    /**
     * Validates and normalizes a Gherkin tag.
     *
     * @param string $value
     * @return string
     */
    private function normalizeTag(string $value): string
    {
        $tag = $value[0] === '@' ? $value : '@' . $value;
        if (preg_match('/^@[A-Za-z0-9_][A-Za-z0-9_.-]*$/D', $tag) !== 1) {
            throw new \InvalidArgumentException('Use a BDT run UID or a single Gherkin tag such as "@smoke".');
        }

        return $tag;
    }

    /**
     * Finds the newest run containing a scenario whose stored tags include the exact tag.
     *
     * The database comparator performs a substring search; exact token matching is checked
     * after retrieval to avoid confusing tags such as "@smoke" and "@smoke_test".
     *
     * @param string $tag
     * @return string
     */
    private function findLatestRunUidByTag(string $tag): string
    {
        $sheet = $this->createDataSheet('axenox.BDT.run_scenario', [
            'tags',
            'run_feature__run__UID',
            'run_feature__run__started_on'
        ]);
        $sheet->getFilters()->addConditionFromString('tags', $tag, ComparatorDataType::IS);
        $sheet->getSorters()->addFromString('run_feature__run__started_on', 'DESC');

        for ($offset = 0; ; $offset += self::TAG_SEARCH_PAGE_SIZE) {
            $sheet->setRowsLimit(self::TAG_SEARCH_PAGE_SIZE);
            $sheet->setRowsOffset($offset);
            $sheet->dataRead();
            $rows = $sheet->getRows();

            foreach ($rows as $row) {
                if ($this->hasExactTag((string) ($row['tags'] ?? ''), $tag)) {
                    $runUid = (string) ($row['run_feature__run__UID'] ?? '');
                    if ($runUid !== '') {
                        return $runUid;
                    }
                }
            }

            if (count($rows) < self::TAG_SEARCH_PAGE_SIZE) {
                break;
            }
        }

        throw new \OutOfBoundsException('No BDT test run found for tag "' . $tag . '".');
    }

    /**
     * Checks comma-separated stored scenario tags for an exact match.
     *
     * @param string $storedTags
     * @param string $tag
     * @return bool
     */
    private function hasExactTag(string $storedTags, string $tag): bool
    {
        $tags = preg_split('/\s*,\s*/', $storedTags, -1, PREG_SPLIT_NO_EMPTY);
        return in_array($tag, $tags ?: [], true);
    }

    /**
     * Reads the run summary fields used by the report.
     *
     * @param string $runUid
     * @return array|null
     */
    private function readRun(string $runUid): ?array
    {
        $rows = $this->readRows(
            'axenox.BDT.run',
            [
                'UID',
                'behat_command',
                'started_on',
                'finished_on',
                'duration_time',
                'run_stats__status',
                'run_stats__features_total',
                'run_stats__features_passed',
                'run_stats__features_failed',
                'run_stats__scenarios_total',
                'run_stats__scenarios_passed',
                'run_stats__scenarios_failed',
                'run_stats__steps_total',
                'run_stats__steps_passed',
                'run_stats__steps_failed',
                'run_stats__steps_skipped'
            ],
            'UID',
            $runUid,
            ComparatorDataType::EQUALS,
            1
        );

        return $rows[0] ?? null;
    }

    /**
     * Reads every scenario belonging to a run in feature and source order.
     *
     * @param string $runUid
     * @return array
     */
    protected function readScenarios(string $runUid, ?string $featureUid = null): array
    {
        $sheet = $this->createDataSheet('axenox.BDT.run_scenario', [
            'UID',
            'name',
            'line',
            'tags',
            'started_on',
            'finished_on',
            'duration_time',
            'run_feature__UID',
            'run_feature__name',
            'run_feature__filename',
            'run_feature__run_sequence_idx',
            'run_scenario_stats__status',
            'run_scenario_stats__steps_total',
            'run_scenario_stats__steps_passed',
            'run_scenario_stats__steps_failed',
            'run_scenario_stats__steps_skipped'
        ]);
        $sheet->getFilters()->addConditionFromString(
            'run_feature__run__UID',
            $runUid,
            ComparatorDataType::EQUALS
        );
        $sheet->getSorters()->addFromString('run_feature__run_sequence_idx', 'ASC');
        if ($featureUid !== null) {
            $sheet->getFilters()->addConditionFromString('run_feature__UID', $featureUid, ComparatorDataType::EQUALS);
        }
        $sheet->getSorters()->addFromString('line', 'ASC');
        $sheet->dataRead();

        return $sheet->getRows();
    }

    /**
     * Reads every step belonging to a run in scenario and execution order.
     *
     * @param string $runUid
     * @return array
     */
    protected function readSteps(string $runUid, ?string $featureUid = null): array
    {
        $sheet = $this->createDataSheet('axenox.BDT.run_step', [
            'UID',
            'run_scenario',
            'parent_step',
            'run_sequence_idx',
            'name',
            'line',
            'status',
            'duration_ms',
            'started_on',
            'finished_on',
            'url',
            'details',
            'error_message',
            'error_log_id',
            'screenshot_path'
        ]);
        $sheet->getFilters()->addConditionFromString(
            'run_scenario__run_feature__run__UID',
            $runUid,
            ComparatorDataType::EQUALS
        );
        $sheet->getSorters()->addFromString('run_scenario__run_feature__run_sequence_idx', 'ASC');
        if ($featureUid !== null) {
            $sheet->getFilters()->addConditionFromString('run_scenario__run_feature__UID', $featureUid, ComparatorDataType::EQUALS);
        }
        $sheet->getSorters()->addFromString('run_scenario__line', 'ASC');
        $sheet->getSorters()->addFromString('run_sequence_idx', 'ASC');
        $sheet->dataRead();

        return $sheet->getRows();
    }

    /**
     * Creates a DataSheet for a BDT object and requests its selected attributes.
     *
     * @param string $objectAlias
     * @param string[] $columns
     * @return DataSheetInterface
     */
    private function createDataSheet(string $objectAlias, array $columns): DataSheetInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, $objectAlias);
        $sheet->getColumns()->addMultiple($columns);

        return $sheet;
    }

    /**
     * Reads rows matching one DataSheet attribute.
     *
     * @param string $objectAlias
     * @param string[] $columns
     * @param string $filterAttribute
     * @param string $filterValue
     * @param string $comparator
     * @param int|null $rowsLimit
     * @return array
     */
    protected function readRows(
        string $objectAlias,
        array $columns,
        string $filterAttribute,
        string $filterValue,
        string $comparator,
        ?int $rowsLimit = null
    ): array {
        $sheet = $this->createDataSheet($objectAlias, $columns);
        $sheet->getFilters()->addConditionFromString($filterAttribute, $filterValue, $comparator);
        if ($rowsLimit !== null) {
            $sheet->setRowsLimit($rowsLimit);
        }
        $sheet->dataRead();

        return $sheet->getRows();
    }

    /**
     * Renders the run summary, features, scenarios, and all their steps as Markdown.
     *
     * @param array $run
     * @param array $scenarios
     * @param array $steps
     * @return string
     */
    protected function renderRun(array $run, array $scenarios, array $steps): string
    {
        $markdown = '# ' . $this->escapeMarkdown($this->workbench->model()->getObject('axenox.BDT.run')->getName()) . "\n\n";
        $metadata = $this->renderAttributes('axenox.BDT.run', [
            'run_stats__status' => $this->formatStatus($run['run_stats__status'] ?? null),
            'UID' => $run['UID'] ?? '',
            'behat_command' => $run['behat_command'] ?? '',
            'started_on' => $run['started_on'] ?? '',
            'finished_on' => $run['finished_on'] ?? '',
            'duration_time' => $run['duration_time'] ?? ''
        ]);
        $markdown .= '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,24rem),1fr));gap:1.5rem;align-items:start">' . "\n"
            . '<div style="min-width:0;overflow-wrap:anywhere;overflow-x:auto">'
            . MarkdownDataType::convertMarkdownToHtml($metadata, false) . "</div>\n"
            . '<div style="min-width:0;overflow-wrap:anywhere;overflow-x:auto">'
            . MarkdownDataType::convertMarkdownToHtml($this->renderRunCounts($run), false) . "</div>\n"
            . "</div>\n\n";

        return $markdown . $this->renderScenarios($scenarios, $steps);
    }

    /**
     * Renders scenario results and nested steps for either a run or a single feature.
     */
    protected function renderScenarios(array $scenarios, array $steps, bool $includeFeatures = true): string
    {
        $stepsByScenario = [];
        $stepsByUid = [];
        foreach ($steps as $step) {
            $stepsByScenario[(string) ($step['run_scenario'] ?? '')][] = $step;
            $stepUid = (string) ($step['UID'] ?? '');
            if ($stepUid !== '') {
                $stepsByUid[$stepUid] = $step;
            }
        }
        $markdown = '';
        if ($scenarios === []) {
            return '_' . $this->escapeMarkdown($this->translate('NO_SCENARIOS')) . '_';
        }

        $currentFeature = null;
        foreach ($scenarios as $scenario) {
            $featureName = (string) ($scenario['run_feature__name'] ?? 'Feature');
            $featureFilename = (string) ($scenario['run_feature__filename'] ?? '');
            $featureUid = (string) ($scenario['run_feature__UID'] ?? '');
            $featureKey = $featureUid !== '' ? $featureUid : ($featureFilename !== '' ? $featureFilename : $featureName);
            if ($includeFeatures && $featureKey !== $currentFeature) {
                $currentFeature = $featureKey;
                $heading = $this->escapeMarkdown($featureName);
                if ($featureFilename !== '') {
                    $heading = '[' . $heading . '](' . $this->buildFeatureReportUrl($featureFilename) . ')';
                }
                $markdown .= '## ' . $heading . "\n\n";
            }

            $markdown .= ($includeFeatures ? '### ' : '## ')
                . $this->escapeMarkdown((string) ($scenario['name'] ?? 'Scenario')) . "\n\n";
            $markdown .= $this->renderAttributes('axenox.BDT.run_scenario', [
                'run_scenario_stats__status' => $this->formatStatus($scenario['run_scenario_stats__status'] ?? null),
                'line' => $scenario['line'] ?? '',
                'tags' => $scenario['tags'] ?? '',
                'started_on' => $scenario['started_on'] ?? '',
                'finished_on' => $scenario['finished_on'] ?? '',
                'duration_time' => $scenario['duration_time'] ?? ''
            ]);
            $scenarioUid = (string) ($scenario['UID'] ?? '');
            $scenarioSteps = $stepsByScenario[$scenarioUid] ?? [];
            $markdown .= $this->renderScenarioSteps($scenarioSteps, $stepsByUid);
        }

        return rtrim($markdown);
    }

    /**
     * Renders available feature, scenario, and step totals for the run.
     *
     * @param array $run
     * @return string
     */
    private function renderRunCounts(array $run): string
    {
        $counts = [
            'features_total', 'features_passed', 'features_failed',
            'scenarios_total', 'scenarios_passed', 'scenarios_failed',
            'steps_total', 'steps_passed', 'steps_failed', 'steps_skipped'
        ];
        $values = [];
        foreach ($counts as $attributeAlias) {
            $value = $run['run_stats__' . $attributeAlias] ?? null;
            $values[$attributeAlias] = $value === null || $value === '' ? '-' : $value;
        }

        return $this->renderAttributes('axenox.BDT.run_stats', $values);
    }

    /**
     * Renders a Markdown table using the object's attribute names instead of custom captions.
     */
    protected function renderAttributes(string $objectAlias, array $values): string
    {
        $lines = [
            '| ' . $this->escapeMarkdown($this->translate('ATTRIBUTE'))
                . ' | ' . $this->escapeMarkdown($this->translate('VALUE')) . ' |',
            '| --- | --- |'
        ];
        foreach ($values as $attributeAlias => $value) {
            $formatted = str_ends_with($attributeAlias, '__status')
                ? $this->renderStatusBadge((string) $value)
                : $this->escapeMarkdown((string) $value);
            $lines[] = '| ' . $this->escapeMarkdown($this->getAttributeName($objectAlias, $attributeAlias))
                . ' | ' . $formatted . ' |';
        }

        return implode("\n", $lines) . "\n\n";
    }

    /**
     * Resolves the model-provided name, including related attributes and model translations.
     */
    private function getAttributeName(string $objectAlias, string $attributeAlias): string
    {
        return $this->workbench->model()->getObject($objectAlias)->getAttribute($attributeAlias)->getName();
    }

    /**
     * Renders all steps in a scenario, preserving nested substep indentation.
     *
     * @param array $steps
     * @param array $stepsByUid
     * @return string
     */
    private function renderScenarioSteps(array $steps, array $stepsByUid): string
    {
        if ($steps === []) {
            return '_' . $this->escapeMarkdown($this->translate('NO_STEPS')) . "_\n\n";
        }

        $markdown = '';
        foreach ($steps as $step) {
            $depth = $this->getStepDepth($step, $stepsByUid);
            $indent = str_repeat('  ', $depth);
            $status = $this->formatStatus($step['status'] ?? null);
            $stepText = $this->escapeMarkdown((string) ($step['name'] ?? 'Step'));
            $line = ($step['line'] ?? null) !== null
                ? ' (' . $this->escapeMarkdown($this->getAttributeName('axenox.BDT.run_step', 'line'))
                    . ': ' . $this->escapeMarkdown((string) $step['line']) . ')'
                : '';
            $duration = ($step['duration_ms'] ?? null) !== null
                ? ' - ' . $this->escapeMarkdown($this->getAttributeName('axenox.BDT.run_step', 'duration_ms'))
                    . ': ' . $this->escapeMarkdown((string) $step['duration_ms'])
                : '';
            $markdown .= $indent . '- ' . $this->renderStatusBadge($status) . ' ' . $stepText . $line . $duration . "\n";

            $details = '';
            foreach ([
                'started_on', 'finished_on', 'details', 'error_message'
            ] as $field) {
                $value = trim((string) ($step[$field] ?? ''));
                if ($value !== '') {
                    $details .= $this->renderQuotedText(
                        $this->getAttributeName('axenox.BDT.run_step', $field) . ': ' . $value,
                        $indent . '  '
                    );
                }
            }
            $details .= $this->renderStepArtifacts($step, $indent . '  ');
            if ($details !== '') {
                $open = in_array($status, [
                    $this->translate('STATUS.FAILED'),
                    $this->translate('STATUS.FAILED_PREVIOUSLY'),
                    $this->translate('STATUS.TIMEOUT'),
                    $this->translate('STATUS.UNDEFINED')
                ], true) ? ' open' : '';
                $markdown .= "\n" . $indent . '  <details' . $open . '><summary style="cursor:pointer;padding:0.4rem 0">'
                    . htmlspecialchars($this->getAttributeName('axenox.BDT.run_step', 'details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . "</summary>\n\n" . $details . "\n" . $indent . "  </details>\n\n";
            }
        }

        return $markdown . "\n";
    }

    /**
     * Adds log references and an accessible screenshot linked to its full-size view.
     * Screenshots use the existing file-object endpoint, never arbitrary local or remote paths.
     */
    private function renderStepArtifacts(array $step, string $prefix): string
    {
        $markdown = '';
        $logId = trim((string) ($step['error_log_id'] ?? ''));
        if ($logId !== '') {
            $logUrl = DebugContext::buildUrlToLogId(
                rawurlencode($logId),
                rtrim($this->workbench->getUrl(), '/') . '/exface.core.logs.html'
            );
            $markdown .= $prefix . '> '
                . $this->escapeMarkdown($this->getAttributeName('axenox.BDT.run_step', 'error_log_id'))
                . ': [' . $this->escapeMarkdown($logId) . '](' . $logUrl . ")\n";
        }

        $path = trim((string) ($step['screenshot_path'] ?? ''));
        if ($path !== '') {
            $screenshotUrl = $this->buildScreenshotUrl($path);
            $caption = $this->getAttributeName('axenox.BDT.run_step', 'screenshot_path');
            $alt = $this->escapeMarkdown($caption . ': ' . (string) ($step['name'] ?? ''));
            $markdown .= $prefix . '> [' . $this->escapeMarkdown($caption) . '](' . $screenshotUrl . ")\n"
                . $prefix . ">\n"
                . $prefix . '> [![' . $alt . '](' . $screenshotUrl . ')](' . $screenshotUrl . ")\n";
        }

        return $markdown;
    }

    /**
     * Encodes screenshot file-object UIDs using Core's Apache-safe Base64URL convention.
     */
    protected function buildScreenshotUrl(string $path): string
    {
        $path = $this->normalizeScreenshotPath($path);
        $object = $this->workbench->model()->getObject('axenox.BDT.run_step_screenshot');
        $uid = 'base64URL,' . BinaryDataType::convertTextToBase64URL($path);
        return rtrim($this->workbench->getUrl(), '/') . '/'
            . HttpFileServerFacade::buildUrlToViewData($object, $uid);
    }

    /**
     * Validates screenshot identities shared by embedded images and attachment collection.
     */
    private function normalizeScreenshotPath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, 'data/axenox/BDT/Screenshots/')
            || preg_match('~(^|/)\.\.?(/|$)|[:\x00-\x1f]~', $path) === 1
            || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'png') {
            throw new \InvalidArgumentException('Screenshots must reference a PNG inside data/axenox/BDT/Screenshots.');
        }
        return $path;
    }

    /**
     * Adds high-contrast status colors while retaining the readable, localized status text.
     */
    private function renderStatusBadge(string $status): string
    {
        $color = '#344054';
        $background = '#f2f4f7';
        if (in_array($status, [$this->translate('STATUS.PASSED'), $this->translate('STATUS.PASSED_PREVIOUSLY')], true)) {
            $color = '#116329';
            $background = '#dafbe1';
        } elseif (in_array($status, [
            $this->translate('STATUS.FAILED'), $this->translate('STATUS.FAILED_PREVIOUSLY'),
            $this->translate('STATUS.TIMEOUT'), $this->translate('STATUS.UNDEFINED')
        ], true)) {
            $color = '#a40e26';
            $background = '#ffebe9';
        }
        return '<span style="display:inline-block;padding:0.1rem 0.55rem;border-radius:1rem;font-weight:600;color:'
            . $color . ';background:' . $background . '">'
            . htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
    }

    /**
     * Calculates a step's nesting depth by following its parent-step UIDs.
     *
     * @param array $step
     * @param array $stepsByUid
     * @return int
     */
    private function getStepDepth(array $step, array $stepsByUid): int
    {
        $depth = 0;
        $parentUid = (string) ($step['parent_step'] ?? '');
        $visited = [(string) ($step['UID'] ?? '') => true];

        while ($parentUid !== '' && isset($stepsByUid[$parentUid]) && !isset($visited[$parentUid])) {
            $visited[$parentUid] = true;
            $depth++;
            $parentUid = (string) ($stepsByUid[$parentUid]['parent_step'] ?? '');
        }

        return $depth;
    }

    /**
     * Renders multiline step details as escaped Markdown block quotes.
     *
     * @param string $text
     * @param string $prefix
     * @return string
     */
    private function renderQuotedText(string $text, string $prefix): string
    {
        $lines = preg_split('/\R/u', $text) ?: [$text];
        $quoted = [];
        foreach ($lines as $line) {
            $quoted[] = $prefix . '> ' . $this->escapeMarkdown($line) . "\n";
        }

        return implode('', $quoted);
    }

    /**
     * Maps BDT step status codes and scenario status strings to readable labels.
     *
     * @param mixed $status
     * @return string
     */
    protected function formatStatus($status): string
    {
        if ($status === null || $status === '') {
            return $this->translate('STATUS.UNKNOWN');
        }
        if (!is_numeric($status)) {
            $labels = [
                'passed' => 'PASSED',
                'failed' => 'FAILED',
                'skipped' => 'SKIPPED',
                'pending' => 'PENDING',
                'started' => 'STARTED',
                'undefined' => 'UNDEFINED',
                'timeout' => 'TIMEOUT'
            ];
            $status = strtolower((string) $status);
            return isset($labels[$status]) ? $this->translate('STATUS.' . $labels[$status]) : ucfirst($status);
        }

        $labels = [
            0 => 'PENDING',
            10 => 'STARTED',
            30 => 'UNDEFINED',
            90 => 'PASSED_PREVIOUSLY',
            91 => 'FAILED_PREVIOUSLY',
            98 => 'SKIPPED',
            100 => 'PASSED',
            101 => 'FAILED',
            102 => 'TIMEOUT'
        ];
        $code = (int) $status;

        return isset($labels[$code])
            ? $this->translate('STATUS.' . $labels[$code])
            : $this->translate('STATUS.UNKNOWN') . ' (' . $code . ')';
    }

    /**
     * Uses the BDT translator's session locale and standard English fallback.
     */
    protected function translate(string $key): string
    {
        return $this->workbench->getApp(BDTApp::APP_ALIAS)->getTranslator()->translate('RUN_REPORT.' . $key);
    }

    /**
     * Escapes Markdown punctuation in untrusted test output.
     *
     * @param string $value
     * @return string
     */
    protected function escapeMarkdown(string $value): string
    {
        $value = preg_replace('/\R/u', ' ', $value) ?? $value;

        return str_replace(
            ['\\', '`', '*', '_', '{', '}', '[', ']', '(', ')', '#', '+', '-', '.', '!', '|', '>', '<'],
            ['\\\\', '\\`', '\\*', '\\_', '\\{', '\\}', '\\[', '\\]', '\\(', '\\)', '\\#', '\\+', '\\-', '\\.', '\\!', '\\|', '\\>', '\\<'],
            $value
        );
    }

    /**
     * Builds an installation-absolute component link using Core's documentation URL helper.
     */
    protected function buildFeatureReportUrl(string $filename): string
    {
        return rtrim($this->workbench->getUrl(), '/')
            . '/api/docs/exface/core/Docs/understanding_the_metamodel/Model_components.md?component=bdt_feature&selector='
            . urlencode($filename);
    }
}
