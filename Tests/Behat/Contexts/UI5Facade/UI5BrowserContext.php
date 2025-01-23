<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\MinkContext;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use PHPUnit\Framework\Assert;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Testwork\Tester\Result\TestResult;


/**
 * Test steps available for the OpenUI5 facade
 * 
 * UI5BrowserContext class provides test steps for OpenUI5 facade testing
 * Each scenario gets its own context instance
 * 
 * Every scenario gets its own context instance.
 * You can also pass arbitrary arguments to the
 * context constructor through behat.yml.
 * 
 */
class UI5BrowserContext extends MinkContext implements Context
{
    private $browser;
    private $scenarioName;
    private $screenshotDir; // Directory path for storing screenshots

    /**
     * Constructor initializes screenshot directory path
     * Creates the screenshot directory if it doesn't exist
     */
    public function __construct()
    {
        // Get workbench root path
        $workbenchRoot = $this->getWorkbenchPath();

        // Create screenshot directory path using workbench path
        $this->screenshotDir = $workbenchRoot .
            DIRECTORY_SEPARATOR . 'data' .
            DIRECTORY_SEPARATOR . 'BDT' .
            DIRECTORY_SEPARATOR . 'Reports' .
            DIRECTORY_SEPARATOR . 'screenshots' .
            DIRECTORY_SEPARATOR;

        echo "Screenshot directory will be: " . $this->screenshotDir . "\n";

        // Create directory if it doesn't exist
        if (!file_exists($this->screenshotDir)) {
            if (!mkdir($this->screenshotDir, 0777, true)) {
                throw new \RuntimeException(
                    sprintf('Directory "%s" could not be created', $this->screenshotDir)
                );
            }
            echo "Screenshot directory created successfully\n";
        }
    }

    /**
     * Dynamically determines workbench root path
     * Traverses up from current directory until finding vendor directory
     * @return string Path to workbench root
     */
    private function getWorkbenchPath(): string
    {
        // vendor/axenox/bdt klasöründen root'a çıkma
        $path = __DIR__;

        // Navigate up until vendor directory
        while (basename(dirname($path)) !== 'vendor' && strlen($path) > 3) {
            $path = dirname($path);
        }

        // Go one level up from vendor to get workbench root
        $workbenchRoot = dirname(dirname($path));

        if (!file_exists($workbenchRoot)) {
            throw new \RuntimeException(
                sprintf('Could not determine workbench root path from %s', __DIR__)
            );
        }

        return $workbenchRoot;
    }


    /**
     * Captures scenario name before execution
     * @param BeforeScenarioScope $scope Behat scenario scope
     */
    public function beforeScenario(BeforeScenarioScope $scope)
    {
        $this->scenarioName = $scope->getScenario()->getTitle();
    }

    /**
     * Takes screenshot if scenario fails
     * @param AfterScenarioScope $scope Behat scenario scope
     */
    public function afterScenario(AfterScenarioScope $scope)
    {
        if ($scope->getTestResult()->getResultCode() === TestResult::FAILED) {
            $this->takeScreenshot();
            // HTML extend for to add the report
            if ($this->lastScreenshot) {
                $scope->getTestResult()->getMessage() . "\nScreenshot: screenshots/" . $this->lastScreenshot;
            }
        }
    }

    /**
     * Takes and saves screenshot of current page state
     * Uses ChromeDriver to capture screenshot
     */
    private function takeScreenshot()
    {
        try {
            $driver = $this->getSession()->getDriver();
            echo "\nDebug - Taking screenshot with driver: " . get_class($driver) . "\n";

            // Generate filename using timestamp and scenario name
            $filename = date('Y-m-d_His') . '_' .
                preg_replace('/[^a-zA-Z0-9]/', '_', $this->scenarioName) .
                '.png';

            $fullPath = $this->screenshotDir . $filename;

            echo "\nDebug - Attempting to save screenshot to: " . $fullPath . "\n";

            // Take screenshot if using ChromeDriver
            if ($driver instanceof \DMore\ChromeDriver\ChromeDriver) {
                $screenshotData = $driver->getScreenshot();
                if ($screenshotData) {
                    if (file_put_contents($fullPath, $screenshotData) !== false) {
                        $this->lastScreenshot = $filename;
                        echo "\nDebug - Screenshot saved successfully to: " . $fullPath . "\n";
                        return;
                    } else {
                        echo "\nDebug - Failed to write screenshot to: " . $fullPath . "\n";
                    }
                }
            }

        } catch (\Exception $e) {
            echo "\nDebug - Screenshot error: " . $e->getMessage() . "\n";
            echo "\nDebug - Error trace: " . $e->getTraceAsString() . "\n";
        }
    }

    /**
     * @Then /^I should see the page$/
     */
    public function iShouldSeeThePage()
    {
        $page = $this->getSession()->getPage();
        Assert::assertNotNull($page->getContent(), 'Page content is empty');
    }


    /**
     * Summary of focusStack
     * @var \Behat\Mink\Element\NodeElement[]
     */
    private $focusStack = [];

