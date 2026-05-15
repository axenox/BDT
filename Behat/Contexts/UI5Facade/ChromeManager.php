<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use GuzzleHttp\Client;

/**
 * Manages the lifecycle of a Chrome instance used for UI testing.
 *
 * Starts a dedicated Chrome process at the beginning of a test exercise and
 * stops it afterwards. Each instance listens on its own remote-debugging port,
 * which allows multiple projects to run their tests in parallel on the same
 * server without interfering with each other.
 *
 * Configuration is read from the chrome section of DatabaseFormatterExtension
 * in behat.yml / BaseConfig.yml:
 *
 *   axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension:
 *     chrome:
 *       executable: 'data\...\GoogleChromePortable.exe'
 *       user_data_dir: 'data\...\ChromeUserData'
 *       port: 9222
 *
 * Each project overrides these values in its own behat.yml so that multiple
 * projects can run their tests simultaneously on the same server without
 * interfering with each other.
 *
 * Note: The remote-debugging port is configured separately in MinkExtension
 * (as part of the api_url) and in this config (as "port"). Both must match.
 * They are kept separate because MinkExtension parameters are not yet available
 * in the Symfony DI container when DatabaseFormatterExtension is loaded.
 *
 * Usage (singleton lifecycle):
 *
 *   // 1. DatabaseFormatter initializes the instance once, injecting the logger:
 *   ChromeManager::getInstance($workbench->getLogger())->start($chromeConfig);
 *
 *   // 2. All other callers retrieve the same instance without arguments:
 *   ChromeManager::getInstance()->stop();
 *   ChromeManager::getInstance()->restart();
 */
class ChromeManager
{
    /** @var static|null Singleton instance; supports subclassing via late static binding */
    private static ?self $instance = null;

    /** @var int|null PID of the Chrome process started by this manager */
    private ?int $pid = null;

    /** @var int|null Port on which Chrome's remote debugging API is listening */
    private ?int $port = null;

    /** @var LoggerInterface|null PowerUI logger; injected on the first getInstance() call */
    private ?LoggerInterface $logger = null;

    /** @var LogBookInterface|null Lazily created logbook for structured diagnostic output */
    private ?LogBookInterface $logbook = null;

    private array $config = [];

    /**
     * Private constructor enforces singleton usage via getInstance().
     *
     * The logger is optional so that getInstance() can be called without arguments
     * after the instance has already been initialized with a logger.
     *
     * @param LoggerInterface|null $logger PowerUI logger to route diagnostic messages through
     */
    private function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Returns the singleton ChromeManager instance, creating it on the first call.
     *
     * The logger parameter is only meaningful on the very first call (typically from
     * DatabaseFormatter). All subsequent callers should omit it; the instance retains
     * the logger that was injected during initialization.
     *
     * Pattern: initialize-once singleton with optional constructor injection.
     *
     * @param LoggerInterface|null $logger Logger to inject; ignored if the instance already exists
     * @return static The singleton instance
     */
    public static function getInstance(?LoggerInterface $logger = null): static
    {
        if (self::$instance === null) {
            self::$instance = new static($logger);
        }
        return self::$instance;
    }

    /**
     * Returns the logbook used for structured diagnostic output, creating it on first access.
     *
     * The logbook collects all ChromeManager messages under a single "Chrome" section,
     * which is then flushed through the PowerUI logger by the caller (DatabaseFormatter).
     */
    public function getLogbook(): LogBookInterface
    {
        if ($this->logbook === null) {
            $this->logbook = new MarkdownLogBook('Chrome');
        }
        return $this->logbook;
    }

