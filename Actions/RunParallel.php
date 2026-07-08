<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Behat\Common\PortProbingTrait;
use axenox\BDT\Behat\Common\RunRecordWriter;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Coordinator action for parallel BDT test execution.
 *
 * RATIONALE (Phase 3 scope):
 * This first version runs NO parallelism on purpose. It spawns exactly ONE worker and
 * waits for it synchronously. The goal is to prove the run lifecycle end to end - the
 * coordinator opens the run record, hands the UID to a worker through a generated lane
 * config, the worker writes its child rows in attach-mode, then the coordinator closes
 * the run - WITHOUT the added complexity of free-port discovery, throttling or watchdogs.
 * Those land in Phase 4, where "one worker" simply becomes "N workers".
 *
 * Why the coordinator owns the run-row lifecycle: in attach-mode the worker's
 * DatabaseFormatter binds to an existing run_uid and deliberately skips run creation,
 * expected-count computation and finalization. So the only process that touches the run
 * row itself is this coordinator - it creates it, writes the expected scope, and finalizes
 * it. The worker only inserts run_feature / run_scenario / run_step children under the UID.
 */
class RunParallel extends AbstractAction implements iCanBeCalledFromCLI
{
    // Port-band resolution and free-port probing shared with the interactive RunTest action,
    // so the two execution paths can never drift apart in how they allocate Chrome ports.
    use PortProbingTrait;

    // CLI option names - kept as constants so the option declarations in getCliOptions()
    // and the reads via getTaskParam() can never drift apart.
    private const OPT_TAGS         = 'tags';
    private const OPT_BEHAT_CONFIG = 'behat_config';
    private const OPT_FEATURE      = 'feature';
    private const OPT_CHROME_PATH  = 'chrome_path';
    private const OPT_SUITE        = 'suite';

    private const DEFAULT_TAGS = '@Status::Ready';
    // Base config filename defaulted to when --behat_config is omitted. Behat init (re)writes this at
    // the installation root, so it is always present and current by the time we resolve it.
    private const DEFAULT_BEHAT_CONFIG = 'behat.yml';

    // Wall-clock ceiling per worker, used as the FALLBACK when PARALLEL.WORKER_TIMEOUT_SECONDS
    // is not set in app config. We never use runCliCommand's 60s default - a Behat run needs far
    // longer. Symfony Process enforces this per worker and throws on exceedance; that throw is
    // caught per-lane in the drain phase, so a hung worker is a recorded failure, not a stall.
    private const WORKER_TIMEOUT_SECONDS = 1800;

    // App-config keys for the parallel orchestration layer. Kept as constants so the reads in
    // resolvePortBand()/resolveMaxWorkers()/resolveWorkerTimeout() can never drift from config.
    private const CFG_PORT_BAND  = 'PARALLEL.PORT_BAND_SCHEDULED';
    private const CFG_MAX_WORKERS = 'PARALLEL.MAX_WORKERS';
    private const CFG_WORKER_TIMEOUT = 'PARALLEL.WORKER_TIMEOUT_SECONDS';

    // App-config key deciding Chrome window visibility. MUST match ChromeManager::CFG_CHROME_HEADLESS
    // so the banner reports exactly what the workers' ChromeManager will resolve at launch time.
    private const CFG_CHROME_HEADLESS = 'PARALLEL.CHROME_HEADLESS';
    
    // App-config home for the REAL chrome.exe path. Separate from the base behat.yml chrome.executable
    // on purpose: that one points at GoogleChromePortable.exe (single-instance lock), which workers must
    // NOT use. The fleet needs a direct chrome.exe, so it gets its own key.
    private const CFG_CHROME_PATH = 'PARALLEL.CHROME_PATH';  // NEW

    // Per-project bdt_parallel.yml key for the SCHEDULED band. The override file itself (name,
    // location, rationale) is owned by PortProbingTrait, shared with the interactive RunTest
    // action - only the key differs per execution path.
    private const OVERRIDE_KEY_SCHEDULED = 'port_band';

    private ?DataSheetInterface $runDataSheet = null;

    // Poll cadence for the concurrent drain loop. 100 ms is small enough that lane output is streamed
    // to its log near-live, but large enough that the busy-wait costs negligible CPU while N workers
    // run for minutes.
    private const DRAIN_POLL_MICROSECONDS = 100000;

    // Environment overrides applied to every worker process. The key one is XDEBUG_MODE=off: it
    // disables the Xdebug debugger in the worker regardless of the inherited xdebug.mode/trigger, so
    // workers never connect back to the IDE's single debug client (port 9003). Without this, a
    // coordinator launched under a debugger makes every worker inherit the Xdebug trigger; the IDE
    // serializes those debug sessions, blocking the 3rd/4th worker at startup and capping real
    // concurrency at ~2. XDEBUG_SESSION/XDEBUG_TRIGGER are set to false so Symfony Process REMOVES
    // them from the inherited environment, neutralizing any trigger that XDEBUG_MODE alone might miss.
    // All other parent env vars (PATH, etc.) are inherited unchanged.
    private const WORKER_ENV = [
        'XDEBUG_MODE'    => 'off',
        'XDEBUG_SESSION' => false,
        'XDEBUG_TRIGGER' => false,
    ];

    /**
     * Captured at run-row creation so the finalize step can compute the same wall-clock
     * duration the single-process formatter computes (microtime delta, in seconds - the
     * duration_ms column historically stores seconds in this codebase).
     */
    private float $runStart = 0.0;

    /**
     * The human-readable startup banner (worker count, headless state, debugger state), built once
     * before the fleet launches and reused in the final CLI message so the same expectation the user
     * saw at the start is echoed back at the end.
     */
    private string $startupBanner = '';

