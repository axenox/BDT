<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Common\Traits\CdpConnectionDetectorTrait;
use axenox\BDT\Exceptions\AjaxException;
use axenox\BDT\Exceptions\ChromeHangException;
use axenox\BDT\Exceptions\FetchApiException;
use axenox\BDT\Exceptions\MessagePageException;
use axenox\BDT\Exceptions\TracerException;
use axenox\BDT\Exceptions\UIException;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;
use Behat\Mink\Session;
use exface\Core\Exceptions\RuntimeException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * UI5ErrorDetector - Detects errors that are present in the browser right now.
 *
 * Why this class exists: error DETECTION (probing the DOM/JS for network
 * failures, UI5 MessageManager entries, full-page error views, error popups,
 * error dialogs and JS tracer entries) is a distinct responsibility from
 * WAITING for the app to settle. Previously all of this probing lived inside
 * UI5WaitManager, which conflated "wait until the page is ready" with "decide
 * whether the page is in an error state". This class extracts the detection
 * side so that:
 *   - UI5WaitManager keeps only settle logic and simply asks the detector to
 *     assertNoErrors() once the app has settled;
 *   - the global ErrorManager stays a process-wide store/formatter/logger that
 *     never needs a live Session (Chrome restarts create new sessions, but the
 *     singleton store must outlive them);
 *   - all session-bound probing lives in exactly one place.
 *
 * The detector is intentionally session-bound: it must run against the live
 * Mink/Chrome session it was constructed with, and a new detector is created
 * whenever the session is (re)created after a Chrome restart.
 */
class UI5ErrorDetector
{
    use CdpConnectionDetectorTrait;

    /**
     * How many times assertNoErrors() retries after a CDP connection timeout
     * before giving up for the current cycle.
     *
     * A connection timeout here usually means Chrome is still busy with a heavy
     * JS render cycle rather than dead, so a bounded retry avoids reporting a
     * false failure while still surfacing a genuinely wedged browser.
     */
    private const VALIDATE_RETRY_MAX = 3;

    /**
     * Milliseconds to wait between assertNoErrors() retry attempts.
     */
    private const VALIDATE_RETRY_DELAY_MS = 600;

    /**
     * Live Mink session this detector probes.
     */
    private Session $session;

    /**
     * Binds the detector to the session it must probe.
     *
     * The session is injected (not created here) because its lifecycle is owned
     * by the browser context: on a Chrome restart a fresh session is built and a
     * fresh detector is constructed alongside it, so the detector never holds a
     * stale session reference.
     */
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    /**
     * Returns the bound Mink session.
     *
     * Kept as an accessor so the probing method bodies below read uniformly via
     * getSession()->evaluateScript(...), matching how they were written when
     * they lived in UI5WaitManager.
     */
    private function getSession(): Session
    {
        return $this->session;
    }

