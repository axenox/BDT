<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Exceptions\AjaxException;
use axenox\BDT\Exceptions\FetchApiException;
use axenox\BDT\Exceptions\MessagePageException;
use axenox\BDT\Exceptions\TracerException;
use axenox\BDT\Exceptions\UIException;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;
use Behat\Mink\Session;
use Exception;
use exface\Core\Exceptions\InvalidArgumentException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use axenox\BDT\Exceptions\ChromeHangException;


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
     * @param int|int[] $timeouts Optional custom timeout or array of timeouts for every waiting flag
     * @throws Exception If any wait operation fails
     */
    public function waitForPendingOperations(
        bool $waitForPage = false,
        bool $waitForBusy = false,
        bool $waitForAjax = false,
        $timeouts = null
    ): void {
        switch (true) {
            case is_array($timeouts):
                $timeouts = array_merge($this->defaultTimeouts, $timeouts);
                break;
            case is_int($timeouts):
                $timeout = $timeouts;
                $timeouts = [];
                foreach($this->defaultTimeouts as $i => $t) {
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

        // Give the browser a moment to finish any post-render JS
        // before querying for errors via CDP (avoids connection timeout)
        usleep(200000); // 200ms
        
        // Check if any errors occurred during the wait operations
        $this->validateNoErrors();
        
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
        try
        {
            // Wait for initial page load
            $this->waitForPendingOperations(true, false, false);
           
            // Wait for UI5 framework to initialize
            if (!$this->waitForUI5Framework()) {
                throw new Exception("UI5 framework failed to load");
            }

            $this->enableJsErrorTracer();
            // Extract application ID from URL and wait for it to be available
            $appId = substr($pageUrl, 0, strpos($pageUrl, '.html')) . '.app';
            $this->waitForAppId($appId);

            // Wait for busy indicators and AJAX requests to complete
            $this->waitForPendingOperations(false, true, true);

        } catch (Exception $e) {
            throw new Exception("Failed to load UI5 application DB: " . $e->getMessage(), null, $e);
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
     * Executes a Mink session->wait() call and re-throws CDP connection failures
     * as a ChromeHangException.
     *
     * session->wait() blocks indefinitely when Chrome's WebSocket connection is
     * lost, because it keeps sending CDP commands that never receive a response.
     * This wrapper catches the lower-level connection exceptions that surface in
     * that scenario and converts them into a ChromeHangException so that callers
     * can react to a hung browser without waiting for the outer process timeout
     * (e.g. Symfony Process's 600-second limit).
     *
     * @param int    $timeoutMs Maximum time to wait in milliseconds.
     * @param string $js        JavaScript condition to evaluate repeatedly.
     * @return bool True if the JS condition became truthy within the timeout.
     * @throws ChromeHangException If the CDP connection is detected as lost.
     */
    private function waitWithCdpGuard(int $timeoutMs, string $js): bool
    {
        try {
            return $this->session->wait($timeoutMs, $js);
        } catch (\Exception $e) {
            if ($this->isCdpConnectionError($e)) {
                throw new ChromeHangException(
                    'CDP connection lost during wait: ' . $e->getMessage(),
                    0,
                    $e
                );
            }
            throw $e;
        }
    }

    /**
     * Determines whether an exception originates from a broken CDP/WebSocket connection.
     *
     * Chrome communicates with the PHP test process over a WebSocket via the Chrome
     * DevTools Protocol. When Chrome hangs or crashes, this connection drops and
     * subsequent CDP calls throw exceptions containing keywords like "WebSocket" or
     * "Connection refused". This method centralises that detection logic so it can
     * be reused by any wait operation without duplicating string-matching code.
     *
     * @param \Exception $e The exception to inspect.
     * @return bool True if the exception indicates a lost CDP connection.
     */
    private function isCdpConnectionError(\Exception $e): bool
    {
        $msg = $e->getMessage();
        return str_contains($msg, 'WebSocket')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'Could not connect')
            || str_contains($msg, 'curl error');
    }

    /**
     * Validates that no errors occurred during the UI5 operations
     *
     * Checks for three types of errors:
     * 1. XHR (network) errors
     * 2. UI5 MessageManager errors
     * 3. JavaScript errors
     * 4. Popup (.exf-error)
     *
     * @throws \RuntimeException|\Throwable If any errors are found
     */
    public function validateNoErrors(): void
    {
        try {
            $this->checkMessagePageErrors();

            $this->checkNetworkErrors();

            $this->checkPopupErrors();

            $this->checkUiErrors();

            $this->checkMessageManagerErrors();

            $this->checkTracerErrors();

        } catch (\Throwable $e) {
            // If the browser connection timed out, the tab was likely still busy
            // executing heavy JS (e.g. SAP UI5 render cycle). Skip error validation
            // for this wait cycle — a dead browser will surface on the next action anyway.
            if ($this->isConnectionTimeoutException($e)) {
                ErrorManager::getInstance()->logException($e);
                return;
            }
            $this->clearJsErrorTracer();
            throw $e;
        }
    }

    /**
     * Returns true if the exception is a ChromeDriver DevTools connection timeout.
     */
    private function isConnectionTimeoutException(\Throwable $e): bool
    {
        return $e instanceof \RuntimeException
            && str_contains($e->getMessage(), 'Connection timeout');
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

    public function installHttpInterceptor(): void
    {
        $this->getSession()->evaluateScript(<<<'JS'
(function () {
  if (window.__exfHttpInterceptorInstalled) return;
  window.__exfHttpInterceptorInstalled = true;

  // existing structure or create
  window.exfXHRLog = window.exfXHRLog || {};
  window.exfXHRLog.errors = window.exfXHRLog.errors || [];
  function pushError(err) {
    try {
      var list = window.exfXHRLog.errors;
      var key = (err.type||'') + '|' + (err.status||'') + '|' + (err.method||'') + '|' + (err.url||'');
      for (var i = Math.max(0, list.length - 20); i < list.length; i++) {
        var e = list[i];
        var k = (e.type||'') + '|' + (e.status||'') + '|' + (e.method||'') + '|' + (e.url||'');
        if (k === key) return;
      }
      list.push(err);
    } catch (e) {}
  }

  // allow clearing between steps if desired
  window.exfXHRLog.clear = function () {
    window.exfXHRLog.errors = [];
  };

  // -------- XHR --------
  var origOpen = XMLHttpRequest.prototype.open;
  var origSend = XMLHttpRequest.prototype.send;

  XMLHttpRequest.prototype.open = function(method, url) {
    this.__exfMethod = method;
    this.__exfUrl = url;
    return origOpen.apply(this, arguments);
  };

  XMLHttpRequest.prototype.send = function(requestBody) {
    var xhr = this;
    function done() {
      try {
        var st = xhr.status; // 0 = aborted/cors/file;
        var url = xhr.__exfUrl || '';
        var body = '';
        try { body = (xhr.responseText || '').toString(); } catch (e) { body = ''; }

        // 1) non-2xx network errors
        if (st && (st < 200 || st >= 300)) {
          pushError({
            type: 'NetworkError',
            source: 'XHR',
            status: st,
            statusText: xhr.statusText || '',
            method: xhr.__exfMethod || '',
            url: url,
            message: (st + ' ' + (xhr.statusText || '')).trim(),
            response: body,
            request: {
                body: requestBody
            }
          });
          return;
        }

        var looksBad =
          /Fatal error|Compile Error|Undefined constant|Whoops, looks like something went wrong|Stack trace|Symfony\\Component\\ErrorHandler|Internal error|Internal Server Error/i.test(body);

        if (url.indexOf('/api/pwa/errors') !== -1 && /error|fehler|internal/i.test(body)) {
          looksBad = true;
        }

        if (looksBad) {
  // extract meaningful message
  var extracted = '';

  // 1) Common backend error patterns in body
  var m =
    body.match(/(Fatal error[^<\n\r]*|Compile Error[^<\n\r]*|Undefined constant[^<\n\r]*|Whoops, looks like something went wrong[^<\n\r]*|Internal Server Error[^<\n\r]*)/i);
  if (m && m[1]) extracted = m[1].trim();

  // 2) If body looks like UI5 error-view/controller JS, read the visible UI message instead
  var looksLikeUi5ErrorController =
  /sap\.ui\.(jsview|define)\s*\(/i.test(body) && /controller\.Error/i.test(body);

if (!extracted && looksLikeUi5ErrorController) {
  setTimeout(function () {
    try {
      function pickText(sel) {
        var el = document.querySelector(sel);
        if (!el) return '';
        return ((el.innerText || el.textContent || '') + '').trim();
      }

      // Prefer MessagePage main text, else header title inner, else any visible title text
      var txt =
        pickText('.sapMMessagePageMainText') ||
        pickText('#__page1-title-inner') ||
        pickText('[id$="-title-inner"]') ||
        pickText('.sapMTitle');

      // Only accept if it contains "Fehler"
      if (!/fehler/i.test(txt)) {
        txt = 'UI5 error page shown (Fehler text not found)';
      }

      pushError({
        type: 'AppError',
        source: (url.indexOf('/api/pwa/errors') !== -1) ? 'errorsEndpoint' : 'XHRBody',
        status: st || 200,
        statusText: xhr.statusText || '',
        method: xhr.__exfMethod || '',
        url: url,
        message: txt,
        respone: body
      });
    } catch (e) {}
  }, 0);

  return;
}

  // 3) Fallback (safe): short, non-code message
  if (!extracted) {
    extracted = 'Application error detected (see response)';
  }

  pushError({
    type: 'AppError',
    source: (url.indexOf('/api/pwa/errors') !== -1) ? 'errorsEndpoint' : 'XHRBody',
    status: st || 200,
    statusText: xhr.statusText || '',
    method: xhr.__exfMethod || '',
    url: url,
    message: extracted,
    response: body
  });
}

      } catch (e) {}
    }

    xhr.addEventListener('loadend', done);
    return origSend.apply(this, arguments);
  };

  // -------- fetch --------
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function(input, init) {
      var method = (init && init.method) ? init.method : 'GET';
      var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');

      return origFetch.apply(this, arguments).then(function(res) {
        try {
          if (res && res.ok === false) {
            // clone so we don't consume the original body
            var c = res.clone();
            c.text().then(function(t){
              pushError({
                type: 'NetworkError',
                source: 'fetch',
                status: res.status,
                statusText: res.statusText || '',
                method: method,
                url: url,
                message: (res.status + ' ' + (res.statusText || '')).trim(),
                response: t
              });
            }).catch(function(){
              pushError({
                type: 'NetworkError',
                source: 'fetch',
                status: res.status,
                statusText: res.statusText || '',
                method: method,
                url: url,
                message: (res.status + ' ' + (res.statusText || '')).trim(),
                response: ''
              });
            });
          }
        } catch (e) {}
        return res;
      });
    };
  }
})();
JS);
    }

    private function enableJsErrorTracer(): void
    {
        try {
            $this->getSession()->evaluateScript('window.exfLauncher.enableJsTracing();');
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }


    private function disableJsErrorTracer(): void
    {
        try {
            $this->session->evaluateScript('window.exfLauncher.disableJsTracing();');
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }


    private function clearJsErrorTracer(): void
    {
        try {
            $this->session->evaluateScript('window.exfLauncher.resetJsErrorLogs();');
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }


    private function getJsErrorsFromTracer(): array
    {
        try {
            return $this->session->evaluateScript("
                return window.exfLauncher
                    .getJsErrorLogs()
                    .filter(e => e.level === 'error');
            ");
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
            return [];
        }
    }

    private function checkTracerErrors()
    {
        // Check for JavaScript errors
        $jsErrors = $this->getJsErrorsFromTracer();

        // Add each JavaScript error to the error manager
        foreach ($jsErrors as $error) {
            throw new TracerException(
                $error['message'] ?? null,
                null,
                null,
                ['Source' => 'UI5WaitManager', 'Type' => 'Tracer', 'Details' => $error]
            );
        }
    }

    private function checkNetworkErrors()
    {
        $errors = $this->getSession()->evaluateScript('
    return (window.exfXHRLog && Array.isArray(window.exfXHRLog.errors)) ? window.exfXHRLog.errors : [];
');
        $exception = null;
        foreach ($errors as $error) {
            $type = $error['type'] ?? 'XHR';

            if ($type === 'NetworkError' || $type === 'Network' || $type === null) {
                $request = new Request(
                    $error['request']['method'] ?? 'GET', // method
                    $error['url'],
                    [],
                    $error['request']['body'] ?? '',
                );
                $response = new Response(
                    $error['status'],
                    [],
                    $error['response']
                );
                if ($error['source'] === 'XHR') {
                    $exception = new AjaxException($request, $response, $error['message']);
                } else {
                    $exception = new FetchApiException($request, $response, $error['message']);
                }
            } else {
                $map = [
                    'NetworkError' => 'HTTP',
                    'JSError'      => 'JavaScript',
                    'AppError'     => 'App'
                ];
                $exception = new UIException(
                    $error['message'],
                    null,
                    null,
                    ['Source' => 'UI5WaitManager', 'Type' => $map[$type], 'Details' => $error]
                );
            }
        }
        // Reset JS errors - otherwise they will cause the next step to fail too
        $this->getSession()->executeScript('if (window.exfXHRLog && window.exfXHRLog) { window.exfXHRLog.errors = [] }');
        if ($exception !== null) {
            throw $exception;
        }
    }

    private function checkPopupErrors()
    {
        // 4) Popup (.exf-error) - primary source
        $popupErrors = $this->getSession()->evaluateScript(<<<'JS'
(function () {
    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    var nodes = Array.prototype.slice.call(document.querySelectorAll('.exf-error'));
    var visible = nodes.filter(isVisible);

    return visible.map(function (el) {
        var text = (el.innerText || el.textContent || '').trim();
        if (!text) text = (el.getAttribute('aria-label') || '').trim();

        return {
            type: 'Popup',
            message: text || 'Error popup detected (.exf-error) but no text found',
            details: (el.getAttribute('data-exf-error-details') || '').trim(),
            id: el.id || ''
        };
    });
})();
JS);

        foreach ($popupErrors as $error) {
            throw new UIException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5WaitManager', 'Type' => 'Popup Error', 'ID' => $error['id']]
            );
        }
    }

    private function checkUiErrors()
    {
        //check ui errors
        $uiError = $this->getSession()->evaluateScript("
                var d = document.querySelector('.sapMDialogError');
                if (!d) return null;
                var selectors = [
                    '.sapMDialogScrollCont .sapMText',
                    '.sapMText',
                    '.sapMDialogSection .sapMText'
                ];
                for (var i = 0; i < selectors.length; i++) {
                    var el = d.querySelector(selectors[i]);
                    if (el) {
                        var t = (el.innerText || el.textContent || '').trim();
                        if (t) return t;
                    }
                }
                return 'UI error dialog (no message text found)';
            ");

        if ($uiError) {
            throw new UIException($uiError,null,null, ["Source" => "UI5WaitManager", "Type" => "UI Dialog Error"]);
        }
    }

    private function checkMessageManagerErrors()
    {
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
            throw new UIException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5WaitManager', 'Type' => 'Message Manager', 'Details' => $error['details']]
            );
        }
    }

    private function checkMessagePageErrors()
    {
        // Check for UI5 MessagePage errors (full-page error views like "Server error: Log ID ...")
        $messagePageErrors = $this->getSession()->evaluateScript(<<<'JS'
(function () {
    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }
    function text(el, sel) {
        var node = el ? el.querySelector(sel) : null;
        return node ? (node.innerText || node.textContent || '').trim() : '';
    }
 
    var pages = Array.prototype.slice.call(document.querySelectorAll('.sapMMessagePage'));
    var results = [];
    pages.forEach(function (page) {
        if (!isVisible(page)) return;
        // Only treat it as an error if an error icon is present
        var icon = page.querySelector('.sapMMessagePageIcon');
        if (!icon) return;
        var label = (icon.getAttribute('aria-label') || '').toLowerCase();
        if (label !== 'error' && label !== 'fehler') return;
 
        // Prefer the page header title, fall back to the MessagePage main text
        var header = page.closest('[data-sap-ui-render]')
            ? page.closest('[data-sap-ui-render]').querySelector('[id$="-title-inner"]')
            : null;
        var title       = header ? (header.innerText || header.textContent || '').trim() : '';
        var mainText    = text(page, '.sapMMessagePageMainText');
        var description = text(page, '.sapMMessagePageDescription');
 
        var message = title || mainText;
        if (description) message += ' — ' + description;
 
        results.push({
            type:    'MessagePage',
            message: message || 'UI5 error page shown',
            details: description
        });
    });
    return results;
})();
JS);

        foreach ($messagePageErrors as $error) {
            throw new MessagePageException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5WaitManager', 'Type' => $error['type'], 'Details' => $error['details']]
            );
        }
    }
}