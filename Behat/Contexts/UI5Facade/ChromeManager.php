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
 *       api_url: "http://localhost:9222"
 *       executable: 'C:\...\GoogleChromePortable.exe'
 *       user_data_dir: 'C:\...\ChromeUserData'
 *
 * Each project overrides api_url, executable, and user_data_dir in its own
 * behat.yml so that multiple projects can run simultaneously on the same server.
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
     * Uses Symfony Process so Chrome runs in the same user session as the PHP
     * process — identical to launching Chrome from cmd.exe. This avoids the
     * Session 0 isolation problem that wmic and PowerShell can introduce.
     *
     * If a Chrome process was already started by this manager the existing PID
     * is returned immediately without spawning a second instance.
     *
     * The port is parsed from the api_url config value so there is a single
     * source of truth — the same URL that dmore/chrome-mink-driver uses.
     *
     * @param array $config Chrome config array from DatabaseFormatterExtension:
     *                      ['api_url' => ..., 'executable' => ..., 'user_data_dir' => ...]
     * @return int           PID of the started Chrome process
     * @throws \RuntimeException If config is incomplete, the process cannot be started,
     *                           or the API does not become ready in time
     */
    public static function start(array $config = []): int
    {
        if (self::$pid !== null) {
            return self::$pid;
        }

        $executable = $config['executable'] ?? null;
        $userDataDir = $config['user_data_dir'] ?? null;
        $port = $config['port'] ?? '9222';

        if ($executable === null || $userDataDir === null) {
            throw new \RuntimeException(
                'ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.'
            );
        }

        // Use "start /B" to launch Chrome in the background — identical to running
        // it from a bat file. This avoids session isolation issues that Symfony
        // Process and wmic can cause on Windows.
        $cmd = 'start /B "" '
            . '"' . $executable . '"'
            . ' --remote-debugging-port=' . $port
            . ' --user-data-dir="' . $userDataDir . '"';
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
     * Uses the PID captured at start time so other Chrome instances on
     * different ports are not affected.
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
     * This is used on Windows to get the real Chrome PID after launching it
     * with "start /B", which does not return a PID directly.
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
     * @param int $port           The remote-debugging port to poll
     * @param int $timeoutSeconds Maximum number of seconds to wait
     * @throws \RuntimeException  If Chrome does not become ready within the timeout
     */
    private static function waitUntilReady(int $port, int $timeoutSeconds = 15): void
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
            usleep(500_000);
        }

        throw new \RuntimeException(
            "Chrome did not become ready on port {$port} within {$timeoutSeconds} seconds."
        );
    }
}