    /**
     * Asserts that no error is currently present in the browser.
     *
     * Why this is the single public entry point: callers (UI5WaitManager) must
     * not know HOW errors are detected, only that after settling they can ask
     * "is the page in an error state?". This runs every detection probe and
     * throws a typed exception describing the first error found.
     *
     * Detection covers:
     *  1. Full-page error views (MessagePage)
     *  2. XHR / fetch network errors and application errors in response bodies
     *  3. Error popups (.exfw-error)
     *  4. Error dialogs (sap.m.Dialog type Error)
     *  5. UI5 MessageManager Error/Fatal messages
     *  6. JS tracer error-level entries
     *
     * If a CDP connection timeout occurs (Chrome still executing a heavy JS
     * cycle), the whole cycle is retried up to VALIDATE_RETRY_MAX times with
     * VALIDATE_RETRY_DELAY_MS between attempts. A timeout that persists past all
     * retries means Chrome is wedged, not merely busy, so it is escalated to a
     * ChromeHangException for the recovery path to restart Chrome rather than
     * silently returning and letting a broken browser corrupt later steps.
     *
     * If Chrome is unreachable (crashed between steps), the low-level socket
     * throwable is detected via isCdpConnectionError() and re-thrown as a
     * ChromeHangException so recovery can restart Chrome instead of failing the
     * whole suite with an opaque socket error.
     *
     * The browser-side error buffers are reset exactly once in the finally
     * block, regardless of which probe threw. This guarantees that an early
     * throwing probe (e.g. checkMessagePageErrors) can no longer leak stale
     * network errors into the next step's detection cycle.
     *
     * @throws ChromeHangException If Chrome is unreachable during detection.
     * @throws \Throwable          The first typed error found, otherwise.
     */
    public function assertNoErrors(): void
    {
        try {
            for ($attempt = 1; $attempt <= self::VALIDATE_RETRY_MAX; $attempt++) {
                try {
                    $this->checkMessagePageErrors();
                    $this->checkNetworkErrors();
                    $this->checkPopupErrors();
                    $this->checkUiErrors();
                    $this->checkMessageManagerErrors();
                    $this->checkTracerErrors();
                    return; // all checks passed cleanly
                } catch (\Throwable $e) {
                    if ($this->isCdpConnectionError($e)) {
                        throw new ChromeHangException(
                            'CDP connection lost during error validation: ' . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                    if ($this->isConnectionTimeoutException($e)) {
                        if ($attempt < self::VALIDATE_RETRY_MAX) {
                            // Chrome is still busy with a heavy JS cycle - wait and retry.
                            ErrorManager::getInstance()->logException(
                                new RuntimeException(
                                    "CDP connection timeout in assertNoErrors "
                                    . "(attempt {$attempt}/" . self::VALIDATE_RETRY_MAX . "), "
                                    . "retrying in " . self::VALIDATE_RETRY_DELAY_MS . " ms",
                                    null,
                                    $e
                                )
                            );
                            usleep(self::VALIDATE_RETRY_DELAY_MS * 1000);
                            continue;
                        }
                        // All retries exhausted. A persistent CDP connection timeout here
                        // means Chrome is wedged, not merely busy - fail loudly so the
                        // recovery path can restart it, instead of silently returning and
                        // letting a broken browser corrupt subsequent steps.
                        ErrorManager::getInstance()->logException($e);
                        throw new ChromeHangException(
                            'CDP connection timeout persisted after '
                            . self::VALIDATE_RETRY_MAX . ' attempts in assertNoErrors: '
                            . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                    // Non-timeout application error: clear tracer state and surface the error.
                    // Tracer is cleared only on the error path (not on a clean pass) so that
                    // the same JS error is not re-detected on the next step.
                    $this->clearJsErrorTracer();
                    throw $e;
                }
            }
        } finally {
            // Reset the browser-side error buffers exactly once per cycle, no
            // matter which probe threw. Guarded internally so a dead browser
            // here can never replace the real exception propagating out.
            $this->resetBrowserErrorBuffers();
        }
    }

    /**
     * Clears the browser-side error buffers accumulated by the HTTP interceptor.
     *
     * Why this exists as its own method: previously the XHR log was cleared at
     * the tail of checkNetworkErrors(), so if an earlier probe threw, the reset
     * never ran and stale network errors carried into the next step. Centralising
     * the reset here (called from assertNoErrors()'s finally) makes buffer
     * clearing independent of which probe threw.
     *
     * Must never throw: it runs inside a finally block, so any failure (e.g. the
     * browser is already gone) is swallowed and logged rather than masking the
     * original exception.
     */
    private function resetBrowserErrorBuffers(): void
    {
        try {
            $this->getSession()->executeScript(
                'if (window.exfXHRLog && window.exfXHRLog) { window.exfXHRLog.errors = [] }'
            );
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }

    /**
     * Returns true if the throwable is a ChromeDriver DevTools connection timeout.
     *
     * Why the global \RuntimeException is matched here: the dmore/chrome-mink
     * driver throws PHP's global RuntimeException (NOT the exface one) with a
     * "Connection timeout" message when a CDP command exceeds its deadline. This
     * check must target that concrete driver exception, so the leading backslash
     * is intentional and unrelated to the project convention for THROWING
     * exface\Core\Exceptions\RuntimeException.
     */
    private function isConnectionTimeoutException(\Throwable $e): bool
    {
        return $e instanceof \RuntimeException
            && str_contains($e->getMessage(), 'Connection timeout');
    }

    /**
     * Installs the XHR/fetch interceptor that records non-2xx responses and
     * application errors into a browser-side buffer (window.exfXHRLog.errors).
     *
     * Why detection needs this: the main document navigation aside, all UI5 data
     * traffic goes through XHR/fetch. Without wrapping those APIs there is no
     * client-side signal for a failed backend call, so checkNetworkErrors() would
     * have nothing to read. The interceptor is idempotent (guarded by a window
     * flag) because it must be reinstalled after every navigation - the wrapper
     * belongs to the previous page's JS context and is lost on navigation.
     */
    public function installHttpInterceptor(): void
    {
        $this->getSession()->evaluateScript(<<<'JS'
(function () {
  // Guard: install only once per page context to prevent chained wrappers
  // accumulating across multiple step calls on the same page.
  if (window.__exfHttpInterceptorInstalled) return;
  window.__exfHttpInterceptorInstalled = true;

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

  window.exfXHRLog.clear = function () { window.exfXHRLog.errors = []; };

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
        var st = xhr.status;
        var url = xhr.__exfUrl || '';
        var body = '';
        try { body = (xhr.responseText || '').toString(); } catch (e) { body = ''; }

        // Non-2xx → network error (includes 403, 404, 500 etc.)
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
            request: { body: requestBody.substring(0, 2000) }
          });
          return;
        }
        // Only application responses are body-scanned. See isStaticAsset() for why.
        if (isStaticAsset(url, xhr)) {
          return;
        }

        var looksBad =
          /Fatal error|Compile Error|Undefined constant|Whoops, looks like something went wrong|Stack trace|Symfony\\Component\\ErrorHandler|Internal error|Internal Server Error/i.test(body);
        if (url.indexOf('/api/pwa/errors') !== -1 && /error|fehler|internal/i.test(body)) {
          looksBad = true;
        }
        if (looksBad) {
          var extracted = '';
          var m = body.match(/(Fatal error[^<\n\r]*|Compile Error[^<\n\r]*|Undefined constant[^<\n\r]*|Whoops, looks like something went wrong[^<\n\r]*|Internal Server Error[^<\n\r]*)/i);
          if (m && m[1]) extracted = m[1].trim();

          var looksLikeUi5ErrorController =
            /sap\.ui\.(jsview|define)\s*\(/i.test(body) && /controller\.Error/i.test(body);

          if (!extracted && looksLikeUi5ErrorController) {
            // Read the visible UI5 error page text synchronously — no setTimeout
            function pickText(sel) {
              var el = document.querySelector(sel);
              if (!el) return '';
              return ((el.innerText || el.textContent || '') + '').trim();
            }
            var txt =
              pickText('.sapMMessagePageMainText') ||
              pickText('#__page1-title-inner') ||
              pickText('[id$="-title-inner"]') ||
              pickText('.sapMTitle');
            extracted = (txt && /fehler/i.test(txt)) ? txt : 'UI5 error page shown (Fehler text not found)';
          }

          if (!extracted) extracted = 'Application error detected (see response)';

          pushError({
            type: 'AppError',
            source: (url.indexOf('/api/pwa/errors') !== -1) ? 'errorsEndpoint' : 'XHRBody',
            status: st || 200,
            statusText: xhr.statusText || '',
            method: xhr.__exfMethod || '',
            url: url,
            message: extracted,
            // Cap the stored body: the full response is written into run_step.error_message
            // and into the daily error report. A truncated head is enough to identify the
            // cause, while an untruncated bundle response can be megabytes long and makes
            // the report unreadable (and the DB write needlessly large).
            response: body.substring(0, 2000),
          });
          // Decides whether a response is a static asset whose body must never be scanned
          // for server-side error markers.
          //
          // WHY this exists: the body scan below matches generic PHP/HTTP error wording.
          // Minified JS bundles ship their own error-handling strings ("Stack trace",
          // "Internal error", "Fatal error"), so scanning them turns every such asset into a
          // false "Application error detected" - this is exactly how the mermaid bundle was
          // being reported. A static asset can never carry a server error page, so its body
          // is worthless for detection and scanning it can only produce false positives.
          //
          // Content-Type is the primary signal because it is what the server actually
          // declares; the URL extension is only a fallback for responses served with a
          // missing or generic header (e.g. application/octet-stream).
          function isStaticAsset(url, xhr) {
            var ct = '';
            try { ct = (xhr.getResponseHeader('content-type') || '').toLowerCase(); } catch (e) { ct = ''; }
            if (ct) {
              if (/javascript|ecmascript|text\/css|font|image\/|application\/wasm/.test(ct)) return true;
              // A server error page is always HTML, JSON or plain text. If the server declared
              // one of those, this IS a candidate for scanning - do not fall through to the
              // extension heuristic, which could misjudge an extensionless API route.
              if (/json|html|xml|text\/plain/.test(ct)) return false;
            }
            var path = (url.split('?')[0] || '').toLowerCase();
            return /\.(js|mjs|css|map|woff2?|ttf|eot|svg|png|jpe?g|gif|ico|wasm)$/.test(path);
          }
        }
      } catch (e) {}
    }

    xhr.addEventListener('loadend', done);
    return origSend.apply(this, arguments);
  };

  // -------- fetch --------
  // IMPORTANT: fetch body reading via .text() is async (Promise-based).
  // We must NOT push the error inside .then() because PHP's evaluateScript
  // reads window.exfXHRLog.errors synchronously — the callback fires after
  // the PHP read, so the error is invisible. Instead, push synchronously
  // without reading the body (status code is sufficient for detection).
  if (window.fetch) {
    var origFetch = window.fetch;
    window.fetch = function(input, init) {
      var method = (init && init.method) ? init.method : 'GET';
      var url = (typeof input === 'string') ? input : (input && input.url ? input.url : '');

      return origFetch.apply(this, arguments).then(function(res) {
        try {
          if (res && res.ok === false) {
            // Push error synchronously — do NOT use res.clone().text().then(...)
            // because that Promise resolves after PHP reads the error list.
            pushError({
              type: 'NetworkError',
              source: 'fetch',
              status: res.status,
              statusText: res.statusText || '',
              method: method,
              url: url,
              message: (res.status + ' ' + (res.statusText || '')).trim(),
              response: ''  // body intentionally omitted to stay synchronous
            });
          }
        } catch (e) {}
        return res;
      });
    };
  }
})();
JS
        );
    }