    /**
     * Entry point. Drives the run lifecycle once, fanning the matched features out to N workers.
     *
     * The Phase 3 lifecycle is preserved verbatim - create run -> compute expected scope over
     * ALL features -> finalize exactly once. The only Phase 4 change is additive: instead of one
     * worker, we split the matched features into buckets and run a fleet concurrently. The
     * coordinator stays the sole writer of the run row; every worker attaches to the same UID.
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        // --- Read inputs. Only --tags carries a real default here; the path/scope inputs are read as
        // nullable and resolved AFTER init from their authoritative sources, so the common case is a bare
        // `RunParallel --tags=...`. ---
        $tags           = $this->getOptionalTaskParam($task, self::OPT_TAGS);
        $behatConfigArg = $this->getOptionalTaskParam($task, self::OPT_BEHAT_CONFIG);
        $chromePathArg  = $this->getOptionalTaskParam($task, self::OPT_CHROME_PATH);
        $featureArg     = $this->getOptionalTaskParam($task, self::OPT_FEATURE);
        $suiteArg       = $this->getOptionalTaskParam($task, self::OPT_SUITE);

        // At least one scope selector must be present. tags no longer has a baked-in default, so a bare
        // invocation with no --tags, --feature or --suite would run the ENTIRE test base with no filter -
        // the "looks green but ran the wrong thing" footgun this framework exists to prevent. behat_config
        // and chrome_path are intentionally excluded: they are infrastructure with their own resolution and
        // loud validation, not run scope.
        if ($tags === null && $featureArg === null && $suiteArg === null) {
            throw new RuntimeException('Provide at least one of --tags, --feature or --suite; refusing to run the whole test base unscoped.');
        }
        $cwd = $this->getWorkbench()->getInstallationPath();

        // --- Step 0: prepare the environment exactly like a single Behat run does. Init runs FIRST so the
        // installation-root behat.yml exists and is current before we default to it below; a stale base_url
        // would otherwise break every worker. ---
        $this->runInit($cwd);

        // --- Resolve the deferred inputs from their authoritative sources. Each validates and fails loudly,
        // so defaulting never degrades into a silently-wrong path (never-guess is preserved). ---
        $behatConfig = $this->resolveBehatConfig($behatConfigArg, $cwd);
        $chromePath  = $this->resolveChromePath($chromePathArg);
        $scanRoots   = $this->resolveScanRoots($behatConfig, $featureArg, $suiteArg);

        $runRecordWriter = new RunRecordWriter();
        // --- Step 1: open the run record (sole creator, so the workers can attach to its UID) ---
        // behat_command records WHAT this run executed. We store the coordinator's own resolved invocation
        // (action + scope selectors), NOT the tag string: passing $tags mislabels the column, and a parallel
        // run has no single behat command anyway - it fans out to N lane commands. The reconstructed action
        // command is the one reproducible truth for the whole run.
        $behatCommand = $this->describeInvocation($tags, $featureArg, $suiteArg);
        // --- Step 1: open the run record (sole creator, so the workers can attach to its UID) ---
        $this->runDataSheet = $runRecordWriter->create($this->getWorkbench(), $behatCommand);
        $this->runStart = microtime(true);
        $runUid = $this->runDataSheet->getUidColumn()->getValue(0);

        try {
            // --- Step 2: compute the full expected scope up front over ALL matched features ---
            // Done here, not in the workers, because attach-mode workers skip this, and because
            // the expected totals must reflect ALL matched features even if a worker dies partway
            // through (otherwise silent-stop detection is impossible).
            $expected = (new \axenox\BDT\Behat\Common\ExpectedTestCountCalculator())
                ->calculate($scanRoots, $tags);

            // A broken feature file aborts the whole Behat run at parse time, so surface the
            // offenders now rather than letting a worker crash opaquely with exit code 255.
            if ($expected->hasErrors()) {
                throw new RuntimeException(
                    'Feature files failed to parse: ' . implode('; ', array_keys($expected->errors))
                );
            }

            // --- Step 3: persist the expected counts onto the run row (once, for the whole run) ---
            $runRecordWriter->setExpectedCounts($this->runDataSheet, $expected->featureCount, $expected->scenarioCount);

            // --- Step 4: decide the fleet size. NO user pool exists - users are provisioned per
            // role at run-time by UI5Browser::setupUser(), so the only ceiling is "do not start
            // more workers than there are features to test". ---
            $matchedFiles = $expected->matchedFiles;
            // An empty scope must not fall through to a single worker with an empty bucket: buildWorkerCommand()
            // with no positional paths makes Behat run the ENTIRE suite, contradicting an expected count of 0 and
            // producing a wildly wrong run. Finalize cleanly and report instead.
            if ($matchedFiles === []) {
                $runRecordWriter->finalize($this->runDataSheet);
                return ResultFactory::createMessageResult(
                    $task,
                    sprintf('Parallel run %s: no feature files matched the requested scope/tags. Nothing to run.', $runUid)
                );
            }
            $maxWorkers   = $this->resolveMaxWorkers();
            $workerCount  = max(1, min($maxWorkers, count($matchedFiles)));
            $buckets      = $this->bucketFeatures($matchedFiles, $workerCount);

            // --- Step 4b: announce the resolved run configuration BEFORE any worker starts, so the
            // user knows what to expect (how many workers will run, whether Chrome will be visible,
            // and whether a debugger is in play). Purely informational - it changes no behaviour. ---
            $banner = $this->buildStartupBanner($workerCount, $maxWorkers, count($matchedFiles));
            $this->startupBanner = $banner;
            $this->getWorkbench()->getLogger()->info($banner);

            // --- Step 5: run the fleet. Wrapped so a worker failure still reaches finalize. ---
            $failures = $this->runFleet($cwd, $behatConfig, $runUid, $chromePath, $tags, $buckets, $banner);

        } catch (\Throwable $e) {
            // Any coordinator-side failure must still close the run row, otherwise it stays open
            // forever with no finished_on. Finalize, then re-throw so the CLI sees it.
            $runRecordWriter->finalize($this->runDataSheet);
            throw new RuntimeException('Parallel run coordinator failed: ' . $e->getMessage(), null, $e);
        } finally {
            // Reclaim this run's Chrome fleet and profile dirs on EVERY exit path (success, coordinator
            // failure, or the empty-scope early return). Kill BEFORE delete: a live Chrome holds open
            // handles inside its profile dir, so on Windows the dir cannot be removed until it is gone.
            // Both steps are run-scoped and best-effort, so they can never disturb a concurrent run nor
            // turn a finished run into a failed one. On the early-return/no-work paths nothing was
            // launched or created, so both calls are harmless no-ops.
            $this->killRunChromeProcesses($runUid);
            $this->purgeRunProfileDirs($cwd, $runUid);
        }

        // --- Finalize exactly once, after all workers exit. Child-row outcomes already live in
        // the DB, written by the workers in attach-mode; we only stamp the run as finished. ---
        $runRecordWriter->finalize($this->runDataSheet);

        // Lane output now lives in one file per lane: data/axenox/BDT/Logs/<run_uid>_lane<N>.log.
        // A lane failure here means the WORKER ITSELF failed (crash, signal/timeout termination or a
        // launch failure) - NOT that some of its tests failed. Behat's exit 1 is treated as a normal
        // completion because per-scenario pass/fail is recorded authoritatively in the attach-mode
        // child rows. When a worker fails fatally, the whole run must fail so a scheduled task/queue
        // marks it red. We therefore THROW when $failures is non-empty - AFTER finalize above, so the
        // run row is always closed. The exception message lists each failing lane and points at its
        // log. If no worker failed fatally, we return a terse success message referencing the log
        // directory (individual test failures are still visible in the child rows).
        $logDirRel = 'data/axenox/BDT/Logs';
        if (! empty($failures)) {
            $lines = [];
            foreach ($failures as $lane => $err) {
                $lines[] = 'Lane ' . $lane . ' (' . $logDirRel . '/' . $runUid . '_lane' . $lane . '.log): ' . $err;
            }
            throw new RuntimeException(
                $this->startupBanner . "\n"
                . sprintf('Parallel run %s finished with %d worker error(s):', $runUid, count($failures))
                . "\n" . implode("\n", $lines)
                . "\nLane logs: " . $logDirRel . '/' . $runUid . '_lane*.log'
            );
        }

        $msg = $this->startupBanner . "\n"
            . sprintf('Parallel run %s finished, no worker errors. Lane logs: %s/%s_lane*.log', $runUid, $logDirRel, $runUid);
        return ResultFactory::createMessageResult($task, $msg);
    }

    /**
     * No positional CLI arguments - all inputs are passed as named options (see getCliOptions()).
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments(): array
    {
        return [];
    }

    /**
     * Declares the named CLI options so the ConsoleFacade accepts them.
     *
     * Without this declaration Symfony Console rejects unknown options (e.g. "--tags option
     * does not exist"). Only "tags" has a default (it is optional); the three path options are
     * required because we never guess paths - a missing path must fail loudly.
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::OPT_TAGS)
                ->setDescription('Behat tag filter, e.g. "@Status::Ready". Optional - but at least one of --tags, --feature or --suite is required.')
                ->setDefaultValue(null),
            (new ServiceParameter($this))
                ->setName(self::OPT_BEHAT_CONFIG)
                ->setDescription('Base behat.yml the lanes import. Optional - defaults to the installation-root behat.yml refreshed by Behat init.')
                ->setDefaultValue(null),
            (new ServiceParameter($this))
                ->setName(self::OPT_CHROME_PATH)
                ->setDescription('Path to chrome.exe (NOT GoogleChromePortable.exe). Optional - defaults to app config ' . self::CFG_CHROME_PATH . '.')
                ->setDefaultValue(null),
            (new ServiceParameter($this))
                ->setName(self::OPT_FEATURE)
                ->setDescription('Restrict the run to a single feature file or directory. Optional - defaults to all suites in behat.yml. Mutually exclusive with --suite.')
                ->setDefaultValue(null),
            (new ServiceParameter($this))
                ->setName(self::OPT_SUITE)
                ->setDescription('Restrict the run to a named Behat suite. Optional. Mutually exclusive with --feature.')
                ->setDefaultValue(null),
        ];
    }

    /**
     * Writes the single lane config next to the base behat.yml and returns its path.
     *
     * Why imports the base instead of duplicating it: the lane sits in the same directory as
     * the base config, so "imports: [behat.yml]" resolves relatively and %paths.base% stays
     * the same as a normal run. The lane only ADDS the per-worker overrides (chrome port +
     * isolated user_data_dir + chrome.exe path) plus the run_uid binding for attach-mode.
     *
     * NOTE: the run_uid placement below must match your CURRENT DatabaseFormatterExtension
     * config schema. The extension snapshot in this repo predates attach-mode and only
     * declares chrome.{port,executable,user_data_dir}; align the run_uid node with wherever
     * your live extension reads it (top-level scalar shown here).
     */
    private function writeLaneConfig(
        string $workingDir,
        int $lane,
        string $runUid,
        int $port,
        string $chromePath,
        string $importConfigName = 'behat.yml'
    ): string {
        // Per-lane unique identity passed DOWN to the worker. Two workers may run scenarios that
        // resolve to the SAME role; setupUser() writes a shared USER_AUTHENTICATOR row, so without
        // a per-lane suffix concurrent workers sharing a role collide on optimistic locking. We
        // only GENERATE and PASS this lane_id - setupUser namespaces the provisioned user with it.
        $laneId = $runUid . '_lane' . $lane;
        // Per-run, per-lane profile dir. RATIONALE: the name is prefixed with the run UID so no two
        // runs ever share a profile directory. A fixed "laneN" was reused across runs, and because the
        // scheduled fleet runs as NT AUTHORITY\SYSTEM while interactive/web runs run as a different
        // account (e.g. SDREXF2\wampuser), a later run would open a laneN profile that an earlier run of
        // a DIFFERENT Windows account had created. Chrome then could not decrypt that profile's
        // DPAPI-protected state (encrypted under the other account's key) and could not acquire the
        // per-profile ProcessSingleton lock (Windows sharing violation, error 32), so it aborted on
        // launch and every login failed. A run-scoped directory guarantees each launch gets a clean
        // profile created and owned by the account actually running the fleet.
        //
        // IMPORTANT: still RELATIVE - ChromeManager::start() prepends getcwd(). An absolute path would be
        // double-prepended and make Chrome fall back to the real default profile.
        $userDataDirRelative = 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'BDT' . DIRECTORY_SEPARATOR
            . 'chrome_profiles' . DIRECTORY_SEPARATOR . $laneId;
        $userDataDirAbsolute = $workingDir . DIRECTORY_SEPARATOR . $userDataDirRelative;
        if (! is_dir($userDataDirAbsolute) && ! @mkdir($userDataDirAbsolute, 0755, true) && ! is_dir($userDataDirAbsolute)) {
            throw new RuntimeException('Could not create lane user_data_dir: ' . $userDataDirAbsolute);
        }

        // Lane file uses a per-lane name and is overwritten each run - durable truth lives in
        // the DB, so we deliberately do not accumulate lane files.
        $laneConfigPath = $workingDir . DIRECTORY_SEPARATOR . 'behat_scheduled_lane' . $lane . '.yml';

        $extensionFqn = \axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension::class;

        $yaml = "# AUTO-GENERATED lane config - overwritten every run. Do not edit by hand.\n"
            . "imports:\n"
            . "  - " . $importConfigName . "\n"
            . "default:\n"
            . "  extensions:\n"
            . "    Behat\\MinkExtension:\n"
            . "      sessions:\n"
            . "        CHROME_DEBUG_API:\n"
            . "          chrome:\n"
            . "            api_url: 'http://localhost:" . $port . "'\n"
            . "    \\" . $extensionFqn . ":\n"
            . "      run_uid: '" . $runUid . "'\n"
            . "      lane_id: '" . $laneId . "'\n"
            . "      chrome:\n"
            . "        port: " . $port . "\n"
            . "        executable: '" . $this->yamlEscapeWindowsPath($chromePath) . "'\n"
            . "        user_data_dir: '" . $this->yamlEscapeWindowsPath($userDataDirRelative) . "'\n";

        if (file_put_contents($laneConfigPath, $yaml) === false) {
            throw new RuntimeException('Failed to write lane config: ' . $laneConfigPath);
        }
        return $laneConfigPath;
    }

