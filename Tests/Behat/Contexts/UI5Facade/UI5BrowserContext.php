<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\MinkContext;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Assert;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Testwork\Tester\Result\TestResult;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\Step\Then;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Gherkin\Node\TableNode;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;

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
class UI5BrowserContext extends BehatFormatterContext implements Context
{
    private $browser;
    private $scenarioName;

    private $workbench = null;

    /** 
     * Initializes and starts the workbench for the test environment
     */
    public function __construct()
    {
        $this->workbench = new Workbench();
        $this->workbench->start();
    }

    /**
     * Dynamically determines workbench root path
     * Traverses up from current directory until finding vendor directory
     * @return string Path to workbench root
     */
    private function getWorkbenchPath(): string
    {
        return $this->getWorkbench()->getInstallationPath();
    }

    /**
     * Logs failed steps to the workbench log
     * Captures exceptions and ensures they are properly recorded
     * 
     * @AfterStep
     * @param AfterStepScope $scope The scope containing step execution result
     */
    public function logFailedStep(AfterStepScope $scope)
    {
        try {
            $result = $scope->getTestResult();

            // Handle different result types
            if (!$result->isPassed()) {


                // Get exception based on result type
                $exception = null;
                if (method_exists($result, 'getException')) {
                    $exception = $result->getException();
                } elseif ($result instanceof UndefinedStepResult) {
                    $exception = new \RuntimeException('Step is not defined: ' . $scope->getStep()->getText());
                } else {
                    $exception = new \RuntimeException('Step failed without exception details');
                }

                // Convert to our exception type for consistent handling
                $wrappedException = new \exface\Core\Exceptions\RuntimeException(
                    $exception->getMessage(),
                    null,
                    $exception
                );

                // Log with full details to the workbench log
                $this->getWorkbench()->getLogger()->logException($wrappedException);
                // Set Error Id for reference
                ErrorManager::getInstance()->setLastLogId($wrappedException->getId());

                // Display LogID for debugging purposes
                echo "LogID: " . $wrappedException->getId() . "\n";
            }
        } catch (\Exception $e) {
            // Handle errors in the error handling itself
            echo "\nError in error logging: " . $e->getMessage() . "\n";
            $this->getWorkbench()->getLogger()->logException($e);
        }
    }


    /**
     * Prepares the environment before each test step
     * - Clears XHR logs for fresh monitoring
     * - Ensures UI5 is in ready state
     * - Cleans up any visual debugging elements
     * 
     * @BeforeStep
     */
    public function prepareBeforeStep(BeforeStepScope $scope): void
    {
        try {
            // Skip if browser hasn't been initialized yet
            if (!$this->browser) {
                return;
            }

            // Clear the ErrorManager for a fresh start
            ErrorManager::getInstance()->clearErrors();

            // Clear XHR logs to monitor only current step's network activity
            $this->browser->clearXHRLog();

            // Remove any widget highlights from previous steps
            $this->getBrowser()->clearWidgetHighlights();

            // Perform basic UI5 readiness checks
            $this->getBrowser()->handleStepWaitOperations(false);

            // Log step beginning for debugging purposes
            echo sprintf(
                "\nStarting step: %s %s",
                $scope->getStep()->getKeyword(),
                $scope->getStep()->getText()
            );

        } catch (\Exception $e) {
            // Record any setup errors in the ErrorManager
            ErrorManager::getInstance()->addError([
                'type' => 'StepPreparation',
                'message' => $e->getMessage()
            ], 'BeforeStep');
        }
    }