    /**
     * Enables the exfLauncher JS error tracer.
     *
     * Why guarded: enabling the tracer must never abort a step. If exfLauncher is
     * not yet present (e.g. on an unexpected page) the failure is logged and
     * detection continues rather than crashing the caller.
     */
    public function enableJsErrorTracer(): void
    {
        try {
            $this->getSession()->evaluateScript('window.exfLauncher.enableJsTracing();');
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }

    /**
     * Resets the exfLauncher JS error log.
     *
     * Why guarded and why only on the error path: clearing the tracer prevents a
     * JS error from being re-detected on the next step. It is called after an
     * error is surfaced (not on a clean pass), and any failure to clear is logged
     * rather than thrown so it cannot mask the real error propagating out.
     */
    private function clearJsErrorTracer(): void
    {
        try {
            $this->getSession()->evaluateScript('window.exfLauncher.resetJsErrorLogs();');
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
        }
    }

    /**
     * Reads error-level entries from the exfLauncher JS tracer.
     *
     * Why filtered to error level: the tracer records all log levels, but only
     * error-level entries should fail a step. Returns an empty array on failure
     * so a tracer read problem never aborts detection.
     */
    private function getJsErrorsFromTracer(): array
    {
        try {
            return $this->getSession()->evaluateScript("
                return window.exfLauncher
                    .getJsErrorLogs()
                    .filter(e => e.level === 'error');
            ");
        } catch (\Throwable $e) {
            ErrorManager::getInstance()->logException($e);
            return [];
        }
    }

    /**
     * Throws on the first error-level JS tracer entry.
     *
     * Why this exists: uncaught JS errors do not surface through XHR/fetch or the
     * UI5 message model, so the tracer is the only signal for them.
     */
    private function checkTracerErrors()
    {
        // Check for JavaScript errors
        $jsErrors = $this->getJsErrorsFromTracer();

        // Surface the first JavaScript error as a typed exception
        foreach ($jsErrors as $error) {
            throw new TracerException(
                $error['message'] ?? null,
                null,
                null,
                ['Source' => 'UI5ErrorDetector', 'Type' => 'Tracer', 'Details' => $error]
            );
        }
    }

    /**
     * Throws on the first network / application error captured by the interceptor.
     *
     * Why the buffer is no longer cleared here: the reset moved to
     * resetBrowserErrorBuffers() (called from assertNoErrors()'s finally) so that
     * clearing happens even when an earlier probe throws first.
     */
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
                    'JSError' => 'JavaScript',
                    'AppError' => 'App'
                ];
                $exception = new UIException(
                    $error['message'],
                    null,
                    null,
                    ['Source' => 'UI5ErrorDetector', 'Type' => $map[$type], 'Details' => $error]
                );
            }
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Throws on the first visible error popup (.exfw-error).
     *
     * Why visibility is checked: hidden/detached .exfw-error nodes can linger in
     * the DOM after a prior error was dismissed, so only currently visible popups
     * represent a live error state.
     */
    private function checkPopupErrors()
    {
        // 4) Popup (.exfw-error) - primary source
        $popupErrors = $this->getSession()->evaluateScript(<<<'JS'
(function () {
    function isVisible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    var nodes = Array.prototype.slice.call(document.querySelectorAll('.exfw-error'));
    var visible = nodes.filter(isVisible);

    return visible.map(function (el) {
        var text = (el.innerText || el.textContent || '').trim();
        if (!text) text = (el.getAttribute('aria-label') || '').trim();

        return {
            type: 'Popup',
            message: text || 'Error popup detected (.exfw-error) but no text found',
            details: (el.getAttribute('data-exf-error-details') || '').trim(),
            id: el.id || ''
        };
    });
})();
JS
        );

        foreach ($popupErrors as $error) {
            throw new UIException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5ErrorDetector', 'Type' => 'Popup Error', 'ID' => $error['id']]
            );
        }
    }

    /**
     * Throws when a SAP UI5 error dialog (sap.m.Dialog type Error) is visible.
     *
     * Why the Log-ID is appended: the info strip (sapMMsgStrip) carries the
     * server-side Log-ID; including it in the message gives the report enough
     * context to locate the corresponding server error log entry.
     *
     * @throws UIException If an error dialog is detected.
     */
    private function checkUiErrors(): void
    {
        $uiError = $this->getSession()->evaluateScript(<<<'JS'
(function () {
    var d = document.querySelector('.sapMDialogError');
    if (!d) return null;

    // Read the primary error message from the dialog body text nodes.
    var selectors = [
        '.sapMDialogScrollCont .sapMText',
        '.sapMText',
        '.sapMDialogSection .sapMText'
    ];
    var message = '';
    for (var i = 0; i < selectors.length; i++) {
        var el = d.querySelector(selectors[i]);
        if (el) {
            var t = (el.innerText || el.textContent || '').trim();
            if (t) { message = t; break; }
        }
    }

    // Append the Log-ID from the info strip when present so the report
    // contains enough context to locate the server-side error log entry.
    var strip = d.querySelector('.sapMMsgStripMessage .sapMText');
    if (strip) {
        var logId = (strip.innerText || strip.textContent || '').trim();
        if (logId) message = message ? message + ' — ' + logId : logId;
    }

    return message || 'UI error dialog detected (no message text found)';
})();
JS
        );

        if ($uiError) {
            throw new UIException(
                $uiError,
                null,
                null,
                ['Source' => 'UI5ErrorDetector', 'Type' => 'UI Dialog Error']
            );
        }
    }

    /**
     * Throws on the first UI5 MessageManager Error/Fatal message.
     *
     * Why this exists: UI5 reports many backend/validation problems through the
     * MessageManager model rather than a dialog or popup, so this is the only
     * signal for that class of error.
     */
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

        // Surface each UI5 message as a typed exception
        foreach ($ui5Errors as $error) {
            throw new UIException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5ErrorDetector', 'Type' => 'Message Manager', 'Details' => $error['details']]
            );
        }
    }

    /**
     * Throws on the first visible full-page UI5 error view (MessagePage).
     *
     * Why an error icon is required: a MessagePage is also used for empty/info
     * states, so only pages carrying an error icon (aria-label error/Fehler) are
     * treated as an error state.
     */
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
JS
        );

        foreach ($messagePageErrors as $error) {
            throw new MessagePageException(
                $error['message'],
                null,
                null,
                ['Source' => 'UI5ErrorDetector', 'Type' => $error['type'], 'Details' => $error['details']]
            );
        }
    }

    /**
     * Detects whether the currently loaded document is a server-side error page
     * instead of a real UI5 application page.
     *
     * Why this exists: top-level navigation responses (e.g. HTTP 403 Forbidden
     * from the authorization middleware, HTTP 404 Not Found, HTTP 500 Internal
     * Server Error, or HTTP 400 from FacadeResolverMiddleware when a page alias
     * does not exist) bypass the XHR/fetch interceptor entirely — that
     * interceptor only wraps XMLHttpRequest and fetch, NOT the main document
     * navigation. The only client-side symptom of such a failed navigation is
     * that the UI5 framework never appears. Without this check, the framework
     * wait burns its full timeout and throws a generic "UI5 framework failed to
     * load", hiding the real cause (e.g. "403 Forbidden"), which is then only
     * visible in the server log.
     *
     * Detection strategy (in order of reliability):
     * 1. Read the REAL HTTP status code of the top-level navigation via the
     *    Navigation Timing API (PerformanceNavigationTiming.responseStatus,
     *    available in Chrome 109+). Any status >= 400 is a server error page.
     *    This is independent of the page body text and therefore catches
     *    403/404/500 reliably.
     * 2. As a fallback (older browsers without responseStatus), match a list of
     *    known error markers in the document body text — now including generic
     *    HTTP error wording like "403", "Forbidden", "404", "Not Found", etc.
     *
     * @return string|null The extracted error text if the page is an error page, null otherwise
     */
    public function detectServerErrorPage(): ?string
    {
        return $this->getSession()->evaluateScript(<<<'JS'
(function () {
    // A real UI5 app page always carries the UI5 bootstrap script.
    // If it is present, this is not a plain server error page.
    if (document.querySelector('script[src*="sap-ui-core"]') !== null) return null;
    if (typeof sap !== 'undefined') return null;

    var body = (document.body && (document.body.innerText || document.body.textContent) || '').trim();

    // 1) Primary: the real HTTP status of the top-level navigation.
    // PerformanceNavigationTiming.responseStatus is set to the actual status
    // code of the document response (Chrome 109+). Anything >= 400 means the
    // navigation itself failed (403/404/500/...) — fail fast with the code.
    try {
        var nav = (performance.getEntriesByType
            ? performance.getEntriesByType('navigation')
            : []) || [];
        var status = (nav[0] && typeof nav[0].responseStatus === 'number')
            ? nav[0].responseStatus
            : 0;
        if (status >= 400) {
            var snippet = body ? body.split('\n').slice(0, 5).join(' | ').substring(0, 500) : '';
            return ('HTTP ' + status + ' on page navigation' + (snippet ? ': ' + snippet : '')).substring(0, 500);
        }
    } catch (e) {}

    if (!body) return null;

    // 2) Fallback: known server-side error markers rendered as plain HTML pages
    var markers = [
        /No route can be found for URL/i,
        /please check system configuration option FACADES\.ROUTES/i,
        /UI Page with alias .* not found/i,
        /HttpBadRequestError/i,
        /Fatal error/i,
        /Internal Server Error/i,
        /\b403\b|Forbidden|Access denied|Zugriff verweigert|was denied|don't have authorization|HTTP ERROR/i,
        /\b404\b|Not Found|Nicht gefunden/i,
        /\b500\b|Server Error|Serverfehler/i
    ];
    for (var i = 0; i < markers.length; i++) {
        if (markers[i].test(body)) {
            // Return only the first lines — enough to identify the cause
            return body.split('\n').slice(0, 5).join(' | ').substring(0, 500);
        }
    }
    return null;
})();
JS
        );
    }
}