    /**
     * Starts a new Chrome process and waits until its debug API is ready.
     *
     * Chrome is launched via Windows "start /B" — identical to running it from
     * a bat file. This ensures Chrome runs in the current interactive user session.
     *
     * If a Chrome process was already started by this manager the existing PID
     * is returned immediately without spawning a second instance.
     *
     * If a leftover Chrome process from a previous run is already listening on the
     * configured port, it is killed first to avoid inheriting stale state.
     *
     * When an Xdebug session is active, --headless is omitted so the tester can
     * watch the browser and interact with it during debugging.
     *
     * @param array $config Chrome config array from DatabaseFormatterExtension:
     *                      ['executable' => ..., 'user_data_dir' => ..., 'port' => ...]
     * @return ChromeStartResult Metadata about the started or reused Chrome process
     * @throws ConfigException If config is incomplete or Chrome does not become ready in time
     */
    public function start(array $config = []): ChromeStartResult
    {
        $this->getLogbook()->addLine('ChromeManager::start() called');
        $this->getLogbook()->addIndent(+1);
        $this->logger?->info('Using Chrome for BDT', [], $this->getLogbook());
        if (!empty($config)) {
            $this->config = $config; // store on first call
        }
        $config = $this->config;
        // Return immediately if a managed Chrome process is already running
        if ($this->pid !== null) {
            $this->getLogbook()->addLine("Chrome already running on port {$this->port} with PID {$this->pid} — reusing existing process");
            $this->getLogbook()->addIndent(-1);
            return new ChromeStartResult(
                port: $this->port,
                pid: $this->pid,
                startupMs: 0.0
            );
        }
        $startTime = microtime(true);
        $executable = $config['executable'] ?? null;
        $userDataDir = getcwd() . DIRECTORY_SEPARATOR . $config['user_data_dir'] ?? null;
        $port = $config['port'] ?? 9222;

        $this->getLogbook()->addLine("Config resolved — executable: {$executable}, userDataDir: {$userDataDir}, port: {$port}");

        if ($executable === null || $userDataDir === null) {
            $msg = '**ERROR** ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.';
            $this->getLogbook()->addLine($msg);
            $this->getLogbook()->addIndent(-1);
            throw new ConfigException($msg, null, null);
        }

        // Kill any leftover Chrome process on this port before starting a fresh one
        $this->getLogbook()->addLine("Checking for an existing process on port {$port} via netstat...");
        $this->getLogbook()->addIndent(+1);
        $existingPid = $this->findPidByPort($port);
        if ($existingPid !== null) {
            $this->getLogbook()->addLine("Found leftover process PID {$existingPid} — stopping it before launching a new instance");
            $this->pid = $existingPid;
            $this->stop();
            $this->getLogbook()->addLine("Waiting 500 ms for the old process to fully exit...");
            usleep(500_000);
        } else {
            $this->getLogbook()->addLine("No existing process found on port {$port}");
        }
        $this->getLogbook()->addIndent(-1);

        // "start /B" launches Chrome in the background within the current cmd session —
        // identical to a bat file. The empty "" after "start /B" is the window title
        // placeholder required by the Windows start command when a path follows.
        // When running under a debugger, --headless is omitted so the tester can
        // watch the browser and interact with it during debugging.
        $isDebugging = extension_loaded('xdebug') && xdebug_is_debugger_active();
        $cmd = 'start /B "" '
            . '"' . getcwd() . DIRECTORY_SEPARATOR . $executable . '"'
            . ($isDebugging ? '' : ' --headless --no-sandbox')
            . ' --window-size=1920,1080 --disable-extensions --disable-gpu'
            . ' --disable-dev-shm-usage'
            . ' --remote-debugging-port=' . $port
            . ' --remote-debugging-address=127.0.0.1'
            . ' --hide-crash-restore-bubble'
            . ' --no-first-run'
            . ' --no-default-browser-check'
            . ' --user-data-dir="' . $userDataDir . '"';

        $this->getLogbook()->addLine("Launching Chrome" . ($isDebugging ? " (headless OFF — debugger detected)" : " (headless)") . " with command: {$cmd}");
        pclose(popen($cmd, 'r'));
        $this->getLogbook()->addLine("popen() returned — Chrome process spawned, waiting for debug API to become ready...");

        // Block until Chrome's debug API is ready to accept connections
        $this->waitUntilReady($port);

        // Resolve the PID via netstat because "start /B" does not return one directly
        $this->getLogbook()->addLine("Chrome is ready — resolving PID via netstat...");
        $pid = $this->findPidByPort($port);
        $this->getLogbook()->addIndent(-1);

        $this->pid  = $pid;
        $this->port = $port;

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);
        $this->getLogbook()->addLine("Chrome started successfully — PID: {$pid}, port: {$port}, startup time: {$elapsedMs} ms");
        $this->getLogbook()->addIndent(-1);

