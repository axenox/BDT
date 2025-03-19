<?php

namespace axenox\BDT\Behat\TwigFormatter\Formatter;

use axenox\BDT\Exceptions\FacadeBrowserException;
use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use Behat\Behat\EventDispatcher\Event\AfterOutlineTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\Tester\Result\ExecutedStepResult;
use Behat\Testwork\Counter\Memory;
use Behat\Testwork\Counter\Timer;
use Behat\Testwork\EventDispatcher\Event\AfterExerciseCompleted;
use Behat\Testwork\EventDispatcher\Event\AfterSuiteTested;
use Behat\Testwork\EventDispatcher\Event\BeforeExerciseCompleted;
use Behat\Testwork\EventDispatcher\Event\BeforeSuiteTested;
use Behat\Testwork\EventDispatcher\Event\ExerciseCompleted;
use Behat\Testwork\Output\Exception\BadOutputPathException;
use Behat\Testwork\Output\Formatter;
use Behat\Testwork\Output\Printer\OutputPrinter;
use axenox\BDT\Behat\TwigFormatter\Classes\Feature;
use axenox\BDT\Behat\TwigFormatter\Classes\Scenario;
use axenox\BDT\Behat\TwigFormatter\Classes\Step;
use axenox\BDT\Behat\TwigFormatter\Classes\Suite;
use axenox\BDT\Behat\TwigFormatter\Printer\FileOutputPrinter;
use axenox\BDT\Behat\TwigFormatter\Renderer\BaseRenderer;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;

global $suiteStartDate, $suiteEndDate, $featureStartDate, $featureEndDate, $scenarioStartDate, $scenarioEndDate, $stepStartDate, $stepEndDate;

/**
 * Class BehatFormatter
 * @package tests\features\formatter
 */
class BehatFormatter implements Formatter
{
    private $exceptionListener;

    //<editor-fold desc="Variables">
    /**
     * @var array
     */
    private $parameters;

    /**
     * @var
     */
    private $name;

    /**
     * @var
     */
    private $timer;

    /**
     * @var
     */
    private $memory;

    /**
     * @param String $outputPath where to save the generated report file
     */
    private $outputPath;

    /**
     * @param String $base_path Behat base path
     */
    private $base_path;

    /**
     * Printer used by this Formatter and Context
     * @param $printer OutputPrinter
     */
    private $printer;

    /**
     * Renderer used by this Formatter
     * @param $renderer BaseRenderer
     */
    private $renderer;

    /**
     * Flag used by this Formatter
     * @param $print_args boolean
     */
    private $print_args;

    /**
     * Flag used by this Formatter
     * @param $print_outp boolean
     */
    private $print_outp;

    /**
     * Flag used by this Formatter
     * @param $loop_break boolean
     */
    private $loop_break;

    /**
     * Flag used by this Formatter
     * @param $show_tags boolean
     */
    private $show_tags;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectname;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectdescription;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectimage;

    /**
     * @var Array
     */
    private $suites;

    /**
     * @var Suite
     */
    private $currentSuite;

    /**
     * @var int
     */
    private $featureCounter = 1;

    /**
     * @var Feature
     */
    private $currentFeature;

    /**
     * @var Scenario
     */
    private $currentScenario;

    /**
     * @var Scenario[]
     */
    private $failedScenarios;

    /**
     * @var Scenario[]
     */
    private $passedScenarios;

    /**
     * @var Feature[]
     */
    private $failedFeatures;

    /**
     * @var Feature[]
     */
    private $passedFeatures;

    /**
     * @var Step[]
     */
    private $failedSteps;

    /**
     * @var Step[]
     */
    private $passedSteps;

    /**
     * @var Step[]
     */
    private $pendingSteps;

    /**
     * @var Step[]
     */
    private $skippedSteps;

    private $assetsSubfolder;


    //</editor-fold>