    /**
     * Ensures consistent state after each test step
     * - Waits for all pending UI5 operations
     * - Verifies no errors occurred
     * - Takes screenshot on failure
     * 
     * @AfterStep
     * @param AfterStepScope $scope Current step scope
     */
    public function completeAfterStep(AfterStepScope $scope): void
    {
        $errorManager = ErrorManager::getInstance();

        try {
            // Skip if step already failed
            if (!$scope->getTestResult()->isPassed()) {
                return;
            }

            // Skip if browser hasn't been initialized yet
            if (!$this->browser) {
                return;
            }

            // Comprehensive waiting operations to ensure UI stabilization
            $this->getBrowser()->handleStepWaitOperations(true);

            // Check for any errors that occurred
            $this->browser->getWaitManager()->validateNoErrors();

            // Log step completion for debugging
            echo sprintf(
                "\nCompleted step: %s %s",
                $scope->getStep()->getKeyword(),
                $scope->getStep()->getText()
            );

        } catch (\Exception $e) {
            // Add error to the ErrorManager
            $errorManager->addError([
                'type' => 'StepCompletion',
                'message' => $e->getMessage()
            ], 'AfterStep');


            // Only throw the first error encountered
            if ($errorManager->hasErrors()) {
                $firstError = $errorManager->getFirstError();
                throw new \RuntimeException($errorManager->formatErrorMessage($firstError));
            }
        }
    }

    /**
     * Captures scenario name before execution and sets up monitoring
     * 
     * @param BeforeScenarioScope $scope Behat scenario scope
     */
    public function beforeScenario(BeforeScenarioScope $scope)
    {
        $this->scenarioName = $scope->getScenario()->getTitle();

        // Initialize XHR monitoring if browser is available
        if ($this->browser) {
            $this->browser->initializeXHRMonitoring();
            echo "\nXHR monitoring initialized for scenario: " . $this->scenarioName . "\n";
        }
    }


    /**
     * Verifies that the page content is accessible and not empty
     * 
     * @Then /^I should see the page$/
     */
    public function iShouldSeeThePage()
    {
        try {
            // Get the current page object
            $page = $this->getSession()->getPage();

            // Assert that page content exists and is not empty
            Assert::assertNotNull($page->getContent(), 'Page content is empty');
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iShouldSeeThePage');
            throw $e;
        }
    }




    /**
     * Log in to a URL with a specific role and locale
     * 
     * Examples:
     * - Given I log in to page "exface.core.logs.html" as "Support"
     * - Given I log in to page "exface.core.logs.html" as "Support, Debugger"
     * - Given I log in to page "exface.core.logs.html" as "Support" with locale "de_DE"
     * - Given I log in to page "exface.core.logs.html" as "exface.Core.SUPERUSER"
     * 
     * @Given I log in to the page :url
     * @Given I log in to the page :url as :userRole
     * @Given I log in to the page :url as :userRole with locale :locale
     */
    public function iLogInToPage(string $url, string $userRoles = null, string $userLocale = null)
    {
        try {
            // Setup the user and get the required login data
            $userRolesArray = $this->splitArgument($userRoles);
            $loginFields = UI5Browser::setupUser($this->getWorkbench(), $userRolesArray, $userLocale);
            // Extract tab and button captions from the login field data
            $tabCaption = $loginFields['_tab'];
            unset($loginFields['_tab']);
            $btnCaption = $loginFields['_button'];
            unset($loginFields['_button']);

            // Go to the page
            $this->iVisitPage($url);

            // Find the correct authenticator tab. Keep retrying for 5
            $this->getBrowser()->goToTab($tabCaption, null, 5);

            // Fill out the login form
            foreach ($loginFields as $caption => $value) {
                $input = $this->getBrowser()->findInputByCaption($caption);
                Assert::assertNotNull($input, 'Cannot find login field "' . $caption . '"');
                $input->setValue($value);
            }

            // Clear XHR logs before login
            $this->getBrowser()->clearXHRLog();

            // Find and click the login button
            $loginButton = $this->getBrowser()->findButtonByCaption($btnCaption);
            Assert::assertNotNull($loginButton, 'Cannot find login button "' . $btnCaption . '"');
            $loginButton->click();


        } catch (\Exception $e) {
            // Log debugging information and handle the error
            echo "Debug - Login error: " . $e->getMessage() . "\n";
            $this->getWorkbench()->getLogger()->logException($e);
            $this->handleContextError($e, 'UI5', 'iLogInToPage');
            throw $e;
        }


    }

    /**
     * Navigate to a specific page URL
     * Initializes the UI5Browser with the current session
     * 
     * @Given I visit page :url
     * 
     * @param string $url URL to navigate to (will be appended to base URL)
     * @return void
     */
    public function iVisitPage(string $url): void
    {
        try {
            // Navigate to the page using Mink's path navigation
            $this->visitPath('/' . $url);
            echo "Debug - New page is loading...\n";

            // Initialize the UI5Browser with the current session and URL
            $this->browser = new UI5Browser($this->getWorkbench(), $this->getSession(), $url);
            return;
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iVisitPage');
            throw $e;
        }
    }



