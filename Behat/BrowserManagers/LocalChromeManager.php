<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
use axenox\BDT\Exceptions\ConfigException;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;

/**
 * Starts and supervises a Chrome process on the machine that runs the tests.
 * 
 * Use this manager when Chrome should be launched by BDT itself, either headless or as a visible window.
 * Select it with the app-config key `BROWSER.MANAGER` and configure it in `BROWSER.MANAGER_CONFIG`.
 * 
 * ## Example
 * 
 * ```json
 * {
 *     "executable": "data\\axenox\\BDT\\GoogleChromePortable\\App\\Chrome-bin\\chrome.exe",
 *     "headless": true,
 *     "port_band_interactive": "9201-9251",
 *     "port_band_scheduled": "9251-9300"
 * }
 *  
 * ```
 *  
 * Do not put `port` or `user_data_dir` into static configuration: BDT sets them per run when a test is
 * started interactively or in parallel lanes, so every run gets its own debugging port and profile.
 * 
 * @see BrowserManagerFactory
 *
 * WHY IT EXISTS: it is the former ChromeManager reduced to what is local-only. Operating-system
 * specifics (launching, killing, port lookup) moved to the process hosts, and the shared health policy
 * to AbstractBrowserManager, so this class holds only the Chrome-specific launch and recovery logic.
 *
 * LIFECYCLE: created by BrowserManagerFactory; start() launches Chrome (or reuses a healthy one),
 * stop() kills the owned process tree and sweeps the profile directory for survivors. RunTest and
 * RunParallel supply `port` and `user_data_dir` as runtime overrides.
 */
class LocalChromeManager extends AbstractBrowserManager implements BrowserTabControlInterface, SupportsParallelLanesInterface
{
    use ChromeProfileReaperTrait;

    private const DEFAULT_PORT = 9222;
    private const DEFAULT_PORT_BAND_INTERACTIVE = '9201-9251';
    private const DEFAULT_PORT_BAND_SCHEDULED = '9251-9300';

    private ?int $pid = null;
    private ?int $port = null;
    private ?int $configuredPort = null;
    private ?string $executable = null;
    private ?string $userDataDir = null;
    private ?string $configuredUserDataDir = null;
    private ?bool $headless = null;
    private ?string $portBandInteractive = null;
    private ?string $portBandScheduled = null;

    /**
     * Resolves the local-only executable requirement before process operations begin.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::validateConfig()
     */
    public function validateConfig(): void
    {
        $missing = [];
        if ($this->executable === null || $this->executable === '') {
            $missing[] = 'executable';
        }
        if ($missing !== []) {
            throw new ConfigException(
                'Local Chrome requires the following configuration properties: ' . implode(', ', $missing) . '.'
            );
        }
    }

    /**
     * Path to the Chrome executable that BDT should launch.
     * 
     * Relative paths are resolved against the installation folder.
     * 
     * @uxon-property executable
     * @uxon-type string
     * @uxon-required true
     * 
     * WHY A SETTER: receives the executable (from BROWSER.MANAGER_CONFIG or a CLI override) through
     * UXON without coupling the manager to app config.
     */
    public function setExecutable(string $executable): void
    {
        $this->executable = $executable;
    }

    /**
     * Folder for the Chrome profile of this run.
     * 
     * This value is set by BDT at run time (one profile per interactive run or parallel lane) - do not
     * put it into static configuration. Relative paths are resolved against the installation folder.
     * 
     * @uxon-property user_data_dir
     * @uxon-type string
     * 
     * WHY SEPARATE FROM $userDataDir: configuring a manager does not claim runtime ownership of a
     * profile; the owned directory is only recorded once Chrome was actually launched with it.
     */
    public function setUserDataDir(string $userDataDir): void
    {
        $this->configuredUserDataDir = $userDataDir;
    }

    /**
     * TCP port for the Chrome remote debugging API of this run.
     * 
     * This value is set by BDT at run time (a free port from the configured port band) - do not put it
     * into static configuration, or concurrent runs would collide on it.
     * 
     * @uxon-property port
     * @uxon-type integer
     * @uxon-default 9222
     * 
     * WHY int: Symfony/YAML configuration may deliver numeric strings, which PHP coerces here.
     */
    public function setPort(int $port): void
    {
        $this->configuredPort = $port;
    }

    /**
     * Set to `false` to show the Chrome window, e.g. while stepping through a test in a debugger.
     * 
     * If omitted, Chrome runs headless unless a PHP debugger (Xdebug) is attached.
     * 
     * @uxon-property headless
     * @uxon-type boolean
     * @uxon-default true
     * 
     * WHY EXPLICIT OVERRIDE: the Xdebug-based fallback guesses; operators must be able to decide.
     */
    public function setHeadless(bool $headless): void
    {
        $this->headless = $headless;
    }

