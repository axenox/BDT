<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeStartResult;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use Behat\Testwork\Output\Formatter;
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
use exface\Core\Factories\FormulaFactory;
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
    private ScreenshotProviderInterface $provider;
    /** @var MarkdownLogBook[]  */
    private static array        $stepLogbooks = [];
    
    private ChromeStartResult $chromeStartResult;
    private bool $exerciseFinished = false;

    // Do not create a run record for dry-run executions.
    // Dry-run is used as a pre-flight syntax check and must not pollute the test results DB.
    private bool $isDryRun = false;

    public function __construct(WorkbenchInterface $workbench, ScreenshotProviderInterface $provider, EventDispatcherInterface $eventDispatcher, array $chromeConfig = [])
    {
        self::$eventDispatcher = $eventDispatcher;
        $this->workbench = $workbench;
        $this->provider = $provider;
        $this->isDryRun = in_array('--dry-run', $_SERVER['argv'] ?? [], true);
        if (!$this->isDryRun) {
            $chromeInstance = ChromeManager::getInstance($this->workbench->getLogger());
            $this->chromeStartResult = $chromeInstance->start($chromeConfig);
            $this->startRun();
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // BeforeExerciseCompleted::BEFORE => 'onBeforeExercise',
            // Use __destruct() to finish the log on inner errors too
            // AfterExerciseCompleted::AFTER => 'onAfterExercise',
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
            if ($this->isDryRun || $this->runDataSheet === null) {
                return;
            }
            
            $ds = $this->runDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0,$this->microtime() - $this->runStart);
            $ds->dataUpdate();
            
            // Mark as finished so that onShutdown() does not call this method a second time
            $this->exerciseFinished = true;
        }
        catch(\Throwable $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
            $ds->dataUpdate();
            $this->featureDataSheet = null;
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onBeforeScenario(BeforeScenarioTested $event) 
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        try{
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onBeforeOutline(BeforeOutlineTested $event) 
    {
        if ($this->isDryRun) {
            return;
        }
        static::$scenarioPages = [];
        try{
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
                } catch (\Throwable $e) {
                    $pageUid = null;
                }
                $dsActions->addRow([
                    'run_scenario' => $scenarioUid,
                    'page_alias' => $pageAlias,
                    'page' => $pageUid
                ]);
            }
            if (! $dsActions->isEmpty()) {
                $dsActions->dataCreate();
            }
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
        gc_collect_cycles();
    }

    public function onBeforeStep(BeforeStepTested $event) 
    {
        if ($this->isDryRun) {
            return;
        }
        static::$stepLogbooks = [];
        try{
            $step = $event->getStep();
            $this->stepIdx++;
            $this->stepStart = $this->microtime();
            $ds = $this->logStepStart($step->getText(), $step->getLine());
            $this->stepDataSheet = $ds;
            $this->provider->setName($ds->getUidColumn()->getValue(0));
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onAfterStep(AfterStepTested $event) 
    {
        try{
            if ($this->isDryRun) {
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
        } catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
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
     * {@inheritDoc}
     * @see TestRunObserverInterface::logError()
     */
    public function logError(string $title, ?\Throwable $e = null) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_step');
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
            if($e instanceof ExceptionInterface) {
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

    private function buildChromeInfo(array $extra = []): string
    {
        $formula = FormulaFactory::createFromString(
            $this->workbench,
            '=TimeFromSeconds(' . $this->chromeStartResult?->startupMs . ')'
        );
        $duration = $formula->evaluate();
        $data = [
            'port'              => $this->chromeStartResult?->port,
            'pid'               => $this->chromeStartResult?->pid,
            'startup_duration'  => $duration
        ];
        return json_encode(array_merge($data, $extra));
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
            ErrorManager::getInstance()->logExceptionWithId(
                new RuntimeException($message),
                'DatabaseFormatter::onShutdown',
                $this->workbench
            );
        }

        // Write finished_on only if normal flow (onAfterExercise) did not already do so (question 1)
        if (! $this->exerciseFinished) {
            $this->onAfterExercise();
        }

        ChromeManager::getInstance()->stop();
    }
    private function startRun(): void
    {
        if ($this->isDryRun) {
            return;
        }
        
        $this->runStart = $this->microtime();

        $cliArgs = $_SERVER['argv'] ?? [];
        $command = null;
        if (! empty($cliArgs)) {
            // First item is the file called - remove that
            array_shift($cliArgs);
            $command = implode(' ', $cliArgs);
        }
        try{
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run');
            $ds->addRow([
                'started_on' => DateTimeDataType::now(),
                'behat_command' => $command,
                'chrome_info'   => $this->buildChromeInfo(),
            ]);
            $ds->dataCreate(false);
            $this->runDataSheet = $ds;

            $this->registerMetrics();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
        // Register a shutdown function so that finished_on is always written,
        // even if Behat crashes with a fatal error or an uncaught exception.
        // __destruct() is NOT guaranteed to run in those cases, but shutdown functions are.
        register_shutdown_function(function () {
            $this->onShutdown();
        });
        
    }
}