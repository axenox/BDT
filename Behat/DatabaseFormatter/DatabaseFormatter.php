<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\Behat\Common\ExpectedTestCountCalculator;
use axenox\BDT\Behat\Common\RunRecordWriter;
use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\TestResultInterface;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\Suite\Suite;
use Behat\Testwork\Suite\SuiteRegistry;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Behat\EventDispatcher\Event\AfterOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class DatabaseFormatter implements Formatter, TestRunObserverInterface
{
    private static $eventDispatcher;

    private WorkbenchInterface  $workbench;
    private ?array $metrics = null;

    private ?DataSheetInterface $runDataSheet = null;
    private float               $runStart;

    private ?DataSheetInterface $featureDataSheet = null;
    private float               $featureStart;
    private int                 $featureIdx = 0;

    private ?DataSheetInterface $scenarioDataSheet = null;
    private float               $scenarioStart;
    private static array        $scenarioPages = [];

    private ?DataSheetInterface $stepDataSheet = null;
    private float               $stepStart;
    private int                 $stepIdx = 0;

    /* @var \exface\Core\Interfaces\DataSheets\DataSheetInterface $substepDataSheets */
    private array               $substepDataSheets = [];
    private array               $substepStarts = [];

    private static array        $testedPages = [];
    // Provides all resolved suites (paths, filters) as Behat itself parsed them from
    // behat.yml and its imports - used once at run start to compute the expected scope.
    private SuiteRegistry $suiteRegistry;
    private bool $expectedResultsCalculated = false;

    /**
     * Tracks which page/widget + role-set combinations have already been verified by a
     * works-as-expected check during the current test run.
     *
     * Keys are built by {@see buildRolesKey()} and follow the format:
     *   - Page level:   "RoleA|RoleB::page::exface.Core.Logs"
     *   - Widget level: "RoleA|RoleB::widget::Filter::Name"
     *
     * Values are the {@see TestResultInterface} returned when the check was first executed,
     * so callers can return the cached result without repeating the test.
     *
     * @var array<string, TestResultInterface>
     */
    private static array        $testedEnvironments = [];

    private ScreenshotProviderInterface $provider;
    /** @var MarkdownLogBook[]  */
    private static array        $stepLogbooks = [];
    private bool $exerciseFinished = false;

    // Do not create a run record for dry-run executions.
    // Dry-run is used as a pre-flight syntax check and must not pollute the test results DB.
    private bool $isDryRun = false;
    
    /**
     * When non-null, the formatter is running in attach-mode and must bind to the
     * provided run UID without creating or updating the run row itself.
     * @var string|null
     */
    private ?string $injectedRunUid = null;

    /**
     * Per-lane identifier injected via config in parallel runs (e.g. "<run_uid>_lane2").
     *
     * Kept static so {@see UI5Browser::setupUser()} can read it without a formatter reference and
     * namespace the provisioned test user per lane. This prevents two concurrent workers that
     * resolve to the same role from colliding on the shared user row (optimistic locking).
     */
    private static ?string $laneId = null;

    public function __construct(WorkbenchInterface $workbench, ScreenshotProviderInterface $provider, EventDispatcherInterface $eventDispatcher, SuiteRegistry $suiteRegistry, array $chromeConfig = [], ?string $runUid = null, ?string $laneId = null)
    {
        self::$eventDispatcher = $eventDispatcher;
        self::$laneId = $laneId;
        $this->workbench = $workbench;
        $this->provider = $provider;
        $this->suiteRegistry = $suiteRegistry;
        $this->isDryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
        if (!$this->isDryRun) {
            // A parallel worker is identified by an injected run_uid (it triggers attach-mode instead
            // of startRun below). In that mode a lane_id MUST also be present: setupUser() reads it via
            // getLaneId() to give each concurrent worker its OWN test-user row. If lane_id fails to
            // propagate (e.g. the lane config's DatabaseFormatterExtension key does not merge into the
            // base behat.yml and lane_id falls back to defaultNull), getLaneId() returns null and
            // setupUser() silently reverts to the shared base user - so concurrent workers collide on
            // the same USER_AUTHENTICATOR row and one lane dies mid-run with a TimeStampingBehavior
            // optimistic-lock error. Fail loudly here, at process startup, so a broken lane_id contract
            // surfaces immediately and unambiguously instead of as a cryptic lock error on a random lane.
            if (!empty($runUid) && empty($laneId)) {
                throw new RuntimeException(
                    'Parallel worker started with run_uid "' . $runUid . '" but no lane_id: per-lane '
                    . 'test-user isolation cannot be applied and concurrent workers would collide on the '
                    . 'shared user row. Verify that the lane config\'s DatabaseFormatterExtension key '
                    . 'merges into the base behat.yml so lane_id reaches the formatter.'
                );
            }
            ChromeManager::getInstance($this)
                ->configure($chromeConfig);
            // Announce the resolved run configuration up front so this process's log opens with a
            // clear expectation: which mode it runs in (attach-mode worker vs. single process),
            // whether its Chrome will be visible or headless, and whether a debugger is attached.
            // Purely informational - it changes no behaviour. Mirrors the coordinator's banner so a
            // parallel worker's own lane log is self-explanatory too.
            $this->logStartupBanner(!empty($runUid));
            // If a run UID was injected via config, operate in attach-mode: bind to the existing
            // run row and avoid creating/updating the run record. Otherwise perform the normal
            // startRun flow which creates the run row and registers the finalizer.
            if (!empty($runUid)) {
                $this->injectedRunUid = $runUid;
                try {
                    $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run');
                    $ds->getColumns()->addFromSystemAttributes();
                    $ds->getFilters()->addConditionFromString('UID', $runUid, ComparatorDataType::EQUALS);
                    $ds->dataRead();
                    if ($ds->countRows() === 0) {
                        throw new RuntimeException('Attached run UID ' . $runUid . ' not found');
                    }
                    $this->runDataSheet = $ds;
                    $this->registerMetrics();
                    // Still register the shutdown handler, but the handler will avoid touching the run row
                    // when in attach-mode.
                    register_shutdown_function(function () {
                        $this->onShutdown();
                    });
                } catch (\Throwable $e) {
                    ErrorManager::getInstance()->logException($e, $this->workbench);
                }
            } else {
                $this->startRun();
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // BeforeExerciseCompleted::BEFORE => 'onBeforeExercise',
            // Use __destruct() to finish the log on inner errors too
            // AfterExerciseCompleted::AFTER => 'onAfterExercise',
            BeforeSuiteTested::BEFORE => 'onBeforeSuite',
            AfterSuiteTested::AFTER => 'onAfterSuite',
            BeforeFeatureTested::BEFORE => 'onBeforeFeature',
            AfterFeatureTested::AFTER => 'onAfterFeature',
            BeforeScenarioTested::BEFORE => 'onBeforeScenario',
            AfterScenarioTested::AFTER => 'onAfterScenario',
            BeforeOutlineTested::BEFORE => 'onBeforeOutline',
            AfterOutlineTested::AFTER => 'onAfterScenario',
            BeforeStepTested::BEFORE => 'onBeforeStep',
            AfterStepTested::AFTER => 'onAfterStep',
            // Custom events
            BeforeSubstep::class => 'onBeforeSubstep',
            AfterSubstep::class => 'onAfterSubstep',
            AfterPageVisited::class => 'onAfterPageVisited',
        ];
    }

    public function __destruct()
    {
        if ($this->isDryRun) {
            return;
        }
        // onShutdown() via register_shutdown_function is the primary shutdown handler.
        // This is a last-resort fallback in case the shutdown function was somehow not registered.
        if (! $this->exerciseFinished) {
            $this->onAfterExercise();
            ChromeManager::getInstance()->stop();
        }
    }
    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    public function getName(): string
    {
        return 'BDTDatabaseFormatter';
    }

    /**
     * @inheritDoc
     */
    public function getDescription()
    {
        return 'Saves results to the BDT DB';
    }

    // Implementing Formatter interface (minimal)
    public function getOutputPrinter() {
        return new DummyOutputPrinter();
    }
    public function setOutputPrinter($printer) {}
    public function getParameter($name) {}
    public function setParameter($name, $value) {}

    protected function microtime() : float
    {
        return microtime(true);
    }

    public function onAfterExercise(): void
    {
        try{
            // When running in attach-mode (a coordinator provides the run UID), workers must
            // not modify the run row at all. Only non-attached runs (the current single-process
            // ownership flow) should write finished_on and duration_ms.
            if ($this->isDryRun || $this->runDataSheet === null || $this->injectedRunUid !== null) {
                return;
            }
            (new RunRecordWriter())->finalize($this->runDataSheet);            

            // Mark as finished so that onShutdown() does not call this method a second time
            $this->exerciseFinished = true;
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    /**
     * Creates the run row exactly once, on the first suite of the exercise.
     *
     * Why on the first suite rather than at construction: suite.registry is only populated by
     * the time suites start running, so this is the earliest point where the full expected
     * scope can be computed. The row is created once; subsequent suites short-circuit on the
     * non-null runDataSheet.
     */
    public function onBeforeSuite(BeforeSuiteTested $event): void
    {
        // In attach-mode (injectedRunUid set) the coordinator already computed and wrote the
        // expected counts onto the run row exactly once. Workers must never touch the run row,
        // both because ownership belongs to the coordinator and because concurrent dataUpdate()
        // calls from multiple workers on the same row would trigger optimistic-locking conflicts.
        if ($this->isDryRun === true || $this->runDataSheet === null || $this->expectedResultsCalculated === true|| $this->injectedRunUid !== null) {
            return;
        }
        try {
            [$expectedFeatures, $expectedScenarios] = $this->calculateExpectedTotals();
            if ($expectedFeatures !== null) {
                (new RunRecordWriter())->setExpectedCounts($this->runDataSheet, $expectedFeatures, $expectedScenarios);
            }
            $this->expectedResultsCalculated = true;
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onAfterSuite(AfterSuiteTested $event) : void
    {
        try{
            if ($this->isDryRun) {
                return;
            }
            if (!empty(self::$scenarioPages)) {
                $suite = $event->getSuite();
                $suiteName = $suite->getName();
                $existingPages = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.PAGE');
                $existingPages->getFilters()->addConditionFromString('APP__ALIAS', $suiteName, ComparatorDataType::EQUALS);
                $existingPages->dataRead();
                $pageCount = $existingPages->countRows();
                if ($pageCount > 0) {
                    $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_suite');
                    $ds->addRow([
                        'run' => $this->runDataSheet->getUidColumn()->getValue(0),
                        'app' => $suiteName,
                        'effected_page_count' => count(self::$testedPages),
                        'total_page_count' => $pageCount,
                        'coverage' => number_format((count(self::$testedPages) / $pageCount) * 100, 2)
                    ]);
                    $ds->dataCreate(false);
                }
            }
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onBeforeFeature(BeforeFeatureTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        try{
            $feature = $event->getFeature();
            $suite = $event->getSuite();
            $this->featureIdx++;
            $this->featureStart = $this->microtime();
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_feature');
            $filename = FilePathDataType::normalize($event->getFeature()->getFile(), '/');
            $content = file_get_contents($filename);
            $vendorPath = FilePathDataType::normalize($this->workbench->filemanager()->getPathToVendorFolder(), '/') . '/';
            $filename = StringDataType::substringAfter($filename, $vendorPath, $filename);
            $ds->addRow([
                'run' => $this->runDataSheet->getUidColumn()->getValue(0),
                'run_sequence_idx' => $this->featureIdx,
                'app_alias' => $suite->getName(),
                'name' => $feature->getTitle(),
                'description' => $feature->getDescription(),
                'filename' => $filename,
                'started_on' => DateTimeDataType::now(),
                'content' => $content
            ]);
            $ds->dataCreate(false);
            $this->featureDataSheet = $ds;
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onAfterFeature(AfterFeatureTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        try{
            $ds = $this->featureDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->featureStart);
            $ds->setCellValue('chrome_info', 0, $this->buildChromeInfo());
            $ds->dataUpdate();            
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        } finally {
            $this->featureDataSheet = null;
            // Clear so the next feature starts with a clean history
            ChromeManager::getInstance()->clearStartHistory();
        }
    }

    /**
     * Records a run_scenario row when a scenario starts.
     *
     * Why the null guard: if onBeforeFeature failed to create its run_feature row (e.g. the
     * database rejected the INSERT), its exception was swallowed by the catch below and
     * featureDataSheet stays null. Dereferencing it here would raise an \Error that escapes
     * the catch and kills Behat with exit code 255. We skip recording this scenario instead,
     * keeping the process alive so remaining scenarios/lanes can still run.
     */
    public function onBeforeScenario(BeforeScenarioTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        try {
            if ($this->featureDataSheet === null) {
                throw new RuntimeException('Cannot record scenario: parent feature row was not created (onBeforeFeature failed earlier).');
            }
            $scenario = $event->getScenario();
            $this->scenarioStart = $this->microtime();
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_scenario');
            $ds->addRow([
                'run_feature' => $this->featureDataSheet->getUidColumn()->getValue(0),
                'name' => $scenario->getTitle(),
                'line' => $scenario->getLine(),
                'started_on' => DateTimeDataType::now(),
                'tags' => implode(', ', $scenario->getTags())
            ]);
            $ds->dataCreate(false);
            $this->scenarioDataSheet = $ds;
        }
        catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onBeforeOutline(BeforeOutlineTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        try{
            if ($this->featureDataSheet === null) {
                throw new RuntimeException('Cannot record scenario: parent feature row was not created (onBeforeFeature failed earlier).');
            }
            $outline = $event->getOutline();
            $this->scenarioStart = $this->microtime();
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_scenario');
            $ds->addRow([
                'run_feature' => $this->featureDataSheet->getUidColumn()->getValue(0),
                'name' => $outline->getTitle() . ' - with ' . count($outline->getExamples()) . ' examples',
                'line' => $outline->getLine(),
                'started_on' => DateTimeDataType::now(),
                'tags' => implode(', ', $outline->getTags())
            ]);
            $ds->dataCreate(false);
            $this->scenarioDataSheet = $ds;
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onAfterScenario(AfterScenarioTested|AfterOutlineTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        try{
            $ds = $this->scenarioDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->scenarioStart);
            $ds->dataUpdate();
            $scenarioUid = $ds->getUidColumn()->getValue(0);

            $dsActions = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_scenario_action');
            foreach (static::$scenarioPages as $pageAlias) {
                try {
                    $page = UiPageFactory::createFromModel($this->workbench, $pageAlias);
                    $pageUid = $page->getUid();
                    //not to reach memory limit
                    unset($page);
                    $dsActions->addRow([
                        'run_scenario' => $scenarioUid,
                        'page_alias' => $pageAlias,
                        'page' => $pageUid
                    ]);
                } catch (\Throwable $e) {
                    $pageUid = null;
                }
            }
            if (! $dsActions->isEmpty()) {
                $dsActions->dataCreate();
            }
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onBeforeStep(BeforeStepTested $event): void
    {
        if ($this->isDryRun) {
            return;
        }
        static::$stepLogbooks = [];
        // Reset so that onAfterStep can detect a failed DB record creation
        $this->stepDataSheet = null;
        try {
            $step = $event->getStep();
            $this->stepIdx++;
            $this->stepStart = $this->microtime();
            $ds = $this->logStepStart($step->getText(), $step->getLine());
            $this->stepDataSheet = $ds;
            $this->provider->setName($ds->getUidColumn()->getValue(0));
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onAfterStep(AfterStepTested $event): void
    {
        try {
            if ($this->isDryRun) {
                return;
            }
            // stepDataSheet is null when onBeforeStep failed to create the DB record.
            // In that case there is nothing to close — just clear the orphaned substep
            // stack so the next step starts clean.
            if ($this->stepDataSheet === null) {
                $this->substepDataSheets = [];
                $this->substepStarts = [];
                return;
            }
            $result = $event->getTestResult();
            $ds = $this->stepDataSheet->extractSystemColumns();
            $stepStatusCode = StepStatusDataType::convertFromBehatResultCode($result->getResultCode());
            $this->logStepEnd($ds, $this->stepStart, $stepStatusCode, $result->getResultCode() === TestResult::FAILED ? $result->getException() : null, $this::$stepLogbooks);

            // Make sure to end ALL substeps. Substeps can only exist inside a step, so if the step ends, all
            // of them MUST end too. Give the substeps the status code of the step
            /* @var \exface\Core\Interfaces\DataSheets\DataSheetInterface $ds */
            foreach ($this->substepDataSheets as $i => $ds) {
                $startTime = $this->substepStarts[$i];
                $ds = $ds->extractSystemColumns();
                $this->logStepEnd($ds, $startTime, $stepStatusCode, null, [], null, 'Step finished');
            }
            $this->substepDataSheets = [];
            $this->substepStarts = [];
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onBeforeSubstep(BeforeSubstep $event)
    {
        try{
            if ($this->isDryRun) {
                return;
            }
            $this->stepIdx++;
            $startTime = $this->microtime();
            $parentStepData = (empty($this->substepDataSheets) ? $this->stepDataSheet : $this->substepDataSheets[array_key_last($this->substepDataSheets)]);
            $ds = $this->logStepStart(
                $event->getSubstepName(),
                $this->stepDataSheet->getCellValue('line', 0),
                $parentStepData->getUidColumn()->getValue(0)
            );

            $this->substepStarts[] = $startTime;
            $this->substepDataSheets[] = $ds;

            $this->provider->setName($ds->getUidColumn()->getValue(0));
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    public function onAfterSubstep(AfterSubstep $event)
    {
        try {
            if ($this->isDryRun) {
                return;
            }
            $currentSubstepIdx = array_key_last($this->substepDataSheets);
            $ds = $this->substepDataSheets[$currentSubstepIdx]->extractSystemColumns();
            $this->logStepEnd($ds, $this->substepStarts[$currentSubstepIdx], $event->getResultCode(), $event->getException(), [], $event->getSubstepName(), $event->getResult()->getReason());
            // Remove the top-most substep data sheet from the stack
            array_pop($this->substepDataSheets);
            array_pop($this->substepStarts);
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    protected function logStepStart(string $title, int $line, ?string $parentStepUid = null) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_step');
        $row = [
            'run_scenario' => $this->scenarioDataSheet->getUidColumn()->getValue(0),
            'run_sequence_idx' => $this->stepIdx,
            'name' => mb_ucfirst($title),
            'line' => $line,
            'started_on' => DateTimeDataType::now(),
            'status' => 10
        ];
        if ($parentStepUid !== null) {
            $row['parent_step'] = $parentStepUid;
        }
        $ds->addRow($row);
        $ds->dataCreate(false);
        return $ds;
    }

    /**
     * Log the end of a test step to the database.
     *
     * Records the completion of a test step including duration, status, and error information.
     * For failed steps with screenshots, also records the screenshot path and the URL where
     * the failure occurred.
     *
     * @param DataSheetInterface $ds The data sheet containing the step record
     * @param float $stepStartTime The timestamp when the step started
     * @param int $stepStatusCode The status code of the step (passed, failed, skipped, etc.)
     * @param \Throwable|null $e Optional exception thrown during the step
     * @param array $logbooks Optional array of logbook entries to save
     * @param string|null $updatedTitle Optional updated title for the step
     * @param string|null $reason Optional reason for step status
     *
     * @return DataSheetInterface The updated data sheet
     */
    protected function logStepEnd(DataSheetInterface $ds, float $stepStartTime, int $stepStatusCode, ?\Throwable $e = null, array $logbooks = [], ?string $updatedTitle = null, ?string $reason = null) : DataSheetInterface
    {
        $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
        $ds->setCellValue('duration_ms', 0, $this->microtime() - $stepStartTime);
        $ds->setCellValue('status', 0, $stepStatusCode);
        if ($reason !== null) {
            $ds->setCellValue('error_message', 0, $reason);
        }
        if ($updatedTitle !== null) {
            $ds->setCellValue('name', 0, mb_ucfirst($updatedTitle));
        }
        if ($stepStatusCode === StepStatusDataType::FAILED) {
            if($this->provider->isCaptured()) {
                $screenshotRelativePath = $this->provider->getPath() . DIRECTORY_SEPARATOR . $this->provider->getName();
                $ds->setCellValue('screenshot_path', 0, $screenshotRelativePath);
                $url = $this->provider->getUrl();
                if ($url !== null) {
                    $ds->setCellValue('url', 0, $url);
                }
            }
            if ($e) {
                $ds->setCellValue('error_message', 0, $e->getMessage());
                if(!empty($logId = ErrorManager::getInstance()->getLastLogId())) {
                    $ds->setCellValue('error_log_id', 0, $logId);
                }
            }
        }
        $md = '';
        // TODO save logbook markdown to a new DB field: 
        foreach ($logbooks as $logbook) {
            $md .= $logbook->__toString();
        }
        if ($md !== '') {
            $ds->setCellValue('details', 0, $md);
        }
        $ds->dataUpdate();
        return $ds;
    }

    /**
     * {@inheritDoc}
     * @see TestRunObserverInterface::logException()
     */
    public function logException(\Throwable $e) : DataSheetInterface
    {
        return $this->logError($e->getMessage(), $e);
    }

    /** 
     * Defensive fallback for the "no open scenario" case: a run_step row requires a
     * run_scenario FK, so it can only be written while a scenario is open. logError() can be
     * called before any scenario exists — most importantly when Chrome fails to start inside
     * the very first BeforeScenario hook, before onBeforeScenario() created the scenario
     * record. In that case we must not dereference a null scenarioDataSheet: doing so would
     * crash here, hide the real cause, and leave the run looking like an unexplained stop.
     * Instead, we log the exception through the workbench logger, producing a monitor-visible
     * entry with a log id regardless of hook ordering, and return an unsaved sheet.
     * 
     * {@inheritDoc}
     * @see TestRunObserverInterface::logError()
     */
    public function logError(string $title, ?\Throwable $e = null) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_step');

        // No open scenario yet → a run_step cannot be created (it needs a run_scenario FK).
        // Fall back to a plain workbench log entry so the failure is never silently lost.
        if ($this->scenarioDataSheet === null) {
            $this->workbench->getLogger()->logException($e ?? new RuntimeException($title));
            return $ds;
        }

        $row = [
            'run_scenario' => $this->scenarioDataSheet->getUidColumn()->getValue(0),
            'run_sequence_idx' => $this->stepIdx,
            'name' => mb_ucfirst($title),
            'line' => 0,
            'started_on' => DateTimeDataType::now(),
            'finished_on' => DateTimeDataType::now(),
            'duration_ms' => 0,
            'status' => StepStatusDataType::FAILED
        ];
        if ($e) {
            $ds->setCellValue('error_message', 0, $e->getMessage());
            if ($e instanceof ExceptionInterface) {
                $ds->setCellValue('error_log_id', 0, $e->getLogId());
            }
            $this->workbench->getLogger()->logException($e);
        }
        $ds->addRow($row);
        $ds->dataCreate(false);
        return $ds;
    }

    public static function addTestLogbook(LogBookInterface $logbook): void
    {
        if (!in_array($logbook, static::$stepLogbooks, true)) {
            static::$stepLogbooks[] = $logbook;
        }
    }

    /**
     * @param AfterPageVisited $event
     * @return void
     */
    public function onAfterPageVisited(AfterPageVisited $event)
    {
        $alias = $event->getPageAlias();

        if (!in_array($alias, static::$scenarioPages, true)) {
            static::$scenarioPages[] = $alias;
        }
        if (!in_array($alias, static::$testedPages, true)) {
            static::$testedPages[] = $alias;
        }
    }

    /**
     * {@inheritDoc}
     * @see TestRunObserverInterface::getEventDispatcher()
     */
    public static function getEventDispatcher(): EventDispatcherInterface
    {
        return self::$eventDispatcher;
    }

    /**
     * Returns the per-lane id injected in parallel runs, or null in a normal single run.
     *
     * setupUser() uses this to namespace the test user per lane so concurrent workers sharing a
     * role do not collide on the shared user row.
     */
    public static function getLaneId(): ?string
    {
        return self::$laneId;
    }

    protected function registerMetrics() : array
    {
        if ($this->metrics === null) {
            $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.metric');
            $sheet->getFilters()->addConditionFromString('enabled_flag', true, ComparatorDataType::EQUALS);
            $sheet->getColumns()->addMultiple([
                'UID',
                'name',
                'prototype_path',
                'config_uxon'
            ]);
            $sheet->dataRead();
            foreach ($sheet->getRows() as $row) {
                $class = PhpFilePathDataType::findClassInFile($this->workbench->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . $row['prototype_path']);
                if ($class === null) {
                    throw new RuntimeException('Cannot register BDT metric ' . $row['name'] . ': prototype "' . $row['prototype_path'] . '" cannot be loaded!');
                }
                $uxon = UxonObject::fromJson($row['config_uxon']);
                $uxon->setProperty('uid', $row['UID']);
                $uxon->setProperty('name', $row['name']);
                $this->metrics[] = new $class($this->workbench, $this, $uxon);
            }
        }
        return $this->metrics;
    }

    /**
     * {@inheritDoc}
     * @see TestRunObserverInterface::getCurrentRunUid()
     */
    public function getCurrentRunUid() : ?string
    {
        if ($this->runDataSheet === null) {
            return null;
        }
        return $this->runDataSheet->getUidColumn()->getValue(0);
    }

    /**
     * Builds a JSON string with metadata about every Chrome instance that ran during
     * the current feature.
     *
     * Reads ChromeManager::getStartHistory() which accumulates one entry per start()
     * call since the last clearStartHistory(). A feature with no crash will have a
     * single entry; a feature with one crash recovery will have two entries.
     *
     * @param array $extra Additional key-value pairs merged into each entry (rarely needed).
     * @return string JSON-encoded array of chrome start records.
     */
    private function buildChromeInfo(array $extra = []): string
    {
        $history = ChromeManager::getInstance()->getStartHistory();
        if (!empty($extra)) {
            $history = array_map(fn($entry) => array_merge($entry, $extra), $history);
        }
        return json_encode($history);
    }

    /**
     * Builds a canonical, order-independent string key from a set of role aliases.
     *
     * Roles are sorted before joining so that ["Admin", "Editor"] and ["Editor", "Admin"]
     * produce the same key. An empty role list returns the special token "__no_roles__"
     * to remain distinguishable from a missing/null value.
     *
     * @param string[] $roles Role aliases for the current test scenario.
     * @return string           Sorted, pipe-separated roles string, e.g. "Admin|Editor".
     */
    private static function buildRolesKey(array $roles): string
    {
        if (empty($roles)) {
            return '__no_roles__';
        }
        $sorted = $roles;
        sort($sorted);
        return implode('|', $sorted);
    }

    /**
     * Determines whether the given page has already been fully verified (works-as-expected)
     * for the supplied set of roles during the current test run.
     *
     * Use this check before navigating to a page just to run a works-as-expected assertion:
     * if the same page was already validated for the same user environment (same role set),
     * the navigation can be skipped entirely and the cached result reused.
     *
     * @param string[] $roles     Role aliases active in the current scenario.
     * @param string   $pageAlias Fully-qualified page alias, e.g. "exface.Core.Logs".
     * @return TestResultInterface|null  The previous result if already tested, null otherwise.
     */
    public static function hasTestedPage(array $roles, string $pageAlias): ?TestResultInterface
    {
        $key = self::buildRolesKey($roles) . '::page::' . $pageAlias;
        return self::$testedEnvironments[$key] ?? null;
    }

    /**
     * Records that the given page has been fully verified (works-as-expected) for the
     * supplied role set.
     *
     * Call this immediately after a successful or failed page-level works-as-expected check
     * so that subsequent calls to {@see hasTestedPage()} can return the cached result.
     *
     * @param string[]             $roles     Role aliases active in the current scenario.
     * @param string               $pageAlias Fully-qualified page alias, e.g. "exface.Core.Logs".
     * @param TestResultInterface  $result    The result produced by the works-as-expected check.
     * @return void
     */
    public static function markPageAsTested(array $roles, string $pageAlias, TestResultInterface $result): void
    {
        $key = self::buildRolesKey($roles) . '::page::' . $pageAlias;
        self::$testedEnvironments[$key] = $result;
    }

    /**
     * Determines whether a specific widget has already been verified (works-as-expected)
     * for the supplied role set during the current test run.
     *
     * The widget is identified by its DOM element ID (e.g. "0x1a2b3c__FilterName"), which
     * is unique per widget per page. Use {@see UI5AbstractNode::getElementId()} or
     * {@see UI5Browser::getElementIdFromWidget()} to obtain this value.
     *
     * @param string[] $roles     Role aliases active in the current scenario.
     * @param string   $widgetId  DOM element ID of the widget, e.g. "0x1a2b3c__FilterName".
     * @return TestResultInterface|null  The previous result if already tested, null otherwise.
     */
    public static function hasTestedWidget(array $roles, string $widgetId): ?TestResultInterface
    {
        $key = self::buildRolesKey($roles) . '::widget::' . $widgetId;
        return self::$testedEnvironments[$key] ?? null;
    }

    /**
     * Records that a specific widget has been verified (works-as-expected) for the
     * supplied role set.
     *
     * Call this immediately after a widget-level works-as-expected check so that
     * subsequent calls to {@see hasTestedWidget()} can return the cached result
     * without re-executing the check.
     *
     * @param string[]            $roles    Role aliases active in the current scenario.
     * @param string              $widgetId DOM element ID of the widget, e.g. "0x1a2b3c__FilterName".
     * @param TestResultInterface $result   The result produced by the works-as-expected check.
     * @return void
     */
    public static function markWidgetAsTested(array $roles, string $widgetId, TestResultInterface $result): void
    {
        $key = self::buildRolesKey($roles) . '::widget::' . $widgetId;
        self::$testedEnvironments[$key] = $result;
    }

    /**
     * Guaranteed to run even on fatal PHP errors and uncaught exceptions.
     *
     * Responsibilities:
     *  - Write finished_on to the run record if normal flow did not already do so (question 1)
     *  - Log any PHP error that caused the crash (question 2)
     */
    private function onShutdown(): void
    {
        // Log the PHP error that caused the crash, if any (question 2)
        $error = error_get_last();
        $fatalErrorTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if ($error !== null && in_array($error['type'], $fatalErrorTypes, true)) {
            $message = sprintf(
                'PHP fatal error caused Behat to crash: [%d] %s in %s on line %d',
                $error['type'],
                $error['message'],
                $error['file'],
                $error['line']
            );
            ErrorManager::getInstance()->logException(
                new RuntimeException($message),
                $this->workbench
            );
        }

        // Write finished_on only if normal flow (onAfterExercise) did not already do so (question 1)
        if (! $this->exerciseFinished) {
            $this->onAfterExercise();
        }

        ChromeManager::getInstance()->stop();
    }
    
    /**
     * Builds and logs the startup banner that sets the expectation for THIS Behat process before any
     * feature runs: which mode it is in, whether its Chrome will be visible or headless, and whether a
     * debugger is attached.
     *
     * Why here and not only in the coordinator: the coordinator's banner covers the fleet as a whole,
     * but each parallel worker is a separate process writing its OWN lane log - without a per-process
     * banner, a lane log gives no hint about that worker's Chrome visibility or debugger state. The
     * same method also serves the non-parallel single-process run (`vendor\bin\behat` directly), so a
     * developer running one feature locally gets the same "visible or headless?" answer up front.
     *
     * Chrome visibility is read from ChromeManager::willRunHeadless() - the exact value start() will
     * use - so the banner can never disagree with the real launch. Debugger detection mirrors
     * ChromeManager's Xdebug check so the note stays consistent with the headless fallback.
     *
     * @param bool $attachMode TRUE when this process is a coordinator-driven worker bound to an
     *                         injected run UID; FALSE for a standalone single-process run
     */
    private function logStartupBanner(bool $attachMode): void
    {
        try {
            $headless = ChromeManager::getInstance()->willRunHeadless();
            $debuggerActive = extension_loaded('xdebug')
                && function_exists('xdebug_is_debugger_active')
                && xdebug_is_debugger_active();

            $modeLine = $attachMode
                ? 'attach-mode worker' . (self::$laneId !== null ? ' (lane ' . self::$laneId . ')' : '')
                : 'single process';

            $lines = [
                '===== BDT run configuration =====',
                'Mode:     ' . $modeLine,
                'Chrome:   ' . ($headless ? 'headless' : 'visible'),
            ];
            if ($debuggerActive) {
                $lines[] = 'Debugger: attached'
                    . ($headless ? ' (Chrome still headless - set PARALLEL.CHROME_HEADLESS=false to watch the browser)' : ' - browser visible for stepping');
            } else {
                $lines[] = 'Debugger: not attached';
            }
            $lines[] = '=================================';

            $this->workbench->getLogger()->info(implode("\n", $lines));
        } catch (\Throwable $e) {
            // A banner is purely informational - never let it break run startup.
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    private function startRun(): void
    {
        if ($this->isDryRun) {
            return;
        }

        // Prefer the durable action command RunTest injects via the BDT_RUN_COMMAND env var
        // ("vendor\bin\action <app>:RunTest --tags=..."), the reproducible form the Test Runs page
        // reruns. Fall back to the raw behat argv only for a plain "vendor\bin\behat" run that sets
        // no such variable - that config-based argv is not meant to be rerun elsewhere.
        $command = getenv('BDT_RUN_COMMAND') ?: null;
        if ($command === null) {
            $cliArgs = $_SERVER['argv'] ?? [];
            if (! empty($cliArgs)) {
                // First item is the file called - remove that
                array_shift($cliArgs);
                $command = implode(' ', $cliArgs);
            }
        }
        try{
            // Run-row creation is shared with the coordinator's RunLifecycle via RunRecordWriter,
            // so the run schema stays controlled from one place.
            $this->runDataSheet = (new RunRecordWriter())->create($this->workbench, $command);
            $this->registerMetrics();
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
        // Register a shutdown function so that finished_on is always written,
        // even if Behat crashes with a fatal error or an uncaught exception.
        // __destruct() is NOT guaranteed to run in those cases, but shutdown functions are.
        register_shutdown_function(function () {
            $this->onShutdown();
        });

    }

    /**
     * Computes the total features/scenarios Behat is expected to run across ALL configured
     * suites, before any feature executes.
     *
     * Why the suite repository instead of re-parsing behat.yml: Behat has already resolved the
     * imports chain, profiles, path placeholders and suite globs into concrete Suite objects
     * here. Re-reading the YAML would mean duplicating that whole resolution and drifting from
     * Behat's real behavior. Each suite exposes its resolved paths via getSetting('paths').
     *
     * Why one total up front (not per suite): the value only serves silent-stop detection,
     * which needs the complete expected scope from the start. A per-suite running total would,
     * at a crash, only cover the suites already started - collapsing expected onto actual and
     * hiding the very stop we want to detect. A suite whose paths do not exist on this machine
     * (placeholder / non-real-project suites) simply contributes nothing.
     *
     * @return array{0:?int,1:?int} [expectedFeatureCount, expectedScenarioCount]; null on error.
     * @throws \Throwable
     */
    private function calculateExpectedTotals(): array
    {
        try {
            $calculator = new ExpectedTestCountCalculator();

            // When Behat is invoked with a positional feature/path (RunTest's --feature reaches
            // Behat as a bare path argument, not an option), ONLY that path runs - not the whole
            // suite. Count it alone so the expected totals match what actually executes; otherwise
            // every single-feature interactive run is measured against the suite-wide total and
            // looks like it stopped early. See getPositionalFeaturePath() for why suite-wide
            // counting is wrong here.
            $featurePath = $this->getPositionalFeaturePath();
            if ($featurePath !== null) {
                // Behat still applies a tag filter to the positional path: the CLI --tags when
                // given, otherwise the OWNING suite's configured filter. Reproduce that so a
                // "feature + tags" selection is not over-counted. With no owning suite (a path
                // outside every configured suite) only the CLI --tags can apply.
                $owningSuite = $this->findSuiteOwningPath($featurePath);
                $tagExpression = $owningSuite !== null
                    ? $this->resolveTagExpression($owningSuite)
                    : $this->getCliOption('tags');
                $result = $calculator->calculate([$featurePath], $tagExpression);
                return [$result->featureCount, $result->scenarioCount];
            }

            $features = 0;
            $scenarios = 0;
            $selectedSuite = $this->getCliOption('suite');
            foreach ($this->suiteRegistry->getSuites() as $suite) {
                // Honour Behat's --suite option: when a single suite is selected on the CLI,
                // only that suite runs, so only it must be counted. Without this filter the
                // expected totals would include every configured suite across all imported
                // apps and dwarf what actually ran, making every --suite run look like it
                // stopped early.
                if ($selectedSuite !== null && $suite->getName() !== $selectedSuite) {
                    continue;
                }
                $paths = $suite->hasSetting('paths') ? $suite->getSetting('paths') : [];
                if (empty($paths)) {
                    continue;
                }
                $result = $calculator->calculate($paths, $this->resolveTagExpression($suite));
                $features += $result->featureCount;
                $scenarios += $result->scenarioCount;
            }
            return [$features, $scenarios];
        } catch (\Throwable $e) {
            // Never let scope estimation block run creation; leave the counts NULL instead.
            ErrorManager::getInstance()->logException($e, $this->workbench);
            return [null, null];
        }
    }

    /**
     * Resolves the active tag filter for a suite, preferring the CLI "--tags" option (which
     * Behat applies globally to every suite) over the suite's own configured filters.
     *
     * Why read argv directly: CLI --tags is the authoritative override Behat applies last, and
     * the formatter already reads $_SERVER['argv'] elsewhere. The per-suite "filters.tags"
     * setting is only the fallback for runs without an explicit --tags.
     */
    private function resolveTagExpression(Suite $suite): ?string
    {
        // CLI --tags is Behat's authoritative override, applied to every suite.
        $cliTags = $this->getCliOption('tags');
        if ($cliTags !== null) {
            return $cliTags;
        }
        // Otherwise fall back to the suite's own configured tag filter.
        $filters = $suite->hasSetting('filters') ? $suite->getSetting('filters') : [];
        return $filters['tags'] ?? null;
    }

    /**
     * Reads a "--name=value" or "--name value" option from the CLI arguments.
     *
     * Why centralize: both the tag filter and the --suite selection need the same two argv
     * spellings that Behat itself accepts. One reader keeps their parsing identical and avoids
     * duplicating the lookup in two places.
     */
    private function getCliOption(string $name): ?string
    {
        $argv = $_SERVER['argv'] ?? [];
        $prefix = '--' . $name . '=';
        foreach ($argv as $i => $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
            if ($arg === '--' . $name && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
        }
        return null;
    }

    /**
     * Extracts the positional feature/path argument Behat was invoked with (e.g. RunTest's
     * --feature, which reaches Behat as a bare "features\login.feature" argument), or null when
     * the run carries no positional path and therefore covers the whole suite.
     *
     * WHY THIS EXISTS: calculateExpectedTotals() otherwise counts every scenario in the selected
     * suite's paths. When a single feature (or directory) is passed positionally, Behat runs ONLY
     * that path, so the suite-wide total dwarfs what actually ran and every such run trips the
     * silent-stop detector. Restricting the expected scope to this path is what keeps the
     * expected/actual counts aligned for interactive RunTest runs - the concrete bug this fixes.
     *
     * WHY IT IDENTIFIES THE PATH BY ON-DISK EXISTENCE INSTEAD OF ARGV POSITION: Behat also takes
     * value options whose space-separated values are themselves non-option tokens (--config
     * <file>, --out <file>), so position/prefix parsing alone cannot separate a path VALUE from
     * THE feature path without hard-coding Behat's whole option table. A token that resolves to an
     * existing .feature file or a directory is unambiguously the positional path (config values
     * are .yml, report values are not .feature), which stays correct even if Behat's option set
     * changes - and sidesteps the boolean-flag-vs-value ambiguity entirely.
     *
     * @return string|null Absolute path with any trailing ":line" selector stripped, or null if none.
     */
    private function getPositionalFeaturePath(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];
        // Skip argv[0] (the behat script itself); it is never a feature path.
        for ($i = 1, $n = count($argv); $i < $n; $i++) {
            $arg = (string) $argv[$i];
            // Option flags and their inline "--opt=value" form never carry the positional path.
            if ($arg === '' || $arg[0] === '-') {
                continue;
            }
            // Strip a Behat line selector ("file.feature:12" / ":12:34") before the disk check.
            // A Windows drive colon ("C:\...") is not at the string end, so it stays intact.
            $candidate = preg_replace('/(:\d+)+$/', '', $arg);
            // Return an ABSOLUTE path: Behat's Gherkin FeatureNode rejects a relative file path
            // ("The file should be an absolute path.") - which is exactly what surfaces from the
            // parser->parse($input, $file) call in ExpectedTestCountCalculator. A positional
            // --feature is commonly given relative to the run's cwd. realpath() succeeds here
            // because is_dir()/is_file() already confirmed the path exists on disk.
            if (is_dir($candidate)) {
                return realpath($candidate) ?: $candidate;
            }
            if (is_file($candidate) && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'feature') {
                return realpath($candidate) ?: $candidate;
            }
        }
        return null;
    }

    /**
     * Finds the suite whose configured paths contain the given positional feature path, or null.
     *
     * WHY IT EXISTS: when a positional path is counted on its own (see calculateExpectedTotals),
     * the tag filter Behat applies is still the OWNING suite's configured filter unless --tags
     * overrides it. Locating that suite lets resolveTagExpression() reproduce Behat's real
     * filtering; without it a suite-level default tag filter would be ignored and the expected
     * scenario count over-counted for that path.
     */
    private function findSuiteOwningPath(string $featurePath): ?Suite
    {
        $needle = $this->normalizePathForCompare($featurePath);
        if ($needle === null) {
            return null;
        }
        foreach ($this->suiteRegistry->getSuites() as $suite) {
            $paths = $suite->hasSetting('paths') ? $suite->getSetting('paths') : [];
            foreach ($paths as $suitePath) {
                $base = $this->normalizePathForCompare((string) $suitePath);
                if ($base === null) {
                    continue;
                }
                // Exact file match, or the feature lives under the suite's directory. The trailing
                // separator guards a directory boundary so "features2" cannot match "features".
                if ($needle === $base
                    || str_starts_with($needle, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)
                ) {
                    return $suite;
                }
            }
        }
        return null;
    }

    /**
     * Normalizes a path for cross-suite comparison: real absolute path, lower-cased. Returns null
     * for a path that does not resolve on disk.
     *
     * WHY realpath: suite paths and the CLI path can differ in separators and "." segments;
     * realpath collapses both to one canonical spelling so the prefix compare is reliable.
     *
     * WHY lower-case unconditionally: this framework runs on Windows, whose filesystem is
     * case-insensitive, so "Features\Login.feature" and "features\login.feature" are one file;
     * a case-sensitive compare would wrongly report no owning suite.
     */
    private function normalizePathForCompare(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        return strtolower($real);
    }
}