    /**
     * Range of debugging ports for tests started interactively (from the UI), e.g. `9201-9251`.
     * 
     * Keep it disjoint from `port_band_scheduled` so a tester starting a run never collides with the
     * scheduled parallel fleet.
     * 
     * @uxon-property port_band_interactive
     * @uxon-type string
     * @uxon-default 9201-9251
     * 
     * WHY RETAINED HERE: the interactive band belongs to the local manager's configuration; RunTest
     * reads it through getPortBandInteractive() to pick the port.
     */
    public function setPortBandInteractive(string $portBand): void
    {
        $this->portBandInteractive = $portBand;
    }

    /**
     * Range of debugging ports for scheduled parallel runs, e.g. `9251-9300`.
     * 
     * Keep it disjoint from `port_band_interactive`.
     * 
     * @uxon-property port_band_scheduled
     * @uxon-type string
     * @uxon-default 9251-9300
     * 
     * WHY RETAINED HERE: the scheduled band belongs to the local manager's configuration; RunParallel
     * reads it through getPortBandScheduled() for its lanes.
     */
    public function setPortBandScheduled(string $portBand): void
    {
        $this->portBandScheduled = $portBand;
    }

    /**
     * Launches Chrome, reclaiming only a BDT-owned leftover if the selected port is occupied.
     *
     * Healthy runtime state is reused to prevent a second process tree on the same profile.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::doStart()
     */
    protected function doStart(): BrowserStartResult
    {
        $this->getLogbook()->addLine('LocalChromeManager::start() called');
        $this->getLogbook()->addIndent(+1);
        if ($this->hasActiveSession() && $this->isAlive()) {
            $this->getLogbook()->addLine('start(): a healthy Chrome is already running on port ' . $this->port . ' - reusing it');
            return new BrowserStartResult($this->port, $this->pid, 0.0);
        }

        $this->getWorkbench()->getLogger()->info('Using Chrome for BDT', [], $this->getLogbook());
        $startTime = microtime(true);
        $executable = $this->executable;
        if ($executable !== null && FilePathDataType::isRelative($executable)) {
            $executable = getcwd() . DIRECTORY_SEPARATOR . $executable;
        }
        $userDataDir = null;
        if ($this->configuredUserDataDir !== null) {
            $userDataDir = FilePathDataType::isRelative($this->configuredUserDataDir)
                ? getcwd() . DIRECTORY_SEPARATOR . $this->configuredUserDataDir
                : $this->configuredUserDataDir;
        }
        $port = $this->configuredPort ?? self::DEFAULT_PORT;
        $this->getLogbook()->addLine("Config resolved — executable: {$executable}, userDataDir: {$userDataDir}, port: {$port}");

        if ($executable === null || $userDataDir === null) {
            $msg = '**ERROR** Local Chrome requires "executable" and "user_data_dir". '
                . 'Please set "executable" in BROWSER.MANAGER_CONFIG and "user_data_dir" under DatabaseFormatterExtension > browser > config in your behat.yml.';
            $this->getLogbook()->addLine($msg);
            $this->getLogbook()->addIndent(-1);
            throw new ConfigException($msg, null, null);
        }
        $this->userDataDir = $userDataDir;
        $this->markStartBeenAttempted();

        $this->getLogbook()->addLine("Checking for an existing process on port {$port}...");
        $this->getLogbook()->addIndent(+1);
        $existingPid = $this->findPidByPort($port);
        if ($existingPid !== null) {
            $commandLine = $this->getProcessCommandLine($existingPid);
            if ($commandLine !== null && $this->isOwnLeftover($commandLine, $userDataDir)) {
                $this->getLogbook()->addLine("Found OUR leftover process PID {$existingPid} (command line matches our user_data_dir) — stopping it before launching a new instance");
                $this->pid = $existingPid;
                $this->stop();
                $this->getLogbook()->addLine("Waiting 500 ms for the old process to fully exit...");
                usleep(500_000);
            } elseif ($commandLine === null && $this->findPidByPort($port) === null) {
                $this->getLogbook()->addLine("Process PID {$existingPid} vanished before it could be inspected — port {$port} is free now");
            } else {
                $shownCommandLine = $commandLine === null ? '(command line not retrievable)' : $commandLine;
                $msg = "**ERROR** Port {$port} is occupied by a FOREIGN process (PID {$existingPid}): {$shownCommandLine}. "
                    . 'Refusing to kill it. This usually indicates a port-band collision between projects or runs — '
                    . 'check the port_band / port_band_interactive settings in bdt_parallel.yml and port_band_interactive / port_band_scheduled in BROWSER.MANAGER_CONFIG.';
                $this->getLogbook()->addLine($msg);
                $this->getLogbook()->addIndent(-1);
                $this->getLogbook()->addIndent(-1);
                throw new RuntimeException($msg);
            }
        } else {
            $this->getLogbook()->addLine("No existing process found on port {$port}");
        }
        $this->getLogbook()->addIndent(-1);

        $headless = $this->resolveHeadless();
        $arguments = [];
        if ($headless) {
            $arguments[] = '--headless';
            $arguments[] = '--no-sandbox';
        }
        $arguments[] = '--window-size=1920,1080';
        $arguments[] = '--disable-extensions';
        $arguments[] = '--disable-gpu';
        $arguments[] = '--disable-dev-shm-usage';
        $arguments[] = '--disable-background-networking';
        $arguments[] = '--disable-sync';
        $arguments[] = '--disable-component-update';
        $arguments[] = '--disable-default-apps';
        $arguments[] = '--log-level=3';
        $arguments[] = '--remote-debugging-port=' . $port;
        $arguments[] = '--remote-debugging-address=127.0.0.1';
        $arguments[] = '--hide-crash-restore-bubble';
        $arguments[] = '--no-first-run';
        $arguments[] = '--no-default-browser-check';
        $arguments[] = '--user-data-dir=' . $userDataDir;

        $headlessLabel = $headless ? 'headless' : 'visible';
        $commandLine = $this->getProcessHost()->launchDetached($executable, $arguments);
        $this->getLogbook()->addLine('Launching Chrome (' . $headlessLabel . ') with command: ' . $commandLine);
        $this->getLogbook()->addLine("Launch command returned — Chrome process spawned, waiting for debug API to become ready...");
        $this->waitUntilReady($port);

        $this->getLogbook()->addLine("Chrome is ready — looking up the port owner to resolve the PID...");
        $pid = $this->findPidByPort($port);
        $this->getLogbook()->addIndent(-1);
        $this->pid = $pid;
        $this->port = $port;

        $elapsedMs = round((microtime(true) - $startTime) * 1000, 1);
        $this->getLogbook()->addLine("Chrome started successfully — PID: {$pid}, port: {$port}, startup time: {$elapsedMs} ms");
        $this->getLogbook()->addIndent(-1);

        return new BrowserStartResult($port, $pid, $elapsedMs);
    }