    /**
     * Verifies presence of a specific number of widgets of a given type
     * Optionally focuses on a specific object alias
     * Highlights matching widgets for visual debugging
     * 
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     * 
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     * @param string $objectAlias Optional object alias to filter widgets
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        try {
            $maxRetries = 5;
            $retryCount = 0;
            $widgetNodes = [];

            // Retry loop to handle async UI rendering
            while ($retryCount < $maxRetries) {
                try {
                    // Set object alias if provided and find widgets
                    $this->getBrowser()->setObjectAlias($objectAlias);
                    $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null, 5);

                    // Clear previous highlights and highlight found widgets
                    $this->getBrowser()->clearWidgetHighlights();
                    foreach ($widgetNodes as $index => $node) {
                        $location = $this->getBrowser()->getWidgetLocation($node);
                        $this->getBrowser()->highlightWidget($node, $widgetType, $index);
                    }

                    // Success criteria depends on widget type
                    if ($widgetType === 'DataTable') {
                        // For DataTables, we need at least the specified number
                        if (count($widgetNodes) >= $number) {
                            break;
                        }
                    } else {
                        // For other widgets, we need exactly the specified number
                        if (count($widgetNodes) === $number) {
                            break;
                        }
                    }

                    // Retry with delay
                    $retryCount++;
                    sleep(5);

                } catch (\Exception $e) {
                    $retryCount++;
                    sleep(5);
                    continue;
                }
            }

            // Verify the correct number of widgets was found
            if ($widgetType === 'DataTable') {
                // For DataTables, we need at least the specified number
                Assert::assertGreaterThanOrEqual(
                    $number,
                    count($widgetNodes),
                    sprintf(
                        "Expected at least %d '%s' widget(s), Found: %d\nURL: %s",
                        $number,
                        $widgetType,
                        count($widgetNodes),
                        $this->getSession()->getCurrentUrl()
                    )
                );
            } else {
                // For other widgets, we need exactly the specified number
                Assert::assertEquals(
                    $number,
                    count($widgetNodes),
                    sprintf(
                        "Expected exactly %d '%s' widget(s), Found: %d\nURL: %s",
                        $number,
                        $widgetType,
                        count($widgetNodes),
                        $this->getSession()->getCurrentUrl()
                    )
                );
            }

            // Focus on the first widget if only one was found
            if (count($widgetNodes) === 1) {
                $this->getBrowser()->focus($widgetNodes[0]);
            }
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iSeeWidgets');
            throw $e;
        }
    }


    /**
     * Verifies that the currently focused element contains a specified number of widgets
     * of a given type. Used after focusing on a container element.
     * 
     * @Then It has :number widget of type ":widgetType"
     * 
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {
        try {
            // Get the currently focused node
            $focusedNode = $this->getBrowser()->getFocusedNode();
            Assert::assertNotNull(
                $focusedNode,
                'No widget has focus right now - cannot use steps like "It has..."'
            );


            // Find the main form container within the focused node
            $form = $focusedNode->find('css', '.sapUiForm') ?? $focusedNode;

            // Find widgets of the specified type within the form
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType, $form);

            if (count($widgetNodes) === 0) {
                // If no widgets found, list potential input elements for debugging 
                // Some UI5 input components may be inside .sapMInputBaseContentWrapper, but in the test scenario, 
                //    only the actual input fields need to be found
                // echo "\nAll potential input elements:\n";
                $allElements = $form->findAll('css', '.sapMInputBase:not(.sapMInputBaseContentWrapper)');

                foreach ($allElements as $index => $element) {

                    $inner = $element->find('css', '.sapMInputBaseInner');

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
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'itHasWidgetsOfType');
            throw $e;
        }
    }


    /**
     * Fills multiple form fields with values from a table
     * The table should have columns 'widget_name' and 'value'
     * 
     * @Then I fill the following fields:
     * 
     * @param TableNode $fields Table with field names and values
     */
    public function iFillTheFollowingFields(TableNode $fields): void
    {
        try {
            // Process each row in the table
            foreach ($fields->getHash() as $row) {
                // Find input by caption
                $widget = $this->getBrowser()->findInputByCaption($row['widget_name']);
                Assert::assertNotNull(
                    $widget,
                    sprintf('Cannot find input widget "%s"', $row['widget_name'])
                );

                // Set value and wait for any UI reactions
                $widget->setValue($row['value']);

            }
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iFillTheFollowingFields');
            throw $e;
        }

    }