    /**
     * Doubles backslashes for safe single-quoted YAML on Windows paths.
     *
     * Why: Windows paths contain backslashes; inside single-quoted YAML a literal backslash is
     * fine, but doubling avoids any ambiguity if the file is ever re-parsed by a stricter
     * loader, and keeps the generated file readable.
     */
    private function yamlEscapeWindowsPath(string $path): string
    {
        return str_replace('\\', '\\\\', $path);
    }

    /**
     * Resolves the maximum worker count from app config.
     *
     * Why config-driven: the sensible parallelism level depends on the host (CPU/RAM, number of
     * Chrome instances it can host). Keeping it in app config lets ops tune it without code
     * changes. There is deliberately NO user-pool ceiling - users are provisioned per role at
     * run-time, so the real cap is simply this value vs. the number of features.
     */
    private function resolveMaxWorkers(): int
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        $max = (int) $cfg->getOption(self::CFG_MAX_WORKERS);
        if ($max < 1) {
            throw new RuntimeException(self::CFG_MAX_WORKERS . ' must be >= 1, got ' . $max);
        }
        return $max;
    }

    /**
     * Builds the human-readable startup banner that sets the user's expectation before any worker
     * starts: how many workers will actually run (and why, vs. the configured maximum), whether
     * Chrome will be visible or headless, and whether the coordinator itself is running under a
     * debugger.
     *
     * The banner is purely informational - it changes no behaviour. Its whole job is to make an
     * unattended-looking run self-explanatory: a user who launched N=4 but sees "1 worker" instantly
     * knows there was only one matched feature, and a user debugging locally is reminded that fleet
     * workers ALWAYS run with the debugger off and (by default) headless, so they should use the
     * non-parallel single-worker path to actually step through a browser.
     *
     * @param int $workerCount    The fleet size that will actually run
     * @param int $maxWorkers      The configured PARALLEL.MAX_WORKERS ceiling
     * @param int $matchedFeatures The number of feature files the tag filter matched
     */
    private function buildStartupBanner(int $workerCount, int $maxWorkers, int $matchedFeatures): string
    {
        $debuggerActive = $this->isDebuggerActive();
        $headless = $this->resolveHeadlessForBanner();

        // Explain WHY the fleet is this size, since worker count is min(maxWorkers, matchedFeatures).
        if ($workerCount < $maxWorkers) {
            $reason = 'capped by ' . $matchedFeatures . ' matched feature(s)';
        } else {
            $reason = 'limited by ' . self::CFG_MAX_WORKERS . '=' . $maxWorkers;
        }

        // Headless may be undecided at coordinator level (no app-config flag) - then the workers'
        // ChromeManager falls back to Xdebug auto-detection, and since fleet workers run with the
        // debugger forced off, that fallback is always headless.
        if ($headless === null) {
            $chromeLine = 'headless (auto: ' . self::CFG_CHROME_HEADLESS . ' not set, workers run debugger-off)';
        } else {
            $chromeLine = $headless ? 'headless (' . self::CFG_CHROME_HEADLESS . '=true)' : 'visible (' . self::CFG_CHROME_HEADLESS . '=false)';
        }

        $lines = [
            '===== BDT parallel run configuration =====',
            'Workers:  ' . $workerCount . ' (' . $reason . ')',
            'Chrome:   ' . $chromeLine,
        ];
        if ($debuggerActive) {
            $lines[] = 'Debugger: ATTACHED to the coordinator - NOTE: fleet workers force the debugger OFF, '
                . 'so breakpoints do NOT hit inside tests. Use the non-parallel single-worker path to step through a browser.';
        } else {
            $lines[] = 'Debugger: not attached';
        }
        $lines[] = '==========================================';

        return implode("\n", $lines);
    }

    /**
     * Returns TRUE if an Xdebug debugger session is currently active on the coordinator process.
     *
     * Mirrors the detection ChromeManager uses, so the banner's debugger note stays consistent with
     * the actual headless fallback behaviour. Guarded for older Xdebug builds that may not expose
     * xdebug_is_debugger_active().
     */
    private function isDebuggerActive(): bool
    {
        return extension_loaded('xdebug')
            && function_exists('xdebug_is_debugger_active')
            && xdebug_is_debugger_active();
    }

    /**
     * Resolves the headless state the way the banner needs to report it: the explicit app-config flag
     * PARALLEL.CHROME_HEADLESS when present (true/false), or NULL when it is absent.
     *
     * NULL is meaningful: it tells the banner that no operator decision exists, so the workers'
     * ChromeManager will fall back to Xdebug auto-detection - which, because fleet workers run with the
     * debugger disabled, always resolves to headless. Kept separate from ChromeManager::resolveHeadless()
     * on purpose: this reports intent for the whole fleet, that one decides an individual worker's window.
     *
     * @return bool|null TRUE = headless, FALSE = visible, NULL = not configured (auto -> headless for workers)
     */
    private function resolveHeadlessForBanner(): ?bool
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        if ($cfg->hasOption(self::CFG_CHROME_HEADLESS)) {
            return (bool) $cfg->getOption(self::CFG_CHROME_HEADLESS);
        }
        return null;
    }

    /**
     * Splits matched feature files into N disjoint buckets covering ALL of them.
     *
     * Round-robin so file size/scenario count is spread roughly evenly across lanes instead of
     * front-loading the first worker. Together the buckets cover every matched feature exactly
     * once, so the expected counts (computed over all features) stay consistent with what runs.
     *
     * @param string[] $files
     * @return string[][]
     */
    private function bucketFeatures(array $files, int $workerCount): array
    {
        $buckets = array_fill(0, $workerCount, []);
        foreach (array_values($files) as $i => $file) {
            $buckets[$i % $workerCount][] = $file;
        }
        return $buckets;
    }

    /**
     * Builds the Behat worker command: lane config + optional tag filter, with the bucket as positional
     * args.
     *
     * Positional feature paths go OUTSIDE --config so Behat runs exactly that bucket. The lane config
     * carries run_uid + lane_id + Chrome port, so configuration flows only through the generated YAML -
     * no BEHAT_PARAMS overrides.
     *
     * Why --tags is conditional: when scope came from --feature/--suite with no tag filter, we OMIT
     * --tags rather than pass --tags="". An empty tag expression is not "no filter" to Behat, and it
     * would diverge from ExpectedTestCountCalculator (which counts ALL scenarios when the tag expression
     * is empty), breaking expected==actual.
     *
     * @param string[] $bucket
     */
    private function buildWorkerCommand(string $laneConfigPath, ?string $tags, array $bucket): string
    {
        $cmd = sprintf('vendor\\bin\\behat --config "%s"', $laneConfigPath);
        if ($tags !== null && trim($tags) !== '') {
            $cmd .= sprintf(' --tags="%s"', $tags);
        }
        foreach ($bucket as $feature) {
            $cmd .= ' "' . $feature . '"';
        }
        return $cmd;
    }

    /**
     * Runs the standard Behat "init" once before the fleet, mirroring a single-process run.
     *
     * A normal run is always preceded by `Behat init`, which recreates the global behat.yml,
     * registers app suites and refreshes base_url to the live workbench URL. Lanes never init
     * themselves, so without this a stale base_url breaks every worker. We reuse the existing
     * action verbatim through the CLI runner (blocking, single shot) instead of duplicating its
     * logic, so the parallel path stays equivalent to the sequential one. silent=false so a real
     * init failure throws and the coordinator finalizes/aborts instead of running on a bad config.
     */
    private function runInit(string $cwd): void
    {
        $output = '';
        foreach (CliCommandRunner::runCliCommand('vendor\\bin\\action axenox.BDT:Behat init', [], 300.0, $cwd, false) as $chunk) {
            $output .= $chunk; // drain so init completes before workers start; output is informational
        }
        $this->getWorkbench()->getLogger()->info('BDT parallel: Behat init done');
    }

    /**
     * Resolves the per-worker wall-clock timeout from app config, falling back to the constant.
     *
     * Why never the runCliCommand 60s default: a Behat lane runs minutes, not seconds. Symfony
     * Process enforces this timeout per worker and throws on exceedance; that throw is caught in
     * the per-lane drain, so a hung worker is recorded as a failure without blocking the others.
     */
    private function resolveWorkerTimeout(): float
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        return (float) ($cfg->getOption(self::CFG_WORKER_TIMEOUT) ?: self::WORKER_TIMEOUT_SECONDS);
    }

    /**
     * Launches the whole fleet concurrently, then drains every lane in parallel.
     *
     * The verified runtime is CLI, so we drive Symfony Process directly here instead of through
     * CliCommandRunner's generator: a generator can only be drained with a blocking foreach, which
     * serializes the fleet (lane N+1 is not even read until lane N's process exits). That blocking
     * sequential drain - not workerCount, not the port band, not Process::start() - was the measured
     * cap that pinned real concurrency to a 2-wide pipeline. Instead we:
     *   Phase A - start every worker Process (start() is eager + non-blocking), so all lanes run at once.
     *   Phase B - poll ALL still-running processes in a round-robin loop, reading each one's
     *             incremental output non-blockingly and streaming it to that lane's log. No lane can
     *             block another, so wall-clock is ~max(lane) and true N-wide concurrency is achieved.
     * Exit-code classification: a lane fails only when the WORKER ITSELF fails - a crash (exit 2/255)
     * or a signal/timeout termination (null exit code). Behat's exit 1 ("some tests failed") is NOT a
     * lane failure, because authoritative per-scenario results live in the attach-mode child rows; a
     * worker that ran to completion did its job even if some of its tests failed. A lane failure is
     * recorded WITHOUT aborting the others, so finalize still runs once; the caller then throws if
     * $failures is non-empty so the task fails.
     *
     * @param string[][] $buckets
     * @param string $banner Startup banner echoed into the coordinator log so the run's log opens with
     *                       the same expectation the user saw on the console
     * @return array<int,string> Worker failures keyed by lane; non-empty means the run must fail
     * @throws \Throwable
     */
    private function runFleet(string $cwd, string $behatConfig, string $runUid, string $chromePath, ?string $tags, array $buckets, string $banner = ''): array
    {
        [$portStart, $portEnd] = $this->resolvePortBand($behatConfig, self::OVERRIDE_KEY_SCHEDULED, self::CFG_PORT_BAND);
        $timeout = $this->resolveWorkerTimeout();
        $heldPorts = [];
        // Import the base config by its real filename so the lane import matches even on
        // case-sensitive systems instead of assuming "behat.yml".
        $importConfigName = basename($behatConfig);

        // Coordinator-level diagnostic log. The fleet diagnostics (launch/drain timings used to
        // localize the 2-worker concurrency cap) belong in OUR OWN log file, not in the workbench
        // logger: the DB-backed log only keeps a couple of info rows and is the wrong place for
        // high-frequency orchestration traces. One coordinator log per run sits next to the per-lane
        // logs so the whole fleet's timeline is greppable in a single file.
        $logDir = $this->ensureLogDir($cwd);
        $diagLog = $this->openCoordinatorLog($logDir, $runUid);
        $this->writeRunLog($diagLog, '===== Coordinator DIAG (run ' . $runUid . ') =====');
        if ($banner !== '') {
            $this->writeRunLog($diagLog, $banner);
        }

        // Phase A - launch: start every worker Process up front. Process::start() is eager and
        // non-blocking (the diagnostic run confirmed all 4 lanes spawn within ~1.2 s), so this gets
        // the whole fleet running concurrently. A launch-side failure (port band exhausted, lane
        // config unwritable) must NOT escape runFleet: doing so would hit the coordinator's catch and
        // finalize the run while workers already launched are still writing child rows. Instead we
        // record it as that lane's failure, stop launching further lanes, and fall through to drain
        // whatever DID start. Buckets that never launch simply produce no child rows, so the run's
        // child count falls short of expected_scenario_count - the silent-stop signal this framework
        // exists to raise - instead of being masked by a premature finalize.
        $processes = [];   // lane => Process
        $laneLogs  = [];   // lane => log file handle (opened in Phase A so output streams live)
        $laneStart = [];   // lane => microtime when the worker started
        $failures  = [];
        $launchStartWall = microtime(true);
        foreach ($buckets as $idx => $bucket) {
            $lane = $idx + 1;
            try {
                $port = $this->allocateFreePort($portStart, $portEnd, $heldPorts);
                $heldPorts[] = $port;
                $laneConfig = $this->writeLaneConfig($cwd, $lane, $runUid, $port, $chromePath, $importConfigName);
                $cmd = $this->buildWorkerCommand($laneConfig, $tags, $bucket);

                // Open the lane log BEFORE start() so a failure to open it cannot leave an orphan
                // running worker with nowhere to stream its output.
                $laneLog = $this->openLaneLog($logDir, $runUid, $lane);
                $this->writeRunLog($laneLog, '===== Lane ' . $lane . ' (port ' . $port . ') =====');

                // Drive Symfony Process directly so Phase B can poll all lanes concurrently. The
                // worker environment is the parent environment with the Xdebug DEBUGGER forced OFF
                // (see WORKER_ENV): the coordinator is often launched under an IDE debugger, so its
                // env carries an Xdebug trigger/session. Inherited unchanged, every worker would
                // connect back to the single IDE debug client (port 9003) on startup; that client
                // services only a couple of sessions at once, so the 3rd/4th worker blocks - silent,
                // producing no output - until an earlier worker exits and frees a debug slot. THAT is
                // the real "cap of 2". Disabling the debugger per worker restores true N-wide
                // concurrency. Headless itself is now decided by the worker's ChromeManager from
                // app-config PARALLEL.CHROME_HEADLESS (with the debugger off, its Xdebug fallback is
                // headless too). To step through a test, use the non-parallel single-worker path.
                $callStart = microtime(true);
                $process = Process::fromShellCommandline($cmd, $cwd, self::WORKER_ENV, null, $timeout);
                $process->start();
                $callMs = (microtime(true) - $callStart) * 1000;

                $processes[$lane] = $process;
                $laneLogs[$lane]  = $laneLog;
                $laneStart[$lane] = microtime(true);

                $this->writeRunLog($diagLog, sprintf(
                    'DIAG launch: lane %d port %d - Process::start() returned in %.1f ms (%.1f ms since first launch)',
                    $lane,
                    $port,
                    $callMs,
                    (microtime(true) - $launchStartWall) * 1000
                ));
            } catch (\Throwable $e) {
                // Stop launching further lanes: an exhausted band stays exhausted and a setup error
                // (e.g. unwritable config) is likely systemic. Record and break to the drain phase so
                // the already-running workers are awaited and the run finalizes cleanly afterwards.
                $failures[$lane] = 'launch failed: ' . $e->getMessage();
                $this->writeRunLog($diagLog, 'DIAG launch: lane ' . $lane . ' FAILED: ' . $e->getMessage());
                $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' launch failed: ' . $e->getMessage());
                break;
            }
        }

        // Phase B - concurrent drain: poll EVERY still-running worker each pass, reading its
        // incremental output non-blockingly and streaming it to that lane's log. Because no single
        // foreach blocks on one lane, all workers progress together - this is the fix for the 2-wide
        // cap, where the old blocking generator drain read lane 1 to completion before even touching
        // lane 2. Per-lane failures (non-ignored exit code, timeout, signal) are recorded WITHOUT
        // aborting the others; finalize still runs once afterwards.
        $active = $processes;
        $firstOutputAt = [];
        while (! empty($active)) {
            foreach ($active as $lane => $process) {
                // Stream whatever new output arrived since the last pass.
                $wrote = $this->streamLaneOutput($process, $laneLogs[$lane]);
                if ($wrote && ! isset($firstOutputAt[$lane])) {
                    $firstOutputAt[$lane] = true;
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: lane %d - first output at +%.1f s into run',
                        $lane,
                        microtime(true) - $this->runStart
                    ));
                }

                // Enforce the per-worker wall-clock timeout. In async mode Symfony only checks the
                // timeout when we ask it to, so a hung worker would otherwise never time out.
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException $e) {
                    $failures[$lane] = 'timed out after ' . $timeout . ' s';
                    $this->writeRunLog($laneLogs[$lane], 'LANE ' . $lane . ' TIMED OUT after ' . $timeout . ' s');
                    $this->writeRunLog($diagLog, sprintf('DIAG drain: lane %d TIMED OUT at +%.1f s', $lane, microtime(true) - $this->runStart));
                    $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' timed out after ' . $timeout . ' s');
                    $process->stop(0); // SIGKILL-equivalent; release the slot immediately
                    $this->finishLaneLog($laneLogs[$lane]);
                    unset($active[$lane]);
                    continue;
                }

                if (! $process->isRunning()) {
                    // Flush the tail that arrived after the last read, then classify the exit.
                    $this->streamLaneOutput($process, $laneLogs[$lane]);
                    $exitCode = $process->getExitCode(); // null if the worker was terminated by a signal
                    $durationS = microtime(true) - $laneStart[$lane];
                    // Only the worker's OWN fatal failure is a lane failure here - a crash (exit 2/255)
                    // or a signal termination (null exit code, e.g. taskkill). Behat's exit 1 ("some
                    // tests failed") is deliberately NOT treated as a lane failure: authoritative
                    // per-scenario pass/fail already lives in the attach-mode child rows, so a worker
                    // that completed normally must not be reported as a worker error just because some
                    // of its tests failed. Exit 0 (all passed) and exit 1 (ran to completion, some
                    // tests failed) both mean the worker itself did its job.
                    if ($exitCode !== 0 && $exitCode !== 1) {
                        if ($exitCode === null) {
                            $failures[$lane] = 'terminated without exit code';
                        } else {
                            $failures[$lane] = 'exit code ' . $exitCode;
                        }
                        $this->writeRunLog($laneLogs[$lane], 'LANE ' . $lane . ' FAILED: ' . $failures[$lane]);
                        $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' failed: ' . $failures[$lane]);
                    }
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: lane %d finished - exit %s after %.1f s (+%.1f s into run)',
                        $lane,
                        $exitCode === null ? 'n/a' : (string) $exitCode,
                        $durationS,
                        microtime(true) - $this->runStart
                    ));
                    $this->finishLaneLog($laneLogs[$lane]);
                    unset($active[$lane]);
                }
            }
            // Yield the CPU briefly between passes; nothing to do until workers produce more output.
            if (! empty($active)) {
                usleep(self::DRAIN_POLL_MICROSECONDS);
            }
        }

        if (is_resource($diagLog)) {
            fclose($diagLog);
        }
        return $failures;
    }

    /**
     * Kills every Chrome still holding one of THIS run's lane profiles, as a coordinator-side backstop.
     *
     * WHY THIS IS NEEDED even though workers stop their own Chrome: a worker only runs its shutdown
     * handler on a graceful exit. When the coordinator hard-kills a worker on the per-lane timeout
     * (Process::stop), or the worker dies on a fatal error, that handler never runs - and because
     * Chrome was launched detached via "start /B", it outlives the worker as an orphan. Such orphans
     * pile up across runs (leaking memory/handles) and keep their profile's ProcessSingleton lock,
     * the very thing that blocks a later run's launch. Running once here, after the whole fleet has
     * drained, mops them up.
     *
     * WHY IT IS SAFE FOR CONCURRENT RUNS: every lane profile is run-scoped ("<run_uid>_laneN"), so we
     * match ONLY command lines carrying THIS run's UID marker and never touch another run's or another
     * project's Chrome. Enumeration uses PowerShell Get-CimInstance (wmic is removed on current
     * Windows), consistent with ChromeManager::getProcessCommandLine(); the marker is compared in PHP,
     * so no dynamic value enters the shell command and there is no injection surface. All failures are
     * swallowed - teardown must never turn a finished run into a failed one.
     */
    private function killRunChromeProcesses(string $runUid): void
    {
        // Marker present in the --user-data-dir of every lane profile of THIS run and no other.
        $marker = strtolower($runUid . '_lane');

        // Constant chrome.exe filter only - no dynamic data enters the command, so it is injection-free.
        $lines = [];
        @exec(
            'powershell -NoProfile -Command "'
            . 'Get-CimInstance Win32_Process -Filter \'name=\'\'chrome.exe\'\'\' '
            . '| ForEach-Object { $_.ProcessId.ToString() + \'|\' + $_.CommandLine }'
            . '"',
            $lines
        );

        foreach ($lines as $line) {
            $sep = strpos($line, '|');
            if ($sep === false) {
                continue;
            }
            $pid = (int) substr($line, 0, $sep);
            // A detached Chrome may report an empty command line for its child processes; those never
            // carry our marker and are skipped, but killing the matched parent with /T takes them too.
            $cmdLine = strtolower(substr($line, $sep + 1));
            if ($pid > 0 && str_contains($cmdLine, $marker)) {
                @exec('taskkill /F /PID ' . $pid . ' /T 2>nul');
            }
        }
    }

    /**
     * Deletes THIS run's lane profile directories once the fleet has fully drained.
     *
     * WHY AFTER killing this run's Chromes: a live Chrome holds open handles inside its profile dir,
     * so on Windows the directory cannot be removed until the process is gone. WHY run-scoped and
     * best-effort: the dirs are named "<run_uid>_laneN", so only this run owns them and deleting them
     * can never disturb a concurrent run; and a delete that still fails (a lingering handle, an
     * antivirus scan) must not fail the run - the leftover dir is harmless and the next run uses a
     * fresh run-scoped name anyway. Without this, run-scoped dirs would accumulate on disk forever.
     */
    private function purgeRunProfileDirs(string $cwd, string $runUid): void
    {
        $profilesRoot = $cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'chrome_profiles';
        foreach ((array) glob($profilesRoot . DIRECTORY_SEPARATOR . $runUid . '_lane*', GLOB_ONLYDIR) as $dir) {
            $this->deleteDirectoryRecursive($dir);
        }
    }

    /**
     * Recursively deletes a directory tree, ignoring individual failures.
     *
     * WHY ITS OWN HELPER AND WHY BEST-EFFORT: PHP has no built-in recursive rmdir, and profile purging
     * must never throw - a file locked by a lingering handle should be skipped, not abort the run. Any
     * entry that cannot be removed is left in place; the surrounding purge is already best-effort.
     */
    private function deleteDirectoryRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    /**
     * Resolves the base behat.yml the lanes import: the explicit --behat_config when given, otherwise
     * the installation-root behat.yml that runInit() just (re)created.
     *
     * Why default to the installation root: runInit() runs Behat init, which writes the global behat.yml
     * there and refreshes its base_url. That file is therefore always present and current by the time we
     * reach here, so requiring the operator to pass its path was pure ceremony. We still validate and
     * fail loudly - defaulting is not guessing, since this is the exact file the rest of the run (init,
     * lane imports, port-band override lookup) already uses.
     *
     * @throws RuntimeException if the resolved file does not exist
     */
    private function resolveBehatConfig(?string $explicit, string $cwd): string
    {
        $path = ($explicit !== null && $explicit !== '')
            ? $explicit
            : $cwd . DIRECTORY_SEPARATOR . self::DEFAULT_BEHAT_CONFIG;
        if (! is_file($path)) {
            throw new RuntimeException('behat_config is not a file: ' . $path);
        }
        return $path;
    }

    /**
     * Resolves the real chrome.exe path: the explicit --chrome_path when given, otherwise the
     * PARALLEL.CHROME_PATH app-config value.
     *
     * Why a dedicated config key rather than the base behat.yml chrome.executable: that value points at
     * GoogleChromePortable.exe, whose single-instance lock is exactly what workers must NOT use. hasOption
     * guards against exface throwing on an unset key so we can emit our own actionable message. Existence
     * is validated and failure is loud - a missing binary must not degrade into a silent green run.
     *
     * @throws RuntimeException if neither source yields an existing file
     */
    private function resolveChromePath(?string $explicit): string
    {
        if ($explicit !== null && $explicit !== '') {
            $path = $explicit;
        } else {
            $cfg  = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
            $path = $cfg->hasOption(self::CFG_CHROME_PATH) ? (string) $cfg->getOption(self::CFG_CHROME_PATH) : '';
        }
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException(
                'chrome_path could not be resolved to an existing chrome.exe. '
                . 'Pass --chrome_path or set ' . self::CFG_CHROME_PATH . ' in app config. Got: ' . var_export($path, true)
            );
        }
        return $path;
    }

    /**
     * Decides WHICH feature files define this run's scope: an explicit --feature path, a named --suite,
     * or (default) every suite declared in the base behat.yml.
     *
     * Why derive from behat.yml instead of a mandatory --feature: the suites in behat.yml already declare
     * where features live, so a separate required path was both ceremony AND a footgun - an operator path
     * that disagreed with the suites would make the expected counts cover a different set than the workers
     * actually run, breaking expected==actual for a non-test reason. Deriving from the same behat.yml the
     * workers import ties the expected-count scan and the run to one source of truth.
     *
     * --feature and --suite are mutually exclusive: same question, two ways, so accepting both would force
     * a silent precedence rule. We fail loudly. An unknown suite or an explicit --feature that does not
     * exist also fails loudly rather than resolving to an empty set (which would look green having run
     * nothing).
     *
     * @return string[] Scan roots handed to ExpectedTestCountCalculator
     * @throws RuntimeException on the --feature/--suite combination, a missing --feature, an unknown suite,
     *                          or no resolvable paths
     */
    private function resolveScanRoots(string $behatConfig, ?string $feature, ?string $suite): array
    {
        $hasFeature = $feature !== null && $feature !== '';
        $hasSuite   = $suite  !== null && $suite  !== '';

        if ($hasFeature && $hasSuite) {
            throw new RuntimeException('Pass either --feature or --suite, not both.');
        }
        if ($hasFeature) {
            if (! file_exists($feature)) {
                throw new RuntimeException('feature does not exist: ' . $feature);
            }
            return [$feature];
        }

        $resolver = new \axenox\BDT\Behat\Common\BehatSuiteResolver();
        $paths = $hasSuite
            ? $resolver->resolvePathsFromGlobalYml($behatConfig, $suite)
            : $resolver->resolvePathsFromGlobalYml($behatConfig);
        if ($paths === []) {
            throw new RuntimeException(
                'No feature paths resolved from ' . $behatConfig
                . ($hasSuite ? ' for suite "' . $suite . '"' : '') . '.'
            );
        }
        return $paths;
    }

    /**
     * Reconstructs a reproducible description of what this coordinator ran, for the run row's
     * behat_command column.
     *
     * Why the coordinator action invocation rather than a behat command: a parallel run spawns one
     * behat command per lane, differing by lane config and feature bucket, so there is no single behat
     * command to record. The action invocation with its resolved scope selectors is the value that
     * reproduces the whole run. Only selectors the operator actually set are included, so the string
     * mirrors what was really passed.
     */
    private function describeInvocation(?string $tags, ?string $feature, ?string $suite): string
    {
        $cmd = 'vendor\\bin\\action axenox.BDT:RunParallel';
        if ($tags !== null && $tags !== '') {
            $cmd .= ' --tags="' . $tags . '"';
        }
        if ($feature !== null && $feature !== '') {
            $cmd .= ' --feature="' . $feature . '"';
        }
        if ($suite !== null && $suite !== '') {
            $cmd .= ' --suite="' . $suite . '"';
        }
        return $cmd;
    }

    /**
     * Streams a worker's incremental stdout/stderr into its lane log, then frees the read buffer.
     *
     * Why incremental + clearOutput: getIncrementalOutput()/getIncrementalErrorOutput() are
     * non-blocking and return only what arrived since the previous call, which is exactly what the
     * round-robin drain needs to keep every lane moving. clearOutput()/clearErrorOutput() then drop
     * the already-written bytes so a worker that logs for minutes does not accumulate its entire
     * output in memory. Raw fwrite (no added newline) preserves the worker's own line breaks.
     *
     * @param resource $logHandle
     * @return bool TRUE if any bytes were written this call (used to timestamp first output)
     */
    private function streamLaneOutput(Process $process, $logHandle): bool
    {
        $out = $process->getIncrementalOutput();
        $err = $process->getIncrementalErrorOutput();
        if ($out !== '' && is_resource($logHandle)) {
            @fwrite($logHandle, $out);
        }
        if ($err !== '' && is_resource($logHandle)) {
            @fwrite($logHandle, $err);
        }
        $process->clearOutput();
        $process->clearErrorOutput();
        return $out !== '' || $err !== '';
    }

    /**
     * Closes a lane log handle if it is still open. Centralized so every drain exit path (normal
     * finish, timeout, failure) releases the handle exactly once.
     *
     * @param resource $logHandle
     */
    private function finishLaneLog($logHandle): void
    {
        if (is_resource($logHandle)) {
            fclose($logHandle);
        }
    }

    /**
     * Appends a line if the handle is open; ignores write failures so logging never breaks the fleet.
     *
     * @param resource $handle
     */
    private function writeRunLog($handle, string $text): void
    {
        if (is_resource($handle)) {
            @fwrite($handle, $text . PHP_EOL);
        }
    }

    /**
     * Reads an OPTIONAL task parameter, returning null when it is absent or empty.
     *
     * Why a separate reader instead of reusing getTaskParam(): getTaskParam treats a null default as
     * "required" and throws when the value is missing - correct for inputs that must be present, wrong
     * for the deferred inputs (behat_config, chrome_path, feature, suite). Those are resolved from their
     * authoritative sources after init, so here we only need "value or null" with no loud failure; the
     * loud failure lives in the resolver that validates the RESOLVED value instead.
     */
    private function getOptionalTaskParam(TaskInterface $task, string $name): ?string
    {
        if ($task->hasParameter($name)) {
            $val = $task->getParameter($name);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        return null;
    }

    /**
     * Ensures the BDT log directory exists and returns its absolute path.
     *
     * Extracted from the old single-file opener so the directory guarantee and the absolute base
     * are shared by every per-lane log. Anchored at $cwd (installation root), so it never depends on
     * this action's process cwd.
     *
     * @return string Absolute path to data/axenox/BDT/Logs
     */
    private function ensureLogDir(string $cwd): string
    {
        $dir = $cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'Logs';
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create BDT log directory: ' . $dir);
        }
        return $dir;
    }

    /**
     * Opens one log file per lane named "<run_uid>_lane<N>.log" for append.
     *
     * Why a file per lane instead of one shared run log: a worker that crashes or times out leaves
     * its partial output in its OWN clearly-named file instead of buried under other lanes, and each
     * lane becomes independently greppable/tailable. Append mode tolerates a re-run without
     * truncating earlier diagnostics; naming by run_uid + lane keeps runs from overwriting each other.
     *
     * @return resource
     */
    private function openLaneLog(string $logDir, string $runUid, int $lane)
    {
        $handle = @fopen($logDir . DIRECTORY_SEPARATOR . $runUid . '_lane' . $lane . '.log', 'a');
        if ($handle === false) {
            throw new RuntimeException('Could not open lane log file for run ' . $runUid . ' lane ' . $lane);
        }
        return $handle;
    }

    /**
     * Opens the single coordinator diagnostic log "<run_uid>_coordinator.log" for append.
     *
     * Why a dedicated coordinator log instead of the workbench logger: the launch/drain timings used
     * to localize the concurrency cap are high-frequency orchestration traces. The DB-backed workbench
     * log is meant for a few meaningful info/error rows, not a per-lane timeline, so the fleet
     * diagnostics live here next to the per-lane logs and stay greppable in one file. Append mode +
     * naming by run_uid keeps runs from overwriting each other.
     *
     * @return resource
     */
    private function openCoordinatorLog(string $logDir, string $runUid)
    {
        $handle = @fopen($logDir . DIRECTORY_SEPARATOR . $runUid . '_coordinator.log', 'a');
        if ($handle === false) {
            throw new RuntimeException('Could not open coordinator log file for run ' . $runUid);
        }
        return $handle;
    }
}