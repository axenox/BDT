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
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Step\Then;
use Behat\Behat\Hook\Scope\AfterStepScope;

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
            DIRECTORY_SEPARATOR . 'axenox' .
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

        // Start XHR monitoring
        if ($this->browser) {
            $this->browser->initializeXHRMonitoring();
            echo "\nXHR monitoring initialized for scenario: " . $this->scenarioName . "\n";
        }
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
     * @AfterStep
     */
    public function afterStep($scope)
    {
        if ($scope->getTestResult()->getResultCode() === TestResult::FAILED) {
            // Take Screenshot
            $this->takeScreenshot();

            // check Ajax errors
            if ($this->browser) {
                $error = $this->browser->getAjaxError();
                if ($error) {
                    echo "\nAJAX Error Details:\n" . json_encode($error, JSON_PRETTY_PRINT) . "\n";
                }
            }
        }
    }

    /**
     * Captures and saves a screenshot of the current browser state
     * 
     * Used primarily for debugging test failures. Creates uniquely named 
     * screenshots with scenario name and error details if available.
     * Uses ChromeDriver to capture screenshot
     * @return void
     */
    private function takeScreenshot()
    {
        try {
            // Get the ChromeDriver instance for taking screenshots
            $driver = $this->getSession()->getDriver();
            // Generate filename with timestamp and scenario name
            $filename = date('Y-m-d_His');
            if ($this->scenarioName) {
                // Add scenario name to filename, replacing invalid characters
                $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $this->scenarioName);
            }
            // Add error type to filename if available
            if ($this->browser) {
                $error = $this->browser->getLastError();
                if ($error) {
                    // Append error type to filename, sanitizing special characters
                    $filename .= '_' . preg_replace('/[^a-zA-Z0-9]/', '_', $error['type']);
                }
            }
            $filename .= '.png';
            // Construct full path for saving screenshot
            $fullPath = $this->screenshotDir . $filename;
            // Check if driver is ChromeDriver and take screenshot
            if ($driver instanceof \DMore\ChromeDriver\ChromeDriver) {
                $screenshotData = $driver->getScreenshot();
                if ($screenshotData && file_put_contents($fullPath, $screenshotData) !== false) {
                    // Store filename for reference in error messages
                    $this->lastScreenshot = $filename;
                    echo "\nScreenshot saved: " . $fullPath;

                    // Save error details alongside screenshot if available
                    if ($this->browser && ($error = $this->browser->getLastError())) {
                        // Create separate JSON file for error details
                        $errorLog = $this->screenshotDir . str_replace('.png', '_error.json', $filename);
                        file_put_contents($errorLog, json_encode($error, JSON_PRETTY_PRINT));
                        echo "\nError details saved: " . $errorLog;
                    }
                }
            }
        } catch (\Exception $e) {
            // Log any errors that occur during screenshot capture
            echo "\nScreenshot error: " . $e->getMessage();
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

        // Wait for the page to fully load and become idle
        $this->browser->waitForPageIsFullyLoaded(10);
        $this->browser->waitWhileAppBusy(30);
        $this->browser->waitForAjaxFinished(10);


        // Find Widgets
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
                // TODO: Object alias validation will be added
            }
        }

        Assert::assertEquals($number, count($widgetNodes), "The expected number of  {$widgetType} widgets was not found");
        if (count($widgetNodes) === 1) {
            $this->focus($widgetNodes[0]);
        }
    }


    /**
     * @When I click button ":caption"
     */
    public function iClickButton(string $caption): void
    {
        // Log XHR count when checking for errors
        $this->logXHRCount('iClickButton start xhr Count');

        // Get requests before click button
        $beforeRequests = $this->getSession()->evaluateScript('return window.exfXHRLog.requests.slice();');

        $btn = $this->browser->findButtonByCaption($caption);
        $this->logXHRCount('iClickButton Count');
        Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

        $btn->click();

        $this->browser->waitWhileAppBusy(30);

        // After request list of after clicking the button
        $afterRequests = $this->getSession()->evaluateScript('return window.exfXHRLog.requests;');

        // 
        echo "\nRequests triggered by button click:\n";
        foreach ($afterRequests as $request) {
            if (!in_array($request, $beforeRequests)) {
                echo "URL: {$request['url']}, Status: {$request['status']}\n";
            }
        }

        // Log XHR count when checking for errors
        $this->logXHRCount('iClickButton end xhr Count');
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


    /**
     * @Then the DataTable contains :text
     */
    public function theDataTableContains(string $text): void
    {
        try {
            // Find all DataTable widgets on the page
            $dataTables = $this->browser->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');
            // Get the first DataTable found
            $dataTable = $dataTables[0];

            // Search for text in all table cells
            $found = false;
            $cells = $dataTable->findAll('css', 'td');
            // Check each cell for the specified text
            foreach ($cells as $cell) {
                if (strpos($cell->getText(), $text) !== false) {
                    $found = true;
                    break;
                }
            }
            // Assert that text was found, throw exception if not
            Assert::assertTrue($found, "Text '$text' not found in DataTable");

        } catch (\Exception $e) {
            // Capture screenshot for debugging if assertion fails
            $this->takeScreenshot();
            throw $e;
        }
    }

    /**
     * @Then I see filtered results in the DataTable
     */
    public function iSeeFilteredResultsInDataTable(): void
    {
        try {
            // Wait for any pending operations to complete
            $this->browser->waitForAjaxFinished(10);
            $this->browser->waitWhileAppBusy(10);

            // Find DataTable widgets
            $dataTables = $this->browser->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');

            // Get the first DataTable
            $dataTable = $dataTables[0];

            // Look for different types of UI5 table classes
            $ui5TableSelectors = [
                '.sapMTable',        // Standard table
                '.sapUiTable',       // Grid table
                '.sapMList'          // List that might be used as table
            ];

            $ui5Table = null;
            foreach ($ui5TableSelectors as $selector) {
                $ui5Table = $dataTable->find('css', $selector);
                if ($ui5Table !== null) {
                    break;
                }
            }

            Assert::assertNotNull(
                $ui5Table,
                'No UI5 Table element found. Available classes: ' .
                implode(', ', array_map(function ($class) use ($dataTable) {
                    return $dataTable->find('css', $class) ? "$class (found)" : "$class (not found)";
                }, $ui5TableSelectors))
            );

            // Check for both standard rows and tree table rows
            $rows = $ui5Table->findAll('css', 'tr.sapMListItem, tr.sapUiTableRow');

            // Also check for no data indicator
            $noDataText = $ui5Table->find('css', '.sapMListNoData, .sapUiTableCtrlEmpty');
            if ($noDataText) {
                // If we have a no-data indicator, that's also a valid state
                return;
            }

            Assert::assertNotEmpty($rows, 'No rows found in filtered results');

            // Check for filter indicators
            $filterIndicators = [
                '.sapMTableFilterIcon',     // Standard table filter
                '.sapUiTableColFiltered'    // Grid table filter
            ];

            $hasFilter = false;
            foreach ($filterIndicators as $selector) {
                if ($dataTable->find('css', $selector)) {
                    $hasFilter = true;
                    break;
                }
            }

            // Log for debugging
            echo sprintf(
                "Found table with %d rows. Filter indicators: %s\n",
                count($rows),
                $hasFilter ? 'present' : 'not present'
            );

        } catch (\Exception $e) {
            // Take screenshot and add additional debug info
            $this->takeScreenshot();

            // Get page content for debugging
            $pageContent = $this->getSession()->getPage()->getContent();
            echo "Page content sample: " . substr($pageContent, 0, 500) . "\n";

            throw new \Exception(
                "Failed to verify filtered results: " . $e->getMessage() .
                "\nDebug info: Current URL = " . $this->getSession()->getCurrentUrl()
            );
        }
    }

    /**
     * @Then AJAX request should complete successfully
     * 
     * Validates if the most recent AJAX request completed successfully.
     * This method performs two main checks:
     * 1. Verifies AJAX request status using UI5Browser's checkAjaxRequestStatus
     * 2. Ensures UI5 framework is not in busy state
     * 
     * On any failure:
     * - Retrieves detailed error information from UI5 or jQuery
     * - Takes screenshot for debugging purposes
     * - Logs error details with descriptive message
     * 
     * @throws \Exception when AJAX request fails, returns error status, or UI5 remains busy
     */
    public function ajaxRequestShouldCompleteSuccessfully(): void
    {
        try {
            // Step 1: Validate AJAX request status
            if (!$this->browser->checkAjaxRequestStatus()) {
                // Get detailed error information from UI5Browser - Checks status 200 or not
                $error = $this->browser->getAjaxError();

                // Format error message - use detailed error if available, fallback to generic
                $errorMessage = $error ? json_encode($error) : 'Unknown error';

                // Throw exception with descriptive message
                throw new \Exception("AJAX request failed: " . $errorMessage);
            }

            // Step 2: Ensure UI5 is not busy (5 second timeout)
            $this->browser->waitWhileAppBusy(5);

            // Log success message if all checks pass
            echo "AJAX request completed successfully (Status: 200)\n";

        } catch (\Exception $e) {
            // Error handling:
            // 1. Log error details
            echo "Debug - AJAX status check error: " . $e->getMessage() . "\n";

            // 2. Take screenshot for debugging
            $this->takeScreenshot();

            // 3. Re-throw exception to fail the test
            throw $e;
        }
    }

    /**
     * @BeforeScenario
     */
    public function resetAjaxLog(BeforeScenarioScope $scope)
    {
        if ($this->browser) {
            $this->browser->clearXHRLog();
            echo "\nXHR logs cleared before scenario: " . $scope->getScenario()->getTitle() . "\n";
        }
    }

    /**
     * @When I click button :caption and wait for AJAX
     */
    public function iClickButtonAndWaitForAjax(string $caption): void
    {
        try {
            // First, check if XHR monitoring is active
            $isXHRLogInitialized = $this->getSession()->evaluateScript('return typeof window.exfXHRLog !== "undefined"');

            if (!$isXHRLogInitialized) {
                echo "\nWarning: XHR monitoring not initialized. Reinitializing...\n";
                $this->browser->initializeXHRMonitoring();
            }

            // Now we can safely get the logs
            echo "\n------------ BEFORE CLICK ------------\n";
            $beforeLog = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n" +
                        JSON.stringify(window.exfXHRLog.requests, null, 2);
                }
                return "No requests logged yet";
            ');
            echo $beforeLog;

            // Find and validate the button
            $btn = $this->browser->findButtonByCaption($caption);
            Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

            // Clear XHR logs
            $this->browser->clearXHRLog();

            // Debug information
            echo "\n------------ CLICKING BUTTON ------------\n";
            echo "Clicking button: " . $caption . "\n";

            // Click the button
            $btn->click();

            // Wait for AJAX operations to complete
            $this->waitForAjaxComplete();
            $this->browser->waitWhileAppBusy(5);

            // Show final state
            echo "\n------------ AFTER CLICK ------------\n";
            $afterLog = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.requests) {
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n" +
                        JSON.stringify(window.exfXHRLog.requests, null, 2);
                }
                return "No requests logged";
            ');
            echo $afterLog;

        } catch (\Exception $e) {
            echo "\nDebug - Button Click Error: " . $e->getMessage() . "\n";
            $this->takeScreenshot();
            throw $e;
        }
    }




    /**
     * @Then AJAX requests should complete successfully
     */
    public function ajaxRequestsShouldCompleteSuccessfully(): void
    {
        try {
            // Check AJAX status
            $error = $this->browser->getAjaxError();
            if ($error !== null) {
                // Prepare error details
                $errorDetails = "AJAX request failed:\n";
                $errorDetails .= "Type: " . $error['type'] . "\n";
                $errorDetails .= "Message: " . ($error['message'] ?? 'Unknown error') . "\n";

                if (isset($error['details'])) {
                    $errorDetails .= "Details: " . $error['details'] . "\n";
                }
                if (isset($error['url'])) {
                    $errorDetails .= "URL: " . $error['url'] . "\n";
                }
                if (isset($error['status'])) {
                    $errorDetails .= "Status: " . $error['status'] . "\n";
                }

                // Take a screenshot in case of error
                $this->takeScreenshot();
                throw new \Exception($errorDetails);
            }

            // Ensure UI5 is not busy
            $this->browser->waitWhileAppBusy(5);

        } catch (\Exception $e) {
            echo "Debug - AJAX Error: " . $e->getMessage() . "\n";
            $this->takeScreenshot();
            throw $e;
        }
    }

    /**
     * Waits for AJAX requests to complete
     * 
     * @param int $timeout Maximum wait time in seconds
     */
    private function waitForAjaxComplete(int $timeout = 30): void
    {
        $start = time();
        $lastError = null;

        while (time() - $start < $timeout) {
            // Check for AJAX errors
            $error = $this->browser->getAjaxError();

            // If no error, request is successful
            if ($error === null) {
                return;
            }

            // Special check for UI5 busy state
            if ($error['type'] === 'UI5Busy') {
                sleep(1);
                continue;
            }

            // Handle other error cases
            $lastError = $error;
            sleep(1);
        }

        // Handle timeout case
        $errorMsg = "AJAX requests did not complete within {$timeout} seconds.";
        if ($lastError) {
            $errorMsg .= "\nLast error: " . json_encode($lastError, JSON_PRETTY_PRINT);
        }
        throw new \Exception($errorMsg);
    }

}

// V1
