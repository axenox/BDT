<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
use axenox\BDT\Behat\TwigFormatter\Context\BehatFormatterContext;
use axenox\BDT\Common\Installer\TestDataInstaller;
use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Result\UndefinedStepResult;
use Behat\Mink\Element\NodeElement;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\Model\Expression;
use exface\Core\CommonLogic\Security\AuthenticationToken\CliEnvAuthToken;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\CommonLogic\Workbench;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade;
use exface\Core\Factories\FormulaFactory;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Assert;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Gherkin\Node\TableNode;
use axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode;


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
    private $stepStarttime = null;
    private $browser;
    private $scenarioName;

    private $workbench = null;
    private $debug = false;
    private string $locale = 'de_DE';
    /** @var array<string,bool> */
    private array $validatedAliases = [];
    /** 
     * Initializes and starts the workbench for the test environment
     */
    public function __construct(bool $debug = false) // Update constructor
    {
        $this->workbench = new Workbench();
        $this->workbench->start();
        // Authenticated with the default CLI user if called from CLI. The authenticated
        // user will change with Browser::setupUser() later, but for now the CLI user is
        // better than no user at all!
        if (ConsoleFacade::isPhpScriptRunInCli()) {
            $token = new CliEnvAuthToken();
            $this->workbench->getSecurity()->authenticate($token);
        }
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
    
    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $value): void
    {
        $this->locale = $value;
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
                // Convert to our exception type for consistent handling
                $wrappedException = new RuntimeException(
                    $exception->getMessage(),
                    null,
                    $exception
                );
            } elseif ($result instanceof UndefinedStepResult) {
                $wrappedException = new RuntimeException('Step is not defined: ' . $scope->getStep()->getText());
            } else {
                $wrappedException = new RuntimeException('Step failed without exception details');
            }

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

        // install http status interceptor to eliminate bad requests
        $this->getBrowser()->getWaitManager()->installHttpInterceptor();
        
        // Add a small additional wait to ensure complete stability
        $this->getSession()->wait(1000);

        // Remove any widget highlights from previous steps
        $this->getBrowser()->clearWidgetHighlights();

        // Log the beginning of the step for debugging purposes
        $stepKeyword = $scope->getStep()->getKeyword();
        $stepText = $scope->getStep()->getText();

        // Get the step's line number
        $stepLine = $scope->getStep()->getLine();

        // Show the step number in the message
        $this->logDebug(sprintf("\n[%d] Starting step: %s %s", $stepLine, $stepKeyword, $stepText));

        // Show the step name
        $this->browser->showTestCaseName(sprintf("Step [%d]: %s - %s", $stepLine, $stepKeyword, $stepText));

        // Record step start time and display timing information
        $stepName = sprintf("%s %s", $stepKeyword, $stepText);
        $this->stepStartTime = $this->browser->showStepTiming($stepName, true);
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

        //  Log step completion for debugging   
        $stepKeyword = $scope->getStep()->getKeyword();
        $stepText = $scope->getStep()->getText();
        $this->logDebug(sprintf(
            "\nCompleted step: %s %s",
            $stepKeyword,
            $stepText
        ));

        // Show step completion timing information
        $stepName = sprintf("%s %s", $stepKeyword, $stepText);
        $this->browser->showStepTiming($stepName, false, $this->stepStartTime);

        // Add a 1-second pause after each step
        $this->getSession()->wait(1000);

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
        
        $this->setLocale($userLocale);
        
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
        
        $this->getBrowser()->getWaitManager()->waitForAppLoaded($url);
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
        if ($url && !StringDataType::endsWith($url, '.html')) {
            $url .= '.html';
        }
        
        // Page alias like `axenox.bdt.home`
        $pageAlias = StringDataType::substringAfter($url, '/', false, true);
        $pageAlias = StringDataType::substringBefore($url, '.html', $url, false, true);
        
        $this->navigateToPageAlias($pageAlias);
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
     * @throws AssertionFailedError If widget count doesn't match expectation
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias = null): void
    {
        // Clear all focus stack
        $this->getBrowser()->clearFocusStack();

        // Wait for any pending operations to complete
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        // Fetch widgets based on type and optional alias
        $widgetNodes = $this->getBrowser()->findWidgetNodes($widgetType, 15);

        // if widget is a dialog or table, make it focused
        if (count($widgetNodes) === 1) {

            $firstNode = reset($widgetNodes);

            if ($firstNode->capturesFocus() === true) {
                $this->getBrowser()->focus($firstNode);
            }

        }

        // Assert the number of widgets
        Assert::assertCount(
            $number,
            $widgetNodes,
            sprintf(
                "Expected %d widget(s) of type '%s' with alias '%s', but found %d",
                $number,
                $widgetType,
                $objectAlias ?? 'N/A',
                count($widgetNodes)
            )
        );

        // // Optionally highlight the first widget for debugging
        // if (!empty($widgets)) {
        //     echo "Test Girdi\n";
        //     $this->browser->highlightWidget($widgets[0], $widgetType, 0);
        // }

        // Optionally highlight widgets for debugging
        if (!empty($widgetNodes)) {
            $maxHighlight = min(count($widgetNodes), 3);
            for ($i = 0; $i < $maxHighlight; $i++) {
                // change to NodeElement with getNodeElement() 
                $nodeElement = $widgetNodes[$i]->getNodeElement();
                $this->browser->highlightWidget($nodeElement, $widgetType, $i);
            }
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
        // If dialog exists, set dialog as focus point
        $dialogWidgets = $this->getBrowser()->findWidgets('Dialog');

        if (!empty($dialogWidgets)) {
            // If dialog is found, use null as object alias to search within the dialog
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null);
        } else {
            // If no dialog exists, search on the entire page
            $widgetNodes = $this->getBrowser()->findWidgets($widgetType, null);
        }

        // Check the number of found widgets
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

        // Highlight found widgets (optional, for debugging)
        foreach (array_slice($widgetNodes, 0, 3) as $index => $node) {
            $this->getBrowser()->highlightWidget(
                $node,
                $widgetType,
                $index
            );
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
        $expectedFilters = array_map('trim', explode(',', $filterList));

        // Get the currently focused node
        $focusedNode = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($focusedNode, 'No widget is currently focused. Call "I look at" first.');
        
        $filterNodes = $this->getBrowser()->getFilters();
        $foundFilters = [];
        foreach ($filterNodes as $index => $filterNode) {
            // Find the label for the filter
            $caption = $filterNode->getCaption();
            if (in_array($caption, $expectedFilters)) {
                $foundFilters[] = $caption;

                // Highlight the filter
                $this->getBrowser()->highlightWidget(
                    $filterNode->getNodeElement(),
                    'Filter',
                    $index  // Use the actual index from the filtered containers
                );
            }
        }

        // Verify each expected filter is present
        foreach ($expectedFilters as $expectedFilter) {
            Assert::assertTrue(
                in_array($expectedFilter, $foundFilters),
                sprintf(
                    'Filter "%s" not found. Available filters: %s',
                    $expectedFilter,
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
        $this->getBrowser()->getFilterByCaption($filterName)->setValue($value);
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
        $focusedNode = $this->getBrowser()->getFocusedNode();

        // Find all DataTable widgets on the page
        $dataTables = $this->getBrowser()->findWidgets('DataTable');
        Assert::assertNotEmpty($dataTables, 'No DataTable found on page');

        // Verify the first DataTable contains the expected text in the specified column
        $this->getBrowser()->verifyTableContent($focusedNode->getNodeElement(), [
            ['column' => $columnName, 'value' => $text]
        ]);

    }


    /**
     * Clicks a button with the specified caption
     * Searches for a button within the currently focused widget or page
     * Uses multiple search strategies to find the button
     * 
     * @When I click button ":caption"
     * 
     * @param string $caption Text caption of the button to click
     * @throws RuntimeException If button cannot be found or clicked
     */
    public function iClickButton(string $caption): void
    {
        // Get the currently focused widget's node element
        $widget = $this->getBrowser()->getFocusedNode()->getNodeElement();

        if (!$widget) {
            throw new RuntimeException("No focused widget found");
        }

        // First, try standard Mink named button search within the widget
        $button = $widget->find('named', ['button', $caption]);

        // If standard search fails, use alternative search strategies
        if (!$button) {
            // Find all buttons within the widget
            $buttons = $widget->findAll('css', 'button');

            foreach ($buttons as $btn) {
                // Check the matches
                if (stripos($btn->getText(), $caption) !== false) {
                    $button = $btn;
                    break;
                }

                // Title attribute control
                if (
                    $btn->getAttribute('title') &&
                    stripos($btn->getAttribute('title'), $caption) !== false
                ) {
                    $button = $btn;
                    break;
                }
            }
        }

        // If still doesn't find, check it in entire page
        if (!$button) {
            $page = $this->getSession()->getPage();
            $buttons = $page->findAll('css', 'button');

            foreach ($buttons as $btn) {
                // Check button text for caption match
                if (stripos($btn->getText(), $caption) !== false) {
                    $button = $btn;
                    break;
                }
            }
        }

        // If button is still not found, provide detailed debug information
        if (!$button) {
            // Log detailed search context for debugging
            //$this->debugButtonSearchContext($caption, $widget);

            throw new RuntimeException("Button '$caption' not found in the current widget or page");
        }

        if ($button) {
            // highlight the button with highlightWidget
            $this->getBrowser()->highlightWidget(
                $button,
                'Button',  // Widget type
                0           // Index (0 for the first button)
            );

            // button click process
            try {
                $button->click();
            } catch (\Exception $e) {
                //$this->debugButtonClickContext($button, $caption);
                throw $e;
            }
        }

        // Attempt to click the button
        try {
            $button->click();
        } catch (\Exception $e) {
            // If error occurs while clicking
            //$this->debugButtonClickContext($button, $caption);
            throw $e;
        }

    }

    /**
     * Provides detailed debugging information when button search fails
     * 
     * Logs:
     * - Widget HTML content
     * - All buttons within the widget
     * - All buttons on the page
     * 
     * @param string $caption The button caption being searched
     * @param NodeElement $widget The widget being searched
     */
    private function debugButtonSearchContext(string $caption, $widget)
    {
        // Log the HTML content of the current widget
        echo "Widget HTML Content:\n";
        echo $widget->getHtml() . "\n\n";

        // List all buttons within the widget
        echo "All Buttons in Widget:\n";
        $buttons = $widget->findAll('css', 'button');
        foreach ($buttons as $btn) {
            echo "Button Text: " . $btn->getText() . "\n";
            echo "Button Title: " . $btn->getAttribute('title') . "\n";
            echo "Button Classes: " . $btn->getAttribute('class') . "\n\n";
        }

        // List all buttons on the page
        echo "All Buttons on Page:\n";
        $pageButtons = $this->getSession()->getPage()->findAll('css', 'button');
        foreach ($pageButtons as $btn) {
            echo "Button Text: " . $btn->getText() . "\n";
            echo "Button Title: " . $btn->getAttribute('title') . "\n";
            echo "Button Classes: " . $btn->getAttribute('class') . "\n\n";
        }
    }

    /**
     * Provides detailed debugging information when button click fails
     * 
     * Logs:
     * - Button text
     * - Button visibility status
     * - Button enabled/disabled state
     * - Executes JavaScript to further investigate button properties
     * 
     * @param NodeElement $button The button that failed to click
     * @param string $caption The button's caption
     */
    private function debugButtonClickContext($button, string $caption)
    {
        // Log basic button properties

        echo "Button Click Debug:\n";
        echo "Button Text: " . $button->getText() . "\n";
        echo "Button Visibility: " . ($button->isVisible() ? 'Visible' : 'Hidden') . "\n";
        echo "Button Enabled: " . ($button->hasAttribute('disabled') ? 'Disabled' : 'Enabled') . "\n";

        // Use JavaScript to perform additional button property checks
        $this->getSession()->executeScript("
        var button = arguments[0];
        console.log('Button found:', button);
        console.log('Button text:', button.textContent);
        console.log('Button visibility:', button.offsetParent !== null);
        console.log('Button disabled:', button.disabled);
    ", [$button->getXpath()]);
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
        $widgetNodes = $this->getBrowser()->findWidgetNodes($widgetType,15);
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
     * @Then I should see buttons :buttonText
     * @Then I should see a button with text :buttonText
     * @Then I should see button :buttonText at the :tableName
     * 
     * @param string $buttonText The text of the button to find
     * @param string|null $tableName Optional table/section name
     * @throws \Exception If button is not found
     */
    public function iShouldSeeButton(string $buttonText, string $tableName = null)
    {
        $buttons = $this->explodeList($buttonText);
        foreach ($buttons as $buttonText) {
            // Attempt to find the button using the UI5Browser instance
            $button = $this->getBrowser()->findButtonByCaption($buttonText);

            // Assert that the button was found
            Assert::assertNotNull($button, "Button with text '{$buttonText}' not found.");

            // Highlight the button for debugging purposes
            $this->getBrowser()->highlightWidget($button, 'Button', 0);
        }

    }

    /**
     * @Then the column :columnName in data spreadsheet should be disabled
     */
    public function theColumnInDataSpreadsheetShouldBeDisabled($columnName)
    {
        // Find the column by its header text
        $dataSpreadSheetNode = $this->getBrowser()->findWidgetNodes("DataSpreadSheet", 15);

        // Find header cells (column names)
        $headers = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel thead tr td");
        $columnIndex = null;

        foreach ($headers as $index => $header) {
            //& !strpos(trim($header->getText()), "hidden" )
            if (trim($header->getText()) === $columnName ) {
                print($header->getText() . "\n");
                $columnIndex = $index;
                break;
            }
        }
        if ($columnIndex === null) {
            throw new \Exception("Column '$columnName' not found in Data Spreadsheet.");
        }

        // Find all cells in that column
        $rows = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel tbody tr");

        foreach ($rows as $rowIndex => $row) {
            $tds = $row->findAll('css', "td");
            $cell = $tds[$columnIndex];

            $class = $cell->getAttribute('class');

            // If the class is not readonly class throw Exception
            if (strpos($class, 'readonly') === false) {
                throw new \Exception("Column '$columnName' is NOT disabled in row " . ($rowIndex + 1));
            }
        }
    }

    /**
     *
     * Fills multiple form fields with values from a table
     * The table should have columns 'Column' and 'Value'
     *
     * @When I fill the row :rowIndex of data spreadsheet with:
     * @When I fill the last row of data spreadsheet with:
     *
     *
     */
    public function iFillTheNthRowOfDataSpreadsheetWith(TableNode $table, $rowIndex = null)
    {
        if ($rowIndex === null) {
            $rowIndex = 'last';
        }

        $dataSpreadSheetNode = $this->getBrowser()->findWidgetNodes("DataSpreadSheet", 15);


        // get headers
        $headers = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel thead tr td");

        $headerMap = [];
        foreach ($headers as $index => $header) {
            $headerMap[trim($header->getText())] = $index;
        }

        // last row
        $rows = $dataSpreadSheetNode[0]->getNodeElement()->findAll('css', "table.jexcel tbody tr");
        if (empty($rows)) {
            throw new \Exception("No rows found in Data Spreadsheet.");
        }

        if (strtolower($rowIndex) === 'last') {
            $rowNumber = count($rows) - 1;
        } else {
            $rowNumber = intval($rowIndex) - 1; // adjust to 0-based
        }

        if (!isset($rows[$rowNumber])) {
            throw new \Exception("Row '$rowIndex' not found.");
        }
        $targetRow = $rows[$rowNumber];
        $tds = $targetRow->findAll('css', "td");


        // loop over table rows given in feature file
        foreach ($table->getHash() as $row) {
            $columnName = $row['Column'];
            $value = $row['Value'];

            if (!isset($headerMap[$columnName])) {
                throw new \Exception("Column '$columnName' not found.");
            }
            $columnIndex = $headerMap[$columnName];
            $cell = $tds[$columnIndex];

            // double click to activate editor
            $cell->doubleClick();

            // try to find editor element inside cell
            $editor = $cell->find('css', 'input, textarea, [contenteditable]');

            if ($editor !== null) {
                // execute events
                $editor->setValue($value);
                $this->getSession()->executeScript("
                    var el = document.activeElement;
                    if (el) {
                        el.dispatchEvent(new Event('input',{bubbles:true}));
                        el.dispatchEvent(new Event('change',{bubbles:true}));
                    }
                ");

                // check if dropdowns?
                $dropdownItem = $this->getSession()->getPage()->find('xpath', "//div[contains(@class,'jdropdown') or contains(@class,'jexcel_dropdown')]//div[text()=".json_encode($value)."]");
                if ($dropdownItem !== null) {
                    $dropdownItem->click();
                }

            } else {
                // if there is no editor
                $this->getSession()->executeScript(sprintf(
                    "var row = document.querySelectorAll('table.jexcel tbody tr')[%d];
                     var cell = row.querySelectorAll('td')[%d];
                     if(cell){ cell.textContent = %s; }",
                    $rowNumber, $columnIndex, json_encode($value)
                ));
            }

        }

    }

    /**
     * Verifies that the currently focused widget has a column with the specified caption
     * Typically used with DataTable widgets
     * 
     * @Then it has a column ":caption"
     * @Then it has columns ":caption"
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

        $captions = $this->explodeList($caption);
        foreach ($captions as $caption) {
            $col = $this->getBrowser()->findColumnByCaption($caption, $tableNode);
            Assert::assertNotNull($col, 'Column "' . $caption . '" not found');
            $this->getBrowser()->highlightWidget($col, 'Column', 0);
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


        $dataTable = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($dataTable, 'No focused node found');
        Assert::assertInstanceOf(UI5DataTableNode::class, $dataTable, 'Focused node is not a data table');

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
        //TODO: this also needs to add visited pages to the DatabaseFormatter
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
            $this->browser = new UI5Browser($this->getWorkbench(), $currentSession, $url, $this->getLocale());
            $this->wireBrowserCallbacks();
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
     * Focuses on a specific table by index
     * 
     * @When I look at table :index
     * 
     * @param int $index The 1-based index of the table to focus on
     * @throws \RuntimeException If the table cannot be found
     */
    public function iLookAtTable(int $index): void
    {

        // Adjust to 0-based index for internal use
        $tableIndex = $index - 1;
        $tables = $this->getBrowser()->findWidgetNodes('DataTable');
        Assert::assertNotEmpty($tables, 'No DataTable found on page');

        if (!isset($tables[$index - 1])) {
            throw new \RuntimeException("Table {$index} not found. Only " . count($tables) . " tables available.");
        }
        $table = $tables[$tableIndex];
        $this->getBrowser()->highlightWidget($table->getNodeElement(), 'DataTable', $index);
        // Focus the selected table
        $this->getBrowser()->focus($table);
    }


    /**
     * Selects a specific row in a table
     *
     * @When I select table row :rowNumber
     */
    public function iSelectTableRow(int $rowNumber)
    {
        // Use the focused table (if there is no error, throw an error)
        /** @var \axenox\BDT\Behat\Contexts\UI5Facade\Nodes\UI5DataTableNode $table */
        $table = $this->getBrowser()->getFocusedNode();
        Assert::assertNotNull($table, "No table is currently focused. Call 'I look at table' first.");

        $table->selectRow($rowNumber);

        // Wait for UI 
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        Assert::assertTrue($table->isRowSelected($rowNumber), "Failed to select row {$rowNumber}");
    }


    /**
     * @When I click button :caption on the :tableIndex table
     */
    public function iClickButtonOnTable(string $buttonCaption, $tableIndex = 1)
    {
        $this->logDebug("Button Click Started: $buttonCaption, Table: $tableIndex");

        // Wait for all pending operations to complete
        $this->getBrowser()->getWaitManager()->waitForPendingOperations(true, true, true);

        $page = $this->getBrowser()->getPage();

        // Find all DataTables
        $dataTables = $page->findAll('css', '.exfw-DataTable');
        $this->logDebug("DataTable count: " . count($dataTables));

        // Adjust table index (1-based indexing)
        $tableNumber = filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);

        if (count($dataTables) === 0) {
            throw new \Exception("No DataTables found on the page");
        }

        // Select a specific table if multiple DataTables exist
        $targetTable = count($dataTables) > 1
            ? $dataTables[$tableNumber - 1]
            : $dataTables[0];

        // Find the button
        $button = $targetTable->findButton($buttonCaption);

        if (!$button) {
            // If not found in the table, search globally on the page
            $button = $page->findButton($buttonCaption);
        }

        // Check and click the button
        Assert::assertNotNull($button, "Button '$buttonCaption' not found");

        try {
            // Use JavaScript click method to bypass visibility constraints
            $this->getSession()->executeScript(
                "arguments[0].click();",
                [$button->getXpath()]
            );

            // Short wait after clicking
            $this->getSession()->wait(1000);

            $this->logDebug("Button '$buttonCaption' clicked successfully");
        } catch (\Exception $e) {
            $this->logDebug("Button click failed: " . $e->getMessage());
            throw new \Exception("Could not click button '$buttonCaption': " . $e->getMessage());
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


        // Click the overflow button
        $this->clickOverflowButton($tableNumber);
    }

    /**
     * Clicks the overflow button of the selected table or in the focused context
     * 
     * @param int|null $tableIndex The table index (1-based) of the overflow button to click
     * @return void
     */
    public function clickOverflowButton($tableIndex = null)
    {
        // Check if the browser object is initialized
        if (!$this->browser) {
            throw new \RuntimeException("Browser is not initialized. You need to visit a page first.");
        }

        $overflowButton = null;

        // First, try to find in the focused node
        $focusedNode = $this->getBrowser()->getFocusedNode();
        if ($focusedNode) {
            $overflowButton = $this->getBrowser()->getFocusedNode()->getNodeElement()->find('css', 'button[id$="-overflowButton"]');
        }

        if ($overflowButton) {
            // Highlight Button 
            $this->getBrowser()->highlightWidget($overflowButton, 'Button', 0);
        } else {
            // If button cant found, throw ex
            throw new \RuntimeException("Overflow button not found");
        }

        // If not found in focused node and a table index is provided
        if (!$overflowButton && $tableIndex !== null) {
            $page = $this->getBrowser()->getPage();
            $tables = $page->findAll('css', '.exfw-DataTable, .sapUiTable, .sapMTable');

            if (count($tables) < $tableIndex) {
                throw new \RuntimeException("Table not found at the specified index: " . $tableIndex);
            }

            $targetTable = $tables[$tableIndex - 1];
            $overflowButton = $targetTable->find('css', 'button[id*="overflowButton"], button[id*="tableMenuButton"]');
        }

        // If still not found, try in the page
        if (!$overflowButton) {
            $page = $this->getBrowser()->getPage();
            $overflowButton = $page->find('css', 'button[id*="overflowButton"]');
        }

        // If no overflow button was found, throw an error
        if (!$overflowButton) {
            throw new \RuntimeException("Overflow button couldn't be found" .
                ($tableIndex ? " (Table index: $tableIndex)" : ""));
        }

        // Click the button
        $overflowButton->click();

        // Wait briefly
        $this->getSession()->wait(1000);

        // Verify the click was successful
        $menu = $this->getBrowser()->getPage()->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');

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
            $menu = $this->getBrowser()->getPage()->find('css', '.sapMPopover, .sapMMenu, [role="menu"], .sapUiMenu');
        }

        $this->logDebug("✓ Overflow button clicked successfully\n");
    }



    /**
     * @Then an XLSX file should be downloaded
     */
    public function anXlsxFileShouldBeDownloaded(): void
    {
        // Flexible waiting time
        $maxWaitTime = 30; // Maximum wait 30 seconds
        $startTime = time();

        while (time() - $startTime < $maxWaitTime) {
            // Check downloaded files
            $downloadedFile = $this->getBrowser()->findLatestXlsxFile();

            if ($downloadedFile) {
                // Short wait to ensure file is completely downloaded
                sleep(2);

                // Check file size
                $fileSize = filesize($downloadedFile);
                if ($fileSize > 0) {
                    $this->logDebug("✓ Downloaded file: " . basename($downloadedFile) . " (Size: {$fileSize} bytes)");
                    return;
                }
            }

            // Wait a short time
            sleep(2);
        }

        throw new \RuntimeException("XLSX file could not be downloaded or is empty.");
    }






    /**
     * Verify the presence of specific tiles on the page
     * This method checks if all expected tiles are present in the UI
     * 
     * @Then I see tiles :tileNames 
     */
    public function iSeeTiles($tileNames): void
    {
        // Convert the comma-separated tile names into an array
        // Trims whitespace and handles multiple tile names
        $captions = $this->explodeList($tileNames);

        // Array to track which tiles have been found
        // Helps in providing detailed reporting
        $foundTiles = [];

        // Iterate through all tiles found on the page
        // Uses the browser's tile finding method to locate tile elements
        foreach ($this->getBrowser()->findTiles() as $tile) {
            // Extract the caption (name/text) of the current tile
            $tileName = $tile->getCaption();

            // Check if the current tile's name matches any of the expected tile names
            // array_search allows for exact matching and provides the index
            $matchIndex = array_search($tileName, $captions);

            // If a match is found
            if ($matchIndex !== false) {
                // Add the found tile to the list of discovered tiles
                $foundTiles[] = $tileName;

                // Remove the found tile from the list of expected tiles
                // This helps track which tiles are still missing
                unset($captions[$matchIndex]);
            }
        }

        // Final assertion to ensure all expected tiles are found
        // If any tiles remain in $captions, it means they were not discovered
        Assert::assertEmpty(
            $captions,
            // Detailed error message showing:
            // 1. Which tiles were not found
            // 2. Which tiles were successfully located
            'Tiles not found: ' . implode(', ', $captions) .
            '. Found tiles: ' . implode(', ', $foundTiles)
        );
    }

    /**
     * @Then I only see tiles :tileNames 
     */
    public function iOnlySeeTiles($tileNames): void
    {
        $captions = $this->explodeList($tileNames);

        $otherCaptions = [];
        foreach ($this->getBrowser()->findTiles() as $tile) {
            $tileName = $tile->getCaption();
            $tileIdx = array_search($tileName, $captions);
            if ($tileIdx !== false) {
                unset($captions[$tileIdx]);
            } else {
                $otherCaptions[] = $tileName;
            }
        }
        Assert::assertEmpty($captions, 'Tiles not found: ' . implode(', ', $captions));
        Assert::assertEmpty($otherCaptions, 'Found more tiles than expected: ' . implode(', ', $otherCaptions));
    }

    /**
     * @Then I should not see the buttons :unexpectedButtons
     * @Then I should not see the buttons :unexpectedButtons on the :tableIndex table
     * 
     */
    public function iShouldNotSeeTheFollowingButtons($unexpectedButtons, $tableIndex = null)
    {
        $page = $this->getBrowser()->getPage();

        // Parse the comma-separated tile list
        $unexpectedButtons = array_map('trim', explode(',', $unexpectedButtons));

        foreach ($unexpectedButtons as $btn) {
            if (empty($tableIndex)) {
                $foundButton = $this->getBrowser()->findButtonByCaption($btn);
            } else {
                //find the parent data table 
                // Convert index to integer and remove any non-numeric characters (e.g., ".")
                $tableNumber = (int) filter_var($tableIndex, FILTER_SANITIZE_NUMBER_INT);
                $parents = $page->findAll('css', '.exfw-DataTable');

                //find button with parent
                $foundButton = $this->getBrowser()->findButtonByCaption($btn, $parents[$tableNumber - 1]);

            }

            if (!empty($foundButton)) {
                $this->getBrowser()->highlightWidget($foundButton, 'Button', 0);
            }
            Assert::assertEmpty($foundButton, "Unexpected buttons found: " . $btn);
        }
    }

    /**
     * @Then I should see tabs :tabs
     * @Then I should see tab :tabs
     */
    public function iSeeTabs($tabs): void
    {
        $tabs = $this->explodeList($tabs);

        foreach ($tabs as $tab) {
            $foundedTab = $this->getBrowser()->findTabByCaption($tab);
            Assert::assertNotNull($foundedTab, "The Tab " . $tab . " is not found!");
            $this->getBrowser()->highlightWidget($foundedTab, "Tab", 0);
        }

    }

    /**
     * Given I log in ...
     * And test data from "nbr.OneLink" folder "Global" is loaded
     * When I do...
     * 
     * @Given test data from ":appAlias" folder ":subfolder" is loaded
     * 
     * @param string $appAlias
     * @param string $subfolder
     * @return void
     */
    public function testDataIsLoaded(string $appAlias, string $subfolder)
    {
        $workbench = $this->getWorkbench();
        $appSelector = new AppSelector($workbench, $appAlias);
        $installer = new TestDataInstaller($appSelector, '');
        $log = '';
        foreach ($installer->installTestData($subfolder) as $output) {
            $log .= $output . PHP_EOL;
        }
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

    /**
     * Examples:
     * 
     * - [#=Now()#]
     * - [#=GetConfig('exface.Core', 'CONFIG_KEY')#]
     * - `TestReport [#=Now('yyyyMMdd_HHmmss')#]`
     * 
     * @param string $argument
     * @return string
     */
    protected function parseArgument(string $argument) : string
    {
        $phs = StringDataType::findPlaceholders($argument);
        if (! empty($phs)) {
            $phVals = [];
            foreach ($phs as $ph) {
                if (Expression::detectFormula($ph)) {
                    $formula = FormulaFactory::createFromString($this->getWorkbench(), $ph);
                    $phVals[$ph] = $formula->evaluate();
                }
                $argument = StringDataType::replacePlaceholders($argument, $phVals);
            }
        }
        return $argument;
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

    protected function explodeList(string $list): array
    {
        return array_map('trim', explode(',', $list));
    }

    /**
     * Example
     * 
     * ```
     * Given I log in ...
     * When I look at table 1
     * Then it works as shown below
     * Column Caption | Filter Caption | Button Caption
     * 
     * ```
     * @Then it works as shown below
     * | :Column Caption | :Filter Caption | :Button Caption |
     * 
     * @param TableNode $fields Table with field names and values
     * @return void
     */
    public function itWorksAsShown(TableNode $fields)
    {
        $node = $this->getBrowser()->getFocusedNode();
        Assert::assertInstanceOf(UI5DataTableNode::class, $node, 'Focused node is not a data table');
        $node->itWorksAsShown($fields);
    }

    /**
     * Example
     *
     * ```
     * Given I log in ...
     * When I look at table 1
     * Then it works as expected
     * ```
     * @Then it works as expected
     * 
     * @return void
     */
    public function itWorksAsExpected()
    {
        $node = $this->getBrowser()->getFocusedNode();
        $node->itWorksAsExpected();
    }

    /**
     * Centralized navigation helper.
     *
     * This method is the single source of truth for:
     *  1) DB/report logging of visited pages
     *  2) actual browser navigation
     *  3) UI5Browser re-initialization after navigation
     *
     * @param string $pageAlias
     */
    private function navigateToPageAlias(string $pageAlias): void
    {
        DatabaseFormatter::addTestedPage($pageAlias);

        // Navigate to the page using Mink's path navigation
        $url = $pageAlias . '.html';
        $this->visitPath('/' . $url);
        $this->logDebug("Debug - New page is loading: {$url}\n");

        // Initialize the UI5Browser with the current session and URL
        $this->browser = new UI5Browser($this->getWorkbench(), $this->getSession(), $url, $this->getLocale());
        $this->wireBrowserCallbacks();
    }

    private function wireBrowserCallbacks(): void
    {
        $this->browser->setNavigator(function (string $pageAlias): void {
            $this->navigateToPageAlias($pageAlias);
        });

        // Used to start validation on whatever page is currently loaded
        $this->browser->setVerifyCurrentPage(function (): void {
            $this->verifyCurrentPageWorksAsExpected();
        });
    }

    /**
     * Entry point to validate the currently loaded page.
     *
     * Why:
     * - After UI navigation (e.g., clicking a Tile), we need to start validation from
     *   the "main widget" on the new page.
     * - Nodes should validate their own subtree, but choosing the correct starting node
     *   is orchestration logic and belongs to the context/browser layer.
     */
    private function verifyCurrentPageWorksAsExpected(): void
    {
        $page = $this->getBrowser()->getPageCurrent();
        $alias = $page->getAliasWithNamespace();

        if ($alias && isset($this->validatedAliases[$alias])) {
            return;
        }
        if ($alias) {
            $this->validatedAliases[$alias] = true;
        }
        
        $rootWidget = $page->getWidgetRoot();
        $rootElementId = $this->getBrowser()->getElementIdFromWidget($rootWidget);
        $rootNode = $this->getSession()->getPage()->findById($rootElementId);
        // Decide which widget type is the best "root" for the page validation.
        // $rootNodeElement = $this->findMainWidgetNodeElementForCurrentPage($alias);
        Assert::assertNotNull($rootNode, 'Cannot determine the main widget for the current page.(' . $alias . '.html)');

        $widgetType = $rootWidget->getWidgetType();

        $facadeNode = UI5FacadeNodeFactory::createFromNodeElement(
            $widgetType,
            $rootNode,
            $this->getSession(),
            $this->browser
        );

        $facadeNode->itWorksAsExpected();
    }
}