<?php

namespace axenox\BDT\Behat\TwigFormatter\Formatter;

use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use Behat\Behat\EventDispatcher\Event\AfterFeatureTested;
use Behat\Behat\EventDispatcher\Event\AfterOutlineTested;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\BeforeFeatureTested;
use Behat\Behat\EventDispatcher\Event\BeforeOutlineTested;
use Behat\Behat\EventDispatcher\Event\BeforeScenarioTested;
use Behat\Behat\EventDispatcher\Event\BeforeStepTested;
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
    private $basePath;

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
    private $printArgs;

    /**
     * Flag used by this Formatter
     * @param $print_outp boolean
     */
    private $printOutp;

    /**
     * Flag used by this Formatter
     * @param $loop_break boolean
     */
    private $loopBreak;

    /**
     * Flag used by this Formatter
     * @param $show_tags boolean
     */
    private $showTags;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectName;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectDescription;

    /**
     * Flag used by this Formatter
     * @var string
     */
    private $projectImage;

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

    private ScreenshotProviderInterface $provider;

    private Timer $timerFeature;

    //</editor-fold>

    //<editor-fold desc="Formatter functions">

    /**
     * @param $name
     * @param $projectName
     * @param $projectImage
     * @param $projectDescription
     * @param $renderer
     * @param $filename
     * @param $printArgs
     * @param $printOutp
     * @param $loopBreak
     * @param $showTags
     * @param $basePath
     * @param ScreenshotProviderInterface $provider
     */
    public function __construct(
        $name,
        $projectName,
        $projectImage,
        $projectDescription,
        $renderer,
        $filename,
        $printArgs,
        $printOutp,
        $loopBreak,
        $showTags,
        $basePath,
        ScreenshotProviderInterface $provider
    )
    {
        $this->projectName = $projectName;
        $this->projectImage = $projectImage;
        $this->projectDescription = $projectDescription;
        $this->name = $name;
        $this->basePath = $basePath;
        $this->printArgs = $printArgs;
        $this->printOutp = $printOutp;
        $this->loopBreak = $loopBreak;
        $this->showTags = $showTags;
        $this->renderer = new BaseRenderer($renderer, $basePath);
        $this->printer = new FileOutputPrinter($this->renderer->getNameList(), $filename, $basePath);
        $this->timer = new Timer();
        $this->timerFeature = new Timer();
        $this->memory = new Memory();
        $this->provider = $provider;
        
        // Initialize the exception listener but don't try to register it directly
        $exceptionPresenter = new \Behat\Testwork\Exception\ExceptionPresenter();
        $this->exceptionListener = new \axenox\BDT\Behat\Listeners\GlobalExceptionListener($exceptionPresenter);
    }
    
    public function __destruct()
    {
        // whenever the formatter object is about to be destroyed
        // (i.e. at the very end of the test run, even on error), flush the report:
        $this->timer->stop();
        $print = $this->renderer->renderAfterExercise($this);
        $this->printer->writeln($print);
    }
    /**
     * Returns an array of event names this subscriber wants to listen to.
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents()
    {
        return array(
            'tester.exercise_completed.before' => 'onBeforeExercise',
            'tester.suite_tested.before' => 'onBeforeSuiteTested',
            'tester.suite_tested.after' => 'onAfterSuiteTested',
            'tester.feature_tested.before' => 'onBeforeFeatureTested',
            'tester.feature_tested.after' => 'onAfterFeatureTested',
            'tester.scenario_tested.before' => 'onBeforeScenarioTested',
            'tester.scenario_tested.after' => 'onAfterScenarioTested',
            'tester.outline_tested.before' => 'onBeforeOutlineTested',
            'tester.outline_tested.after' => 'onAfterOutlineTested',
            'tester.step_tested.after' => 'onAfterStepTested'
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
        return $this->projectName;
    }

    /**
     * @return string
     */
    public function getProjectImage()
    {
        $imagePath = $this->projectImage ? realpath($this->projectImage) : null;
        if ($imagePath === FALSE || $this->projectImage === null) {
            //There is no image to copy
            return null;
        }

        // Copy project image to assets folder

        //first create the assets dir
        $destination = $this->printer->getOutputPath() . DIRECTORY_SEPARATOR . 'assets';
        @mkdir($destination);

        $filename = basename($imagePath);
        copy($imagePath, $destination . DIRECTORY_SEPARATOR . $filename);

        return "assets/" . $filename;
    }

    /**
     * @return string
     */
    public function getProjectDescription()
    {
        return $this->projectDescription;
    }

    /**
     * @return string
     */
    public function getBasePath()
    {
        return $this->basePath;
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
        $outpath = realpath($this->basePath . DIRECTORY_SEPARATOR . $path);
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
        return $this->printArgs;
    }

    /**
     * Returns if it should print the step outputs
     * @return boolean
     */
    public function getPrintOutputs()
    {
        return $this->printOutp;
    }

    /**
     * Returns if it should print scenario loop break
     * @return boolean
     */
    public function getPrintLoopBreak()
    {
        return $this->loopBreak;
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
        return $this->showTags;
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
     * @throws \Exception
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
     * @throws \Exception
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
     * @throws \Exception
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
    public function onAfterStepTested(AfterStepTested $event): void
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
            if ($exception) {
                $errorManager = ErrorManager::getInstance();

                // If there are no errors already in the ErrorManager, create a new error message
                if (!$errorManager->hasErrors()) {
                    $errorMessage = "(";
                    $errorMessage .= "During the page operation, founded an issue:\n\n";
                    $message = $exception->getMessage();
                    $errorMessage .= "------------aaa------------\n";
                    $errorMessage .= ")";

                    $step->addOutput($errorMessage);
                } else {
                    // Get the existing error from ErrorManager
                    $error = $errorManager->getFirstError();
                    $step->addOutput($errorManager->formatErrorMessage($error));
                }
            }
        }

        if (!$result->isPassed()) {
            if($this->provider->isCaptured()){
                $screenshotPath = $this->basePath . DIRECTORY_SEPARATOR . $this->provider->getPath() . DIRECTORY_SEPARATOR . $this->provider->getName();
                if (file_exists($screenshotPath)) {
                    $relativeWebPath = $this->getRelativeWebPath($this->printer->getOutputPath(), $screenshotPath);
                    $step->setScreenshot($relativeWebPath);
                }
            }
        }

        $this->currentScenario->addStep($step);

        $print = $this->renderer->renderAfterStep($this);
        $this->printer->writeln($print);
    }

    /**
     * @param $text
     */
    public function printText($text): void
    {
        file_put_contents('php://stdout', $text);
    }

    /**
     * @param $obj
     */
    public function dumpObj($obj): void
    {
        ob_start();
        var_dump($obj);
        $result = ob_get_clean();
        $this->printText($result);
    }
    
    /**
     * Calculate the relative web path from one directory to another.
     *
     * @param string $from Absolute filesystem path of the starting directory (e.g. Reports folder)
     * @param string $to   Absolute filesystem path of the target directory (e.g. Screenshots folder)
     * @return string      Relative path using forward slashes (e.g. "../Screenshots/assets")
     */
    function getRelativeWebPath(string $from, string $to): string
    {
        // Normalize both paths and ensure they exist
        $from = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($from));
        $to   = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, realpath($to));

        // Split the paths into their segments
        $fromParts = explode(DIRECTORY_SEPARATOR, trim($from, DIRECTORY_SEPARATOR));
        $toParts   = explode(DIRECTORY_SEPARATOR, trim($to,   DIRECTORY_SEPARATOR));

        // Determine the index of the last common directory
        $max = min(count($fromParts), count($toParts));
        $lastCommon = -1;
        for ($i = 0; $i < $max; $i++) {
            if ($fromParts[$i] === $toParts[$i]) {
                $lastCommon = $i;
            } else {
                break;
            }
        }

        // Calculate how many levels to go up from the 'from' path
        $upLevels = count($fromParts) - $lastCommon - 1;
        $relativeSegments = array_fill(0, $upLevels, '..');

        // Get the remaining segments from the 'to' path after the common root
        $downSegments = array_slice($toParts, $lastCommon + 1);

        // Merge up (`..`) and down segments, and join with forward slashes for URL
        $webPath = array_merge($relativeSegments, $downSegments);
        return implode('/', $webPath);
    }
    
}


// v1