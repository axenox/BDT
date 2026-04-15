<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeExerciseCompleted;
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

    public function __construct(WorkbenchInterface $workbench, ScreenshotProviderInterface $provider, EventDispatcherInterface $eventDispatcher, array $chromeConfig = [])
    {
        self::$eventDispatcher = $eventDispatcher;
        $this->workbench = $workbench;
        $this->provider = $provider;
        ChromeManager::start($chromeConfig);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeExerciseCompleted::BEFORE => 'onBeforeExercise',
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
        $this->onAfterExercise();
        ChromeManager::stop();
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
    
    public function onBeforeExercise(): void
    {
        $this->runStart = $this->microtime();

        $cliArgs = $_SERVER['argv'] ?? [];
        $command = null;
        if (! empty($cliArgs)) {
            // First item is the file called - remove that
            $filepath = array_shift($cliArgs);
            $command = implode(' ', $cliArgs);
        }
        try{
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run');
            $ds->addRow([
                'started_on' => DateTimeDataType::now(),
                'behat_command' => $command
            ]);
            $ds->dataCreate(false);
            $this->runDataSheet = $ds;        
            
            $this->registerMetrics();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onAfterExercise(): void
    {
        try{
            if ($this->runDataSheet === null) {
                $this->onBeforeExercise();
            }
            $ds = $this->runDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0,$this->microtime() - $this->runStart);
            $ds->dataUpdate();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }
    
    public function onAfterSuite(AfterSuiteTested $event) : void
    {
        try{
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
        try{
            $ds = $this->featureDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->featureStart);
            $ds->dataUpdate();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onBeforeScenario(BeforeScenarioTested $event) {
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

    public function onBeforeOutline(BeforeOutlineTested $event) {
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
        try{
            $ds = $this->scenarioDataSheet->extractSystemColumns();
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->scenarioStart);
            $ds->dataUpdate();
            $cenarioUid = $ds->getUidColumn()->getValue(0);
    
            $dsActions = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_scenario_action');
            foreach (static::$scenarioPages as $pageAlias) {
                try {
                    $page = UiPageFactory::createFromModel($this->workbench, $pageAlias);
                    $pageUid = $page->getUid();
                } catch (\Throwable $e) {
                    $pageUid = null;
                }
                $dsActions->addRow([
                    'run_scenario' => $cenarioUid,
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
    }

    public function onBeforeStep(BeforeStepTested $event) 
    {
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
}