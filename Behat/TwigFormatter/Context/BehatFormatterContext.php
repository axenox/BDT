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
     * Capture a screenshot when a step fails.
     *
     * @AfterStep
     * @param AfterStepScope $scope The Behat after-step scope
     * @return void
     */
    public function captureScreenshotOnFailure(AfterStepScope $scope): void
    {
        // only on failed steps
        if ($scope->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }

        $this->captureScreenshot();
    }
    
    /**
     * Capture and store a screenshot with the current URL.
     *
     * Takes a screenshot of the current browser state and stores it along with
     * the current URL. Retries up to 3 times if the screenshot capture fails.
     * The screenshot path and URL are stored in the provider for later database logging.
     *
     * @return void
     */
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
        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->saveScreenshot($fileName, $dir);
                $this->provider->setScreenshot($fileName, $relativePath);
                $this->provider->setUrl($this->getSession()->getCurrentUrl());
                return;
            } catch (\Throwable $e) {
                if ($attempt === $maxAttempts) {
                    error_log('Screenshot failed after ' . $maxAttempts . ' attempts: ' . $e->getMessage());
                    throw $e;
                }
                sleep(2);
            }
        }
    }
}