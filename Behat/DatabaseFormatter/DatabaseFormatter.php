<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use axenox\BDT\DataTypes\StepStatusDataType;
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
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class DatabaseFormatter implements Formatter
{
    private WorkbenchInterface  $workbench;
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
    private static array        $testedPages = [];
    private ScreenshotProviderInterface $provider;

    public function __construct(WorkbenchInterface $workbench, ScreenshotProviderInterface $provider)
    {
        $this->workbench = $workbench;
        $this->provider = $provider;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeExerciseCompleted::BEFORE => 'onBeforeExercise',
            // Use __destruct() to finish the log on inner errors too
            // AfterExerciseCompleted::AFTER => 'onAfterExercise',
            BeforeFeatureTested::BEFORE => 'onBeforeFeature',
            AfterFeatureTested::AFTER => 'onAfterFeature',
            BeforeScenarioTested::BEFORE => 'onBeforeScenario',
            AfterScenarioTested::AFTER => 'onAfterScenario',
            BeforeOutlineTested::BEFORE => 'onBeforeOutline',
            AfterOutlineTested::AFTER => 'onAfterScenario',
            BeforeStepTested::BEFORE => 'onBeforeStep',
            AfterStepTested::AFTER => 'onAfterStep',
            AfterSuiteTested::AFTER => 'onAfterSuite'
        ];
    }
    
    public function __destruct()
    {
        $this->onAfterExercise();
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
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onAfterExercise(): void
    {
        try{
            if ($this->runDataSheet === null) {
                $this->runStart = $this->onBeforeExercise();
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
            $dsActions->dataCreate();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }

    public function onBeforeStep(BeforeStepTested $event) 
    {
        try{
            $step = $event->getStep();
            $this->stepIdx++;
            $this->stepStart = $this->microtime();
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_step');
            $ds->addRow([
                'run_scenario' => $this->scenarioDataSheet->getUidColumn()->getValue(0),
                'run_sequence_idx' => $this->stepIdx,
                'name' => $step->getText(),
                'line' => $step->getLine(),
                'started_on' => DateTimeDataType::now(),
                'status' => 10
            ]);
            $ds->dataCreate(false);
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
            $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
            $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->stepStart);
            $ds->setCellValue('status', 0, StepStatusDataType::convertFromBehatResultCode($result->getResultCode()));
            if ($result->getResultCode() === TestResult::FAILED) {
                if($this->provider->isCaptured()) {
                    $screenshotRelativePath = $this->provider->getPath() . DIRECTORY_SEPARATOR . $this->provider->getName();
                    $ds->setCellValue('screenshot_path', 0, $screenshotRelativePath);
                }
                if ($e = $result->getException()) {
                    $ds->setCellValue('error_message', 0, $e->getMessage());
                    if(!empty($logId = ErrorManager::getInstance()->getLastLogId())) {
                        $ds->setCellValue('error_log_id', 0, $logId);
                    }
                }
            }
            $ds->dataUpdate();
        }
        catch(\Exception $e){
            ErrorManager::getInstance()->logExceptionWithId($e, 'DatabaseFormatter', $this->workbench);
        }
    }
    
    public static function addTestedPage(string $alias): void
    {
        if (!in_array($alias, static::$scenarioPages, true)) {
            static::$scenarioPages[] = $alias;
        }
        if (!in_array($alias, static::$testedPages, true)) {
            static::$testedPages[] = $alias;
        }
    }
    
}