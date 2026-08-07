<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Exceptions\BrowserTimeoutException;
use axenox\BDT\Exceptions\ChromeHangException;
use axenox\BDT\Exceptions\FacadeBrowserException;
use Behat\Mink\Session;
use Exception;
use exface\Core\Exceptions\InvalidArgumentException;
use exface\Core\Exceptions\RuntimeException;
use axenox\BDT\Behat\Common\Traits\CdpConnectionDetectorTrait;


/**
 * UI5WaitManager - Manages waiting operations for UI5 framework
 *
 * This class provides methods to handle various waiting scenarios in UI5 applications,
 * such as waiting for page loads, busy indicators, AJAX requests, and framework initialization.
 * It also validates if any errors occurred during these operations.
 */
class UI5WaitManager
{
    use CdpConnectionDetectorTrait;

    /**
     * Maximum total seconds waitForPendingOperations() may run across all sub-waits.
     *
     * Must be set BELOW the Mink/Chrome session timeout so that a graceful
     * FacadeBrowserException is thrown before the session is silently killed.
     * Adjust this value to match your environment's session timeout minus a
     * safety margin (e.g. session_timeout - 20 s).
     *
     * It must also stay ABOVE the driver's socket_timeout: a single stalled CDP read
     * blocks for up to socket_timeout, so if socket_timeout exceeded this budget the
     * budget could never trip before one read already blew it.
     */
    private const SESSION_BUDGET_SECONDS = 95;
    
