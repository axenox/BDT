<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use Behat\Mink\Session;
use Exception;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;


/**
 * UI5WaitManager - Manages waiting operations for UI5 framework
 * 
 * This class provides methods to handle various waiting scenarios in UI5 applications,
 * such as waiting for page loads, busy indicators, AJAX requests, and framework initialization.
 * It also validates if any errors occurred during these operations.
 */
class UI5WaitManager
{
    /**
     * Mink session instance
     */
    private Session $session;

    /**
     * Gets the current Mink session
     * 
     * @return Session The Mink session
     */
    public function getSession(): Session
    {
        return $this->session;
    }

    /**
     * Default timeout values (in seconds) for different wait operations
     */
    private array $defaultTimeouts = [
        'page' => 30,  // Page load timeout
        'busy' => 60,  // Busy indicator timeout
        'ajax' => 60   // AJAX requests timeout
    ];

    /**
     * Constructor - initializes the manager with  session
     * 
     * @param Session $session  session instance
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Waits for specified UI5 operations
     * 
     * This method is the main entry point for waiting for various UI5 operations.
     * It can wait for page loads, busy indicators, and AJAX requests based on the parameters provided.
     * 
     * @param bool $waitForPage Wait for page load
     * @param bool $waitForBusy Wait for busy indicator
     * @param bool $waitForAjax Wait for AJAX requests
     * @param array $timeouts Optional custom timeouts
     * @throws Exception If any wait operation fails
     */
    public function waitForPendingOperations(
        bool $waitForPage = false,
        bool $waitForBusy = false,
        bool $waitForAjax = false,
        
        array $timeouts = []
    ): void {
        // Merge provided timeouts with defaults
        $timeouts = array_merge($this->defaultTimeouts, $timeouts);

     

        try {
      
            // Wait for page load if requested
            if ($waitForPage) {
                $this->waitForPageLoad($timeouts['page']);
            }

            // Wait for busy indicator to disappear if requested
            if ($waitForBusy) {
                $this->waitForBusyIndicator($timeouts['busy']);
            }

            // Wait for AJAX requests to complete if requested
            if ($waitForAjax) {
                $this->waitForAjaxRequests($timeouts['ajax']);
            }

            // Wait for page to load
            $this->waitForUI5Controls();
 

            // Check if any errors occurred during the wait operations
            $this->validateNoErrors();
           
        } catch (Exception $e) {
            throw new Exception("UI5 wait operation failed: " . $e->getMessage());
        }
    }

    /**
     * Waits for an element to have a specific CSS class
     *
     * @param NodeElement $element The element to check
     * @param string $className The class name to wait for
     * @param int $timeout Maximum time to wait in seconds
     * @return bool True if element has the class within timeout, false otherwise
     */
    public function waitForElementToHaveClass($element, string $className, int $timeout = 5): bool
    {
        $elementId = $element->getAttribute('id');

        if (empty($elementId)) {
            // If element has no ID, we'll use XPath to identify it
            $xpath = $element->getXpath();
            return $this->getSession()->wait(
                $timeout * 1000,
                "document.evaluate(\"$xpath\", document, null, XPathResult.FIRST_ORDERED_NODE_TYPE, null).singleNodeValue.classList.contains(\"$className\")"
            );
        }

        // If element has ID, we can use it directly
        return $this->getSession()->wait(
            $timeout * 1000,
            "document.getElementById(\"$elementId\").classList.contains(\"$className\")"
        );
    }

    /**
     * Waits for initial UI5 application load
     * 
     * This method performs a complete initialization wait sequence:
     * 1. Waits for the initial page to load
     * 2. Waits for the UI5 framework to initialize
     * 3. Waits for UI5 controls to be rendered 
     * 4. Waits for any busy indicators and AJAX requests to complete
     * 
     * @param string $pageUrl The URL of the page being loaded
     * @throws Exception If any part of the application loading fails
     */
    public function waitForAppLoaded(string $pageUrl): void
    {
        try {  
            // Wait for initial page load
            $this->waitForPendingOperations(true, false, false);
           
            // Wait for UI5 framework to initialize
            if (!$this->waitForUI5Framework()) {
                throw new Exception("UI5 framework failed to load");
            }

            // Wait for UI5 controls to be rendered
            if (!$this->waitForUI5Controls()) {
                throw new Exception("UI5 controls failed to load");
            }

            // Extract application ID from URL and wait for it to be available
            $appId = substr($pageUrl, 0, strpos($pageUrl, '.html')) . '.app';
            $this->waitForAppId($appId);

            
            // Wait for busy indicators and AJAX requests to complete
            $this->waitForPendingOperations(false, true, true);
          
        } catch (Exception $e) {
            throw new Exception("Failed to load UI5 application DB: " . $e->getMessage());
        }
    }

    /**
     * Waits for the page to be fully loaded
     * 
     * @param int $timeout Maximum time to wait in seconds
     * @return bool True if page loaded successfully, false otherwise
     */
    private function waitForPageLoad(int $timeout): bool
    {
        // Wait until document.readyState becomes 'complete'
        return $this->session->wait(
            $timeout * 1000,
            "document.readyState === 'complete'"
        );
    }

    /**
     * Waits for the UI5 busy indicator to disappear
     * 
     * This method checks multiple conditions to determine if the application is still busy:
     * 1. Verifies document has finished loading (readyState === 'complete')
     * 2. Checks if jQuery AJAX requests are active ($.active)
     * 3. Verifies exfLauncher exists and is not in busy state
     * 
     * The method returns true only when all conditions indicate the application is no longer busy.
     * 
     * @param int $timeout Maximum time to wait in seconds
     * @return bool True if application is no longer busy, false if timeout occurred
     */
    private function waitForBusyIndicator(int $timeout): bool
    {
        // Execute JavaScript to check if the busy indicator is no longer displayed
        return $this->session->wait(
            $timeout * 1000,
            <<<JS
            (function() {
                if (document.readyState !== "complete") return false;
                if (typeof $ !== 'undefined' && $.active !== 0) return false;
                if (typeof exfLauncher === 'undefined') return false;
                return exfLauncher.isBusy() === false;
            })()
            JS
        );
    }

