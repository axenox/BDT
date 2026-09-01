<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade;

use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
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
    /**
     * WHY THIS TRAIT: the PID is not a reliable identity for a Chrome instance (see stop()), but the
     * profile dir is - every Chrome carries it in its --user-data-dir switch. The trait owns the one
     * correct way to parse that switch out of a command line and to kill everything bound to a given
     * profile dir. Sharing it with RunParallel/RunTest guarantees the three code paths can never drift
     * apart in HOW they match a Chrome to its profile - a drift that previously left hundreds of zombie
     * chrome.exe processes and their profile dirs behind.
     */
    use ChromeProfileReaperTrait;

    /** @var static|null Singleton instance; supports subclassing via late static binding */
    private static ?self $instance = null;

    /** @var int|null PID of the Chrome process started by this manager */
    private ?int $pid = null;

    /** @var int|null Port on which Chrome's remote debugging API is listening */
    private ?int $port = null;

    /**
     * @var string|null Absolute user_data_dir of the Chrome instance managed here, as resolved by start().
     *
     * WHY IT IS KEPT: the profile dir - not the PID - is the durable identity of our Chrome. stop()
     * needs it to verify that the browser really is gone, because the PID it holds can be stale or was
     * never resolved at all (see stop()). Stored at resolve time in start() so every later teardown has
     * it, including a teardown that happens after Chrome silently replaced its own process.
     */
    private ?string $userDataDir = null;

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
     * Wall-clock ceiling for a single health probe, in seconds.
     *
     * WHY SO SHORT: isAlive() runs before EVERY step, so its cost is paid on the hot path.
     * A live Chrome answers /json/version on loopback in single-digit milliseconds; anything
     * that needs more than this is, for our purposes, not usable as a browser anyway. The
     * ceiling also bounds the WEDGED case, where Chrome still accepts the TCP connection but
     * never writes a response - without an explicit timeout such a probe would block forever
     * and turn the health check itself into the hang it is meant to detect.
     */
    private const HEALTH_PROBE_TIMEOUT_SECONDS = 2.0;

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
        // WHY AN IDEMPOTENCY GUARD: the docblock has always promised that a second start() is a
        // no-op, but nothing enforced it - every caller that lost the PID race silently spawned a
        // second Chrome tree on the same profile dir, which is both a ProcessSingleton conflict and
        // a leak. Liveness (not the PID) is the correct condition, for the reasons isAlive() states.
        if ($this->port !== null && $this->isAlive()) {
            $this->getLogbook()->addLine('start(): a healthy Chrome is already running on port ' . $this->port . ' - reusing it');
            return new ChromeStartResult(port: $this->port, pid: $this->pid, startupMs: 0.0);
        }
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
        // Resolve user_data_dir the SAME way as executable above: a RELATIVE path is joined to the
        // installation root, an ABSOLUTE path is used AS-IS. Without the isRelative() guard an
        // absolute user_data_dir would be double-prepended into a broken "C:\...\C:\..." path, and
        // Chrome would silently fall back to the real default profile (profile picker / shared
        // state), defeating the per-run profile isolation. A missing key stays null so the
        // mandatory-config guard below fails loudly instead of using cwd itself as the profile dir.
        $userDataDir = null;
        if (isset($config['user_data_dir'])) {
            $userDataDir = FilePathDataType::isRelative($config['user_data_dir'])
                ? getcwd() . DIRECTORY_SEPARATOR . $config['user_data_dir']
                : $config['user_data_dir'];
        }
        $port = $config['port'] ?? 9222;

        $this->getLogbook()->addLine("Config resolved — executable: {$executable}, userDataDir: {$userDataDir}, port: {$port}");

        if ($executable === null || $userDataDir === null) {
            $msg = '**ERROR** ChromeManager requires "executable" and "user_data_dir" in the chrome config. '
                . 'Please set them under DatabaseFormatterExtension > chrome in your behat.yml.';
            $this->getLogbook()->addLine($msg);
            $this->getLogbook()->addIndent(-1);
            throw new ConfigException($msg, null, null);
        }

        // Remember the profile dir BEFORE anything can be killed. The leftover-cleanup right below
        // already calls stop(), and stop()'s profile-based verification sweep depends on this value.
        $this->userDataDir = $userDataDir;

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
            // Cut Chrome's background "phone home" services. Every fresh lane profile
            // tries to register with Google push messaging (GCM) on startup; with several
            // lanes starting from the same server IP Google throttles the registrations,
            // producing the PHONE_REGISTRATION_ERROR / DEPRECATED_ENDPOINT / QUOTA_EXCEEDED
            // noise in the lane logs. These flags stop the requests at the source instead
            // of merely hiding the log lines. Page-level test traffic is NOT affected.
            . ' --disable-background-networking'
            . ' --disable-sync'
            . ' --disable-component-update'
            . ' --disable-default-apps'
            // Let only FATAL messages reach stderr - suppresses the remaining harmless
            // ERROR spam (geolocation COM class, on-device model backend) that would
            // otherwise interleave with Behat output in the lane log.
            . ' --log-level=3'
            . ' --remote-debugging-port=' . $port
            . ' --remote-debugging-address=127.0.0.1'
            . ' --hide-crash-restore-bubble'
            . ' --no-first-run'
            . ' --no-default-browser-check'
            . ' --user-data-dir="' . $userDataDir . '"'
            // Discard Chrome's own stderr: the "DevTools listening on ws://..." line plus the absl
            // InitializeLog WARNING and voice_transcription INFO lines. With "start /B" Chrome
            // inherits this process's console handles, so without the redirect that noise leaks into
            // the Behat output the tester watches - and only Behat's output belongs there. A real
            // launch failure is still surfaced loudly by waitUntilReady() below, so redirecting
            // Chrome's stderr loses no diagnostic signal.
            . ' 2>nul';

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
     * Stops the Chrome instance managed here: kills its PID tree, then verifies via its profile dir.
     *
     * WHY THE PID ALONE IS NOT ENOUGH: the PID is resolved once, at launch, from netstat. It goes stale
     * in several ordinary situations - Chrome relaunches its own browser process after an internal
     * crash-recovery, findPidByPort() loses the race and returns null, or the tree was partially killed
     * and only children survive. In every one of those cases a PID-only stop() kills nothing and reports
     * success, and the browser plus its locked profile dir stay behind forever. That is one of the ways
     * the server accumulated zombie chrome.exe processes.
     *
     * WHY THE PROFILE DIR IS THE REAL IDENTITY: every Chrome we launch carries --user-data-dir with a
     * per-run, per-lane profile path that no other process on the machine uses. Sweeping by that dir
     * catches exactly our own instance and its children - including an orphaned child whose parent has
     * already died - and can never touch a foreign browser, which lives under a different profile path.
     *
     * WHY IT NEVER THROWS: teardown runs from hooks and finally blocks that are forbidden to throw. A
     * browser that could not be killed is logged, never escalated.
     */
    public function stop(): void
    {
        $this->getLogbook()->addLine("ChromeManager::stop() called");
        $this->getLogbook()->addIndent(+1);

        if ($this->pid === null && $this->userDataDir === null) {
            $this->getLogbook()->addLine("No Chrome process is being managed — nothing to do");
            $this->getLogbook()->addIndent(-1);
            return;
        }

        if ($this->pid !== null) {
            $this->getLogbook()->addLine("Stopping Chrome process PID {$this->pid} (taskkill /F /PID /T)...");
            // /T also terminates child processes spawned by Chrome
            exec('taskkill /F /PID ' . $this->pid . ' /T 2>nul');
        } else {
            $this->getLogbook()->addLine("No PID recorded — relying on the profile-dir sweep below");
        }

        // Verification sweep: whatever the PID kill did or did not achieve, nothing bound to OUR
        // profile dir may survive this call. Never throws (see the docblock).
        if ($this->userDataDir !== null) {
            try {
                $survivors = $this->reapChromeProfileDir($this->userDataDir, $this->listChromeProcessCommandLines());
                if ($survivors !== []) {
                    $this->getLogbook()->addLine(
                        'Profile sweep killed ' . count($survivors) . ' Chrome process(es) the PID kill missed: '
                        . implode(', ', $survivors)
                    );
                }
            } catch (\Throwable $e) {
                $this->getLogbook()->addLine(
                    '**WARNING** Profile-dir sweep failed: ' . get_class($e) . ' — ' . $e->getMessage()
                );
            }
        }

        $this->pid  = null;
        $this->port = null;
        // userDataDir is deliberately NOT cleared: it comes from the config, is identical for the next
        // start() on this manager, and a later teardown (restart, AfterScenario) still needs it to sweep.
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
     * Returns TRUE if the Chrome process managed by this instance is reachable and able to
     * serve CDP requests right now.
     *
     * WHY THIS EXISTS: today a dead Chrome is only discovered INDIRECTLY - some step calls the
     * Mink session, the driver fails to open its WebSocket, and a low-level socket exception is
     * thrown from wherever that call happened to be. When that call sits inside a step, the
     * AfterStep hook can still translate it into a restart; when it sits inside Mink's own
     * session lifecycle (reset/stop between scenarios), the exception escapes every guard we
     * own and kills the whole Behat process with exit code 255, taking the entire lane and its
     * DB recording down with it. A cheap, explicit liveness probe lets callers ASK whether the
     * browser is usable, instead of finding out by crashing into it.
     *
     * WHY /json/version AND NOT /json/list: version is the smallest endpoint Chrome serves and
     * needs no tab enumeration, so it stays cheap enough to run before every step. It answers
     * the only question asked here - "is a CDP-speaking Chrome listening on our port" - while
     * tab-level readiness (a navigable page with a WebSocket URL) remains the job of
     * waitUntilReady() at startup.
     *
     * WHY NOT getPid(): the PID is recorded at launch and is never invalidated when Chrome dies
     * or is reaped, so a non-null PID proves nothing about the CURRENT state. Only the port
     * answers.
     *
     * Never throws: a failed probe IS the answer (FALSE). Callers use it to decide on a restart,
     * so it must be safe to call from hooks that are forbidden to throw.
     *
     * @param int|null $port Port to probe; falls back to the currently managed port when omitted
     * @return bool TRUE if Chrome answered a CDP request within HEALTH_PROBE_TIMEOUT_SECONDS
     */
    public function isAlive(?int $port = null): bool
    {
        $port = $port ?? $this->port;
        if ($port === null) {
            // Chrome was never started by this manager - there is nothing that could be alive.
            return false;
        }

        try {
            // 127.0.0.1 rather than "localhost" on purpose: on Windows "localhost" may resolve to
            // ::1 first, and when Chrome only binds IPv4 the probe pays a connect timeout before
            // falling back - turning a healthy browser into a "dead" verdict.
            $client   = new Client([
                // connect_timeout bounds an unreachable port, timeout bounds a wedged Chrome that
                // accepts the connection but never answers. Both are required: either one alone
                // leaves a way for the probe to block.
                'connect_timeout' => self::HEALTH_PROBE_TIMEOUT_SECONDS,
                'timeout'         => self::HEALTH_PROBE_TIMEOUT_SECONDS,
                // A non-200 answer is a health verdict, not an exceptional situation - handle it
                // as data instead of paying for exception unwinding on the hot path.
                'http_errors'     => false
            ]);
            $response = $client->request('GET', 'http://127.0.0.1:' . $port . '/json/version');

            if ($response->getStatusCode() !== 200) {
                $this->getLogbook()->addLine(
                    "isAlive({$port}): Chrome answered with HTTP " . $response->getStatusCode() . ' - treating as dead'
                );
                return false;
            }

            $body = json_decode($response->getBody()->__toString(), true);
            // A CDP-capable Chrome always advertises a browser-level WebSocket endpoint here.
            // Requiring it rules out the case where some FOREIGN service occupies our port and
            // happens to answer 200 - attaching to that would fail in a far more confusing way.
            if (! is_array($body) || ($body['webSocketDebuggerUrl'] ?? '') === '') {
                $this->getLogbook()->addLine(
                    "isAlive({$port}): the listener on this port is not a CDP endpoint - treating as dead"
                );
                return false;
            }

            return true;
        } catch (\Throwable $e) {
            // Connection refused, DNS, timeout, malformed response - all mean the same thing to
            // the caller. Logged (not swallowed silently) so a flapping browser is traceable.
            $this->getLogbook()->addLine(
                "isAlive({$port}): probe failed - " . get_class($e) . ' - ' . $e->getMessage()
            );
            return false;
        }
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
     * WHY 127.0.0.1 AND NOT "localhost": on Windows "localhost" resolves to ::1 before 127.0.0.1.
     * Chrome binds its debug port on IPv4 only, so every call would first pay a failed IPv6
     * connect attempt before falling back - inflating startup polling and, worse, making a
     * healthy Chrome look unreachable whenever the probe timeout is short.
     *
     * @param int|null $port Port to query; falls back to the currently managed port if omitted
     * @return array Decoded JSON tab list, or an empty array if the endpoint could not be reached
     */
    public function getTabList(?int $port = null): array
    {
        return $this->runGuzzleApi('http://127.0.0.1:' . ($port ?? $this->getPort()) . '/json/list');
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
     * Decides whether a command line belongs to one of OUR leftover Chrome instances.
     *
     * WHY MATCH ON THE chrome_profiles ROOT (not just this exact lane dir): profile dirs are now
     * run-scoped ("<run_uid>_laneN"), so a zombie Chrome left behind by a PREVIOUS run has a
     * DIFFERENT user_data_dir than the current run's lane. Matching only the current exact dir would
     * classify that previous-run zombie as foreign and make start() fail loudly instead of reclaiming
     * the port it still holds. Because every BDT Chrome - across all runs and lanes - is launched with
     * its profile under this installation's data\axenox\BDT\chrome_profiles tree, treating any process
     * whose user_data_dir sits under that root as ours lets us reclaim our own zombies on a reused port
     * while never touching a genuinely foreign browser (a human's Chrome or another project's fleet
     * live under entirely different profile paths).
     *
     * SAFETY - no prefix trap and no foreign kill: (1) the switch VALUE is parsed out and tested against
     * the profiles root followed by a directory separator, so "...\chrome_profiles\" never bleeds into a
     * sibling like "...\chrome_profiles_backup\". (2) This check only runs against the single process
     * occupying THIS lane's unique port, so a concurrently LIVE sibling lane (on its own distinct port)
     * is never a candidate for killing. Anything whose user_data_dir is NOT under our profiles root stays
     * foreign - the safe default of "fail loudly, never kill".
     *
     * WHY THE PARSED VALUE AND NOT A SUBSTRING OF THE COMMAND LINE: our launch command writes
     * --user-data-dir="<dir>" WITH quotes, but Chrome re-serializes the same switch for its own
     * renderer/gpu/utility children WITHOUT quotes whenever the path contains no spaces. A quoted-only
     * substring needle therefore recognized only the browser process. When the process holding the port
     * was an orphaned CHILD of a previous run - the common case once its parent had been killed - it was
     * misclassified as FOREIGN and start() failed the whole lane loudly instead of reclaiming the port.
     * extractUserDataDir() handles both serializations, so ownership is decided on the actual path.
     *
     * The comparison is Windows-tolerant: case-insensitive with normalized backslashes, because CIM
     * output and our config may disagree on casing or slash direction.
     *
     * @param string $commandLine         Full command line of the occupying process
     * @param string $userDataDirAbsolute Our resolved absolute user_data_dir (a child of the profiles root)
     * @return bool TRUE if the process is one of our leftovers and safe to kill
     */
    private function isOwnLeftover(string $commandLine, string $userDataDirAbsolute): bool
    {
        $foreignDir = $this->extractUserDataDir($commandLine);
        if ($foreignDir === null) {
            // Not a Chrome, or a Chrome launched without an explicit profile: never ours, never killed.
            return false;
        }
        // Derive the shared chrome_profiles root from this lane's dir (its parent) and require the
        // trailing separator so the match cannot bleed into a same-prefixed sibling directory.
        $profilesRoot = $this->normalizeWindowsPath(dirname($userDataDirAbsolute)) . '\\';
        return str_starts_with($foreignDir, $profilesRoot);
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
        $this->getLogbook()->addLine("waitUntilReady(): polling http://127.0.0.1:{$port}/json/list (timeout: {$timeoutSeconds}s)...");
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
     * WHY EXPLICIT TIMEOUTS: Guzzle's default request timeout is UNLIMITED. A Chrome that binds
     * the port but never answers - exactly the wedged state we are trying to detect - would make
     * this call block forever, and the caller's own timeout loop (waitUntilReady) would never get
     * to re-evaluate its wall-clock condition. The whole "give up after N seconds" contract of
     * every caller therefore depends on the two timeouts below being set here.
     *
     * @param string $url Full URL of the CDP endpoint (e.g. http://127.0.0.1:9222/json/list)
     * @return array Decoded JSON response body, or an empty array on any error
     */
    private function runGuzzleApi(string $url): array
    {
        try {
            $client   = new Client([
                // connect_timeout bounds a closed port, timeout bounds a Chrome that accepts the
                // connection but never writes a response. Both are needed - either alone still
                // leaves a path for the call to hang indefinitely.
                'connect_timeout' => self::HEALTH_PROBE_TIMEOUT_SECONDS,
                'timeout'         => self::HEALTH_PROBE_TIMEOUT_SECONDS
            ]);
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