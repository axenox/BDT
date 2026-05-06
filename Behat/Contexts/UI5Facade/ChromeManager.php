<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;


use axenox\BDT\Exceptions\ConfigException;
use exface\Core\Exceptions\RuntimeException;
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
 */
class ChromeManager
{
    /** @var int|null PID of the Chrome process started by this manager */
    private static ?int $pid = null;

    /** @var int|null Port on which Chrome's remote debugging API is listening */
    private static ?int $port = null;

    /**
     * Starts a new Chrome process and waits until its debug API is ready.
     *
     * Chrome is launched via Windows "start /B" — identical to running it from
     * a bat file. This ensures Chrome runs in the current interactive user
     * session
     *
     * If a Chrome process was already started by this manager the existing PID
     * is returned immediately without spawning a second instance.
     *
     * @param array $config Chrome config array from DatabaseFormatterExtension:
     *                      ['executable' => ..., 'user_data_dir' => ..., 'port' => ...]
     * @return ChromeStartResult Metadata about the started or reused Chrome process
     * @throws ConfigException If config is incomplete or Chrome does not become ready in time
     */
    public static function start(array $config = []): ChromeStartResult
    {
        if (self::$pid !== null) {
            return new ChromeStartResult(
                port:      self::$port,
                pid:       self::$pid,
                startupMs: 0.0
            );
        }

        $startTime   = microtime(true);
        $executable = $config['executable'] ?? null;
        $userDataDir = getcwd() . DIRECTORY_SEPARATOR . $config['user_data_dir'] ?? null;
        $port = $config['port'] ?? 9222;

        // If Chrome is already listening on this port (e.g. a leftover process from a
        // previous run), kill it and start fresh to avoid inheriting stale state.
        $existingPid = self::findPidByPort($port);
        if ($existingPid !== null) {
            self::$pid = $existingPid;
            self::stop();
            usleep(500_000); // wait for the process to fully exit before launching a new one
        }
        
        if ($executable === null || $userDataDir === null) {
            throw new ConfigException(
                'ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.',
                null,
                null
            );
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
        
        pclose(popen($cmd, 'r'));

        // Block until Chrome's debug API is ready
        self::waitUntilReady($port);

        // Find the PID of the Chrome process listening on this port
        $pid = self::findPidByPort($port);

        self::$pid     = $pid;
        self::$port    = $port;

        return new ChromeStartResult(
            port:      $port,
            pid:       $pid,
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
    public static function stop(): void
    {
        if (self::$pid === null) {
            return;
        }

        // /T also terminates child processes spawned by Chrome
        exec('taskkill /F /PID ' . self::$pid . ' /T 2>nul');

        self::$pid     = null;
        self::$port    = null;
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
    private static function findPidByPort(int $port): ?int
    {
        $output = [];
        // Single process — no pipe, no cmd.exe accumulation
        exec('netstat -ano -p TCP', $output);
        foreach ($output as $line) {
            if (preg_match('/(?:0\.0\.0\.0|127\.0\.0\.1):' . $port . '\s+.*LISTENING\s+(\d+)/', $line, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    /**
     * Returns the PID of the currently managed Chrome process, or null if none is running.
     */
    public static function getPid(): ?int
    {
        return self::$pid;
    }

    /**
     * Returns the remote-debugging port of the currently managed Chrome process,
     * or null if none is running.
     */
    public static function getPort(): ?int
    {
        return self::$port;
    }

    /**
     * Polls Chrome's /json/version endpoint until it responds or the timeout expires.
     *
     * Waits for the webSocketDebuggerUrl field to be present, which confirms that
     * Chrome's remote debugging WebSocket is fully initialized and ready to accept
     * connections from dmore/chrome-mink-driver.
     *
     * @param int $port           The remote-debugging port to poll
     * @param int $timeoutSeconds Maximum number of seconds to wait
     * @throws RuntimeException  If Chrome does not become ready within the timeout
     */
    private static function waitUntilReady(int $port, int $timeoutSeconds = 10): void
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            $pages = self::getTabList($port);
            if ($pages !== []) {
                foreach ($pages as $page) {
                    // Wait until there is at least one navigatable page with a ws:// URL
                    if (($page['type'] ?? '') === 'page' && !empty($page['webSocketDebuggerUrl'])) {
                        return;
                    }
                }
            }
            usleep(200_000);
        }

        throw new RuntimeException(
            "Chrome did not become ready on port {$port} within {$timeoutSeconds} seconds."
        );
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
     * @return array|null Decoded JSON tab list, or null if the endpoint could not be reached
     */
    public static function getTabList(?int $port = null): ?array
    {
        return self::runGuzzleApi("http://localhost:" . ($port ?? self::getPort()) . "/json/list");
    }
    
    private static function runGuzzleApi(string $url): array
    {
        try {
            /* @var $client \GuzzleHttp\Client */
            $client = new Client();
            $response = $client->request(
                'GET',
                $url
            );

            if ($response->getStatusCode() === 200) {
                return json_decode($response->getBody()->__toString(), true) ?? [];
            }
        } catch (\Throwable $exception) {
        }
        return [];
    }
}