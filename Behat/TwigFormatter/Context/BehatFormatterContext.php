<?php
namespace axenox\BDT\Behat\TwigFormatter\Context;

use axenox\BDT\Behat\Common\ScreenshotRegistry;
use Behat\Behat\Context\SnippetAcceptingContext;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\BeforeFeatureScope;

use Behat\Mink\Exception\DriverException;
use Behat\MinkExtension\Context\MinkContext;

/**
 * Class BehatFormatterContext
 *
 * @package axenox\BDT\Behat\TwigFormatter\Context
 */
class BehatFormatterContext extends MinkContext implements SnippetAcceptingContext
{
    private $currentScenario;
    protected static $currentSuite;

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
     * Take screen-shot when step fails.
     * Take screenshot on result step (Then)
     * Works only with Selenium2Driver.
     *
     * @AfterStep
     * @param AfterStepScope $scope
     */
    public function afterStepScreenShotOnFailure(AfterStepScope $scope)
    {

          /* TODO how to check, which driver can take screenshots?
            if (!$driver instanceof Selenium2Driver) {
                return;
            }*/
             

        if(!$scope->getTestResult()->isPassed()) {
            $fileName = $this->buildScreenshotFilename(
                $scope->getSuite()->getName(),
                $scope->getFeature()->getFile(),
                $scope->getStep()->getLine()
            );
    
            $destination = getcwd() . DIRECTORY_SEPARATOR . ScreenshotRegistry::getScreenshotPath();
            if (!is_dir($destination)) {
                mkdir($destination, 0777, true);
            }
    
            error_log("Taking screenshot for failed step: " . $scope->getStep()->getText());
            ScreenshotRegistry::setScreenshotName($fileName);
            
            $attempts = 0;
            $success = false;
            while ($attempts < 3 && ! $success) {
                try {
                    $this->saveScreenshot($fileName, $destination);
                    $success = true;
                } catch (DriverException $e) {
                    $attempts++;
                    error_log("[DEBUG] Screenshot attempt #{$attempts} failed: ".$e->getMessage());
                    usleep(200000); // 200ms
                }
            }
            if (! $success) {
                error_log("[ERROR] All screenshot attempts failed.");
            }
        }
    }
    
    public function buildScreenshotFilename(string $suiteName, string $featureFilePath, int $featureLine): string
    {
        return $suiteName
            . "." . str_replace('.feature', '', basename($featureFilePath))
            . "." . $featureLine
            . "." . date("YmdHis")
            . ".png";
    }
}