<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use Behat\Mink\Session;
use Exception;

class UI5WaitManager
{
    private Session $session;
    private array $defaultTimeouts = [
        'page' => 10,
        'busy' => 30,
        'ajax' => 30
    ];

    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Waits for specified UI5 operations
     * 
     * @param bool $waitForPage Wait for page load
     * @param bool $waitForBusy Wait for busy indicator
     * @param bool $waitForAjax Wait for AJAX requests
     * @param array $timeouts Optional custom timeouts
     */
    public function waitForPendingOperations(
        bool $waitForPage = false,
        bool $waitForBusy = false,
        bool $waitForAjax = false,
        array $timeouts = []
    ): void {
        // Merge timeouts with defaults
        $timeouts = array_merge($this->defaultTimeouts, $timeouts);

        try {
            // Page load
            if ($waitForPage) {
                $this->waitForPageLoad($timeouts['page']);
            }

            // Busy indicator
            if ($waitForBusy) {
                $this->waitForBusyIndicator($timeouts['busy']);
            }

            // AJAX requests
            if ($waitForAjax) {
                $this->waitForAjaxRequests($timeouts['ajax']);
            }

            // Validate no errors occurred
            $this->validateNoErrors();
        } catch (Exception $e) {
            throw new Exception("UI5 wait operation failed: " . $e->getMessage());
        }
    }

    /**
     * Waits for initial UI5 application load
     */
    public function waitForAppLoaded(string $pageUrl): void
    {
        try {
            // Initial page load
            $this->waitForPendingOperations(true, false, false);

            // Wait for UI5 framework
            if (!$this->waitForUI5Framework()) {
                throw new Exception("UI5 framework failed to load");
            }

            // Wait for UI5 controls
            if (!$this->waitForUI5Controls()) {
                throw new Exception("UI5 controls failed to load");
            }

            // Wait for app ID
            $appId = substr($pageUrl, 0, strpos($pageUrl, '.html')) . '.app';
            $this->waitForAppId($appId);

            // Wait for busy indicator and AJAX
            $this->waitForPendingOperations(false, true, true);

        } catch (Exception $e) {
            throw new Exception("Failed to load UI5 application DB: " . $e->getMessage());
        }
    }

    private function waitForPageLoad(int $timeout): bool
    {
        return $this->session->wait(
            $timeout * 1000,
            "document.readyState === 'complete'"
        );
    }

    private function waitForBusyIndicator(int $timeout): bool
    {
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

    private function waitForAjaxRequests(int $timeout): bool
    {
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

    private function waitForUI5Framework(): bool
    {
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

    private function waitForAppId(string $appId): void
    {
        $page = $this->session->getPage();
        $page->waitFor($this->defaultTimeouts['ajax'] * 1000, function () use ($page, $appId) {
            $app = $page->findById($appId);
            return $app && $app->isVisible();
        });
    }

    private function validateNoErrors(): void
    {
        $errors = $this->session->evaluateScript(
            <<<JS
            (function() {
                var errors = [];
                
                    // Network errors - now includes redirects (300+)
            if (window.exfXHRLog && window.exfXHRLog.requests) {
                errors = errors.concat(window.exfXHRLog.requests
                    .filter(req => req.status >= 300)  // Changed from 400 to 300
                    .map(req => ({
                        type: 'Network',
                        status: req.status,
                        url: req.url,
                        response: req.response
                    }))
                );
            }
             
             // UI5 errors
            if (typeof sap !== 'undefined' && sap.ui && sap.ui.getCore()) {
                    var mm = sap.ui.getCore().getMessageManager();
                    if (mm && mm.getMessageModel) {
                        errors = errors.concat(mm.getMessageModel().getData()
                            .filter(msg => msg.type === 'Error' || msg.type === 'Fatal')
                            .map(msg => ({
                                type: 'UI5',
                                message: msg.message
                        }))
                    );
                }
            }    
               
                
                return errors;
            })()
            JS
        );

        if (!empty($errors)) {
            throw new Exception("UI5 errors detected: " . json_encode($errors, JSON_PRETTY_PRINT));
        }
    }
}