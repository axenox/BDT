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
 * Logging: Call ChromeManager::setLogger($workbench->getLogger()) once before
 * start() to route all diagnostic messages through the PowerUI logger (visible
 * in the PowerUI log viewer). If no logger is injected, messages fall back to
 * PHP's error_log().
 */
class ChromeManager
{
    private static ?ChromeManager $instance = null;
    
    /** @var int|null PID of the Chrome process started by this manager */
    private ?int $pid = null;

    /** @var int|null Port on which Chrome's remote debugging API is listening */
    private ?int $port = null;

    /** @var LoggerInterface|null PowerUI logger injected from DatabaseFormatter */
    private ?LoggerInterface $logger = null;

    private ?LogBookInterface $logbook = null;

    private function __construct(?LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public static function getInstance(?LoggerInterface $logger = null): static
    {
        if (self::$instance === null) {
            self::$instance = new static($logger);
        }
        return self::$instance;
    }
    
    public function getLogbook(): LogBookInterface
    {
        if ($this->logbook == null) {
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
        if ($this->pid !== null) {
            $this->getLogbook()->addLine("Chrome already running on port " . $this->port . " with PID " . $this->pid . " — reusing existing process");
            if ($this->pid !== null) {
                return new ChromeStartResult(
                    port: $this->port,
                    pid: $this->pid,
                    startupMs: 0.0
                );
            }
        }
        $startTime = microtime(true);
        $executable = $config['executable'] ?? null;
        $userDataDir = getcwd() . DIRECTORY_SEPARATOR . $config['user_data_dir'] ?? null;
        $port = $config['port'] ?? 9222;

        $this->getLogbook()->addLine("Config resolved — executable: {$executable}, userDataDir: {$userDataDir}, port: {$port}");

        if ($executable === null || $userDataDir === null) {
            $msg = 'ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.';
            $this->getLogbook()->addLine($msg);
            throw new ConfigException($msg, null, null);
        }

        // If Chrome is already listening on this port (e.g. a leftover process from a
        // previous run), kill it and start fresh to avoid inheriting stale state.
        $this->getLogbook()->addLine("Checking for an existing process on port {$port} via netstat...");
        $existingPid = $this->findPidByPort($port);
        if ($existingPid !== null) {
            $this->pid = $existingPid;
            $this->getLogbook()->addLine("Found leftover Chrome process with PID {$existingPid} on port {$port} — stopping it before launching a new instance");
            $this->pid = $existingPid;
            $this->stop();
            $this->getLogbook()->addLine("Waiting 500 ms for the old process to fully exit...");
            usleep(500_000);
        } else {
            $this->getLogbook()->addLine("No existing process found on port {$port}");
        }

        // "start /B" launches Chrome in the background within the current cmd session —
        // identical to a bat file. The empty "" after "start /B" is the window title
        // placeholder required by the Windows start command when a path follows.
        // When running under a debugger, --headless is omitted so the tester can
        // watch the browser and interact with it during debugging.
        $isDebugging = extension_loaded('xdebug') && xdebug_is_debugger_active();
        $cmd = 'start /B "" '
            . '"' . getcwd() . DIRECTORY_SEPARATOR . $executable . '"'
            . ($isDebugging ? '' : ' --headless --no-sandbox')
            . " --window-size=1920,1080 --disable-extensions --disable-gpu"
            . ' --disable-dev-shm-usage'
            . ' --remote-debugging-port=' . $port
            . " --remote-debugging-address=127.0.0.1"
            . ' --hide-crash-restore-bubble'
            . ' --no-first-run'
            . ' --no-default-browser-check'
            . ' --user-data-dir="' . $userDataDir . '"';

        $this->getLogbook()->addLine("Launching Chrome" . ($isDebugging ? " (headless OFF — debugger detected)" : " (headless)") . " with command: {$cmd}");
        pclose(popen($cmd, 'r'));
        $this->getLogbook()->addLine("popen() returned — Chrome process spawned, waiting for debug API to become ready...");

        // Block until Chrome's debug API is ready
        $this->waitUntilReady($port);

        // Find the PID of the Chrome process listening on this port
        $this->getLogbook()->addLine("Chrome is ready — resolving PID via netstat...");
        $pid = $this->findPidByPort($port);

        $this->pid = $pid;
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
     * are not affected.
     */
    public function stop(): void
    {
        if ($this->pid === null) {
            $this->getLogbook()->addLine("stop() called but no Chrome process is being managed — nothing to do");
            return;
        }

        $this->getLogbook()->addLine("Stopping Chrome process PID " . $this->pid . " (taskkill /F /PID /T)...");

        // /T also terminates child processes spawned by Chrome
        exec('taskkill /F /PID ' . $this->pid . ' /T 2>nul');

        $this->pid     = null;
        $this->port    = null;
        $this->getLogbook()->addLine("taskkill executed — resetting PID and port state");
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
     * @throws \RuntimeException If stop() cannot terminate the process or start()
     *                           cannot confirm readiness within its timeout.
     */
    public function restart(): void
    {
        $this->stop();
        sleep(2); // allow the OS to fully release the port before relaunching
        $this->start();
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
        $output = [];
        // Single process — no pipe, no cmd.exe accumulation
        exec('netstat -ano -p TCP', $output);
        foreach ($output as $line) {
            if (preg_match('/(?:0\.0\.0\.0|127\.0\.0\.1):' . $port . '\s+.*LISTENING\s+(\d+)/', $line, $matches)) {
                $pid = (int) $matches[1];
                $this->getLogbook()->addLine("findPidByPort({$port}): found LISTENING process with PID {$pid}");
                return $pid;
            }
        }
        $this->getLogbook()->addLine("findPidByPort({$port}): no LISTENING process found");
        return null;
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
     * Polls Chrome's /json/list endpoint until it responds with a ready page or the timeout expires.
     *
     * Waits for the webSocketDebuggerUrl field to be present, which confirms that
     * Chrome's remote debugging WebSocket is fully initialized and ready to accept
     * connections from dmore/chrome-mink-driver.
     *
     * @param int $port           The remote-debugging port to poll
     * @param int $timeoutSeconds Maximum number of seconds to wait
     * @throws RuntimeException   If Chrome does not become ready within the timeout
     */
    private function waitUntilReady(int $port, int $timeoutSeconds = 10): void
    {
        $this->getLogbook()->addLine("waitUntilReady(): polling http://localhost:{$port}/json/list (timeout: {$timeoutSeconds}s)...");

        $start   = time();
        $attempt = 0;
        while (time() - $start < $timeoutSeconds) {
            $attempt++;
            $pages = $this->getTabList($port);

            if ($pages === []) {
                $this->getLogbook()->addLine("waitUntilReady() attempt #{$attempt}: tab list empty or Chrome not yet reachable — retrying in 200 ms...");
            } else {
                $this->getLogbook()->addLine("waitUntilReady() attempt #{$attempt}: received " . count($pages) . " tab(s)");
                foreach ($pages as $page) {
                    $type  = $page['type'] ?? '(no type)';
                    $wsUrl = $page['webSocketDebuggerUrl'] ?? '';
                    $this->getLogbook()->addLine("  tab type: {$type}, url: " . ($page['url'] ?? '(none)') . ", wsDebuggerUrl: " . ($wsUrl !== '' ? $wsUrl : '(empty)'));

                    // Wait until there is at least one navigatable page with a ws:// URL
                    if ($type === 'page' && $wsUrl !== '') {
                        $elapsed = round((time() - $start) * 1000);
                        $this->getLogbook()->addLine("waitUntilReady(): Chrome ready after {$attempt} attempt(s) ({$elapsed} ms)");
                        return;
                    }
                }
                $this->getLogbook()->addLine("waitUntilReady() attempt #{$attempt}: no ready page tab yet — retrying in 200 ms...");
            }

            usleep(200_000);
        }

        $msg = "Chrome did not become ready on port {$port} within {$timeoutSeconds} seconds.";
        $this->getLogbook()->addLine($msg . " (total attempts: {$attempt})");
        throw new RuntimeException($msg);
    }

    /**
     * Returns the list of open Chrome tabs by querying the /json/list debug endpoint.
     *
     * Each entry in the returned array represents one tab and contains fields such as
     * "id", "type", "url", "title", and "webSocketDebuggerUrl". An empty array
     * means Chrome has no open tabs, which typically indicates the tab crashed or was
     * closed unexpectedly. A null return value means Chrome is not reachable at all.
     *
     * Useful for diagnostics when a connection error occurs: if the list is empty or
     * null the root cause is in Chrome itself rather than the WebSocket layer.
     *
     * @param int|null $port Port to query; falls back to the currently managed port if omitted
     * @return array|null Decoded JSON tab list, or an empty array if the endpoint could not be reached
     */
    public function getTabList(?int $port = null): ?array
    {
        return $this->runGuzzleApi("http://localhost:" . ($port ?? $this->getPort()) . "/json/list");
    }

    /**
     * Sends a GET request to a Chrome DevTools Protocol HTTP endpoint and returns the decoded JSON body.
     *
     * A new Guzzle client is created per call because this method is invoked from a static
     * context where no shared HTTP client can be injected. Guzzle exceptions are caught,
     * logged, and swallowed so that callers (e.g. waitUntilReady) can simply retry.
     *
     * @param string $url Full URL of the CDP endpoint (e.g. http://localhost:9222/json/list)
     * @return array Decoded JSON response body, or an empty array on any error
     */
    private function runGuzzleApi(string $url): array
    {
        try {
            /* @var $client \GuzzleHttp\Client */
            $client   = new Client();
            $response = $client->request('GET', $url);

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->__toString(), true) ?? [];
            }

            $this->getLogbook()->addLine("runGuzzleApi({$url}): unexpected HTTP status " . $response->getStatusCode());
        } catch (\Throwable $e) {
            // Guzzle throws ConnectException while Chrome is still starting up;
            // log the details so we can distinguish a real failure from normal startup delay.
            $this->getLogbook()->addLine("runGuzzleApi({$url}): " . get_class($e) . " — " . $e->getMessage());
        }

        return [];
    }
}