    //<editor-fold desc="Formatter functions">
    /**
     * @param $name
     * @param $base_path
     */
    public function __construct($name, $projectName, $projectImage, $projectDescription, $renderer, $filename, $print_args, $print_outp, $loop_break, $show_tags, $base_path)
    {
        $this->projectname = $projectName;
        $this->projectimage = $projectImage;
        $this->projectdescription = $projectDescription;
        $this->name = $name;
        $this->base_path = $base_path;
        $this->print_args = $print_args;
        $this->print_outp = $print_outp;
        $this->loop_break = $loop_break;
        $this->show_tags = $show_tags;
        $this->renderer = new BaseRenderer($renderer, $base_path);
        $this->assetsSubfolder = $filename == 'generated' ? date('YmdHis') : FilePathDataType::findFileName($filename, false);
        $this->printer = new FileOutputPrinter($this->renderer->getNameList(), $filename, $base_path);
        $this->timer = new Timer();
        $this->timerFeature = new Timer();
        $this->memory = new Memory();

        // Initialize the exception listener but don't try to register it directly
        $exceptionPresenter = new \Behat\Testwork\Exception\ExceptionPresenter();
        $this->exceptionListener = new \axenox\BDT\Behat\Listeners\GlobalExceptionListener($exceptionPresenter);
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return array(
            'tester.exercise_completed.before' => 'onBeforeExercise',
            'tester.exercise_completed.after' => 'onAfterExercise',
            'tester.suite_tested.before' => 'onBeforeSuiteTested',
            'tester.suite_tested.after' => 'onAfterSuiteTested',
            'tester.feature_tested.before' => 'onBeforeFeatureTested',
            'tester.feature_tested.after' => 'onAfterFeatureTested',
            'tester.scenario_tested.before' => 'onBeforeScenarioTested',
            'tester.scenario_tested.after' => 'onAfterScenarioTested',
            'tester.outline_tested.before' => 'onBeforeOutlineTested',
            'tester.outline_tested.after' => 'onAfterOutlineTested',
            'tester.step_tested.after' => 'onAfterStepTested',
        );
    }

    /**
     * Returns formatter name.
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getProjectName()
    {
        return $this->projectname;
    }

    /**
     * Copy temporary screenshot folder to the assets folder
     */
    public function copyTempScreenshotDirectory()
    {
        // Build paths for source and destination directories

        $source = getcwd() . DIRECTORY_SEPARATOR . ".tmp_behatFormatter";
        $destination = FilePathDataType::normalize(
            $this->printer->getOutputPath()
            . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . FilePathDataType::findFileName($this->assetsSubfolder),
            DIRECTORY_SEPARATOR
        );
        // Log the start of copy operation

        error_log("Copying screenshots from: " . $source . " to: " . $destination);
        // If destination exists, delete it to ensure clean copy

        if (is_dir($destination)) {
            Filemanager::deleteDir($destination);
        }
        // Create destination directory structure

        Filemanager::pathConstruct($destination);
        // Move screenshot directory from temp location to final destination
        // Using rename instead of copy for better performance
        $result = @rename($source, $destination . DIRECTORY_SEPARATOR . 'screenshots');
        error_log("Screenshot copy result: " . ($result ? 'Success' : 'Failed'));

    }

    /**
     * @return string
     */
    public function getProjectImage()
    {
        $imagepath = $this->projectimage ? realpath($this->projectimage) : null;
        if ($imagepath === FALSE || $this->projectimage === null) {
            //There is no image to copy
            return null;
        }

        // Copy project image to assets folder

        //first create the assets dir
        $destination = $this->printer->getOutputPath() . DIRECTORY_SEPARATOR . 'assets';
        @mkdir($destination);

        $filename = basename($imagepath);
        copy($imagepath, $destination . DIRECTORY_SEPARATOR . $filename);

        return "assets/" . $filename;
    }

    /**
     * @return string
     */
    public function getProjectDescription()
    {
        return $this->projectdescription;
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->base_path;
    }

    /**
     * Returns formatter description.
     * @return string
     */
    public function getDescription()
    {
        return "Elkan's behat Formatter";
    }

    /**
     * Returns formatter output printer.
     * @return OutputPrinter
     */
    public function getOutputPrinter()
    {
        return $this->printer;
    }

    /**
     * Sets formatter parameter.
     * @param string $name
     * @param mixed $value
     */
    public function setParameter($name, $value)
    {
        $this->parameters[$name] = $value;
    }

    /**
     * Returns parameter name.
     * @param string $name
     * @return mixed
     */
    public function getParameter($name)
    {
        return $this->parameters[$name];
    }

