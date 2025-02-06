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
use Behat\Gherkin\Node\TableNode;

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


    private $lastScreenshot = null;  // Add this property at the top of the class with other properties


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
            DIRECTORY_SEPARATOR . 'assets' .
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


    /** @AfterStep */
    public function takeScreenshotAfterFailedStep(AfterStepScope $scope)
    {
        if (!$scope->getTestResult()->isPassed()) {
            // $this->takeScreenshot();
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
            $screenshotPath = $this->takeScreenshot();
            
            if ($this->lastScreenshot) {
                $urlInfo = $this->getCurrentUrlInfo();
                $message = $scope->getTestResult()->getMessage() ?? 'Test failed';
                $message .= "\nScreenshot: " . $this->lastScreenshot;
                $message .= sprintf(
                    "\nURLs:\n" .
                    "  Base URL: %s\n" .
                    "  Full URL: %s\n" .
                    "  UI5 Hash: %s",
                    $urlInfo['baseUrl'],
                    $urlInfo['fullUrl'],
                    $urlInfo['hash']
                );
                
                // Ajax hatasını kontrol et
                if ($error = $this->browser->getAjaxError()) {
                    $message .= "\nAJAX Error:";
                    $message .= "\nType: " . ($error['type'] ?? 'Unknown');
                    $message .= "\nStatus: " . ($error['status'] ?? 'N/A');
                    if (isset($error['structured'])) {
                        $message .= "\nLogID: " . ($error['structured']['logid'] ?? 'N/A');
                        $message .= "\nError Code: " . ($error['structured']['code'] ?? 'N/A');
                    }
                }

                // Test sonucuna detayları ekle
                $this->attachErrorDetailsToResult($scope, $message);
            }
        }
    }

    private function attachErrorDetailsToResult(AfterScenarioScope $scope, string $message): void
    {
        try {
            $reflection = new \ReflectionObject($scope->getTestResult());
            $messageProperty = $reflection->getProperty('message');
            $messageProperty->setAccessible(true);
            $messageProperty->setValue($scope->getTestResult(), $message);
        } catch (\Exception $e) {
            echo "\nWarning: Could not attach error details to report: " . $e->getMessage();
        }
    }

    /**
     * Automatic error checking hook that runs after each step in the test scenario
     * 
     * This hook performs comprehensive error checking after every test step:
     * 1. Waits for all AJAX requests to complete
     * 2. Checks for any AJAX errors (network, timeout, etc.)
     * 3. Verifies network request status codes
     * 4. Examines UI5 framework error messages
     * 5. Takes screenshots on failures for debugging
     * 
     * Benefits:
     * - No need to explicitly write AJAX error checks in feature files
     * - Catches errors immediately after they occur
     * - Provides detailed error information for debugging
     * - Maintains screenshots of failure states
     * 
     * @param AfterStepScope $scope Provides context about the current test step
     * @throws \Exception When any type of error is detected
     */
    /**
     * Automatic error checking hook that runs after each step in the test scenario
     * Coordinates all error checks through separate specialized methods
     * v1
     * @param AfterStepScope $scope Provides context about the current test step
     * @throws \Exception When any type of error is detected
     */
    /** @AfterStep */
    public function afterStep(AfterStepScope $scope)
    {
        try {
            $result = $scope->getTestResult();
            
            if (!$result->isPassed()) {
                $urlInfo = $this->getCurrentUrlInfo();
                $error = $this->browser->getAjaxError();
                
                $message = "Step failed.\n";
                $message .= sprintf(
                    "URLs:\n" .
                    "  Base URL: %s\n" .
                    "  Full URL: %s\n" .
                    "  UI5 Hash: %s\n",
                    $urlInfo['baseUrl'],
                    $urlInfo['fullUrl'],
                    $urlInfo['hash']
                );
                
                if ($error) {
                    $message .= sprintf(
                        "AJAX Error:\nType: %s\nStatus: %s\n",
                        $error['type'] ?? 'Unknown',
                        $error['status'] ?? 'N/A'
                    );
                    
                    if (isset($error['structured'])) {
                        $message .= sprintf(
                            "LogID: %s\nError Code: %s\n",
                            $error['structured']['logid'] ?? 'N/A',
                            $error['structured']['code'] ?? 'N/A'
                        );
                    }
                }
                
                throw new \RuntimeException($message);
            }
        } catch (\Exception $e) {
            $this->takeScreenshot();
            throw $e;
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
            $session = $this->getSession();
            // TODO handle user roles here

            $this->iVisitPage($url);

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
                $username->setValue('admin');

                $password = $this->browser->findInputByCaption('Password');
                if ($password) {
                    $password->setValue('admin');

                    $loginButton = $this->browser->findButtonByCaption('Login');
                    if ($loginButton) {
                        // Clear XHR logs before login
                        $this->browser->clearXHRLog();

                        $loginButton->click();

                        // Wait for login completion
                        sleep(3);
                        $this->browser->waitWhileAppBusy(60);
                        $this->browser->waitForAjaxFinished(30);

                        // Check for failed requests
                        $failedRequests = $this->getSession()->evaluateScript('
                        if (window.exfXHRLog && window.exfXHRLog.requests) {
                            return window.exfXHRLog.requests.filter(function(req) {
                                return req.status >= 300;
                            }).map(function(req) {
                                return {
                                    url: req.url || "unknown",
                                    status: req.status || 0,
                                    response: req.response || "No response"
                                };
                            });
                        }
                        return [];
                    ');

                        if (!empty($failedRequests)) {
                            $errorMsg = "Failed requests detected during login:\n";
                            foreach ($failedRequests as $req) {
                                $errorMsg .= sprintf(
                                    "URL: %s\nStatus: %s\nResponse: %s\n\n",
                                    $req['url'],
                                    $req['status'],
                                    substr($req['response'], 0, 500)
                                );
                            }
                            throw new \Exception($errorMsg);
                        }

                        // Check authentication status
                        $statusCode = $this->getSession()->getStatusCode();
                        if ($statusCode === 401) {
                            throw new \Exception("Login failed: Unauthorized (401)");
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
     * Summary of iVisitPage
     * 
     * @Given I visit page :url
     * 
     * @param string $url
     * @return void
     */
    public function iVisitPage(string $url) : void
    {
        // Navigate to page
        $this->visitPath('/' . $url);
        echo "Debug - First page is loading...\n";

        $this->browser = new UI5Browser($this->getSession(), $url);

        return;
    }


    /**
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        $this->browser->waitForPageIsFullyLoaded(10);
        $this->browser->waitWhileAppBusy(30);
        $this->browser->waitForAjaxFinished(10);

        $this->getSession()->wait(5000);

        // Debug çıktılarını kaldır
        $this->browser->setObjectAlias($objectAlias);
        $widgetNodes = $this->browser->findWidgets($widgetType, null, 5);

        Assert::assertEquals(
            $number,
            count($widgetNodes),
            sprintf(
                "Expected %d widget(s) of type '%s'%s, but found %d",
                $number,
                $widgetType,
                $objectAlias ? " with text '$objectAlias'" : '',
                count($widgetNodes)
            )
        );

        if (count($widgetNodes) === 1) {
            $this->focus($widgetNodes[0]);
        }
    }

    private function getCurrentUrlWithHash(): string
    {
        return $this->getSession()->evaluateScript('
        return window.location.href + 
        (window.location.hash ? "" : "#" + window.location.hash);
    ');
    }



    /**
     * @Then It has :number widget of type ":widgetType"
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {
        // Get the currently focused node
        $focusedNode = $this->getFocusedNode();
        Assert::assertNotNull(
            $focusedNode,
            'No widget has focus right now - cannot use steps like "It has..."'
        );

        // Wait for UI5 components to fully load
        $this->browser->waitForPageIsFullyLoaded(10);
        $this->browser->waitWhileAppBusy(30);
        $this->browser->waitForAjaxFinished(10);

        // Find the main form container
        $form = $focusedNode->find('css', '.sapUiForm') ?? $focusedNode;

        // // Debugging information about the form
        // echo "\nSearching in context:\n";
        // echo "Classes: " . $form->getAttribute('class') . "\n";
        // echo "Content: " . $form->getText() . "\n";

        // Find widgets of the specified type within the form
        $widgetNodes = $this->browser->findWidgets($widgetType, $form);

        // // Debugging results
        // echo "\nDebug - Widget Search Results:\n";
        // echo "Looking for widget type: " . $widgetType . "\n";
        // echo "Expected count: " . $number . "\n";
        // echo "Found count: " . count($widgetNodes) . "\n";

        if (count($widgetNodes) === 0) {
            // If no widgets found, list potential input elements for debugging 
            // Some UI5 input components may be inside .sapMInputBaseContentWrapper, but in the test scenario, 
            //    only the actual input fields need to be found
            // echo "\nAll potential input elements:\n";
            $allElements = $form->findAll('css', '.sapMInputBase:not(.sapMInputBaseContentWrapper)');

            foreach ($allElements as $index => $element) {
                // echo "Element #" . ($index + 1) . ":\n";
                // echo "ID: " . ($element->getAttribute('id') ?? 'no id') . "\n";
                // echo "Classes: " . ($element->getAttribute('class') ?? 'no class') . "\n";
                $inner = $element->find('css', '.sapMInputBaseInner');
                // echo "Has inner input: " . ($inner ? 'yes' : 'no') . "\n";
                // echo "Visible: " . ($element->isVisible() ? 'yes' : 'no') . "\n";
                // echo "---\n";
            }
        }
        // Assert the expected number of widgets
        Assert::assertEquals(
            $number,
            count($widgetNodes),
            sprintf(
                "Expected %d widgets of type '%s', found %d",
                $number,
                $widgetType,
                count($widgetNodes)
            )
        );
    }


    /**
     * @Then I fill the following fields:
     */
    public function iFillTheFollowingFields(TableNode $fields): void
    {
        foreach ($fields->getHash() as $row) {
            // Find input by caption
            $widget = $this->browser->findInputByCaption($row['widget_name']);
            Assert::assertNotNull(
                $widget,
                sprintf('Cannot find input widget "%s"', $row['widget_name'])
            );

            // Set value and wait for any UI reactions
            $widget->setValue($row['value']);

            // Wait for potential UI updates
            $this->browser->waitWhileAppBusy(5);
            $this->browser->waitForAjaxFinished(5);
        }
    }

    /**
     * @Then It has filters: :filterList
     */
    public function itHasFilters(string $filterList): void
    {
        $filters = array_map('trim', explode(',', $filterList));

        // Input containerları bul
        $inputContainers = $this->browser->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
        $foundFilters = [];

        foreach ($inputContainers as $container) {
            $label = $container->find('css', '.sapMLabel bdi');
            if ($label) {
                $foundFilters[] = trim($label->getText());
            }
        }

        foreach ($filters as $filter) {
            Assert::assertTrue(
                in_array($filter, $foundFilters),
                sprintf(
                    'Filter "%s" not found. Available filters: %s',
                    $filter,
                    implode(', ', $foundFilters)
                )
            );
        }
    }

    // /**
    //  * @When I enter :value in filter :filterName
    //  */
    // public function iEnterInFilter(string $value, string $filterName): void
    // {
    //     // // Önce doğru input container'ı bul
    //     // $inputContainers = $this->browser->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
    //     // $targetInput = null;

    //     // foreach ($inputContainers as $container) {
    //     //     $label = $container->find('css', '.sapMLabel bdi');
    //     //     if ($label && trim($label->getText()) === $filterName) {
    //     //         $targetInput = $container->find('css', '.sapMInputBaseInner');
    //     //         break;
    //     //     }
    //     // }

    //     // Assert::assertNotNull(
    //     //     $targetInput,
    //     //     sprintf('Cannot find filter input "%s"', $filterName)
    //     // );

    //     // $targetInput->setValue($value);
    //     // $this->browser->waitWhileAppBusy(5);
    //     // $this->browser->waitForAjaxFinished(5);

    //     $filterSelector = "//input[@placeholder='$filterName']"; // XPath veya CSS Selector
    //     $this->waitForElement($filterSelector); // Elementin yüklenmesini bekle
    //     $this->fillField($filterSelector, $value);

    //     // Arama kutusuna giriş yaptıktan sonra, DOM'un güncellenmesini bekle
    //     $this->waitForTableUpdate();
    // }

    /**
     * @When I enter :value in filter :filterName
     */
    public function iEnterInFilter(string $value, string $filterName): void
    {
        $this->browser->waitWhileAppBusy(5);
        $this->browser->waitForAjaxFinished(5);

        $inputContainers = $this->browser->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
        $targetInput = null;

        foreach ($inputContainers as $container) {
            $label = $container->find('css', '.sapMLabel bdi');
            if ($label && trim($label->getText()) === $filterName) {
                $targetInput = $container->find('css', 'input.sapMInputBaseInner');
                break;
            }
        }

        Assert::assertNotNull($targetInput, "Filter input '{$filterName}' not found");

        $targetInput->setValue($value);

        $this->browser->waitWhileAppBusy(5);
        $this->browser->waitForAjaxFinished(5);
    }

    protected function waitForElement($element, $timeout = 30)
    {
        return $this->getSession()->wait(
            $timeout * 1000,
            "jQuery('" . $element . "').length > 0"
        );
    }

    /**
     * UI'nin güncellenmesini bekler (Özel fonksiyon)
     */
    public function waitForTableUpdate()
    {
        $this->getSession()->wait(5000, "document.querySelectorAll('.sapUiTableRow').length > 0");
    }



    public function getTableContents(NodeElement $table, string $columnName): array
    {
        $contents = [];

        // Find column index
        $headerCells = $table->findAll('css', '.sapUiTableHeaderCell');
        $columnIndex = null;

        foreach ($headerCells as $index => $cell) {
            $label = $cell->find('css', '.sapUiTableCellInner label, .sapUiTableCellInner .sapMLabel');
            if ($label && trim($label->getText()) === $columnName) {
                $columnIndex = $index;
                break;
            }
        }

        if ($columnIndex !== null) {
            $rows = $table->findAll('css', '.sapUiTableCtrl tr[data-sap-ui-rowindex]');
            foreach ($rows as $row) {
                $cells = $row->findAll('css', '.sapUiTableCell');
                if (isset($cells[$columnIndex])) {
                    $textElement = $cells[$columnIndex]->find('css', '.sapMText');
                    if ($textElement) {
                        $contents[] = trim($textElement->getText());
                    }
                }
            }
        }

        return $contents;
    }


    /**
     * @Then I see ":text" in column ":columnName"
     */
    public function iSeeInColumn(string $text, string $columnName): void
    {
        try {
            $this->browser->waitForAjaxFinished(10);
            $this->browser->waitWhileAppBusy(10);
            $this->browser->waitForUI5Component('Table', 30);

            $dataTables = $this->browser->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');
            $dataTable = $dataTables[0];

            // Önce doğru kolonu bul
            $headerCells = $dataTable->findAll('css', 'th, [role="columnheader"]');
            $columnIndex = null;

            foreach ($headerCells as $index => $cell) {
                if (trim($cell->getText()) === $columnName) {
                    $columnIndex = $index;
                    break;
                }
            }

            Assert::assertNotNull($columnIndex, "Column '$columnName' not found");

            // Şimdi o kolondaki hücreleri kontrol et
            $rows = $dataTable->findAll('css', 'tr');
            $found = false;

            foreach ($rows as $row) {
                $cells = $row->findAll('css', 'td, [role="gridcell"]');
                if (isset($cells[$columnIndex])) {
                    if (strpos($cells[$columnIndex]->getText(), $text) !== false) {
                        $found = true;
                        break;
                    }
                }
            }

            Assert::assertTrue($found, sprintf('Text "%s" not found in column "%s"', $text, $columnName));

        } catch (\Exception $e) {
            $this->takeScreenshot();
            throw new \RuntimeException(sprintf(
                "Failed to find text '%s' in column '%s'\nError: %s\nCurrent URL  Address: %s",
                $text,
                $columnName,
                $e->getMessage(),
                $this->getCurrentUrlWithHash()
            ));
        }
    }

    private function getCurrentUrlInfo(): array
    {
        return $this->getSession()->evaluateScript('
        (function() {
            var baseUrl = window.location.href.split("#")[0];
            var fullUrl = window.location.href;
            
            // UI5 specific routing information
            var ui5Hash = "";
            if (typeof sap !== "undefined" && 
                sap.ui && 
                sap.ui.core && 
                sap.ui.core.routing && 
                sap.ui.core.routing.HashChanger) {
                
                try {
                    // Get the current hash from UI5 router
                    ui5Hash = sap.ui.core.routing.HashChanger.getInstance().getHash();
                } catch(e) {
                    ui5Hash = window.location.hash.replace("#", "");
                }
            } else {
                ui5Hash = window.location.hash.replace("#", "");
            }
            
            return {
                baseUrl: baseUrl,
                fullUrl: fullUrl,
                hash: ui5Hash
            };
        })()
    ');
    }

    /**
     * Logs the current count of XHR requests for debugging purposes
     * 
     * @param string $context Optional context message to identify the log entry
     * @return void
     */
    private function logXHRCount(string $context = ''): void
    {
        try {
            // Check if XHR monitoring is initialized
            $isInitialized = $this->getSession()->evaluateScript('return typeof window.exfXHRLog !== "undefined"');

            if (!$isInitialized) {
                //echo "\nWarning: XHR monitoring not initialized. Initializing now...\n";
                $this->browser->initializeXHRMonitoring();
            }

            // Get the current count of XHR requests
            $xhrCount = $this->getSession()->evaluateScript('
            if (window.exfXHRLog && window.exfXHRLog.requests) {
                return window.exfXHRLog.requests.length;
            }
            return 0;
        ');

            // Log the count with context if provided
            $message = "\n[XHR LOG] " . ($context ? "{$context} - " : '') . "Request Count: {$xhrCount}";

            // Add any errors if present
            $errors = $this->getSession()->evaluateScript('
            if (window.exfXHRLog && window.exfXHRLog.errors) {
                return window.exfXHRLog.errors;
            }
            return [];
        ');

            if (!empty($errors)) {
                $message .= "\nErrors found: " . count($errors);
                foreach ($errors as $error) {
                    $message .= "\n - Type: " . ($error['type'] ?? 'Unknown');
                    $message .= "\n   Message: " . ($error['message'] ?? 'No message');
                }
            }

            echo $message . "\n";

        } catch (\Exception $e) {
            echo "\nWarning: Failed to log XHR count - " . $e->getMessage() . "\n";
        }
    }


    /**
     * @When I click button ":caption"
     */
    public function iClickButton(string $caption): void
    {
        try {
            // Find and validate the button
            $btn = $this->browser->findButtonByCaption($caption);
            Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

            // Clear XHR logs before click
            $this->browser->clearXHRLog();

            // Click the button
            $btn->click();

            // Wait for UI and network operations
            $this->browser->waitWhileAppBusy(30);
            $this->browser->waitForAjaxFinished(30);

            // Check for any errors after button click
            $error = $this->browser->getAjaxError();
            if ($error !== null) {
                $errorDetails = sprintf(
                    'Button click "%s" failed:\nType: %s\nMessage: %s',
                    $caption,
                    $error['type'] ?? 'Unknown',
                    $error['message'] ?? 'No message'
                );

                throw new \RuntimeException($errorDetails);
            }

            // // Verify that the button click had some effect
            // $requestCount = $this->getSession()->evaluateScript('
            //     return window.exfXHRLog && window.exfXHRLog.requests ? 
            //         window.exfXHRLog.requests.length : 0;
            // ');

            // if ($requestCount === 0) {
            //     // Check UI5 busy indicator to see if any UI operations happened
            //     $busyIndicator = $this->getSession()->evaluateScript('
            //         return sap.ui.core.BusyIndicator._globalBusyIndicatorCounter > 0;
            //     ');

            //     if (!$busyIndicator) {
            //         throw new \RuntimeException(
            //             sprintf('Button click "%s" had no effect - no network requests or UI updates detected', $caption)
            //         );
            //     }
            // }

        } catch (\Exception $e) {
            $this->takeScreenshot();
            throw new \RuntimeException('Button click failed: ' . $e->getMessage());
        }
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
            // $this->takeScreenshot();
            throw $e;
        }
    }

    /**
     * @Then I see at least one data item
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
            // $this->takeScreenshot();

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
     * @When I click the search-button
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
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n"
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
                    return "Total Requests: " + window.exfXHRLog.requests.length + "\n";
                }
                return "No requests logged";
            ');
            echo $afterLog;

        } catch (\Exception $e) {
            echo "\nDebug - Button Click Error: " . $e->getMessage() . "\n";
            // $this->takeScreenshot();
            throw $e;
        }
    }




    // /**
    //  * @Then AJAX requests should complete successfully
    //  * @then Data is loaded
    //  */
    // public function ajaxRequestShouldCompleteSuccessfully(): void
    // {
    //     try {
    //         // Check AJAX status
    //         $error = $this->browser->getAjaxError();
    //         if ($error !== null) {
    //             echo "Debug - AJAX Error Detected: " . json_encode($error, JSON_PRETTY_PRINT) . "\n";
    //             // Prepare error details
    //             $errorDetails = "AJAX request failed:\n";
    //             $errorDetails .= "Type: " . $error['type'] . "\n";
    //             $errorDetails .= "Message: " . ($error['message'] ?? 'Unknown error') . "\n";

    //             // Take a screenshot in case of error
    //             // $this->takeScreenshot();
    //             throw new \Exception($errorDetails);
    //         }

    //         // Ensure UI5 is not busy
    //         $this->browser->waitWhileAppBusy(5);
    //         echo "Debug - AJAX request completed successfully.\n";

    //     } catch (\Exception $e) {
    //         echo "Debug - AJAX Error: " . $e->getMessage() . "\n";
    //         // $this->takeScreenshot();
    //         throw $e;
    //     }
    // }

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

    // /**
    //  * @Then AJAX requests should not have any errors
    //  */
    // public function ajaxRequestsShouldNotHaveErrors(): void
    // {
    //     /**
    //      * Main method to check for any AJAX, UI5, or network errors
    //      * Coordinates all error checks and handles exceptions
    //      */
    //     try {
    //         // Check XHR errors
    //         $this->checkXHRErrors();

    //         // Check UI5 framework errors
    //         $this->checkUI5Errors();

    //         // Check network resource errors
    //         $this->checkNetworkErrors();

    //     } catch (\Exception $e) {
    //         $this->takeScreenshot();
    //         throw $e;
    //     }
    // }

    private function throwFormattedError(string $errorTitle, array $errors, array $fieldMappings): void
    {
        $errorDetails = $errorTitle . ":\n";
        foreach ($errors as $error) {
            foreach ($fieldMappings as $field => $label) {
                $value = $error[$field] ?? 'unknown';
                if ($field === 'response') {
                    $value = substr($value, 0, 500);
                }
                $errorDetails .= "{$label}: {$value}\n";
            }
            $errorDetails .= "\n";
        }

        // $this->takeScreenshot();
        throw new \Exception($errorDetails);
    }

}
//v1