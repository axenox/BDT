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
use axenox\BDT\Exceptions\BrowserTimeoutException;
use axenox\BDT\Interfaces\TestResultInterface;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\Suite\Suite;
use Behat\Testwork\Suite\SuiteRegistry;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Behat\EventDispatcher\Event\AfterOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use axenox\BDT\Behat\Common\ErrorManager;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\PhpFilePathDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Events\Workbench\OnCleanUpEvent;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
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

    /**
     * App-config key holding the run retention window in days.
     *
     * Kept config-driven (mirroring the ETL cleanups) so ops can tune how long test runs are kept
     * on the results database - the growth of which is what the recurring "allocate memory" errors
     * are traced back to - without a code change.
     */
    private const CLEANUP_DAYS_TO_KEEP = 'CLEANUP.DAYS_TO_KEEP';
    
    /**
     * App-config key capping how many old runs a single cleanup pass deletes.
     *
     * Deleting a large backlog in one pass can itself exhaust memory on the results DB - the very
     * failure this cleanup fights - and holds locks longer than needed. Capping the batch keeps each
     * pass bounded; the next scheduled cleanup continues where this one left off.
     */
    private const CLEANUP_DELETE_BATCH = 'CLEANUP.DELETE_BATCH';

    /**
     * Fallback batch size when CLEANUP.DELETE_BATCH is not configured, so cleanup stays bounded even
     * on an installation that set the retention age but not an explicit batch.
     */
    private const DELETE_BATCH_DEFAULT = 100;

    /**
     * Reason why the current scenario is NOT being recorded, or null while a scenario is open.
     *
     * Exists to make the degraded state self-explaining: once scenario-record creation fails,
     * every step/substep write short-circuits, and each of those short-circuits must be able to
     * state WHY it did nothing. Without this, a run would simply show missing steps with no
     * indication that a scenario row was never created in the first place.
     */
    private ?string             $scenarioSkipReason = null;

    private ?DataSheetInterface $stepDataSheet = null;
    private float               $stepStart;
    private int                 $stepIdx = 0;

    /* @var \exface\Core\Interfaces\DataSheets\DataSheetInterface $substepDataSheets */
    private array               $substepDataSheets = [];
    private array               $substepStarts = [];

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
                    $this->bindRunUidToProvider();
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
                $this->bindRunUidToProvider();
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
     * Marks a scenario as successfully opened, i.e. persisted with a usable UID.
     *
     * Why this exists: a run_scenario row that was created but carries no UID is worse than no row
     * at all - every subsequent run_step write would inherit an empty run_scenario FK and be
     * rejected by the database, potentially aborting the whole lane. So a sheet is only accepted as
     * "the open scenario" once its UID is actually present. Anything else is treated as a failed
     * creation and routed into the degraded state via skipScenarioRecording().
     *
     * @param DataSheetInterface $ds
     * @return void
     */
    protected function openScenario(DataSheetInterface $ds) : void
    {
        $uid = $ds->getUidColumn()->getValue(0);
        if ($uid === null || $uid === '') {
            $this->skipScenarioRecording('Scenario record was created without a UID - cannot attach steps to it.');
            return;
        }
        $this->scenarioDataSheet = $ds;
        $this->scenarioSkipReason = null;
    }

    /**
     * Enters the degraded "no open scenario" state after a failed scenario-record creation.
     *
     * Why this exists: previously a failure here left the PREVIOUS scenario's sheet in place, so the
     * steps of the current scenario were silently recorded against the wrong scenario - and when no
     * previous scenario existed, an empty UID produced an invalid run_scenario FK on the next step
     * write. Dropping the reference outright guarantees neither can happen: there is no stale
     * scenario to mis-attribute to, and no half-valid sheet to build an FK from. A degraded run must
     * degrade its records, not corrupt them and not take the lane down.
     *
     * @param string $reason
     * @return void
     */
    protected function skipScenarioRecording(string $reason) : void
    {
        $this->scenarioDataSheet = null;
        $this->scenarioSkipReason = $reason;
        $this->workbench->getLogger()->warning('BDT: scenario not recorded - ' . $reason);
    }

    /**
     * Returns the UID of the currently open scenario or null if there is none.
     *
     * Why this exists: it is the single point where "may I write a row that references a scenario?"
     * is answered. Every step, substep and error write funnels through it, so the "no valid open
     * scenario" case is handled identically everywhere instead of being re-implemented (and
     * forgotten) per hook.
     *
     * @return string|null
     */
    protected function getOpenScenarioUid() : ?string
    {
        if ($this->scenarioDataSheet === null) {
            return null;
        }
        $uid = $this->scenarioDataSheet->getUidColumn()->getValue(0);
        return ($uid === null || $uid === '') ? null : $uid;
    }

    /**
     * Human readable explanation for the current degraded state - used by the short-circuiting hooks.
     *
     * @return string
     */
    protected function getScenarioSkipReason() : string
    {
        return $this->scenarioSkipReason ?? 'No scenario is currently open.';
    }

    /**
     * Records a run_scenario row when a scenario starts.
     *
     * Why the null guard: if onBeforeFeature failed to create its run_feature row (e.g. the
     * database rejected the INSERT), its exception was swallowed by the catch below and
     * featureDataSheet stays null. Dereferencing it here would raise an \Error that escapes
     * the catch and kills Behat with exit code 255. We skip recording this scenario instead,
     * keeping the process alive so remaining scenarios/lanes can still run.
     *
     * Why the sheet is dropped on failure: whatever the reason for the failure, this process must
     * NOT keep the previous scenario's sheet around. Steps of this scenario would otherwise be
     * attributed to the previous one, or - with no previous one - be written with an empty
     * run_scenario FK. skipScenarioRecording() clears the reference so all downstream writes
     * short-circuit cleanly instead.
     */
    public function onBeforeScenario(BeforeScenarioTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        // Drop any previous scenario reference BEFORE attempting to create the new row, so a
        // failure below can never leave a stale scenario in place.
        $this->scenarioDataSheet = null;
        $this->scenarioSkipReason = null;
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
            $this->openScenario($ds);
        }
        catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e, $this->workbench);
            $this->skipScenarioRecording('Scenario record could not be created: ' . $e->getMessage());
        }
    }

    /**
     * Records a run_scenario row for a scenario outline.
     *
     * Mirrors onBeforeScenario(): the previous scenario reference is dropped up front, and a failed
     * creation leaves NO scenario open. See skipScenarioRecording() for why a stale reference here
     * would mis-attribute the outline's steps or emit an invalid run_scenario FK.
     */
    public function onBeforeOutline(BeforeOutlineTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        $this->scenarioDataSheet = null;
        $this->scenarioSkipReason = null;
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
            $this->openScenario($ds);
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
            $this->skipScenarioRecording('Outline record could not be created: ' . $e->getMessage());
        }
    }

    /**
     * Closes the run_scenario row.
     *
     * Why the early return: when scenario-record creation failed, there is no row to close. This is
     * an expected degraded state (not an error), so we exit quietly after resetting the per-scenario
     * state - dereferencing a null sheet here would only produce a misleading secondary error that
     * hides the original cause.
     */
    public function onAfterScenario(AfterScenarioTested|AfterOutlineTested $event)
    {
        if ($this->isDryRun) {
            return;
        }
        if ($this->scenarioDataSheet === null) {
            static::$scenarioPages = [];
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
        finally {
            // The scenario is over either way - never carry its sheet into the next scenario.
            $this->scenarioDataSheet = null;
            $this->scenarioSkipReason = null;
        }
    }

    /**
     * Opens a run_step row for the step that is about to run.
     *
     * Why the open-scenario check: a run_step requires a valid run_scenario FK. If the scenario row
     * was never created, writing the step would either attach it to a foreign scenario or send an
     * empty FK to the database - the latter can abort the entire lane. When no scenario is open we
     * therefore skip the DB record entirely and log the reason. stepDataSheet stays null, which
     * onAfterStep and onBeforeSubstep already treat as "nothing to record".
     */
    public function onBeforeStep(BeforeStepTested $event): void
    {
        if ($this->isDryRun) {
            return;
        }
        static::$stepLogbooks = [];
        // Reset so that onAfterStep can detect a failed DB record creation
        $this->stepDataSheet = null;
        // A new step starts with no evidence of its own. This is the only place where dropping the
        // capture state is safe - no picture can be in flight here, whereas a reset while a row is
        // being closed would discard a screenshot that had just been written for it.
        $this->provider->reset();
        if ($this->getOpenScenarioUid() === null) {
            $this->workbench->getLogger()->warning(
                'BDT: step "' . $event->getStep()->getText() . '" not recorded - ' . $this->getScenarioSkipReason()
            );
            return;
        }
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
            $stepException = $result->getResultCode() === TestResult::FAILED ? $result->getException() : null;

            // Behat only knows PASSED/FAILED/SKIPPED/UNDEFINED, so a step that merely ran out
            // of time arrives here indistinguishable from a step that hit a real application
            // error. Refining FAILED to TIMEOUT when the cause was a wait timeout is what makes
            // the two separable in the report - without it, every slow step keeps inflating the
            // framework-error count and burying the failures that need an actual code fix.
            if ($stepStatusCode === StepStatusDataType::FAILED && $this->isTimeoutException($stepException)) {
                $stepStatusCode = StepStatusDataType::TIMEOUT;
            }

            $this->logStepEnd($ds, $this->stepStart, $stepStatusCode, $stepException, $this::$stepLogbooks);

            // Make sure to end ALL substeps. Substeps can only exist inside a step, so if the step ends, all
            // of them MUST end too. Give the substeps the status code of the step.
            // Skip null markers that indicate a failed substep record creation in onBeforeSubstep.
            /* @var \exface\Core\Interfaces\DataSheets\DataSheetInterface $ds */
            foreach ($this->substepDataSheets as $i => $ds) {
                // If this substep was never recorded due to a DB insertion failure, the marker is null.
                // Skip it to avoid attempting an extractSystemColumns() call on null.
                if ($ds === null) {
                    continue;
                }
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

    /**
     * Opens a run_step row for a substep.
     *
     * Why the parent guard: a substep row is only meaningful below a persisted parent step (it needs
     * both a run_scenario FK and a parent_step UID). If the step was not recorded - because no
     * scenario is open or because the step INSERT failed - there is nothing to hang the substep on.
     * We skip it and leave the stack untouched, so onAfterSubstep finds an empty stack and also does
     * nothing. Recording a substep here would inherit exactly the invalid-FK problem we are avoiding.
     *
     * Why degraded-state markers for DB insertion failures: when logStepStart() throws (e.g., DB
     * constraint, connection timeout), the substep is not persisted. Without explicit markers in the
     * stack arrays, onAfterSubstep would attempt to close a non-existent record, producing an invalid
     * UPDATE and potentially corrupting the run with wrong timestamps. The marker (null value) ensures
     * onAfterSubstep recognizes the failure and exits cleanly. The parent step's error message already
     * captures the root cause for logging.
     */
    public function onBeforeSubstep(BeforeSubstep $event)
    {
        try{
            if ($this->isDryRun) {
                return;
            }
            if ($this->stepDataSheet === null) {
                $this->workbench->getLogger()->warning(
                    'BDT: substep "' . $event->getSubstepName() . '" not recorded - parent step was not recorded.'
                );
                return;
            }
            $this->stepIdx++;
            $startTime = $this->microtime();

            // Find the parent step's UID by walking backwards through the substep stack.
            // If the last entry is a null marker (indicating a failed DB insertion in a prior substep),
            // skip it and use the nearest non-null substep. If all are markers or the stack is empty,
            // default to the main parent step's UID.
            $parentStepUid = null;
            if (!empty($this->substepDataSheets)) {
                for ($i = count($this->substepDataSheets) - 1; $i >= 0; $i--) {
                    if ($this->substepDataSheets[$i] !== null) {
                        $parentStepUid = $this->substepDataSheets[$i]->getUidColumn()->getValue(0);
                        break;
                    }
                }
            }
            // If all substeps are markers or no substeps exist, use the main parent step as the true parent.
            if ($parentStepUid === null) {
                $parentStepUid = $this->stepDataSheet->getUidColumn()->getValue(0);
            }

            $ds = $this->logStepStart(
                $event->getSubstepName(),
                $this->stepDataSheet->getCellValue('line', 0),
                $parentStepUid
            );

            $this->substepStarts[] = $startTime;
            $this->substepDataSheets[] = $ds;

            $this->provider->setName($ds->getUidColumn()->getValue(0));
        }
        catch(\Throwable $e){
            // Substep record creation failed. Push null markers so onAfterSubstep recognizes the
            // failure and does not attempt to close a non-existent record. The exception is logged
            // via ErrorManager for root-cause visibility.
            $this->workbench->getLogger()->error(
                'BDT: substep "' . $event->getSubstepName() . '" not recorded - DB insertion failed: ' . $e->getMessage()
            );
            $this->substepStarts[] = $this->microtime();
            $this->substepDataSheets[] = null;
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    /**
     * Closes the top-most substep row.
     *
     * Why the empty-stack guard: when onBeforeSubstep skipped the substep (no recorded parent step),
     * the stack is empty and array_key_last() returns null - indexing with it would raise a warning
     * and then an \Error. An empty stack simply means "this substep was never recorded", which is an
     * expected degraded state, so we return quietly.
     *
     * Why the null-marker check: when onBeforeSubstep's logStepStart() throws (e.g., DB insertion
     * failure), the substep record is never created. A null marker is pushed into the stack to keep
     * stack indices synchronized with BeforeSubstep/AfterSubstep events. When we detect a null marker
     * here, we pop it and return — the failure is already logged by onBeforeSubstep and no UPDATE
     * should be attempted on a non-existent row.
     */
    public function onAfterSubstep(AfterSubstep $event)
    {
        try {
            if ($this->isDryRun) {
                return;
            }
            if (empty($this->substepDataSheets)) {
                return;
            }
            $currentSubstepIdx = array_key_last($this->substepDataSheets);
            $ds = $this->substepDataSheets[$currentSubstepIdx];

            // If this substep was never recorded due to a DB insertion failure in onBeforeSubstep,
            // the marker will be null. Pop both stacks and return to avoid attempting an UPDATE
            // on a non-existent row.
            if ($ds === null) {
                array_pop($this->substepDataSheets);
                array_pop($this->substepStarts);
                $this->restoreScreenshotName();
                return;
            }

            $ds = $ds->extractSystemColumns();
            $this->logStepEnd($ds, $this->substepStarts[$currentSubstepIdx], $event->getResultCode(), $event->getException(), [], $event->getSubstepName(), $event->getResult()->getReason());
            // Remove the top-most substep data sheet from the stack
            array_pop($this->substepDataSheets);
            array_pop($this->substepStarts);
            $this->restoreScreenshotName();
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logException($e, $this->workbench);
        }
    }

    /**
     * Points the screenshot provider back at the row that is now on top of the substep stack.
     *
     * WHY it exists: the provider holds exactly one name - the UID of the row a screenshot would
     * belong to - and every substep overwrites it on entry. Nothing restored it on exit, so a PARENT
     * substep that failed AFTER its children had run wrote its picture under the LAST CHILD's UID.
     * The image landed on disk, but the parent row referenced a name no consumer resolves, which is
     * why such failures looked as if no screenshot had been taken at all.
     *
     * WHY it walks the stack: entries can be null markers left by a failed row INSERT, so the nearest
     * RECORDED ancestor - the main step if there is none - is the row that owns the screen from here
     * on.
     *
     * @return void
     */
    private function restoreScreenshotName(): void
    {
        for ($i = count($this->substepDataSheets) - 1; $i >= 0; $i--) {
            if ($this->substepDataSheets[$i] !== null) {
                $this->provider->setName($this->substepDataSheets[$i]->getUidColumn()->getValue(0));
                return;
            }
        }
        if ($this->stepDataSheet !== null) {
            $this->provider->setName($this->stepDataSheet->getUidColumn()->getValue(0));
        }
    }

    /**
     * Creates a run_step row for a step or substep.
     *
     * Why it throws instead of writing a partial row: the run_scenario FK is mandatory. Callers are
     * expected to check getOpenScenarioUid() first; if one ever forgets, we must fail here rather
     * than send an empty FK to the database, which can abort the whole lane. Throwing keeps the
     * failure inside the caller's catch block, where it becomes a log entry instead of a corrupt row.
     */
    protected function logStepStart(string $title, int $line, ?string $parentStepUid = null) : DataSheetInterface
    {
        $scenarioUid = $this->getOpenScenarioUid();
        if ($scenarioUid === null) {
            throw new RuntimeException('Cannot record step "' . $title . '": ' . $this->getScenarioSkipReason());
        }
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_step');
        $row = [
            'run_scenario' => $scenarioUid,
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
        // A timeout carries exactly the same forensic value as a failure: the screenshot
        // shows what the UI was stuck on, and the message and log id are the only trail
        // back to the cause. Keying this block on FAILED alone would silently strip all of
        // it the moment a step is reclassified as TIMEOUT.
        if ($stepStatusCode === StepStatusDataType::FAILED || $stepStatusCode === StepStatusDataType::TIMEOUT) {
            // Ask for THIS row, not for "anything at all": the picture is taken while the row is
            // still open and several calls happen before it is closed - failure cleanup, nested
            // substeps, back navigation - each of which points the provider at another row. A plain
            // captured-flag was therefore either already cleared again or belonged to a sibling.
            if ($this->provider->isCapturedFor($ds->getUidColumn()->getValue(0))) {
                // getFileName(), not getName(): the latter is only the BASE name for the next capture.
                $screenshotRelativePath = $this->provider->getPath() . DIRECTORY_SEPARATOR . $this->provider->getFileName();
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

        // No valid open scenario → a run_step cannot be created (it needs a run_scenario FK).
        // Fall back to a plain workbench log entry so the failure is never silently lost.
        $scenarioUid = $this->getOpenScenarioUid();
        if ($scenarioUid === null) {
            $this->workbench->getLogger()->logException($e ?? new RuntimeException($title));
            return $ds;
        }

        $row = [
            'run_scenario' => $scenarioUid,
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
     * Reports whether a step failure was caused by a browser wait timeout.
     *
     * WHY the chain is walked instead of a plain instanceof: a BrowserTimeoutException
     * raised deep inside the wait manager is rarely what reaches Behat. Intermediate
     * layers re-wrap it while adding context ("Wait operation failed (after step): ...",
     * "Failed to load UI5 application DB: ..."), so the outermost throwable is almost
     * never the timeout itself. Testing only the top of the chain would classify nearly
     * every timeout as an ordinary failure and leave this mapping useless in practice.
     *
     * @param \Throwable|null $e The exception recorded for the step, if any
     * @return bool
     */
    protected function isTimeoutException(?\Throwable $e): bool
    {
        while ($e !== null) {
            if ($e instanceof BrowserTimeoutException) {
                return true;
            }
            $e = $e->getPrevious();
        }
        return false;
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

    /**
     * Deletes expired BDT test runs and lets the model cascade take care of everything below them.
     *
     * WHY parent-only: the delete logic must stay in ONE place. run_feature, run_scenario, run_step
     * and run_step_screenshot are all reachable from run through delete-with-related-object
     * relations, so removing the run row is the single instruction that expresses "this run and
     * everything it produced is gone". Enumerating the children here would duplicate knowledge that
     * already lives in the meta model and would silently rot whenever the model changes.
     *
     * WHY one transaction: a half-deleted run (run_feature gone, run_step left behind) is worse than
     * no cleanup at all, because the orphans are unreachable through the model and can only be found
     * by hand in SQL. Either the whole tree goes or nothing does.
     *
     * WHY the pass is capped and oldest-first: deleting a large backlog in one go can exhaust memory
     * on the results database - the very failure this cleanup fights. The pass therefore takes at
     * most CLEANUP.DELETE_BATCH runs, sorted oldest first, so each scheduled run drains a bounded
     * slice of the backlog and the next one continues where this one stopped. Sorting matters: an
     * unsorted limited read would pick an arbitrary slice and could leave the oldest runs - the ones
     * the retention window is actually about - alive indefinitely.
     *
     * WHY addResultMessage instead of a return value: OnCleanUpEvent ignores whatever the listener
     * returns, so the only way to report back to the operator running the CleanUp is the event itself.
     *
     * KNOWN LIMITATION - screenshots live in a file data source. File deletes are NOT part of the
     * transaction, so a rollback after the files are gone cannot bring them back.
     *
     * @param OnCleanUpEvent $event
     * @return void
     */
    public static function onCleanUp(OnCleanUpEvent $event) : void
    {
        $workbench = $event->getWorkbench();
        $config = $workbench->getApp('axenox.BDT')->getConfig();

        // Retention is opt-in: without an explicit positive age, keep every run.
        if (! $config->hasOption(self::CLEANUP_DAYS_TO_KEEP)) {
            $event->addResultMessage('BDT: no cleanup - option "' . self::CLEANUP_DAYS_TO_KEEP . '" is not set.');
            return;
        }
        $maxAgeDays = (int) $config->getOption(self::CLEANUP_DAYS_TO_KEEP);
        if ($maxAgeDays <= 0) {
            $event->addResultMessage('BDT: no cleanup - option "' . self::CLEANUP_DAYS_TO_KEEP . '" must be a positive number of days.');
            return;
        }

        // An unset or non-positive batch size must not be read as "unlimited": an unbounded pass is
        // exactly the memory-exhausting delete this cap exists to prevent, so fall back to the default.
        $batchSize = $config->hasOption(self::CLEANUP_DELETE_BATCH)
            ? (int) $config->getOption(self::CLEANUP_DELETE_BATCH)
            : self::DELETE_BATCH_DEFAULT;
        if ($batchSize <= 0) {
            $batchSize = self::DELETE_BATCH_DEFAULT;
        }

        // Cutoff = now - maxAgeDays. Runs created strictly before this are eligible for deletion.
        $cutoff = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . $maxAgeDays . 'D'));
        $cutoffStr = DateTimeDataType::formatDateNormalized($cutoff);

        $runs = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.BDT.run');
        $runs->getColumns()->addFromUidAttribute();
        $runs->getFilters()->addConditionFromString('CREATED_ON', $cutoffStr, ComparatorDataType::LESS_THAN);
        $runs->getSorters()->addFromString('CREATED_ON', SortingDirectionsDataType::ASC);
        $runs->setRowsLimit($batchSize);
        // No total count is needed - the message below infers a remaining backlog from a full batch -
        // and skipping it saves an extra COUNT over a table this cleanup exists because it is large.
        $runs->setAutoCount(false);
        $runs->dataRead();

        // An empty sheet must never reach dataDelete(): with no rows and no narrowing filter the
        // delete scope would widen to the whole object - that is how a cleanup wipes a table.
        if ($runs->isEmpty()) {
            $event->addResultMessage('BDT: no test runs older than ' . $maxAgeDays . ' days - nothing to clean up.');
            return;
        }

        $transaction = $workbench->data()->startTransaction();
        try {
            // The cascade cannot order a MULTI-level self-referencing hierarchy: a substep can itself
            // have substeps, so a mid-level step gets deleted before its own children and trips the
            // RESTRICT FK (SQL error 1451). Depth is data-dependent, so no fixed number of passes
            // works either. Read the whole parent/child map once, compute each step's depth in PHP and
            // delete deepest-first - depth-agnostic, one read, no recursion, same transaction.
            self::deleteRunStepsLeafFirst($workbench, $runs->getUidColumn()->getValues(false), $transaction);
            $deleted = $runs->dataDelete($transaction);
            $transaction->commit();
        } catch (\Throwable $e) {
            // Roll back so a partially deleted tree never persists, then re-throw: the real error
            // (e.g. the 1451 FK violation above) must stay visible instead of being swallowed here.
            $transaction->rollback();
            throw $e;
        }

        // A full batch means the age filter still matches more runs, so the operator knows the
        // backlog is being drained gradually rather than assuming this pass finished the job.
        $message = 'BDT: removed ' . $deleted . ' test runs older than ' . $maxAgeDays . ' days.';
        if ($runs->countRows() >= $batchSize) {
            $message .= ' Batch limit of ' . $batchSize . ' reached - more runs remain and will be removed by the next cleanup.';
        }
        $event->addResultMessage($message);
    }

    /**
     * Deletes all run_step rows belonging to the given runs in leaf-to-root order.
     *
     * WHY this exists: bdt_run_step.parent_step_oid references bdt_run_step.oid with ON DELETE
     * RESTRICT. MS SQL Server forbids ON DELETE CASCADE on a self-referencing table, so the database
     * cannot be taught this order and the constraint cannot simply be relaxed - the order has to come
     * from the application. Doing it here rather than per call site keeps the cleanup a single unit.
     *
     * WHY depth is computed in PHP: expressing "has no remaining children" as a data sheet filter
     * would mean one query per hierarchy level. One flat read plus an in-memory depth calculation
     * costs a single round trip regardless of how deep the tree gets.
     *
     * @param WorkbenchInterface $workbench
     * @param string[] $runUids UIDs of the runs whose steps are to be removed
     * @param DataTransactionInterface $transaction Transaction the deletes must join
     * @return int Number of deleted step rows
     */
    private static function deleteRunStepsLeafFirst(
        WorkbenchInterface $workbench,
        array $runUids,
        DataTransactionInterface $transaction
    ) : int
    {
        if (empty($runUids)) {
            return 0;
        }

        $steps = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.BDT.run_step');
        $steps->getColumns()->addFromUidAttribute();
        $steps->getColumns()->addFromExpression('parent_step');
        $steps->getFilters()->addConditionFromValueArray('run_scenario__run_feature__run__UID', $runUids);
        $steps->dataRead();
        if ($steps->isEmpty()) {
            return 0;
        }

        $uidAlias = $steps->getMetaObject()->getUidAttributeAlias();
        $parentOf = [];
        foreach ($steps->getRows() as $row) {
            $parentOf[$row[$uidAlias]] = $row['parent_step'] ?: null;
        }

        // Walk each step up to its root to get its depth. The visited set is not an optimisation but
        // a safety net: corrupt data with a parent cycle would otherwise loop forever inside a
        // transaction and hang the scheduled cleanup.
        $byDepth = [];
        foreach (array_keys($parentOf) as $uid) {
            $depth = 0;
            $cursor = $uid;
            $visited = [];
            while (($parent = $parentOf[$cursor] ?? null) !== null) {
                if (isset($visited[$parent])) {
                    throw new RuntimeException('Cannot clean up BDT test runs: the run_step hierarchy contains a cycle at step "' . $parent . '".');
                }
                $visited[$parent] = true;
                $cursor = $parent;
                $depth++;
            }
            $byDepth[$depth][] = $uid;
        }
        krsort($byDepth);

        $deleted = 0;
        foreach ($byDepth as $uids) {
            $batch = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.BDT.run_step');
            $batch->getColumns()->addFromUidAttribute();
            $batch->getFilters()->addConditionFromValueArray($uidAlias, $uids);
            $batch->dataRead();
            if (! $batch->isEmpty()) {
                // Deleting a step also cascades to its screenshots, so the files follow the rows.
                $deleted += $batch->dataDelete($transaction);
            }
        }
        return $deleted;
    }

    /**
     * Passes the current run UID to the screenshot provider once the run is known.
     *
     * Why this exists: captureScreenshot() groups files under Screenshots/<run_uid>/, so the provider
     * must carry the run UID before any step screenshot is taken. The UID is stable for the whole run,
     * so it is set once here - from both the normal startRun flow and attach-mode - instead of being
     * repeated on every step.
     *
     * @return void
     */
    private function bindRunUidToProvider() : void
    {
        $runUid = $this->getCurrentRunUid();
        if ($runUid !== null && $runUid !== '') {
            $this->provider->setRunUid($runUid);
        }
    }
}