    /**
     * Stops the owned process tree and verifies profile-based cleanup catches stale or child PIDs.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::stop()
     */
    public function stop(): void
    {
        $this->getLogbook()->addLine("LocalChromeManager::stop() called");
        $this->getLogbook()->addIndent(+1);
        if ($this->pid === null && $this->userDataDir === null) {
            $this->getLogbook()->addLine("No Chrome process is being managed — nothing to do");
            $this->getLogbook()->addIndent(-1);
            return;
        }

        if ($this->pid !== null) {
            $this->getLogbook()->addLine("Stopping Chrome process tree of PID {$this->pid}...");
            $this->getProcessHost()->killTree($this->pid);
        } else {
            $this->getLogbook()->addLine("No PID recorded — relying on the profile-dir sweep below");
        }

        if ($this->userDataDir !== null) {
            try {
                $chromeProcesses = $this->listChromeProcessCommandLines();
                if ($chromeProcesses === null) {
                    $this->getLogbook()->addLine(
                        '**WARNING** Could not enumerate Chrome processes - the profile verification sweep was '
                        . 'SKIPPED, not completed. A leftover browser may still be bound to ' . $this->userDataDir
                        . '. The next run\'s startup sweep will reclaim it.'
                    );
                } else {
                    $survivors = $this->reapChromeProfileDir($this->userDataDir, $chromeProcesses);
                    if ($survivors !== []) {
                        $this->getLogbook()->addLine(
                            'Profile sweep killed ' . count($survivors) . ' Chrome process(es) the PID kill missed: '
                            . implode(', ', $survivors)
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->getLogbook()->addLine(
                    '**WARNING** Profile-dir sweep failed: ' . get_class($e) . ' — ' . $e->getMessage()
                );
            }
        }

        $this->pid = null;
        $this->port = null;
        $this->getLogbook()->addLine("Process tree killed — PID and port state reset");
        $this->getLogbook()->addIndent(-1);
    }

    /**
     * Waits for Windows and Chrome to release process handles before the next local launch.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::waitBeforeRestart()
     */
    protected function waitBeforeRestart(): void
    {
        $this->getLogbook()->addLine("Sleeping 2 s to allow the OS to fully release the port...");
        sleep(2);
    }

    /**
     * Supplies the local PID required by fleet diagnostics and lane cleanup.
     *
     * {@inheritDoc}
     *
     * @see SupportsParallelLanesInterface::getPid()
     */
    public function getPid(): ?int
    {
        return $this->pid;
    }

    /**
     * Supplies the local debugging port required by lane-scoped behavior.
     *
     * {@inheritDoc}
     *
     * @see SupportsParallelLanesInterface::getPort()
     */
    public function getPort(): ?int
    {
        return $this->port;
    }

    /**
     * Uses the managed local port or configured default so drivers can connect before the first start.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getApiUrl()
     */
    public function getApiUrl(): string
    {
        return self::buildApiUrl($this->port ?? $this->configuredPort ?? self::DEFAULT_PORT);
    }

    /**
     * Builds the DevTools URL of a local Chrome on the given port - the single source of truth for it.
     *
     * WHY STATIC AND PUBLIC: RunTest and RunParallel write the Mink api_url into the generated Behat
     * configs before any manager instance exists for that port, while the manager itself needs the
     * same URL at run time. Two hand-built strings would drift apart. The literal 127.0.0.1 (not
     * "localhost") avoids the IPv6 lookup delay on Windows.
     *
     * @param int $port Remote debugging port
     * @return string e.g. http://127.0.0.1:9222
     */
    public static function buildApiUrl(int $port): string
    {
        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Supplies the interactive port band so RunTest searches the window the manager config defines.
     *
     * WHY HERE: the band is part of the local manager's configuration (BROWSER.MANAGER_CONFIG), and a
     * remote manager has no ports at all, so coordinators read it from the manager instead of from
     * app-config keys.
     *
     * {@inheritDoc}
     *
     * @see BrowserLaneConfigurationInterface::getPortBandInteractive()
     */
    public function getPortBandInteractive(): string
    {
        return $this->portBandInteractive ?? self::DEFAULT_PORT_BAND_INTERACTIVE;
    }

    /**
     * Supplies the scheduled port band so RunParallel allocates its lane ports from the configured window.
     *
     * {@inheritDoc}
     *
     * @see BrowserLaneConfigurationInterface::getPortBandScheduled()
     */
    public function getPortBandScheduled(): string
    {
        return $this->portBandScheduled ?? self::DEFAULT_PORT_BAND_SCHEDULED;
    }

    /**
     * Describes local visibility and port in startup and recovery diagnostics.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::describe()
     */
    public function describe(): string
    {
        return $this->describeForParallelLanes()
            . ', port ' . ($this->port ?? $this->configuredPort ?? self::DEFAULT_PORT);
    }

    /**
     * Describes the browser mode without a port because a coordinator has no lane of its own.
     *
     * {@inheritDoc}
     * @see BrowserLaneConfigurationInterface::describeForParallelLanes()
     */
    public function describeForParallelLanes(): string
    {
        return 'local Chrome, ' . ($this->resolveHeadless() ? 'headless' : 'visible');
    }

    /**
     * Tells a developer stepping through a test whether the browser can be watched.
     *
     * WHY BUILT FROM OWN STATE: the formatter used to infer this by matching words in describe(), which
     * broke silently whenever the description was reworded. The text is unchanged from that banner.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getDebugHint()
     */
    public function getDebugHint(): ?string
    {
        return $this->resolveHeadless()
            ? 'Chrome remains headless - set "headless": false in BROWSER.MANAGER_CONFIG to watch the browser'
            : 'Browser visible for stepping';
    }

    /**
     * Marks every local browser tab as owned because this manager owns the whole browser process.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::isTestOwnedTab()
     */
    public function isTestOwnedTab(string $targetId): bool
    {
        return true;
    }

    /**
     * Queries local Chrome for diagnostics and abandoned-tab cleanup.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::getTabList()
     */
    public function getTabList(): array
    {
        return $this->createDevToolsClient()->getTabList();
    }

    /**
     * Closes an abandoned local tab through Chrome's HTTP debugging endpoint.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::closeTab()
     */
    public function closeTab(string $targetId): void
    {
        $this->createDevToolsClient()->closeTab($targetId);
    }

    /**
     * Binds a DevTools client to the current local debugging port for every operation.
     */
    private function createDevToolsClient(): ChromeDevToolsHttpClient
    {
        return new ChromeDevToolsHttpClient($this->getApiUrl(), $this->getLogbook());
    }

    /**
     * Probes the local DevTools endpoint with the timeout selected by the shared health policy.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::probe()
     */
    protected function probe(float $timeoutSeconds): bool
    {
        return $this->createDevToolsClient()->probe($timeoutSeconds);
    }

    /**
     * Avoids probing loopback before a local port has been assigned by a successful launch.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::hasActiveSession()
     */
    public function hasActiveSession(): bool
    {
        return $this->port !== null;
    }

    /**
     * Waits for a navigable page so driver initialization does not race Chrome's CDP startup.
     */
    private function waitUntilReady(int $port, int $timeoutSeconds = 10): void
    {
        $this->getLogbook()->addLine("waitUntilReady(): polling http://127.0.0.1:{$port}/json/list (timeout: {$timeoutSeconds}s)...");
        $this->getLogbook()->addIndent(+1);
        $start = time();
        $attempt = 0;
        while (time() - $start < $timeoutSeconds) {
            $attempt++;
            $pages = (new ChromeDevToolsHttpClient('http://127.0.0.1:' . $port, $this->getLogbook()))->getTabList();
            if ($pages === []) {
                $this->getLogbook()->addLine("Attempt #{$attempt}: tab list empty or Chrome not yet reachable — retrying in 200 ms...");
            } else {
                $this->getLogbook()->addLine("Attempt #{$attempt}: received " . count($pages) . " tab(s)");
                $this->getLogbook()->addIndent(+1);
                foreach ($pages as $page) {
                    $type = $page['type'] ?? '(no type)';
                    $wsUrl = $page['webSocketDebuggerUrl'] ?? '';
                    $this->getLogbook()->addLine("tab type: {$type}, url: " . ($page['url'] ?? '(none)') . ", wsDebuggerUrl: " . ($wsUrl !== '' ? $wsUrl : '(empty)'));
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
     * Finds the listener PID while preserving startup-race diagnostics from the existing manager.
     */
    private function findPidByPort(int $port): ?int
    {
        $this->getLogbook()->addLine("findPidByPort({$port}): looking up the port owner...");
        $this->getLogbook()->addIndent(+1);
        $pid = $this->getProcessHost()->findPidListeningOn($port);
        $this->getLogbook()->addLine($pid === null
            ? "No LISTENING process found on port {$port}"
            : "Found LISTENING process with PID {$pid}");
        $this->getLogbook()->addIndent(-1);
        return $pid;
    }

    /**
     * Reads process identity needed to distinguish a BDT leftover from a foreign listener.
     */
    private function getProcessCommandLine(int $pid): ?string
    {
        $this->getLogbook()->addLine("getProcessCommandLine({$pid}): querying the process command line...");
        $processHost = $this->getProcessHost();
        $commandLine = $processHost->getCommandLine($pid);
        if ($commandLine === null) {
            $exitCode = $processHost->getLastCommandExitCode() ?? 'n/a';
            $this->getLogbook()->addLine("getProcessCommandLine({$pid}): no command line retrievable (exit {$exitCode})");
            return null;
        }
        $this->getLogbook()->addLine("getProcessCommandLine({$pid}): {$commandLine}");
        return $commandLine;
    }

    /**
     * Treats any profile below the BDT profiles root as ours because deployments change its absolute path.
     */
    private function isOwnLeftover(string $commandLine, string $userDataDirAbsolute): bool
    {
        $foreignDir = $this->extractUserDataDir($commandLine);
        if ($foreignDir === null) {
            return false;
        }
        $marker = $this->profilesRootMarker(dirname($userDataDirAbsolute));
        if ($marker === null) {
            return false;
        }
        return str_contains($this->normalizeProcessPath($foreignDir), $marker);
    }

    /**
     * Supplies the configured executable so coordinators and the shared reaper agree on the image name.
     *
     * WHY PUBLIC: RunTest and RunParallel no longer resolve the Chrome path themselves; they take it
     * from their coordinator manager so the image name they sweep always matches what lanes launch.
     *
     * {@inheritDoc}
     *
     * @see BrowserLaneConfigurationInterface::getExecutable()
     */
    public function getExecutable(): ?string
    {
        return $this->executable;
    }

    /**
     * Supplies the configured executable so the shared reaper can derive the image name to enumerate.
     *
     * @see ChromeProfileReaperTrait::getBrowserExecutable()
     */
    protected function getBrowserExecutable(): ?string
    {
        return $this->getExecutable();
    }

    /**
     * Applies an explicit visibility choice or retains the existing Xdebug fallback when it is absent.
     */
    private function resolveHeadless(): bool
    {
        if ($this->headless !== null) {
            return $this->headless;
        }
        return !(extension_loaded('xdebug')
            && function_exists('xdebug_is_debugger_active')
            && xdebug_is_debugger_active());
    }
}
