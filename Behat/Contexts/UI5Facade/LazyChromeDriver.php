<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use DMore\ChromeDriver\ChromeDriver;
use DMore\ChromeDriver\HttpClient;

/**
 * ChromeDriver that connects to Chrome when the Mink session is started, not when it is constructed.
 *
 * WHY THIS EXISTS: since dmore/chrome-mink-driver 2.11 the driver constructor queries Chrome's
 * /json/version and throws if no Chrome is listening. Behat builds every Mink driver while it is still
 * creating its CLI command - before any hook runs - but BDT launches its own Chrome (ChromeManager) in
 * the BeforeScenario hook. With the stock driver every run dies at startup, before Chrome is ever
 * launched.
 *
 * WHY THE PARENT CONSTRUCTOR RUNS IN start(), AND ON EVERY start(): the parent constructor is what
 * resolves the browser-level WebSocket URL and builds the browser connection. That URL carries a
 * per-process browser id, so it changes whenever ChromeManager restarts Chrome on the same port.
 * Re-running the parent constructor on each start() re-resolves it, which is what lets the existing
 * stop()/start() reconnect path attach to a restarted browser - a URL captured once at construction
 * would point at a process that no longer exists. This restores the connect-on-start behaviour BDT was
 * built around (driver 2.10) without forking the library.
 *
 * WHY A SUBCLASS AND NOT A PATCHED COPY: all relevant state is private in the parent and its
 * constructor is the only way to (re)initialise it. Re-invoking it keeps every other driver behaviour
 * exactly as shipped.
 *
 * NOTE: this relies on the parent constructor being the place that resolves the browser connection.
 * Re-check it whenever the driver's minor version is raised.
 */
class LazyChromeDriver extends ChromeDriver
{
    /** Arguments for the deferred parent constructor, kept exactly as the container passed them. */
    private array $deferredConstructorArgs;

    /**
     * Captures the driver configuration without contacting Chrome.
     *
     * WHY parent::__construct() IS NOT CALLED HERE: it would query Chrome immediately, at Behat
     * startup, before ChromeManager has launched it - see the class docblock.
     */
    public function __construct($api_url = 'http://localhost:9222', ?HttpClient $http_client = null, $base_url = null, $options = [])
    {
        $this->deferredConstructorArgs = [$api_url, $http_client, $base_url, $options];
    }

    /**
     * Resolves the CURRENT browser connection, then starts the session.
     *
     * WHY ON EVERY START: Chrome may have been restarted by ChromeManager since the previous start, and
     * only a fresh resolve yields the new browser's WebSocket URL - see the class docblock.
     */
    public function start()
    {
        parent::__construct(...$this->deferredConstructorArgs);
        parent::start();
    }
}