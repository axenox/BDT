<?php
namespace axenox\BDT\Behat\TwigFormatter\Context;

use axenox\BDT\Behat\Common\ScreenshotAwareInterface;
use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;
use Behat\MinkExtension\Context\MinkContext;
use Behat\Testwork\Tester\Result\TestResult;

/**
 * Class BehatFormatterContext
 *
 * @package axenox\BDT\Behat\TwigFormatter\Context
 */
class BehatFormatterContext extends MinkContext implements SnippetAcceptingContext, ScreenshotAwareInterface
{
    private $currentScenario;
    protected static $currentSuite;
    
    private ScreenshotProviderInterface $provider;

    public function setScreenshotProvider(ScreenshotProviderInterface $provider) :void
    {
        $this->provider = $provider;
    }
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
     * Take screenshot when step fails.
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

        $this->captureScreenshot();
    }
    
    public function captureScreenshot(): void
    {
        $relativePath = 'data'
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'BDT'
            . DIRECTORY_SEPARATOR . 'Screenshots'
            . DIRECTORY_SEPARATOR . date('Ymd');
        $dir = getcwd()
            . DIRECTORY_SEPARATOR . $relativePath;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fileName = $this->provider->getName() . '.png';

        // take screenshot
        $this->saveScreenshot($fileName, $dir);
        $this->provider->setScreenshot($fileName, $relativePath);
    }
}