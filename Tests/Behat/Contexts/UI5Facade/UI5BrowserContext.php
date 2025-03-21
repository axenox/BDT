<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use Behat\Behat\Context\Context;
use Behat\Mink\Element\NodeElement;
use Behat\MinkExtension\Context\MinkContext;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
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
    private $debug = false;

    /** 
     * Initializes and starts the workbench for the test environment
     */
    public function __construct(bool $debug = false) // Update constructor
    {
        $this->workbench = new Workbench();
        $this->workbench->start();
        $this->debug = $debug; // Add this line
    }

    private function logDebug(string $message): void
    {
        if ($this->debug) {
            echo $message . PHP_EOL; // If debug mode is true, it writes the messages
        }
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

           

            // Add to ErrorManager as a Behat exception
            ErrorManager::getInstance()->addError([
                'type' => 'BehatException',
                'message' => $exception->getMessage(),
                'status' => $exception->getCode(),
                'stack' => $exception->getTraceAsString(),
            ], 'AfterStep');

            echo "LogID: " . $wrappedException->getId() . "\n";
            // Display LogID for debugging purposes 
            $this->logDebug("LogID: " . $wrappedException->getId() . "\n");
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

         // Log the beginning of the step for debugging purposes
         $stepKeyword = $scope->getStep()->getKeyword();
         $stepText = $scope->getStep()->getText();
 
         // Get the step's line number
         $stepLine = $scope->getStep()->getLine();
 
         // Show the step number in the message
         $this->logDebug(sprintf("\n[%d] Starting step: %s %s", $stepLine, $stepKeyword, $stepText));
 
         // Show the step name
         $this->browser->showTestCaseName(sprintf("Step [%d]: %s - %s", $stepLine, $stepKeyword, $stepText));
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
        $this->logDebug(sprintf(
            "\nCompleted step: %s %s",
            $scope->getStep()->getKeyword(),
            $scope->getStep()->getText()
        ));

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
            $this->logDebug("\nXHR monitoring initialized for scenario: " . $this->scenarioName . "\n");
        }
    }


    /**
     * Verifies that the page content is accessible and not empty
     * 
     * @Then I should see the page
     */
    public function iShouldSeeThePage()
    {
        // Get the current page object
        $page = $this->getSession()->getPage();

        // Assert that page content exists and is not empty
        Assert::assertNotNull($page->getContent(), 'Page content is empty');

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
        // try {
            if ($url && !StringDataType::endsWith($url, '.html')) {
                $url .= '.html';
            } 
            // Navigate to the page using Mink's path navigation
            $this->visitPath('/' . $url); 
            $this->logDebug("Debug - New page is loading...\n"); 
            // Initialize the UI5Browser with the current session and URL
            $this->browser = new UI5Browser($this->getWorkbench(), $this->getSession(), $url); 
            return;
        // } catch (FacadeBrowserException $e) {
        //     echo "Debug - 5"; 
        //     // Hata durumunda, DebugMessage kullanarak hata widget'ı oluşturma
        //     $debugMessage = new DebugMessage($this->getWorkbench());
        //     $e->createDebugWidget($debugMessage);
        //     throw $e;
        // }  
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

    }


    /**
     * Verifies that the currently focused element contains a specified number of widgets
     * of a given type. Used after focusing on a container element.
     * 
     * @Then it has :number widget of type ":widgetType"
     * 
     * @param int $number Expected number of widgets
     * @param string $widgetType Type of widget to look for
     */
    public function itHasWidgetsOfType(int $number, string $widgetType): void
    {

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

    }

    /**
     * Verifies that a focused widget (typically a form or filter group) contains the 
     * specified filters by name
     * 
     * @Then it has filters: :filterList
     * 
     * @param string $filterList Comma-separated list of expected filter names
     */
    public function itHasFilters(string $filterList): void
    {

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

        // Find all DataTable widgets on the page
        $dataTables = $this->getBrowser()->findWidgets('DataTable');
        Assert::assertNotEmpty($dataTables, 'No DataTable found on page');

        // Verify the first DataTable contains the expected text in the specified column
        $this->getBrowser()->verifyTableContent($dataTables[0], [
            ['column' => $columnName, 'text' => $text]
        ]);

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

        // Find the button by its caption text
        $btn = $this->getBrowser()->findButtonByCaption($caption);
        if (!$btn) {
            throw new \exface\Core\Exceptions\RuntimeException(
                sprintf('Cannot find button "%s"', $caption)
            );
        }
        $btn->click();

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

        // Find the input widget by its caption
        $widget = $this->getBrowser()->findInputByCaption($caption);
        Assert::assertNotNull($widget, 'Cannot find input widget "' . $caption . '"');
        // Set the input value
        $widget->setValue($value);

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

        // Find all widgets of the specified type
        $widgetNodes = $this->getBrowser()->findWidgets($widgetType);
        // Get the widget at the specified position (1-based index)
        $node = $widgetNodes[$number - 1];
        Assert::assertNotNull($node, 'Cannot find "' . $widgetType . '" no. ' . $number . '!');
        // Set focus to this widget
        $this->getBrowser()->focus($node);

    }

    /**
     * Verify the existence of a button with specific text
     * 
     * This method supports multiple scenarios for button verification:
     * - Check button existence by text
     * - Check button existence in a specific table/section
     * 
     * @Then I should see button :buttonText
     * @Then I should see a button with text :buttonText
     * @Then I should see button :buttonText at the :tableName
     * 
     * @param string $buttonText The text of the button to find
     * @param string|null $tableName Optional table/section name
     * @throws \Exception If button is not found
     */
    public function iShouldSeeButton(string $buttonText, string $tableName = null)
    {
        // Attempt to find the button using the UI5Browser instance
        $button = $this->getBrowser()->findButtonByCaption($buttonText);

        // Assert that the button was found
        Assert::assertNotNull($button, "Button with text '{$buttonText}' not found.");

        // Highlight the button for debugging purposes
        $this->getBrowser()->highlightWidget($button, 'Button', 0);

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

        /**
         * @var \Behat\Mink\Element\NodeElement $tableNode
         */
        $tableNode = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($tableNode, 'No widget has focus right now - cannot use steps like "it has..."');
        $colNode = $tableNode->find('css', 'td');
        Assert::assertNotNull($colNode, 'Column "' . $caption, '" not found');

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


    }

    /**
     * Verifies that at least one data item is present in a DataTable
     * Useful for checking if filtering operations returned results
     * 
     * @Then I see at least one data item
     */
    public function iSeeFilteredResultsInDataTable(): void
    {

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
        $this->logDebug(sprintf(
            "Found table with %d rows. Filter indicators: %s\n",
            count($rows),
            $hasFilter ? 'present' : 'not present'
        ));

    }



    /**
     * @When I visit the following pages:
     */
    public function iVisitTheFollowingPages(TableNode $table): void
    {

        $urls = $table->getHash();
        $currentSession = $this->getSession();

        // Get base URL from current session
        $baseUrl = $currentSession->getCurrentUrl();
        $baseUrl = preg_replace('/\/[^\/]*$/', '/', $baseUrl);

        foreach ($urls as $urlData) {
            $url = $urlData['url'];


            // Combine base URL with page URL
            $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');

            // Navigate using full URL
            $currentSession->visit($fullUrl);

            // Initialize browser with current session
            $this->browser = new UI5Browser($this->getWorkbench(), $currentSession, $url);

            // Verify page loaded
            $this->iShouldSeeThePage();
        }
    }

    /**
     * @Then all pages should load successfully
     */
    public function allPagesShouldLoadSuccessfully(): void
    {

        // Verify no errors in current session
        $this->browser->getWaitManager()->validateNoErrors();

        // Verify UI5 is in stable state
        $isStable = $this->getSession()->evaluateScript(
            'return sap.ui.getCore().isThemeApplied() && !sap.ui.getCore().getUIDirty()'
        );

        if (!$isStable) {
            throw new \RuntimeException('UI5 framework is not in stable state after page navigation');
        }

    }

    /**
     * @When I select :rowIndex row on the :tableIndex table  
     */
    public function iSelectRow($rowIndex, $tableIndex)
    {

        $page = $this->getBrowser()->getPage();

        // Convert index to integer and remove any non-numeric characters (e.g., ".")
        $rowNumber = filter_var($rowIndex, FILTER_SANITIZE_NUMBER_INT);
        $tableNumber = filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
        $this->logDebug("Row Nr: " . $rowNumber . "\n");
        $this->logDebug("Table Nr: " . $tableNumber . "\n");


        if (!is_numeric($rowNumber) || $rowNumber < 1) {
            throw new Exception("Invalid row index: '{$rowIndex}'. It should be a positive number.");
        }

        // Save the last selected table number for to use for like clickTableOverflowButton calls
        $this->lastSelectedTable = $tableNumber;

        $splitBar = $page->findAll('css', '.sapUiLoSplitterBar');

        $this->logDebug("Splitbar count: " . count($splitBar) . "\n");

        // Check if there is more than one table 
        if (count($splitBar) > 0) {
            $parents = $page->findAll('css', '.exfw-DataTable');
            $rows = $parents[$tableNumber - 1]->findAll('css', '.sapUiTableRow');

            $selectedRow = $rows[$rowNumber];

            Assert::assertNotNull($selectedRow, "Element Not Found");

            $this->getSession()->wait(1000, false);

            $selectedRow->click();

            // Check if the row really selected 
            $this->logDebug($selectedRow->getAttribute('aria-selected'));
            Assert::assertTrue($selectedRow->getAttribute('aria-selected') === 'true', "{$rowIndex}. row could not be selected");
        } else {
            $this->logDebug(message: "\n ***One Panel Found*** \n");
            $rows = $page->findAll('css', '.sapUiTableRow');
            $selectedRow = $rows[$rowNumber];
            Assert::assertNotNull($selectedRow, "Element Not Found");

            //TODO: Wait for any pending operations to complete add here centralized wait
            $this->getSession()->wait(1000, false);

            $selectedRow->click();
            $this->logDebug($selectedRow->getAttribute('aria-selected'));
            Assert::assertTrue($selectedRow->getAttribute('aria-selected') === 'true', "{$rowIndex}. row could not be selected");
        }

    }





    /**
     * @When I click button :caption on the :tableIndex table
     */
    public function iclickButtonOnTable($caption, $tableIndex)
    {

        $page = $this->getBrowser()->getPage();

        // Convert index to integer and remove any non-numeric characters (e.g., ".")
        $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
        $this->logDebug($caption . "\n");
        $this->logDebug($tableNumber . "\n");

        $this->getSession()->wait(1000, false);
        $splitBar = $page->findAll('css', '.sapUiLoSplitterBar');
        $this->getSession()->wait(1000, false);


        $this->logDebug("\n **** Splitbar count: " . count($splitBar) . " ****** \n");
        // Check if there is more than one table 
        if (count($splitBar) > 0) {

            //$parent = $page->findAll('css', '.sapUiLoSplitterContent:nth-of-type('.$tableNumber.')');
            $parents = $page->findAll('css', '.exfw-DataTable');
            $btn = $this->getBrowser()->findButtonByCaption($caption, $parents[$tableNumber - 1]);
            Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

            $btn->click();

        } else {
            $this->logDebug("***One Panel Found***" . "\n");
            $btn = $this->getBrowser()->findButtonByCaption($caption);
            Assert::assertNotNull($btn, 'Cannot find button "' . $caption . '"');

            $btn->click();
        }

    }


    /**
     * Clicks the overflow button on the specified table
     * 
     * @Then I click the overflow button on table :tableIndex
     * @Then I click the overflow button
     * 
     * @param string|null $tableIndex Table index (optional)
     * @return void
     */
    public function clickTableOverflowButton($tableIndex = null): void
    {

        // If a table index is provided, convert it to an integer
        $tableNumber = null;
        if ($tableIndex !== null) {
            $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
        }

        // If no table index is provided and a last selected table exists, use the last selected table
        if ($tableNumber === null && isset($this->lastSelectedTable)) {
            $tableNumber = $this->lastSelectedTable;
        }

        // Click the overflow button
        $this->clickOverflowButton($tableNumber);


    }

    /**
     * Clicks the overflow button of the selected table
     * 
     * @param int|null $tableIndex The table index (1-based) of the overflow button to click
     * @return void
     */
    public function clickOverflowButton(int $tableIndex = null): void
    {

        // Check if the browser object is initialized
        if (!$this->browser) {
            throw new \RuntimeException("Browser is not initialized. You need to visit a page first.");
        }

        $page = $this->getBrowser()->getPage();

        // If a table index is provided, find the overflow button of that table
        if ($tableIndex !== null) {
            // Find all tables
            $tables = $page->findAll('css', '.exfw-DataTable, .sapUiTable, .sapMTable');

            if (count($tables) < $tableIndex) {
                throw new \RuntimeException("Table not found at the specified index: " . $tableIndex);
            }

            // Get the table at the specified index (convert to 0-based index)
            $targetTable = $tables[$tableIndex - 1];

            // Find the overflow button in this table
            $overflowButton = $targetTable->find('css', 'button[id*="overflowButton"], button[id*="tableMenuButton"]');

            if (!$overflowButton) {
                // Alternatively, search for the overflow button in the table's toolbar
                $toolbar = $targetTable->find('css', '.sapMTB, .sapUiTableTbr');
                if ($toolbar) {
                    $overflowButton = $toolbar->find('css', 'button[id*="overflowButton"]');
                }
            }
        } else {
            // If no table index is provided, find the first overflow button on the page
            $overflowButton = $page->find('css', 'button[id*="overflowButton"]');
        }

        // If no overflow button was found, throw an error
        if (!$overflowButton) {
            throw new \RuntimeException("Overflow butonu bulunamadı" .
                ($tableIndex ? " (Tablo indeksi: $tableIndex)" : ""));
        }

        // Click the button
        $overflowButton->click();

        // Wait briefly
        $this->getSession()->wait(1000);

        // Verify the click was successful
        // In UI5, a popup or popover element usually appears when a menu is opened
        $menu = $page->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');

        if (!$menu) {
            // Try clicking via JavaScript as an alternative method
            $buttonId = $overflowButton->getAttribute('id');
            $this->getSession()->executeScript("
                var element = document.getElementById('$buttonId');
                if (element) {
                    element.click();
                    return true;
                }
                return false;
            ");

            // Wait again and check
            $this->getSession()->wait(1000);
            $menu = $page->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');
        }

        $this->logDebug("✓ Overflow button clicked successfully\n");

    }



    /**
     * @Then an XLSX file should be downloaded
     */
    public function anXlsxFileShouldBeDownloaded(): void
    {

        // First check for the toast message
        $this->verifyToastMessage("Download ready");

        // Wait for a few seconds to ensure the file has been fully downloaded
        sleep(15);

        // Get the download directory from configuration
        $config = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        $downloadDir = $config->getOption('TEST_DOWNLOADS.DIRECTORY_WINDOWS');

        // Ensure the download directory exists
        if (!file_exists($downloadDir) || !is_dir($downloadDir)) {
            throw new \RuntimeException("Download directory does not exist: $downloadDir");
        }

        // Retrieve the latest XLSX file from the download directory
        $latestFile = null;
        $latestTime = 0;
        $oneMinuteAgo = time() - 60;

        $files = scandir($downloadDir);
        foreach ($files as $file) {
            $filePath = $downloadDir . DIRECTORY_SEPARATOR . $file;

            // Check if the item is a file and modified within the last minute
            if (is_file($filePath) && filemtime($filePath) >= $oneMinuteAgo) {
                // Ensure the file has an XLSX extension
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'xlsx') {
                    if (filemtime($filePath) > $latestTime) {
                        $latestTime = filemtime($filePath);
                        $latestFile = $file;
                    }
                }
            }
        }

        // Verify if a valid XLSX file was found
        if ($latestFile) {
            $this->logDebug("✓ Latest downloaded file: $latestFile\n");
        } else {
            throw new \RuntimeException("No recently downloaded XLSX file found in: $downloadDir");
        }

    }

      /**
     * @Then I see tiles :tileNames 
     */
    public function iSeeTiles($tileNames): void
    {
        
        // Parse the comma-separated tile list
        $tileNames = array_map('trim', explode(',', $tileNames));

        // Find tiles on the page
        $this->getSession()->wait(1000, false);
        $tiles = $this->getBrowser()->findWidgets("tile");
   
        Assert::assertNotEmpty($tiles);

        // Store the tile names on the page
        $tileNamesOnPage = [];
        foreach($tiles as $tile){

            // The first part of aria-labes is the name of tile without detailed explanation
            $TileName = strstr($tile->getAttribute('aria-label'), "\n", true);
            
            if(!empty($TileName)){
                $tileNamesOnPage[] = $TileName;
                $this->getBrowser()->highlightWidget($tile, "tile", 0);
            }
        }

        foreach($tileNames as $tileName){
            Assert::assertTrue(in_array($tileName, $tileNamesOnPage), "Tile ".$tileName." not found!");
        }

    }

    
    /**
     * @Then I should not see the buttons :unexpectedButtons
     * @Then I should not see the buttons :unexpectedButtons on the :tableIndex table
     * 
     */
    public function iShouldNotSeeTheFollowingButtons($unexpectedButtons, $tableIndex=null)
    {
        $page = $this->getBrowser()->getPage();

        // Parse the comma-separated tile list
        $unexpectedButtons = array_map('trim', explode(',', $unexpectedButtons));
        
        foreach ($unexpectedButtons as $btn) {
            if(empty($tableIndex)){
                $foundButton = $this->getBrowser()->findButtonByCaption($btn);
            }else{
                //find the parent data table 
                // Convert index to integer and remove any non-numeric characters (e.g., ".")
                $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
                $parents = $page->findAll('css', '.exfw-DataTable');

                //find button with parent
                $foundButton = $this->getBrowser()->findButtonByCaption($btn, $parents[$tableNumber - 1]);
                
            }
            $this->getBrowser()->clearWidgetHighlights();
            if(!empty($foundButton)){
                $this->getBrowser()->highlightWidget($foundButton, 'Button', 0);
            }       
            Assert::assertEmpty($foundButton, "Unexpected buttons found: ".$btn);
        }
        
    }


    /**
     * @Then I only see tiles :tileNames 
     */
    public function iOnlySeeTiles($tileNames): void
    {
        // Parse the comma-separated tile list
        $tileNames = array_map('trim', explode(',', $tileNames));

        // Find tiles on the page
        $this->getSession()->wait(1000, false);
        $tiles = $this->getBrowser()->findWidgets("tile");
        Assert::assertNotEmpty($tiles);

        // Store the tile names on the page
        $tileNamesOnPage = [];
        foreach($tiles as $tile){

            // The first part of aria-labes is the name of tile without detailed explanation
            $TileName = strstr($tile->getAttribute('aria-label'), "\n", true);
            
            if(!empty($TileName)){
                $tileNamesOnPage[] = $TileName;
                $this->getBrowser()->highlightWidget($tile, "tile", 0);
            }
        }

         // Check if all expected tiles are found
         $expectedTiles = array_diff($tileNames, $tileNamesOnPage);
         Assert::assertEmpty(array_diff($tileNames, $tileNamesOnPage), "Some expected tiles are missing: " . implode(", ", $expectedTiles));
         
         // Check if there are unexpected tiles
         $unexpectedTiles = array_diff($tileNamesOnPage, $tileNames);
         Assert::assertEmpty($unexpectedTiles, "Unexpected tiles found: " . implode(", ", $unexpectedTiles));

    }
 

    /**
     * Verifies that a toast message appears with the expected text
     * 
     * @param string $expectedText The text (or part of text) expected in the toast
     * @param int $timeout Maximum time to wait for the toast in seconds
     * @return void
     * @throws \RuntimeException if toast message is not found
     */
    private function verifyToastMessage(string $expectedText, int $timeout = 30): void
    {

        // Start timer
        $start = time();
        $toastFound = false;

        // Try to find the toast message with retries
        while ((time() - $start) < $timeout && !$toastFound) {
            // Look for toast message elements
            $toastElements = $this->getBrowser()->getPage()->findAll('css', '.sapMMessageToast');

            foreach ($toastElements as $toast) {
                $toastText = $toast->getText();

                $this->logDebug("Found toast: $toastText\n");

                // Check if the toast contains the expected text
                if (strpos($toastText, $expectedText) !== false) {

                    $this->logDebug("✓ Found expected toast message: \"$toastText\"\n");
                    $toastFound = true;
                    break;
                }
            }

            if (!$toastFound) {
                // Wait a short time before retrying
                usleep(500000); // 0.5 seconds
            }
        }

        // Assert that the toast was found
        if (!$toastFound) {
            throw new \RuntimeException(
                "Expected toast message containing \"$expectedText\" did not appear within $timeout seconds"
            );
        }

        // Wait a moment to let the toast disappear (if needed)
        sleep(1);

    }


    /**
     * @BeforeScenario
     */
    public function resetAjaxLog(BeforeScenarioScope $scope)
    {
        if ($this->browser) {
            $this->browser->clearXHRLog();
            $this->logDebug("\nXHR logs cleared before scenario: " . $scope->getScenario()->getTitle() . "\n");
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
            $e = new RuntimeException('BDT Browser not initialized!');
            $this->getWorkbench()->getLogger()->logException($e);
            throw $e;
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
            'url' => $this->browser === null ? null : $this->getBrowser()->getCurrentUrlWithHash(), // Current URL with hash
        ];

        // Add additional data if provided
        if (!empty($additionalData)) {
            $errorData = array_merge($errorData, $additionalData);
        }

        // Add the error to ErrorManager
        $errorManager->addError($errorData, 'UI5BrowserContext');
    }

}