    /**
     * Mink session instance
     */
    private Session $session;
    private UI5ErrorDetector $errorDetector;

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
        'busy' => 30,  // Busy indicator timeout
        'ajax' => 30   // AJAX requests timeout
    ];

    /**
     * Constructor - initializes the manager with  session
     *
     * @param Session $session session instance
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
        $this->errorDetector = new UI5ErrorDetector($session);
    }

    /**
     * Waits for specified UI5 operations
     *
     * Tracks wall-clock time across all sub-waits (page, busy, AJAX) and caps
     * each sub-wait's timeout to the remaining SESSION_BUDGET_SECONDS budget.
     * If the budget is already exhausted before a sub-wait starts, a
     * FacadeBrowserException is thrown immediately — before the Mink/Chrome
     * session timeout can silently kill the test.
     *
     * Individual wait timeouts from $timeouts[] are still respected as upper
     * bounds, but they can never exceed the remaining session budget.
     *
     * @param bool $waitForPage Wait for page load
     * @param bool $waitForBusy Wait for busy indicator
     * @param bool $waitForAjax Wait for AJAX requests
     * @param int|int[] $timeouts Optional custom timeout or array of timeouts for every waiting flag
     * @throws FacadeBrowserException If any wait times out or the session budget is exceeded
     */
    public function waitForPendingOperations(
        bool $waitForPage = false,
        bool $waitForBusy = false,
        bool $waitForAjax = false,
             $timeouts = null
    ): void
    {
        $startTime = microtime(true);

        switch (true) {
            case is_array($timeouts):
                $timeouts = array_merge($this->defaultTimeouts, $timeouts);
                break;
            case is_int($timeouts):
                $timeouts = [];
                foreach ($this->defaultTimeouts as $i => $t) {
                    $timeouts[$i] = $t;
                }
                break;
            case $timeouts === null;
                $timeouts = $this->defaultTimeouts;
                break;
            default:
                throw new InvalidArgumentException('Invalid step timeout value "' . $timeouts . '"');
        }
        // Merge provided timeouts with defaults

        // Wait for page load if requested
        if ($waitForPage) {
            $allowed = $this->remainingBudget($startTime);
            if (!$this->waitForPageLoad(min($timeouts['page'], $allowed))) {
                throw new BrowserTimeoutException(
                    "The page was not loaded within the expected time of {$timeouts['page']} seconds.",
                    ["URL" => $this->getSession()->getCurrentUrl()]
                );
            }
        }

        // Wait for busy indicator to disappear if requested
        if ($waitForBusy) {
            $allowed = $this->remainingBudget($startTime);
            if (!$this->waitForBusyIndicator(min($timeouts['busy'], $allowed))) {
                throw new BrowserTimeoutException(
                    "The busy indicator did not disappear within the expected time of {$timeouts['busy']} seconds.",
                    ["URL" => $this->getSession()->getCurrentUrl()]
                );
            }
        }

        // Wait for AJAX requests to complete if requested
        if ($waitForAjax) {
            $allowed = $this->remainingBudget($startTime);
            if (!$this->waitForAjaxRequests(min($timeouts['ajax'], $allowed))) {
                throw new BrowserTimeoutException(
                    "The AJAX requests was not completed within the expected time of {$timeouts['ajax']} seconds.",
                    ["URL" => $this->getSession()->getCurrentUrl()]
                );
            }
        }

        // Wait for page to load
        $this->waitForUI5Controls();

        // Give the browser a short window to finish any post-render JS before
        // querying for errors via CDP. validateNoErrors() has its own retry logic
        // for connection timeouts, but a small settle delay reduces retry frequency.
        usleep(200000); // 200 ms

        // Check if any errors occurred during the wait operations
        $this->errorDetector->assertNoErrors();
    }

    /**
     * Returns how many seconds of the session budget are still available.
     *
     * WHY it throws instead of returning 0: once the budget is gone there is no valid
     * timeout left to hand to the next sub-wait, and calling one with a zero or negative
     * timeout would either return instantly (a false negative) or block until the
     * Mink/Chrome session is killed - which produces an opaque socket error instead of an
     * attributable timeout.
     */
    private function remainingBudget(float $startTime): int
    {
        $elapsed = microtime(true) - $startTime;
        $remaining = self::SESSION_BUDGET_SECONDS - (int)$elapsed;
        if ($remaining <= 0) {
            throw new BrowserTimeoutException(
                "waitForPendingOperations exceeded the session budget of "
                . self::SESSION_BUDGET_SECONDS . " s (elapsed: " . round($elapsed) . " s).",
                ["URL" => $this->getSession()->getCurrentUrl()]
            );
        }
        return $remaining;
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
            // Wait ONLY for the raw document load (document.readyState ===
            // 'complete'), NOT for UI5 controls yet. waitForPendingOperations()
            // would internally call waitForUI5Controls() and validateNoErrors(),
            // both of which block for the full 30 s timeout on a server error
            // page (403/404/500) because sapUiView/sapMPage never render there.
            // That timeout is exactly the "hang" we want to avoid — the error
            // page must be detected BEFORE any UI5-specific wait.
            if (!$this->waitForPageLoad($this->defaultTimeouts['page'])) {
                throw new FacadeBrowserException(
                    "The page was not loaded within the expected time of {$this->defaultTimeouts['page']} seconds.",
                    ["URL" => $this->getSession()->getCurrentUrl()]
                );
            }

            // Install the HTTP interceptor immediately after DOM is ready,
            // BEFORE UI5 fires its initial AJAX requests (e.g. DataTable data load).
            // The interceptor installed in prepareBeforeStep() belongs to the previous
            // page's JS context and is lost on navigation — we must reinstall here.
            $this->errorDetector->installHttpInterceptor();
            // Fail fast on server error pages: if the navigation landed on a
            // server error page (e.g. HTTP 403 "Access to localhost was denied",
            // 404, 500 or "No route can be found"), there is no point waiting
            // 30 s for a UI5 framework that will never appear. Throwing here puts
            // the REAL server error into the step record.
            $errorText = $this->errorDetector->detectServerErrorPage();
            if ($errorText !== null) {
                throw new RuntimeException(
                    'Server returned an error page instead of the UI5 application: ' . $errorText
                );
            }
            
            // Wait for UI5 framework to initialize
            if (!$this->waitForUI5Framework()) {
                throw new Exception("UI5 framework failed to load");
            }
            $this->errorDetector->enableJsErrorTracer();
            // Extract application ID from URL and wait for it to be available
            $appId = substr($pageUrl, 0, strpos($pageUrl, '.html')) . '.app';
            $this->waitForAppId($appId);

            // Wait for busy indicators and AJAX requests to complete
            $this->waitForPendingOperations(false, true, true);

        } catch (\Throwable $e) {
            throw new Exception("Failed to load UI5 application DB: " . $e->getMessage(), 0, $e);
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
        return $this->waitWithCdpGuard(
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
        return $this->waitWithCdpGuard(
            $timeout * 1000,
            <<<JS
            (function() {
                if (document.readyState !== "complete") return false;
                if (typeof $ === 'undefined') return false;
                if ($.active !== 0) return false;
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
        return $this->waitWithCdpGuard(
            $timeout * 1000,
            <<<JS
            (function() {
                if (typeof jQuery !== 'undefined' && jQuery.active !== 0) return false;
                if (typeof sap !== 'undefined' && sap.ui && sap.ui.core && sap.ui.core.BusyIndicator) {
                var counter = sap.ui.core.BusyIndicator._globalBusyIndicatorCounter;
                if (typeof counter !== "undefined" && counter > 0) {
                    return false;
                }
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
        return $this->waitWithCdpGuard(
            $this->defaultTimeouts['ajax'] * 1000,
            <<<JS
            (function() {
                if (typeof sap === 'undefined') return false;
                if (!sap.ui) return false;

                var core = sap.ui.getCore();
                if (!core || !core.isInitialized()) return false;

                // UI5 rendering queue
                if (core.getUIDirty && core.getUIDirty()) {
                    return false;
                }

                return document.readyState === "complete";

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
        return $this->waitWithCdpGuard(
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
     * The lookup is intentionally fault tolerant: during the initial page load
     * (e.g. right after a login redirect) the UI5 launchpad tears down and
     * re-creates the root NavContainer that carries the "<page_alias>.app" id.
     * If findById() locates the element in one polling cycle and the element is
     * re-rendered before isVisible() re-resolves it, Mink throws an
     * "Tag matching xpath //DIV[@id=..] not found" (stale element) error.
     * That transient race must NOT fail the whole "app loaded" wait, because the
     * app does appear a moment later. Therefore any exception inside the polling
     * callback is swallowed and treated as "not ready yet" so polling continues
     * until the element is stably present or the timeout is reached.
     *
     * @param string $appId The application ID to wait for
     */
    private function waitForAppId(string $appId): void
    {
        $page = $this->session->getPage();
        $page->waitFor($this->defaultTimeouts['ajax'] * 1000, function () use ($page, $appId) {
            try {
                $app = $page->findById($appId);
                return $app && $app->isVisible();
            } catch (\Throwable $e) {
                // Element became stale because UI5 re-rendered the root container
                // between findById() and isVisible(). Keep polling instead of
                // aborting - the app container will settle within the timeout.
                return false;
            }
        });
    }

    /**
     * Executes a Mink session->wait() call and re-throws CDP connection failures
     * as a ChromeHangException.
     *
     * session->wait() blocks indefinitely when Chrome's WebSocket connection is
     * lost, because it keeps sending CDP commands that never receive a response.
     * This wrapper catches the lower-level connection throwables that surface in
     * that scenario and converts them into a ChromeHangException so that callers
     * can react to a hung browser without waiting for the outer process timeout
     * (e.g. Symfony Process's 600-second limit).
     *
     * \Throwable is used instead of \Exception so that PHP \ErrorException
     * instances (produced when stream_socket_client() fires a PHP warning that
     * is converted by an error handler) are also intercepted here.
     *
     * @param int $timeoutMs Maximum time to wait in milliseconds.
     * @param string $js JavaScript condition to evaluate repeatedly.
     * @return bool True if the JS condition became truthy within the timeout.
     * @throws ChromeHangException If the CDP connection is detected as lost.
     * @throws \Throwable          Any other non-connection throwable is re-thrown as-is.
     */
    private function waitWithCdpGuard(int $timeoutMs, string $js): bool
    {
        try {
            return $this->session->wait($timeoutMs, $js);
        } catch (\Throwable $e) {
            if ($this->isCdpConnectionError($e)) {
                throw new ChromeHangException(
                    'CDP connection lost during wait: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            // A socket read timeout here means Chrome did not answer a single
            // Runtime.evaluate poll within socket_timeout. Because ChromeDriver::wait()
            // is iteration-based rather than wall-clock based, such a stalled read would
            // otherwise silently inflate a nominal wait far past its intended ceiling.
            // Treat it as a hang so the recovery path can restart Chrome instead of
            // letting the step hang until the outer process timeout.
            if ($this->isSocketReadTimeout($e)) {
                throw new ChromeHangException(
                    'Chrome did not respond within socket_timeout during wait: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Returns true if the throwable is a CDP socket READ timeout (as opposed to a
     * lost/never-established connection, which isCdpConnectionError() covers).
     *
     * Why this exists: dmore/chrome-mink-driver wraps a websocket read timeout in a
     * StreamReadException once socket_timeout elapses. Its message does not match any
     * keyword in isCdpConnectionError(), so without this check the throwable would be
     * re-thrown raw and the step would fail with an opaque low-level error instead of
     * triggering the Chrome-restart recovery path.
     *
     * Kept SEPARATE from isCdpConnectionError() on purpose: validateNoErrors() must be
     * able to RETRY a transient read timeout, whereas a read timeout during an
     * interactive wait is treated as a hang. Confirm the exact class/message in the
     * pinned driver version and extend the checks below if it differs.
     *
     * @param \Throwable $e The throwable to inspect.
     * @return bool True if the throwable indicates a websocket read timeout.
     */
    private function isSocketReadTimeout(\Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            if (str_contains(get_class($current), 'StreamReadException')) {
                return true;
            }
            $msg = $current->getMessage();
            if (str_contains($msg, 'Timed out')
                || str_contains($msg, 'read from stream')
                || str_contains($msg, 'socket read')
            ) {
                return true;
            }
            $current = $current->getPrevious();
        }
        return false;
    }

    /**
     * Waits till the specified number of DOM elements matching the given CSS selector are available
     *
     * NOTE: this does not mean, they are visible! They are merely available in the DOM. So if you
     * need to have 4 Tiles visible, so something like this:
     *
     * ```
     * $this->waitManager->waitForDOMElements('.exf-tile', 4);
     * $cnt = 0;
     * foreach ($this->findAll(...) as $node) {
     *     if ($node->isVisible()) $cnt++;
     * }
     * ```
     *
     * @param string $cssSelector
     * @param int $number
     * @param int $timeoutInSeconds
     * @return bool
     */
    public function waitForDOMElements(string $cssSelector, int $number = 1, int $timeoutInSeconds = 10): bool
    {
        return $this->waitWithCdpGuard(
            $timeoutInSeconds * 1000,
            "($('{$cssSelector}').length >= {$number})"
        );
    }
}