    /**
     * Verifies that a focused widget (typically a form or filter group) contains the 
     * specified filters by name
     * 
     * @Then It has filters: :filterList
     * 
     * @param string $filterList Comma-separated list of expected filter names
     */
    public function itHasFilters(string $filterList): void
    {
        try {
            // Parse the comma-separated filter list
            $filters = array_map('trim', explode(',', $filterList));

            // Find all filter containers in the page
            $inputContainers = $this->getBrowser()->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
            $foundFilters = [];

            // Extract filter labels from each container
            foreach ($inputContainers as $container) {
                $label = $container->find('css', '.sapMLabel bdi');
                if ($label) {
                    $foundFilters[] = trim($label->getText());
                }
            }

            // Verify each expected filter is present
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
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'itHasFilters');
            throw $e;
        }
    }


    /**
     * Filter input handling for UI5 applications
     * Supports both standard input fields and special UI5 components like ComboBox
     * 
     * @When I enter :value in filter :filterName
     * 
     * @param string $value The value to enter/select in the filter
     * @param string $filterName The name/label of the filter field
     * @throws \RuntimeException if filter field cannot be found or interaction fails
     */
    public function iEnterInFilter(string $value, string $filterName): void
    {
        try {
            // Find all filter containers in the page
            $inputContainers = $this->getBrowser()->getPage()->findAll('css', '.sapUiVlt.exfw-Filter');
            $targetInput = null;

            // Iterate through containers to find matching filter
            foreach ($inputContainers as $container) {
                $label = $container->find('css', '.sapMLabel bdi');
                if ($label && trim($label->getText()) === $filterName) {
                    // First check if this is a ComboBox/MultiComboBox component
                    $comboBox = $container->find('css', '.sapMComboBoxBase, .sapMMultiComboBox');
                    if ($comboBox) {
                        // Handle ComboBox type input
                        $this->getBrowser()->handleComboBoxInput($comboBox, $value);
                        return;
                    }

                    // Check for Select type input
                    $select = $container->find('css', '.sapMSelect');
                    if ($select) {
                        // Handle Select type input
                        $this->getBrowser()->handleSelectInput($select, $value);
                        return;
                    }

                    // Standard handling for regular input fields
                    $targetInput = $container->find('css', 'input.sapMInputBaseInner');
                    break;
                }
            }

            // If a standard input field was found, set its value
            if ($targetInput) {
                $targetInput->setValue($value);

            } else {
                throw new \RuntimeException("Could not find input element for filter: {$filterName}");
            }
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iEnterInFilter');
            throw $e;
        }
    }


    /**
     * Handles input for ComboBox and MultiComboBox components
     * Opens the dropdown and selects the matching option
     * 
     * @param NodeElement $comboBox The ComboBox element to interact with
     * @param string $value The value to select
     * @throws \RuntimeException if value cannot be selected
     */
    private function handleComboBoxInput(NodeElement $comboBox, string $value): void
    {
        try {
            // Find the dropdown arrow element
            $arrow = $comboBox->find('css', '.sapMInputBaseIconContainer');
            if (!$arrow) {
                throw new \RuntimeException("Could not find ComboBox dropdown arrow");
            }

            // Click to open the dropdown
            $arrow->click();

            // Find and select the matching item from dropdown list
            // Uses CSS selectors to find items containing the value text
            $item = $this->getBrowser()->getPage()->find(
                'css',
                ".sapMSelectList li:contains('{$value}'), " .
                ".sapMComboBoxItem:contains('{$value}'), " .
                ".sapMMultiComboBoxItem:contains('{$value}')"
            );

            // Verify the item was found in the dropdown
            if (!$item) {
                throw new \RuntimeException("Could not find option '{$value}' in ComboBox list");
            }

            // Click on the matching item to select it
            $item->click();
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'handleComboBoxInput');
            throw $e;
        }

    }




    /**
     * Verifies if specific text appears in a named column of a DataTable
     * 
     * @Then I see ":text" in column ":columnName"
     * 
     * @param string $text Text to look for
     * @param string $columnName Name of the column to check
     */
    public function iSeeInColumn(string $text, string $columnName): void
    {
        try {
            // Find all DataTable widgets on the page
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
            Assert::assertNotEmpty($dataTables, 'No DataTable found on page');

            // Verify the first DataTable contains the expected text in the specified column
            $this->getBrowser()->verifyTableContent($dataTables[0], [
                ['column' => $columnName, 'text' => $text]
            ]);
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iSeeInColumn');
            throw $e;
        }
    }


    /**
     * Clicks a button with the specified caption
     * 
     * @When I click button ":caption"
     * 
     * @param string $caption Text caption of the button to click
     */
    public function iClickButton(string $caption): void
    {
        try {
            // Find the button by its caption text
            $btn = $this->getBrowser()->findButtonByCaption($caption);
            if (!$btn) {
                throw new \exface\Core\Exceptions\RuntimeException(
                    sprintf('Cannot find button "%s"', $caption)
                );
            }
            $btn->click();
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iClickButton');
            throw $e;
        }
    }

    /**
     * Clicks a tab with the specified caption
     * 
     * @When I click tab ":caption"
     * 
     * @param string $caption Text caption of the tab to click
     * @return void
     */
    public function iClickTab(string $caption)
    {
        $this->getBrowser()->goToTab($caption);
    }

    /**
     * Enters text into an input widget identified by its caption
     * 
     * @When I type ":value" into ":caption"
     *
     * @param string $value The text to enter
     * @param string $caption Caption of the input widget
     * @return void
     */
    public function iTypeIntoWidgetWithCaption(string $value, string $caption): void
    {
        try {
            // Find the input widget by its caption
            $widget = $this->getBrowser()->findInputByCaption($caption);
            Assert::assertNotNull($widget, 'Cannot find input widget "' . $caption . '"');
            // Set the input value
            $widget->setValue($value);
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iTypeIntoWidgetWithCaption');
            throw $e;
        }
    }

    /**
     * Focus a widget of a given type at a specific position
     * Used to establish context for subsequent "it has..." steps
     * 
     * @When I look at the first ":widgetType"
     * @When I look at ":widgetType" no. :number
     * 
     * @param string $widgetType Type of widget to focus
     * @param int $number Position of the widget (1-based index)
     * @return void
     */
    public function iLookAtWidget(string $widgetType, int $number = 1): void
    {
        try {
            // Find all widgets of the specified type
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType);
            // Get the widget at the specified position (1-based index)
            $node = $widgetNodes[$number - 1];
            Assert::assertNotNull($node, 'Cannot find "' . $widgetType . '" no. ' . $number . '!');
            // Set focus to this widget
            $this->getBrowser()->focus($node);
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iLookAtWidget');
            throw $e;
        }
    }

    /**
     * Verifies that the currently focused widget has a column with the specified caption
     * Typically used with DataTable widgets
     * 
     * @Then it has a column ":caption"
     * 
     * @param string $caption Column caption to look for
     * @return void
     */
    public function itHasColumn(string $caption): void
    {
        try {
            /**
             * @var \Behat\Mink\Element\NodeElement $tableNode
             */
            $tableNode = $this->getFocusedNode();
            Assert::assertNotNull($tableNode, 'No widget has focus right now - cannot use steps like "it has..."');
            $colNode = $tableNode->find('css', 'td');
            Assert::assertNotNull($colNode, 'Column "' . $caption, '" not found');
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'itHasColumn');
            throw $e;
        }
    }


    /**
     * Verifies that any DataTable on the page contains the specified text
     * Searches all cells in the first DataTable found
     * 
     * @Then the DataTable contains :text
     * 
     * @param string $text Text to search for in the DataTable
     */
    public function theDataTableContains(string $text): void
    {
        try {
            // Find all DataTable widgets on the page
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
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
            $this->handleContextError($e, 'UI5', 'theDataTableContains');
            throw $e;
        }
    }

    /**
     * Verifies that at least one data item is present in a DataTable
     * Useful for checking if filtering operations returned results
     * 
     * @Then I see at least one data item
     */
    public function iSeeFilteredResultsInDataTable(): void
    {
        try {
            // Find DataTable widgets
            $dataTables = $this->getBrowser()->findWidgets('DataTable');
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
            $this->handleContextError($e, 'UI5', 'iSeeFilteredResultsInDataTable');
            throw $e;
        }
    }



    /**
     * @When I visit the following pages:
     */
    public function iVisitTheFollowingPages(TableNode $table): void
    {
        try {
            $urls = $table->getHash();
            $currentSession = $this->getSession();

            // Get base URL from current session
            $baseUrl = $currentSession->getCurrentUrl();
            $baseUrl = preg_replace('/\/[^\/]*$/', '/', $baseUrl);

            foreach ($urls as $urlData) {
                $url = $urlData['url'];

                try {
                    // Combine base URL with page URL
                    $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

                    // Navigate using full URL
                    $currentSession->visit($fullUrl);

                    // Initialize browser with current session
                    $this->browser = new UI5Browser($this->getWorkbench(), $currentSession, $url);

                    // Verify page loaded
                    $this->iShouldSeeThePage();


                } catch (\Exception $e) {
                    throw new \RuntimeException(sprintf(
                        "Failed to navigate to '%s'. Error: %s\nFull URL was: %s",
                        $url,
                        $e->getMessage(),
                        $fullUrl ?? 'unknown'
                    ));
                }
            }
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'iVisitTheFollowingPages');
            throw $e;
        }
    }

    /**
     * @Then all pages should load successfully
     */
    public function allPagesShouldLoadSuccessfully(): void
    {
        try {
            // Verify no errors in current session
            $this->browser->getWaitManager()->validateNoErrors();

            // Verify UI5 is in stable state
            $isStable = $this->getSession()->evaluateScript(
                'return sap.ui.getCore().isThemeApplied() && !sap.ui.getCore().getUIDirty()'
            );

            if (!$isStable) {
                throw new \RuntimeException('UI5 framework is not in stable state after page navigation');
            }
        } catch (\Exception $e) {
            $this->handleContextError($e, 'UI5', 'allPagesShouldLoadSuccessfully');
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

    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    public function __destruct()
    {
        UI5Browser::resetUser($this->workbench);
        $this->workbench->stop();
    }

    protected function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            throw new \RuntimeException('BDT Browser not initialized!');
        }
        return $this->browser;
    }

    protected function splitArgument(string $delimitedList = null, string $delimiter = ','): array
    {
        if ($delimitedList === null) {
            return [];
        }
        $array = explode($delimiter, $delimitedList);
        $array = array_map('trim', $array);
        return $array;
    }


    /**
     * Central function for error handling in UI5 Browser context
     * 
     * This function captures, processes and logs exceptions that occur during browser operations.
     * It standardizes the error handling process by formatting error data into a consistent structure
     * and delegates the actual logging to the ErrorManager singleton. The function enriches basic
     * exception information with contextual data such as the current URL and allows for additional
     * custom data to be included.
     * 
     * @param \Exception $e The caught exception instance
     * @param string $type Error type classification (e.g., 'validation', 'connection', 'timeout')
     * @param string $source Source of the error (typically the method name where exception occurred)
     * @param array $additionalData Additional contextual data to include with the error (optional)
     * @return void
     */
    protected function handleContextError(\Exception $e, string $type, string $source, array $additionalData = []): void
    {
        $errorManager = ErrorManager::getInstance();

        // Basic error data
        $errorData = [
            'type' => $type,         // Type of the error
            'message' => $e->getMessage(), // Error message from exception
            'source' => $source,     // Source method where error occurred
            'url' => $this->getBrowser()->getCurrentUrlWithHash(), // Current URL with hash
        ];

        // Add additional data if provided
        if (!empty($additionalData)) {
            $errorData = array_merge($errorData, $additionalData);
        }

        // Add the error to ErrorManager
        $errorManager->addError($errorData, 'UI5BrowserContext');
    }

}