        return new ChromeStartResult(
            port: $port,
            pid: $pid,
            startupMs: microtime(true) - $startTime
        );
    }

    /**
     * Stops only the Chrome process that was started by this manager.
     *
     * Targets the specific PID captured at start time so that other Chrome
     * instances running on different ports (e.g. belonging to other projects)
     * are not affected. Uses taskkill /T to also terminate child processes
     * spawned by Chrome.
     */
    public function stop(): void
    {
        $this->getLogbook()->addLine("ChromeManager::stop() called");
        $this->getLogbook()->addIndent(+1);

        if ($this->pid === null) {
            $this->getLogbook()->addLine("No Chrome process is being managed — nothing to do");
            $this->getLogbook()->addIndent(-1);
            return;
        }

        $this->getLogbook()->addLine("Stopping Chrome process PID {$this->pid} (taskkill /F /PID /T)...");

        // /T also terminates child processes spawned by Chrome
        exec('taskkill /F /PID ' . $this->pid . ' /T 2>nul');

        $this->pid  = null;
        $this->port = null;
        $this->getLogbook()->addLine("taskkill executed — PID and port state reset");
        $this->getLogbook()->addIndent(-1);
    }

    /**
     * Stops the running Chrome process and starts a fresh one on the same port.
     *
     * This is the recovery entry point called by UI5BrowserContext::recoverChrome()
     * when a ChromeHangException is caught mid-test. The method is intentionally
     * thin: it delegates entirely to the existing stop() and start() methods so
     * that all port-check, PID-detection, and readiness-polling logic stays in
     * one place and restart() automatically benefits from any future improvements
     * to those methods.
     *
     * A short sleep between stop and start gives the OS time to release the port
     * and any file handles Chrome held, reducing the chance of start() finding the
     * port still occupied immediately after the kill.
     *
     * @throws RuntimeException If stop() cannot terminate the process or start()
     *                          cannot confirm readiness within its timeout
     */
    public function restart(): void
    {
        $this->getLogbook()->addLine("ChromeManager::restart() called");
        $this->getLogbook()->addIndent(+1);

        $this->stop();
        $this->getLogbook()->addLine("Sleeping 2 s to allow the OS to fully release the port...");
        sleep(2);
        $this->start();

        $this->getLogbook()->addIndent(-1);
    }

    /**
     * Returns the PID of the currently managed Chrome process, or null if none is running.
     */
    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * Returns the remote-debugging port of the currently managed Chrome process,
     * or null if none is running.
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Returns the list of open Chrome tabs by querying the /json/list debug endpoint.
     *
     * Each entry in the returned array represents one tab and contains fields such as
     * "id", "type", "url", "title", and "webSocketDebuggerUrl". An empty array means
     * Chrome has no open tabs, which typically indicates the tab crashed or was closed
     * unexpectedly.
     *
     * Useful for diagnostics when a connection error occurs: if the list is empty or
     * null the root cause is in Chrome itself rather than the WebSocket layer.
     *
     * @param int|null $port Port to query; falls back to the currently managed port if omitted
     * @return array Decoded JSON tab list, or an empty array if the endpoint could not be reached
     */
    public function getTabList(?int $port = null): array
    {
        return $this->runGuzzleApi('http://localhost:' . ($port ?? $this->getPort()) . '/json/list');
    }

    /**
     * Finds the PID of the process listening on the given TCP port using netstat.
     *
     * Used to retrieve the Chrome PID after launching it with "start /B",
     * which does not return a PID directly.
     *
     * @param int $port The remote-debugging port Chrome is listening on
     * @return int|null The PID, or null if no matching LISTENING process was found
     */
    private function findPidByPort(int $port): ?int
    {
        $this->getLogbook()->addLine("findPidByPort({$port}): scanning netstat output...");
        $this->getLogbook()->addIndent(+1);

        $output = [];
        exec('netstat -ano -p TCP', $output);
        foreach ($output as $line) {
            if (preg_match('/(?:0\.0\.0\.0|127\.0\.0\.1):' . $port . '\s+.*LISTENING\s+(\d+)/', $line, $matches)) {
                $pid = (int) $matches[1];
                $this->getLogbook()->addLine("Found LISTENING process with PID {$pid}");
                $this->getLogbook()->addIndent(-1);
                return $pid;
            }
        }

        $this->getLogbook()->addLine("No LISTENING process found on port {$port}");
        $this->getLogbook()->addIndent(-1);
        return null;
    }

    /**
     * Polls Chrome's /json/list endpoint until it responds with a ready page or the timeout expires.
     *
     * Waits for the webSocketDebuggerUrl field to be present in at least one "page" tab,
     * which confirms that Chrome's remote debugging WebSocket is fully initialized and
     * ready to accept connections from dmore/chrome-mink-driver.
     *
     * @param int $port           The remote-debugging port to poll
     * @param int $timeoutSeconds Maximum number of seconds to wait before giving up
     * @throws RuntimeException   If Chrome does not become ready within the timeout
     */
    private function waitUntilReady(int $port, int $timeoutSeconds = 10): void
    {
        $this->getLogbook()->addLine("waitUntilReady(): polling http://localhost:{$port}/json/list (timeout: {$timeoutSeconds}s)...");
        $this->getLogbook()->addIndent(+1);

        $start   = time();
        $attempt = 0;
        while (time() - $start < $timeoutSeconds) {
            $attempt++;
            $pages = $this->getTabList($port);

            if ($pages === []) {
                $this->getLogbook()->addLine("Attempt #{$attempt}: tab list empty or Chrome not yet reachable — retrying in 200 ms...");
            } else {
                $this->getLogbook()->addLine("Attempt #{$attempt}: received " . count($pages) . " tab(s)");
                $this->getLogbook()->addIndent(+1);
                foreach ($pages as $page) {
                    $type  = $page['type'] ?? '(no type)';
                    $wsUrl = $page['webSocketDebuggerUrl'] ?? '';
                    $this->getLogbook()->addLine("tab type: {$type}, url: " . ($page['url'] ?? '(none)') . ", wsDebuggerUrl: " . ($wsUrl !== '' ? $wsUrl : '(empty)'));

                    // At least one navigatable page with an active WebSocket URL means Chrome is ready
                    if ($type === 'page' && $wsUrl !== '') {
                        $elapsed = round((time() - $start) * 1000);
                        $this->getLogbook()->addLine("Chrome ready after {$attempt} attempt(s) ({$elapsed} ms)");
                        return;
                    }
                }
                $this->getLogbook()->addIndent(-1);
                $this->getLogbook()->addLine("Attempt #{$attempt}: no ready page tab yet — retrying in 200 ms...");
            }

            usleep(200_000);
        }

        $msg = "**ERROR** Chrome did not become ready on port {$port} within {$timeoutSeconds} seconds.";
        $this->getLogbook()->addLine($msg . " (total attempts: {$attempt})");
        $this->getLogbook()->addIndent(-1);
        throw new RuntimeException($msg);
    }

    /**
     * Sends a GET request to a Chrome DevTools Protocol HTTP endpoint and returns the decoded JSON body.
     *
     * A new Guzzle client is created per call because Chrome's CDP endpoints are only
     * queried occasionally (startup polling, tab diagnostics) and do not warrant a
     * persistent HTTP client. Guzzle exceptions are caught, logged, and swallowed so
     * that callers such as waitUntilReady() can simply retry on the next iteration.
     *
     * @param string $url Full URL of the CDP endpoint (e.g. http://localhost:9222/json/list)
     * @return array Decoded JSON response body, or an empty array on any error
     */
    private function runGuzzleApi(string $url): array
    {
        try {
            $client   = new Client();
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->__toString(), true) ?? [];
            }

            $this->getLogbook()->addLine("runGuzzleApi({$url}): unexpected HTTP status " . $response->getStatusCode());
        } catch (\Throwable $e) {
            // Guzzle throws ConnectException while Chrome is still starting up;
            // log the details so we can distinguish a real failure from normal startup delay
            $this->getLogbook()->addLine("**ERROR** runGuzzleApi({$url}): " . get_class($e) . ' — ' . $e->getMessage());
        }

        return [];
    }
}