    /**
     * Verify that the specified output path exists or can be created,
     * then sets the output path.
     * @param String $path Output path relative to %paths.base%
     * @throws BadOutputPathException
     */
    public function setOutputPath($path)
    {
        $outpath = realpath($this->base_path . DIRECTORY_SEPARATOR . $path);
        if (!file_exists($outpath)) {
            if (!mkdir($outpath, 0755, true)) {
                throw new BadOutputPathException(
                    sprintf(
                        'Output path %s does not exist and could not be created!',
                        $outpath
                    ),
                    $outpath
                );
            }
        } else {
            if (!is_dir($outpath)) {
                throw new BadOutputPathException(
                    sprintf(
                        'The argument to `output` is expected to the a directory, but got %s!',
                        $outpath
                    ),
                    $outpath
                );
            }
        }
        $this->outputPath = $outpath;
    }

    /**
     * Returns output path
     * @return String output path
     */
    public function getOutputPath()
    {
        return $this->outputPath;
    }

    /**
     * Returns if it should print the step arguments
     * @return boolean
     */
    public function getPrintArguments()
    {
        return $this->print_args;
    }

    /**
     * Returns if it should print the step outputs
     * @return boolean
     */
    public function getPrintOutputs()
    {
        return $this->print_outp;
    }

    /**
     * Returns if it should print scenario loop break
     * @return boolean
     */
    public function getPrintLoopBreak()
    {
        return $this->loop_break;
    }

    /**
     * Returns date and time
     * @return string
     */
    public function getBuildDate()
    {
        $datetime = (date('I')) ? 7200 : 3600;
        return gmdate("D d M Y H:i", time() + $datetime);
    }


    /**
     * Returns if it should print tags
     * @return boolean
     */
    public function getPrintShowTags()
    {
        return $this->show_tags;
    }

    public function getTimer()
    {
        return $this->timer;
    }

    public function getTimerFeature()
    {
        return $this->timerFeature;
    }

    public function getMemory()
    {
        return $this->memory;
    }

    public function getSuites()
    {
        return $this->suites;
    }

    public function getCurrentSuite()
    {
        return $this->currentSuite;
    }

    public function getFeatureCounter()
    {
        return $this->featureCounter;
    }

    public function getCurrentFeature()
    {
        return $this->currentFeature;
    }

    public function getCurrentScenario()
    {
        return $this->currentScenario;
    }

    public function getFailedScenarios()
    {
        return $this->failedScenarios;
    }

    public function getPassedScenarios()
    {
        return $this->passedScenarios;
    }

    public function getFailedFeatures()
    {
        return $this->failedFeatures;
    }

    public function getPassedFeatures()
    {
        return $this->passedFeatures;
    }

    public function getFailedSteps()
    {
        return $this->failedSteps;
    }

    public function getPassedSteps()
    {
        return $this->passedSteps;
    }

    public function getPendingSteps()
    {
        return $this->pendingSteps;
    }

    public function getSkippedSteps()
    {
        return $this->skippedSteps;
    }
    //</editor-fold>

    //<editor-fold desc="Event functions">
    /**
     * @param BeforeExerciseCompleted $event
     */
    public function onBeforeExercise(BeforeExerciseCompleted $event)
    {
        $this->timer->start();

        $print = $this->renderer->renderBeforeExercise($this);
        $this->printer->write($print);
    }

    /**
     * @param AfterExerciseCompleted $event
     */
    public function onAfterExercise(ExerciseCompleted $event)
    {

        $this->timer->stop();

        $print = $this->renderer->renderAfterExercise($this);
        $this->printer->writeln($print);
    }

    /**
     * @param BeforeSuiteTested $event
     */
    public function onBeforeSuiteTested(BeforeSuiteTested $event)
    {
        $this->currentSuite = new Suite();
        $this->currentSuite->setName($event->getSuite()->getName());

        $print = $this->renderer->renderBeforeSuite($this);
        $this->printer->writeln($print);
    }

    /**
     * @param AfterSuiteTested $event
     */
    public function onAfterSuiteTested(AfterSuiteTested $event)
    {

        $this->suites[] = $this->currentSuite;

        $print = $this->renderer->renderAfterSuite($this);
        $this->printer->writeln($print);
    }