    /**
     * Waits for all AJAX requests and UI5 busy indicators to complete
     * 
     * This method monitors two separate conditions:
     * 1. jQuery AJAX requests (jQuery.active counter)
     * 2. UI5's built-in BusyIndicator status (via _globalBusyIndicatorCounter)
     * 
     * The method returns true only when both jQuery has no active requests
     * and UI5's busy indicator counter is zero.
     * 
     * @param int $timeout Maximum time to wait in seconds
     * @return bool True if all AJAX requests and busy indicators completed, false if timeout occurred
     */
    private function waitForAjaxRequests(int $timeout): bool
    {
        // Execute JavaScript to check if there are no pending AJAX requests
        return $this->session->wait(
            $timeout * 1000,
            <<<JS
            (function() {
                if (typeof jQuery !== 'undefined' && jQuery.active !== 0) return false;
                if (typeof sap !== 'undefined' && sap.ui && sap.ui.core) {
                    if (sap.ui.core.BusyIndicator._globalBusyIndicatorCounter > 0) return false;
                }
                return true;
            })()
            JS
        );
    }

    /**
     * Waits for the UI5 framework to be initialized
     * 
     * @return bool True if UI5 framework initialized, false otherwise
     */
    private function waitForUI5Framework(): bool
    {
        // Execute JavaScript to check if the UI5 framework and core are available

        return $this->session->wait(
            $this->defaultTimeouts['ajax'] * 1000,
            <<<JS
            (function() {
                if (typeof sap === 'undefined') return false;
                if (typeof sap.ui === 'undefined') return false;
                if (typeof sap.ui.getCore === 'undefined') return false;
                var core = sap.ui.getCore();
                return core && typeof core.getLoadedLibraries === 'function';
            })()
            JS
        );
    }

    /**
     * Waits for UI5 controls to be rendered on the page
     * 
     * @return bool True if UI5 controls are rendered, false otherwise
     */
    private function waitForUI5Controls(): bool
    {
        return $this->session->wait(
            $this->defaultTimeouts['ajax'] * 1000,
            <<<JS
            (function() {
                if (typeof sap === 'undefined' || typeof sap.ui === 'undefined') return false;
                var content = document.body.innerHTML;
                return content.indexOf('sapUiView') !== -1 || content.indexOf('sapMPage') !== -1;
            })()
            JS
        );
    }

    /**
     * Waits for the specific application ID to be available and visible
     * 
     * @param string $appId The application ID to wait for
     */
    private function waitForAppId(string $appId): void
    {
        $page = $this->session->getPage();
        $page->waitFor($this->defaultTimeouts['ajax'] * 1000, function () use ($page, $appId) {
            $app = $page->findById($appId);
            return $app && $app->isVisible();
        });
    }

    /**
     * Validates that no errors occurred during the UI5 operations
     * 
     * Checks for three types of errors:
     * 1. XHR (network) errors
     * 2. UI5 MessageManager errors
     * 3. JavaScript errors
     * 
     * @throws \RuntimeException If any errors are found
     */
    public function validateNoErrors(): void
    {
        // Get the error manager instance
        $errorManager = ErrorManager::getInstance();

        try {
            // Check for XHR (network) errors
            $networkErrors = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.errors) {
                    return window.exfXHRLog.errors;
                }
                return [];
            ');

            // echo "\n=== Network Errors ===\n";
            // var_dump($networkErrors);

            // Add each network error to the error manager
            foreach ($networkErrors as $error) {
                $errorManager->addError($error, 'XHR');
            }

            // Check for UI5 MessageManager errors (Error or Fatal type)
            $ui5Errors = $this->getSession()->evaluateScript('
                if (typeof sap !== "undefined" && sap.ui && sap.ui.getCore()) {
                    var messageManager = sap.ui.getCore().getMessageManager();
                    if (messageManager && messageManager.getMessageModel) {
                        return messageManager.getMessageModel().getData()
                            .filter(function(msg) {
                                return msg.type === "Error" || msg.type === "Fatal";
                            })
                            .map(function(msg) {
                                return {
                                    type: "UI5",
                                    message: msg.message,
                                    details: msg.description || ""
                                };
                            });
                    }
                }
                return [];
            ');

            // Add each UI5 error to the error manager
            foreach ($ui5Errors as $error) {
                $errorManager->addError($error, 'UI5');
            }

            // Check for JavaScript errors
            $jsErrors = $this->getSession()->evaluateScript('
                if (window.exfXHRLog && window.exfXHRLog.errors) {
                    return window.exfXHRLog.errors.filter(function(err) {
                        return err.type === "JSError";
                    });
                }
                return [];
            ');

            // Add each JavaScript error to the error manager
            foreach ($jsErrors as $error) {
                $errorManager->addError($error, 'JavaScript');
            }

            // If any errors were found, throw an exception with the first error message
            if ($errorManager->hasErrors()) {
                $firstError = $errorManager->getFirstError();
                throw new \RuntimeException($errorManager->formatErrorMessage($firstError));
            }

        } catch (\Exception $e) {
            // If an exception occurred during validation, add it as an error
            $errorManager->addError([
                'type' => 'Validation',
                'message' => $e->getMessage()
            ], 'WaitManager');
            throw $e;
        }
    }


}