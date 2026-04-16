<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;


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
     * @return int           PID of the started Chrome process, or 0 if PID could not be determined
     * @throws \RuntimeException If config is incomplete or Chrome does not become ready in time
     */
    public static function start(array $config = []): int
    {
        if (self::$pid !== null) {
            return self::$pid;
        }

        $executable = $config['executable'] ?? null;
        $userDataDir = $config['user_data_dir'] ?? null;
        $port = $config['port'] ?? 9222;
        
        // If Chrome is already listening on this port (e.g. a leftover process from a
        // previous run), skip launching a new instance and use the existing one.
        $existingPid = self::findPidByPort($port);
        if ($existingPid !== null) {
            self::$pid  = $existingPid;
            self::$port = $port;
            return $existingPid;
        }
        
        if ($executable === null || $userDataDir === null) {
            throw new \RuntimeException(
                'ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.'
            );
        }
        
        // "start /B" launches Chrome in the background within the current cmd session —
        // identical to a bat file. The empty "" after "start /B" is the window title
        // placeholder required by the Windows start command when a path follows.
        $cmd = 'start /B "" '
            . '"' . getcwd() . DIRECTORY_SEPARATOR . $executable . '"'
            . " --headless --window-size=1920,1080 --disable-extensions --disable-gpu"
            . ' --remote-debugging-port=' . $port
            . ' --user-data-dir="' . getcwd() . DIRECTORY_SEPARATOR . $userDataDir . '"';
        pclose(popen($cmd, 'r'));

        // Block until Chrome's debug API is ready
        self::waitUntilReady($port);

        // Find the PID of the Chrome process listening on this port
        $pid = self::findPidByPort($port);

        self::$pid     = $pid;
        self::$port    = $port;

        return $pid ?? 0;
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
        // netstat -ano lists all connections with PIDs; findstr filters by port
        exec('netstat -ano | findstr :' . $port, $output);
        foreach ($output as $line) {
            // Match lines where Chrome is the listener: 0.0.0.0:9222 or 127.0.0.1:9222
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
     * Chrome's remote debugging WebSocket is fully initialised and ready to accept
     * connections from dmore/chrome-mink-driver.
     *
     * @param int $port           The remote-debugging port to poll
     * @param int $timeoutSeconds Maximum number of seconds to wait
     * @throws \RuntimeException  If Chrome does not become ready within the timeout
     */
    private static function waitUntilReady(int $port, int $timeoutSeconds = 5): void
    {
        $start = time();
        while (time() - $start < $timeoutSeconds) {
            $response = @file_get_contents("http://localhost:{$port}/json/list");
            if ($response !== false) {
                $pages = json_decode($response, true) ?? [];
                foreach ($pages as $page) {
                    // Wait until there is at least one navigatable page with a ws:// URL
                    if (($page['type'] ?? '') === 'page' && !empty($page['webSocketDebuggerUrl'])) {
                        return;
                    }
                }
            }
            usleep(200_000);
        }

        throw new \RuntimeException(
            "Chrome did not become ready on port {$port} within {$timeoutSeconds} seconds."
        );
    }
}