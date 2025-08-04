<?php
namespace axenox\BDT\Behat\TwigFormatter\Context;

use axenox\BDT\Behat\Common\ScreenshotTakenEvent;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Testwork\EventDispatcher\TestworkEventDispatcher;

/**
 * Class BehatFormatterContext
 *
 * @package axenox\BDT\Behat\TwigFormatter\Context
 */
class BehatFormatterContext extends MinkContext implements SnippetAcceptingContext
{
    private $currentScenario;
    protected static $currentSuite;
    
    /** @var TestworkEventDispatcher\ */
    private $dispatcher;

    /**
     * @BeforeFeature
     *
     * @param BeforeFeatureScope $scope
     *
     */
    public static function setUpScreenshotSuiteEnvironment4ElkanBehatFormatter(BeforeFeatureScope $scope)
    {
        self::$currentSuite = $scope->getSuite()->getName();
    }

    /**
     * @BeforeScenario
     */
    public function setUpScreenshotScenarioEnvironmentElkanBehatFormatter(BeforeScenarioScope $scope)
    {
        $this->currentScenario = $scope->getScenario();
    }

    
    /**
     * Setter that your ContextInitializer will call
     */
    public function setEventDispatcher(TestworkEventDispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }
    
    /**
     * Take screen-shot when step fails.
     * Take screenshot on result step (Then)
     * Works only with Selenium2Driver.
     *
     * @AfterStep
     * @param AfterStepScope $scope
     */
    public function captureScreenshotOnFailure(AfterStepScope $scope): void
    {
        // only on failed steps
        if ($scope->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }

        // build filename & physical path
        $fileName = $this->buildScreenshotFilename(
            $scope->getSuite()->getName(),
            $scope->getFeature()->getFile(),
            $scope->getStep()->getLine()
        );
        $relativePath = 'data'
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'BDT'
            . DIRECTORY_SEPARATOR . 'Screenshots'
            . DIRECTORY_SEPARATOR . date('YmdHis');
        $dir = getcwd()
            . DIRECTORY_SEPARATOR . $relativePath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // take screenshot
        $this->saveScreenshot($fileName, $dir);

        // dispatch screenshot event
        $event = new ScreenshotTakenEvent($fileName, $relativePath);
        $this->dispatcher->dispatch($event, ScreenshotTakenEvent::AFTER);
    }

    public function buildScreenshotFilename(string $suiteName, string $featureFilePath, int $featureLine): string
    {
        return $suiteName
            . "." . str_replace('.feature', '', basename($featureFilePath))
            . "." . $featureLine
            . ".png";
    }
}