    /**
     * @param BeforeFeatureTested $event
     */
    public function onBeforeFeatureTested(BeforeFeatureTested $event)
    {
        // datetime
        $GLOBALS['featureStartDate'] = new \DateTime(date("Y-m-d H:i:s"));

        $feature = new Feature();
        $feature->setId($this->featureCounter);
        $this->featureCounter++;
        $feature->setName($event->getFeature()->getTitle());
        $feature->setDescription($event->getFeature()->getDescription());
        $feature->setTags($event->getFeature()->getTags());
        $feature->setFile($event->getFeature()->getFile());
        $feature->setScreenshotFolder($event->getSuite()->getName() . '/' . $event->getFeature()->getTitle());
        $this->currentFeature = $feature;

        $print = $this->renderer->renderBeforeFeature($this);
        $this->printer->writeln($print);
    }

    /**
     * @param AfterFeatureTested $event
     */
    public function onAfterFeatureTested(AfterFeatureTested $event)
    {
        // datetime
        $endTime = new \DateTime(date("Y-m-d H:i:s"));
        $GLOBALS['featureEndDate'] = $GLOBALS['featureStartDate']->diff($endTime);
        // setDuration for the Feature
        $this->currentFeature->setTime($GLOBALS['featureEndDate']->format("%H:%I:%S"));

        $this->currentSuite->addFeature($this->currentFeature);
        if ($this->currentFeature->allPassed()) {
            $this->passedFeatures[] = $this->currentFeature;
        } else {
            $this->failedFeatures[] = $this->currentFeature;
        }

        $print = $this->renderer->renderAfterFeature($this);
        $this->printer->writeln($print);
    }

    /**
     * @param BeforeScenarioTested $event
     */
    public function onBeforeScenarioTested(BeforeScenarioTested $event)
    {
        // datetime
        $GLOBALS['scenarioStartDate'] = new \DateTime(date("Y-m-d H:i:s"));

        $scenario = new Scenario();
        $scenario->setName($event->getScenario()->getTitle());
        $scenario->setTags($event->getScenario()->getTags());
        $scenario->setLine($event->getScenario()->getLine());
        $scenario->setScreenshotName($event->getScenario()->getTitle());
        $this->currentScenario = $scenario;

        $print = $this->renderer->renderBeforeScenario($this);
        $this->printer->writeln($print);
    }

    /**
     * @param AfterScenarioTested $event
     */
    public function onAfterScenarioTested(AfterScenarioTested $event)
    {
        // datetime
        $endTime = new \DateTime(date("Y-m-d H:i:s"));
        $GLOBALS['scenarioEndDate'] = $GLOBALS['scenarioStartDate']->diff($endTime);
        // setDuration for the Feature
        $this->currentScenario->setTime($GLOBALS['scenarioEndDate']->format("%H:%I:%S"));

        $scenarioPassed = $event->getTestResult()->isPassed();

        if ($scenarioPassed) {
            $this->passedScenarios[] = $this->currentScenario;
            $this->currentFeature->addPassedScenario();
        } else {
            $this->failedScenarios[] = $this->currentScenario;
            $this->currentFeature->addFailedScenario();
        }

        $this->currentScenario->setLoopCount(1);
        $this->currentScenario->setPassed($event->getTestResult()->isPassed());
        $this->currentFeature->addScenario($this->currentScenario);

        $print = $this->renderer->renderAfterScenario($this);
        $this->printer->writeln($print);
    }

    /**
     * @param BeforeOutlineTested $event
     */
    public function onBeforeOutlineTested(BeforeOutlineTested $event)
    {
        $scenario = new Scenario();
        $scenario->setName($event->getOutline()->getTitle());
        $scenario->setTags($event->getOutline()->getTags());
        $scenario->setLine($event->getOutline()->getLine());
        $this->currentScenario = $scenario;

        $print = $this->renderer->renderBeforeOutline($this);
        $this->printer->writeln($print);
    }

    /**
     * @param AfterOutlineTested $event
     */
    public function onAfterOutlineTested(AfterOutlineTested $event)
    {
        $scenarioPassed = $event->getTestResult()->isPassed();

        if ($scenarioPassed) {
            $this->passedScenarios[] = $this->currentScenario;
            $this->currentFeature->addPassedScenario();
        } else {
            $this->failedScenarios[] = $this->currentScenario;
            $this->currentFeature->addFailedScenario();
        }

        $this->currentScenario->setLoopCount(sizeof($event->getTestResult()));
        $this->currentScenario->setPassed($event->getTestResult()->isPassed());
        $this->currentFeature->addScenario($this->currentScenario);

        $print = $this->renderer->renderAfterOutline($this);
        $this->printer->writeln($print);
    }

