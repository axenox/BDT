<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use axenox\BDT\DataTypes\StepStatusDataType;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\EventDispatcher\Event\BeforeExerciseCompleted;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use Behat\Testwork\EventDispatcher\Event\AfterExerciseCompleted;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Exceptions\ExceptionInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Behat\Testwork\Tester\Result\TestResult;

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

    private ?DataSheetInterface $stepDataSheet = null;
    private float               $stepStart;
    private int                 $stepIdx = 0;

    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            BeforeExerciseCompleted::BEFORE => 'onBeforeExercise',
            AfterExerciseCompleted::AFTER => 'onAfterExercise',
            BeforeFeatureTested::BEFORE => 'onBeforeFeature',
            AfterFeatureTested::AFTER => 'onAfterFeature',
            BeforeScenarioTested::BEFORE => 'onBeforeScenario',
            AfterScenarioTested::AFTER => 'onAfterScenario',
            BeforeStepTested::BEFORE => 'onBeforeStep',
            AfterStepTested::AFTER => 'onAfterStep',
        ];
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

    public function onBeforeExercise() {
        $this->runStart = $this->microtime();

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run');
        $ds->addRow([
            'started_on' => DateTimeDataType::now()
        ]);
        $ds->dataCreate(false);
        $this->runDataSheet = $ds;
    }

    public function onAfterExercise() {
        $ds = $this->runDataSheet->extractSystemColumns();
        $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
        $ds->setCellValue('duration_ms', 0,$this->microtime() - $this->runStart);
        $ds->dataUpdate();
    }

    public function onBeforeFeature(BeforeFeatureTested $event) {
        $feature = $event->getFeature();
        $this->featureIdx++;
        $this->featureStart = $this->microtime();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_feature');
        $filename = $event->getFeature()->getFile();
        $filename = StringDataType::substringAfter($filename, $this->workbench->getInstallationPath(), $filename);
        $filename = FilePathDataType::normalize($filename, '/');
        $ds->addRow([
            'run' => $this->runDataSheet->getUidColumn()->getValue(0),
            'name' => $feature->getTitle(),
            'description' => $feature->getDescription(),
            'filename' => $filename,
            'started_on' => DateTimeDataType::now(),
            'run_sequence_idx' => $this->featureIdx,
            'content' => '' // TODO how to get the contents of the entire feature file?
        ]);
        $ds->dataCreate(false);
        $this->featureDataSheet = $ds;
    }

    public function onAfterFeature(AfterFeatureTested $event) {
        $ds = $this->featureDataSheet->extractSystemColumns();
        $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
        $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->runStart);
        $ds->dataUpdate();
    }

    public function onBeforeScenario(BeforeScenarioTested $event) {
        $scenario = $event->getScenario();
        $this->scenarioStart = $this->microtime();
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.run_scenario');
        $ds->addRow([
            'run_feature' => $this->featureDataSheet->getUidColumn()->getValue(0),
            'name' => $scenario->getTitle(),
            'line' => $scenario->getLine(),
            'started_on' => DateTimeDataType::now()
        ]);
        $ds->dataCreate(false);
        $this->scenarioDataSheet = $ds;
    }

    public function onAfterScenario(AfterScenarioTested $event) {
        $ds = $this->scenarioDataSheet->extractSystemColumns();
        $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
        $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->runStart);
        $ds->dataUpdate();
    }

    public function onBeforeStep(BeforeStepTested $event) {
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
    }

    public function onAfterStep(AfterStepTested $event) {
        $step = $event->getStep();
        $result = $event->getTestResult();

        $ds = $this->stepDataSheet->extractSystemColumns();
        $ds->setCellValue('finished_on', 0, DateTimeDataType::now());
        $ds->setCellValue('duration_ms', 0, $this->microtime() - $this->runStart);
        $ds->setCellValue('status', 0, StepStatusDataType::convertFromBehatResultCode($result->getResultCode()));
        if ($result->getResultCode() === TestResult::FAILED) {
            if ($e = $result->getException()) {
                $ds->setCellValue('error_message', 0, $e->getMessage());
                if ($e instanceof ExceptionInterface) {
                    $ds->setCellValue('error_log_id', 0, $e->getId());
                }
            }
        }
        $ds->dataUpdate();
    }
}