<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Debug\LogBookInterface;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
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

    /**
     * @var DatabaseFormatter|null
     * The active DatabaseFormatter, injected on the first getInstance() call.
     *
     * Typed against the concrete DatabaseFormatter (not TestRunObserverInterface) on purpose:
     * we specifically rely on its logError() implementation, which creates a FAILED run_step
     * bound to the current scenario/step in the results DB. Holding the formatter instead of a
     * plain logger lets a Chrome startup failure show up as a real failed step, rather than an
     * unexplained timeout with the cause buried in the logbook.
     */
    private ?DatabaseFormatter $databaseFormatter = null;

    /** @var LogBookInterface|null Lazily created logbook for structured diagnostic output */
    private ?LogBookInterface $logbook = null;

    /** @var array Chrome launch configuration stored by configure(); used by every start() call */
    private array $config = [];

    /** @var array[] Log of every start() call; cleared by DatabaseFormatter after each feature is written */
    private array $startHistory = [];
    /** App-config key deciding Chrome window visibility; see resolveHeadless(). */
    private const CFG_CHROME_HEADLESS = 'PARALLEL.CHROME_HEADLESS';

    /**
     * Private constructor enforces singleton usage via getInstance().
     *
     * The formatter is optional so getInstance() can be called without arguments once the
     * instance already exists. It is the DatabaseFormatter, the only component able to write a
     * failed step to the results DB via its overridden logError().
     *
     * @param DatabaseFormatter|null $databaseFormatter Formatter used to report Chrome startup failures as a test step
     */
    private function __construct(?DatabaseFormatter $databaseFormatter = null)
    {
        $this->databaseFormatter = $databaseFormatter;
    }

    /**
     * Returns the singleton ChromeManager instance, creating it on the first call.
     *
     * The formatter parameter is only meaningful on the very first call (from
     * DatabaseFormatter::__construct(), which passes itself). All subsequent callers should
     * omit it; the instance retains the formatter injected during initialization.
     *
     * Pattern: initialize-once singleton with optional constructor injection.
     *
     * @param DatabaseFormatter|null $databaseFormatter Formatter to inject; ignored if the instance already exists
     * @return static The singleton instance
     */
    public static function getInstance(?DatabaseFormatter $databaseFormatter = null): static
    {
        if (self::$instance === null) {
            self::$instance = new static($databaseFormatter);
        }
        return self::$instance;
    }

    /**
     * Stores the Chrome launch configuration so that subsequent start() and restart() calls
     * can use it without requiring the caller to pass config each time.
     *
     * Must be called once before start() — typically from DatabaseFormatter::__construct(),
     * which is the only place that has access to the behat.yml chrome section.
     */
    public function configure(array $config): void
    {
        $this->config = $config;
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
     * Whether Chrome runs headless is decided by resolveHeadless(): the app-config flag
     * PARALLEL.CHROME_HEADLESS wins when set (true = headless, false = visible), and Xdebug
     * auto-detection is only the fallback when the flag is absent - so a live debugger shows the
     * browser only when nobody configured the flag. Fleet workers run with the debugger disabled,
     * so their fallback is always headless.
     *
     * @return ChromeStartResult Metadata about the started or reused Chrome process
     * @throws ConfigException If config is incomplete or Chrome does not become ready in time
     */
    public function start(): ChromeStartResult
    {
        $this->getLogbook()->addLine('ChromeManager::start() called');
        $this->getLogbook()->addIndent(+1);
        $this->databaseFormatter?->getWorkbench()->getLogger()->info('Using Chrome for BDT', [], $this->getLogbook());
        $config = $this->config;
        $startTime = microtime(true);
        $executable = $config['executable'] ?? null;
        // Resolve the executable to an absolute path. An ABSOLUTE path (e.g. the fleet's
        // PARALLEL.CHROME_PATH = "C:\Program Files\Google\Chrome\Application\chrome.exe") is used
        // AS-IS - prepending getcwd() would produce a broken "C:\...\C:\..." path, Chrome would
        // never launch, and waitUntilReady() would block until timeout (the worker "hangs" with no
        // steps recorded). Only a RELATIVE path (e.g. the interactive "data\...\GoogleChromePortable.exe")
        // is resolved against the installation root, exactly as before.
        if ($executable !== null && FilePathDataType::isRelative($executable)) {
            $executable = getcwd() . DIRECTORY_SEPARATOR . $executable;
        }
        // Resolve relative to cwd only when the key is actually present; a missing key must stay
        // null so the mandatory-config guard below fails loudly instead of silently letting Chrome
        // use cwd itself as the profile dir. (?? binds looser than ., so the old one-liner never worked.)
        $userDataDir = isset($config['user_data_dir'])
            ? getcwd() . DIRECTORY_SEPARATOR . $config['user_data_dir']
            : null;
        $port = $config['port'] ?? 9222;

        $this->getLogbook()->addLine("Config resolved — executable: {$executable}, userDataDir: {$userDataDir}, port: {$port}");

        if ($executable === null || $userDataDir === null) {
            $msg = '**ERROR** ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.';
            $this->getLogbook()->addLine($msg);
            $this->getLogbook()->addIndent(-1);
            throw new ConfigException($msg, null, null);
        }

        // Deal with any process already occupying this port BEFORE launching. We only kill it if
        // it is provably OUR OWN leftover Chrome (same user_data_dir on its command line); a live
        // foreign process - another project's fleet worker, another tester's run, or a non-Chrome
        // application - must NEVER be killed. On a shared server the ports come from probed bands,
        // so an occupied port here means either our stale leftover or a band/collision problem
        // that must surface loudly instead of sabotaging someone else's running browser.
        $this->getLogbook()->addLine("Checking for an existing process on port {$port} via netstat...");
        $this->getLogbook()->addIndent(+1);
        $existingPid = $this->findPidByPort($port);
        if ($existingPid !== null) {
            $cmdLine = $this->getProcessCommandLine($existingPid);
            if ($cmdLine !== null && $this->isOwnLeftover($cmdLine, $userDataDir)) {
                // Our own leftover from a previous run on this profile - safe to kill as before.
                $this->getLogbook()->addLine("Found OUR leftover process PID {$existingPid} (command line matches our user_data_dir) — stopping it before launching a new instance");
                $this->pid = $existingPid;
                $this->stop();
                $this->getLogbook()->addLine("Waiting 500 ms for the old process to fully exit...");
                usleep(500_000);
            } elseif ($cmdLine === null && $this->findPidByPort($port) === null) {
                // The process exited between the netstat scan and the command-line query - the
                // port is free now, proceed with a normal launch.
                $this->getLogbook()->addLine("Process PID {$existingPid} vanished before it could be inspected — port {$port} is free now");
            } else {
                // Foreign live process (different user_data_dir, not Chrome at all, or state
                // unknown while the port is still held): fail loudly, never kill.
                $shownCmd = $cmdLine === null ? '(command line not retrievable)' : $cmdLine;
                $msg = "**ERROR** Port {$port} is occupied by a FOREIGN process (PID {$existingPid}): {$shownCmd}. "
                    . 'Refusing to kill it. This usually indicates a port-band collision between projects or runs — '
                    . 'check the port_band / port_band_interactive settings in bdt_parallel.yml and the PARALLEL.* app config.';
                $this->getLogbook()->addLine($msg);
                $this->getLogbook()->addIndent(-1);
                $this->getLogbook()->addIndent(-1);
                $exception = new RuntimeException($msg);
                try {
                    // Same reporting path as waitUntilReady(): surface the failure as a real failed
                    // step in the results DB instead of an unexplained timeout.
                    $this->databaseFormatter?->logError($msg, $exception);
                } catch (\Throwable $logError) {
                    $this->getLogbook()->addLine('Could not report the foreign-process conflict to the DatabaseFormatter: ' . $logError->getMessage());
                }
                throw $exception;
            }
        } else {
            $this->getLogbook()->addLine("No existing process found on port {$port}");
        }
        $this->getLogbook()->addIndent(-1);

        // "start /B" launches Chrome in the background within the current cmd session —
        // identical to a bat file. The empty "" after "start /B" is the window title
        // placeholder required by the Windows start command when a path follows.
        //
        // Whether Chrome runs headless is decided by resolveHeadless(): the app-config flag
        // PARALLEL.CHROME_HEADLESS wins when set (the server sets it true = always headless, a local
        // operator sets it false to watch the browser), and Xdebug auto-detection is only the fallback
        // when the flag is absent. Fleet workers run with the debugger disabled, so their fallback is
        // always headless - the intended default for an unattended run.
        $headless = $this->resolveHeadless();
        $cmd = 'start /B "" '
            . '"' . $executable . '"'
            . ($headless ? ' --headless --no-sandbox' : '')
            . ' --window-size=1920,1080 --disable-extensions --disable-gpu'
            . ' --disable-dev-shm-usage'
            . ' --remote-debugging-port=' . $port
            . ' --remote-debugging-address=127.0.0.1'
            . ' --hide-crash-restore-bubble'
            . ' --no-first-run'
            . ' --no-default-browser-check'
            . ' --user-data-dir="' . $userDataDir . '"';

        $this->getLogbook()->addLine("Launching Chrome (" . ($headless ? "headless" : "visible") . ") with command: {$cmd}");
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

        $result = new ChromeStartResult(
            port: $port,
            pid: $pid,
            startupMs: microtime(true) - $startTime
        );
        $this->startHistory[] = [
            'pid'              => $pid,
            'port'             => $port,
            'startup_duration' => round((microtime(true) - $startTime) * 1000, 1),
            'started_at'       => date('Y-m-d H:i:s')
        ];
        return $result;
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
     * After this method returns, getLastStartResult() reflects the newly started
     * Chrome process so that callers can record the restart metadata.
     *
     * @throws RuntimeException If stop() cannot terminate the process or start()
     *                          cannot confirm readiness within its timeout.
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
     * Clears the start history collected since the last clearStartHistory() call.
     *
     * Called by DatabaseFormatter after writing chrome_info for a feature so that
     * the next feature starts with a clean slate. ChromeManager itself never clears
     * the history — it only appends to it.
     */
    public function clearStartHistory(): void
    {
        $this->startHistory = [];
    }

    /**
     * Returns all start() calls recorded since the last clearStartHistory() call.
     *
     * Each entry contains pid, port, startup_duration (ms), started_at, and restart_reason.
     *
     * @return array[]
     */
    public function getStartHistory(): array
    {
        return $this->startHistory;
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
     * Returns the full command line of a running process, or null if it cannot be determined.
     *
     * WHY THIS EXISTS: it is the evidence for the own-vs-foreign decision in start(). A Chrome
     * command line contains its --user-data-dir argument, which is the only reliable marker
     * distinguishing OUR leftover instance from a live Chrome belonging to another project or
     * tester on the same server - the CDP HTTP endpoints do not expose the profile directory.
     *
     * WHY POWERSHELL Get-CimInstance INSTEAD OF WMIC: wmic is deprecated/removed on current
     * Windows versions; Get-CimInstance queries the same Win32_Process data everywhere. The
     * filter is built from an int PID, so no injection surface exists. Single quotes inside the
     * double-quoted PowerShell command avoid cmd.exe quote-escaping pitfalls.
     *
     * @param int $pid PID of the process to inspect
     * @return string|null The command line, or null on query failure or if the process is gone
     */
    private function getProcessCommandLine(int $pid): ?string
    {
        $cmd = 'powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"';
        $this->getLogbook()->addLine("getProcessCommandLine({$pid}): querying via PowerShell...");

        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        $commandLine = trim(implode(' ', $output));

        if ($exitCode !== 0 || $commandLine === '') {
            $this->getLogbook()->addLine("getProcessCommandLine({$pid}): no command line retrievable (exit {$exitCode})");
            return null;
        }

        $this->getLogbook()->addLine("getProcessCommandLine({$pid}): {$commandLine}");
        return $commandLine;
    }

    /**
     * Decides whether a command line belongs to OUR leftover Chrome instance.
     *
     * WHY MATCH ON user_data_dir: it is the one launch argument that is unique per BDT
     * run/lane by construction (per-lane and per-port profile dirs), so its presence in the
     * command line proves the process was started for THIS configuration. The comparison is
     * Windows-tolerant: case-insensitive with normalized backslashes, because CIM output and
     * our config may disagree on casing or slash direction.
     *
     * WHY THE FULL QUOTED ARGUMENT FORM instead of a bare substring: a bare directory match
     * has a prefix trap - "...\lane1" is a substring of "...\lane10", so lane 1 would treat
     * lane 10's LIVE Chrome as its own leftover and kill it. Every Chrome this manager launches
     * carries exactly --user-data-dir="<absolute dir>" (see the launch command in start()), so
     * matching that complete quoted argument is both precise and guaranteed to recognize our own
     * leftovers. Anything that does not match this exact form is treated as foreign - the safe
     * default, since foreign means "fail loudly", never "kill".
     *
     * @param string $commandLine         Full command line of the occupying process
     * @param string $userDataDirAbsolute Our resolved absolute user_data_dir
     * @return bool TRUE if the process is our own leftover and safe to kill
     */
    private function isOwnLeftover(string $commandLine, string $userDataDirAbsolute): bool
    {
        $normalize = function (string $path): string {
            return strtolower(str_replace('/', '\\', $path));
        };
        $needle = '--user-data-dir="' . $normalize($userDataDirAbsolute) . '"';
        return str_contains($normalize($commandLine), $needle);
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
        $exception = new RuntimeException($msg);
        try {
            $this->databaseFormatter?->logError($msg, $exception);
        } catch (\Throwable $logError) {
            $this->getLogbook()->addLine('Could not report the Chrome startup failure to the DatabaseFormatter: ' . $logError->getMessage());
        }

        throw $exception;
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

    /**
     * Public, side-effect-free view of the headless decision, so callers can REPORT what start()
     * will do before it runs (e.g. a startup banner) without duplicating the resolution logic.
     *
     * Returns the exact same value start() uses, keeping any "will run headless/visible" message in
     * perfect sync with the real launch - there is only one source of truth (resolveHeadless()).
     *
     * @return bool TRUE if the next start() would launch headless, FALSE for a visible window
     */
    public function willRunHeadless(): bool
    {
        return $this->resolveHeadless();
    }

    /**
     * Decides whether Chrome starts headless.
     *
     * The app-config flag PARALLEL.CHROME_HEADLESS wins when present (true = headless, false = visible),
     * so operators control visibility deterministically. When the key is absent (or config is unreadable),
     * fall back to Xdebug auto-detection: visible while a debugger is attached, headless otherwise. Fleet
     * workers run with the debugger disabled, so their fallback is always headless.
     *
     * @return bool TRUE to launch headless, FALSE for a visible window
     */
    private function resolveHeadless(): bool
    {
        try {
            $wb = $this->databaseFormatter?->getWorkbench();
            if ($wb !== null) {
                $cfg = $wb->getApp('axenox.BDT')->getConfig();
                if ($cfg->hasOption(self::CFG_CHROME_HEADLESS)) {
                    return (bool) $cfg->getOption(self::CFG_CHROME_HEADLESS);
                }
            }
        } catch (\Throwable $e) {
            // Config unreadable (no workbench/app) - fall through to Xdebug auto-detection.
        }
        return ! (extension_loaded('xdebug') && xdebug_is_debugger_active());
    }
}