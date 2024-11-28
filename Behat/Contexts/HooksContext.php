<?php


use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\MinkExtension\Context\MinkContext;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;


class HooksContext extends MinkContext implements Context
{
    private static $driver;

    public static function getDriver()
    {
        return self::$driver;
    }

    /**
     * @BeforeScenario
     */
    public function setUp(BeforeScenarioScope $scope)
    {
        if (self::$driver === null) {
            $options = new ChromeOptions();
            $options->addArguments(['--start-maximized']);
            $capabilities = DesiredCapabilities::chrome();
            $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

            self::$driver = RemoteWebDriver::create('http://localhost:4444/wd/hub', $capabilities);
        }
    }

    /**
     * @AfterScenario
     */
    public function tearDown(AfterScenarioScope $scope)
    {
        // Check if the scenario failed
        if (!$scope->getTestResult()->isPassed()) {
            $screenshot = self::$driver->takeScreenshot();
            file_put_contents('failed_test_screenshot.png', $screenshot);
        }

        // Quit driver
        if (self::$driver !== null) {
            self::$driver->quit();
            self::$driver = null;
        }
    }
}