    /**
     * @param BeforeStepTested $event
     */
    public function onBeforeStepTested(BeforeStepTested $event)
    {
        $print = $this->renderer->renderBeforeStep($this);
        $this->printer->writeln($print);
    }


    /**
     * Handles the event triggered after a step is tested
     * Collects step information, manages error reporting, and handles screenshots
     * @param AfterStepTested $event The event containing information about the tested step
     */
    public function onAfterStepTested(AfterStepTested $event)
    {
        error_log("\n=== onAfterStepTested Start ===");
        $result = $event->getTestResult();


        // Create a new Step object and populate it with data from the event
        $step = new Step();
        $step->setKeyword($event->getStep()->getKeyword());
        $step->setText($event->getStep()->getText());
        $step->setLine($event->getStep()->getLine());
        $step->setArguments($event->getStep()->getArguments());
        $step->setResult($result);
        $step->setResultCode($result->getResultCode());

        // If step failed, collect error information for reporting
        if (!$result->isPassed()) {
            $exception = $result instanceof ExecutedStepResult ? $result->getException() : null;
            switch (true) {
                case $exception instanceof FacadeBrowserException:
                    $step->addOutput($exception->toCliOutput());
                    break;
                case ErrorManager::getInstance()->hasErrors():
                    $error = ErrorManager::getInstance()->getFirstError();
                    $step->addOutput($error->toCliOutput());
                case $exception === null:
                    // TODO what if there is no exception?
                    break;
                default:
                    $errorMessage = "(";
                    $errorMessage .= "During the page operation, founded an issue:\n\n";
                    $errorMessage .= $exception->getMessage();
                    $errorMessage .= "------------aaa------------\n";
                    $errorMessage .= ")";
            }
        }

        // Handle screenshots for passed "Then" steps or steps with specific result code
        if (($step->getResultCode() == "99") || ($step->getResult()->isPassed() && $step->getKeyword() === "Then")) {
            $screenshot = self::buildScreenshotFilename(
                $event->getSuite()->getName(),
                $event->getFeature()->getFile(),
                $event->getStep()->getLine()
            );

            // Check if screenshot exists in temporary location
            $tempPath = getcwd() . DIRECTORY_SEPARATOR . ".tmp_behatFormatter" . DIRECTORY_SEPARATOR . $screenshot;
            if (file_exists($tempPath)) {
                // Build the path to the screenshot in the assets directory
                $screenshot = 'assets/'
                    . FilePathDataType::findFileName($this->assetsSubfolder)
                    . '/screenshots/'
                    . $screenshot;
                $step->setScreenshot($screenshot);
            }
        }

        if (!$result->isPassed()) {
            $screenshot = self::buildScreenshotFilename(
                $event->getSuite()->getName(),
                $event->getFeature()->getFile(),
                $event->getStep()->getLine()
            );

            $tempPath = getcwd() . DIRECTORY_SEPARATOR . ".tmp_behatFormatter" . DIRECTORY_SEPARATOR . $screenshot;
            if (file_exists($tempPath)) {
                $screenshot = 'assets/'
                    . FilePathDataType::findFileName($this->assetsSubfolder)
                    . '/screenshots/'
                    . $screenshot;
                $step->setScreenshot($screenshot);
            }
        }

        $this->currentScenario->addStep($step);

        $print = $this->renderer->renderAfterStep($this);
        $this->printer->writeln($print);
    }
    //</editor-fold>

    /**
     * @param $text
     */
    public function printText($text)
    {
        file_put_contents('php://stdout', $text);
    }

    /**
     * @param $obj
     */
    public function dumpObj($obj)
    {
        ob_start();
        var_dump($obj);
        $result = ob_get_clean();
        $this->printText($result);
    }

    public static function buildScreenshotFilename(string $suiteName, string $featureFilePath, int $featureLine): string
    {
        return $suiteName
            . "." . str_replace('.feature', '', basename($featureFilePath))
            . "." . $featureLine
            . "." . date("YmdHis")
            . ".png";
    }
}


// v1