    /**
     * @Given I log in to the page :url
     * @Given I log in to the page :url as :userRole
     */
    public function iLogInToPage(string $url, string $userRole = null)
    {
        try {
            // Navigate to page
            $this->visitPath('/' . $url);
            echo "Debug - First page is loading...\n";

            // Wait for page and UI5 to load
            $this->getSession()->wait(5000, "document.readyState === 'complete'");
            $this->getSession()->wait(10000, "return typeof sap !== 'undefined' && typeof sap.ui !== 'undefined'");

            $this->browser = new UI5Browser($this->getSession(), $url);

            // Find login form with retries
            $username = null;
            $maxAttempts = 10;
            $attempts = 0;

            while ($username === null && $attempts < $maxAttempts) {
                $username = $this->browser->findInputByCaption('User Name');
                if ($username === null) {
                    sleep(1);
                    $attempts++;
                    echo "Debug - Login form searching... Attempt num: " . $attempts . "\n";
                }
            }

            // Handle login form interaction
            if ($username) {
                echo "Debug - Login form found\n";
                // For testing purpose, we can give wrong username or password
                $username->setValue('admin');

                $password = $this->browser->findInputByCaption('Password');
                if ($password) {
                    $password->setValue('admin');

                    $loginButton = $this->browser->findButtonByCaption('Login');
                    if ($loginButton) {
                        $loginButton->click();

                        // Wait for login completion
                        sleep(3);
                        $this->browser->waitWhileAppBusy(60);
                        $this->browser->waitForAjaxFinished(30);

                        echo "Debug - Login attempt completed\n";

                        // Check for authentication failure
                        $statusCode = $this->getSession()->getStatusCode();
                        echo "Debug - Page Status: " . $statusCode . "\n";

                        if ($statusCode === 401) {
                            throw new \Exception("Login failed: Unauthorized connection (401)");
                        }

                        return;
                    }
                }
            }

            throw new \Exception("Login form elements could not be found");

        } catch (\Exception $e) {
            echo "Debug - Login error: " . $e->getMessage() . "\n";
            $this->takeScreenshot(); // Take Screenshot when we have an error
            throw $e; // throw error
        }
    }


    /**
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        // ERROR HANDLING - START
        echo "-------- ERROR HANDLING STARTS --------\n";
        echo "Existing URL: " . $this->getSession()->getCurrentUrl() . "\n";
        echo "Page Status: " . $this->getSession()->evaluateScript('return document.readyState') . "\n";
        // echo "UI5 Status: " . $this->getSession()->evaluateScript('return (typeof sap !== "undefined" && sap.ui && sap.ui.getCore().isInit())') . "\n";
        echo "Searching Widget: " . $widgetType . "\n";
        // ERROR HANDLING - START

        // Sayfanın tamamen yüklenmesini ve meşgul olmamasını bekle
        $this->browser->waitForPageIsFullyLoaded(10);
        $this->browser->waitWhileAppBusy(30);
        $this->browser->waitForAjaxFinished(10);

        // UI5'in özel başlatması için ek bekleme
        // $this->getSession()->wait(5000, "return (typeof sap !== 'undefined' && sap.ui && sap.ui.getCore().isInit())");

        // Widget'ları ara
        $widgetNodes = $this->browser->findWidgets($widgetType, null, 5);

        // ERROR HANDLING - RESULTS
        echo "-------- SEARCHING RESULTS --------\n";
        echo "Count of found Widgets: " . count($widgetNodes) . "\n";
        echo "Waiting widged : " . $number . "\n";

        if (count($widgetNodes) !== $number) {
            echo "-------- PAGE CONTENT SAMPLE (1000char) --------\n";
            echo substr($this->getSession()->getPage()->getContent(), 0, 1000) . "\n";
            echo "-------- ERROR HANDLING FINISH --------\n";
        }

        foreach ($widgetNodes as $node) {
            if ($objectAlias !== null) {
                // TODO: Object alias doğrulaması eklenecek
            }
        }

        Assert::assertEquals($number, count($widgetNodes), "Beklenen sayıda {$widgetType} widget'ı bulunamadı");
        if (count($widgetNodes) === 1) {
            $this->focus($widgetNodes[0]);
        }
    }


    /**
     * 
     * @When I click button ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function iClickButton(string $caption): void
    {
        $btn = $this->browser->findButtonByCaption($caption);
        Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');
        $btn->click();
        $this->browser->waitWhileAppBusy(30);
    }

    /**
     * 
     * @When I type ":value" into ":caption"
     *
     * @param string $value
     * @param string $caption
     * @return void
     */
    public function iTypeIntoWidgetWithCaption(string $value, string $caption): void
    {
        $widget = $this->browser->findInputByCaption($caption);
        Assert::assertNotNull($widget, 'Cannot find input widget "' . $caption . '"');
        $widget->setValue($value);
    }

    /**
     * Focus a widget of a given type
     * 
     * @When I look at the first ":widgetType"
     * @When I look at ":widgetType" no. :number
     * 
     * @param string $widgetType
     * @return void
     */
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        $widgetNodes = $this->browser->findWidgets($widgetType);
        $node = $widgetNodes[$number - 1];
        Assert::assertNotNull($node, 'Cannot find "' . $widgetType . '" no. ' . $number . '!');
        $this->focus($node);
    }

    /**
     * @Then it has a column ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function itHasColumn(string $caption): void
    {
        /**
         * @var \Behat\Mink\Element\NodeElement $tableNode
         */
        $tableNode = $this->getFocusedNode();
        Assert::assertNotNull($tableNode, 'No widget has focus right now - cannot use steps like "it has..."');
        $colNode = $tableNode->find('css', 'td');
        Assert::assertNotNull($colNode, 'Column "' . $caption, '" not found');
    }

    protected function focus(NodeElement $node): void
    {
        $top = end($this->focusStack);
        if ($top !== $node) {
            $this->focusStack[] = $node;
        }
    }

    protected function getFocusedNode(): ?NodeElement
    {
        if (empty($this->focusStack)) {
            return null;
        }
        $top = end($this->focusStack);
        return $top;
    }
}