<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
use axenox\BDT\Behat\Common\Traits\PortProbingTrait;
use axenox\BDT\Behat\Common\RunRecordWriter;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;

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
    use ChromeProfileReaperTrait;

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
    // This is a TOTAL (wall-clock) ceiling: it fires even while the worker is still making
    // progress. Set PARALLEL.WORKER_TIMEOUT_SECONDS to 0 in app config to disable it entirely
    // and rely purely on the idle timeout below.
    private const WORKER_TIMEOUT_SECONDS = 1800;

    // Idle (inactivity) ceiling per worker, used as the FALLBACK when
    // PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS is not set in app config. In contrast to the TOTAL
    // timeout above, the idle timer RESETS whenever the lane shows PROGRESS - and progress here means
    // EITHER new console output OR a growing run_step count for this run in the DB (the coordinator
    // polls that count during the drain). This DB-aware definition is deliberate: a long
    // works-as-expected step emits NO stdout while it runs, it only keeps INSERTing run_step rows per
    // substep, so an output-only idle timeout would wrongly kill a lane that is actually progressing.
    // Only a lane that has produced neither signal for this many seconds (a genuine hang) times out.
    // Set to 0 in app config to disable.
    private const WORKER_IDLE_TIMEOUT_SECONDS = 600;

    // App-config keys for the parallel orchestration layer. Kept as constants so the reads in
    // resolvePortBand()/resolveMaxWorkers()/resolveWorkerTimeout() can never drift from config.
    private const CFG_PORT_BAND  = 'PARALLEL.PORT_BAND_SCHEDULED';
    private const CFG_MAX_WORKERS = 'PARALLEL.MAX_WORKERS';
    private const CFG_WORKER_TIMEOUT = 'PARALLEL.WORKER_TIMEOUT_SECONDS';
    private const CFG_WORKER_IDLE_TIMEOUT = 'PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS';

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

    // How often (seconds) the drain loop queries the DB for the run's run_step count to detect
    // progress. Kept much coarser than DRAIN_POLL_MICROSECONDS because a COUNT round-trip is far
    // heavier than reading a pipe: a long works-as-expected step inserts a run_step per substep, so
    // polling every few seconds is more than enough to prove the fleet is alive without hammering
    // the DB while N workers run for minutes.
    private const DB_PROGRESS_POLL_SECONDS = 5.0;

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
     * The living run-log for the DB "log" column. It is a MarkdownLogBook (the framework's existing
     * structured-log type, also used by DatabaseFormatter for step logbooks) that the coordinator
     * appends to AS THE RUN HAPPENS: run facts up front, then one section per lane filled in when that
     * lane launches, finishes, times out or fails. Building it live means the events the coordinator
     * observes firsthand (launch, exit, timeout, worker failure) are recorded without re-parsing, and
     * each lane's Behat summary/failures are parsed once from its now-closed log at the moment it ends.
     * Persisted (capped) onto the run row in the single close-out. Nullable so a very early failure
     * before it is initialised still finalises cleanly.
     */
    private ?MarkdownLogBook $runLog = null;

    // --- Run-log digest bounds. The digest is persisted on the run row so a tester can triage a
    // parallel run from the DB instead of RDP-ing into the server for the lane logs. These caps stop
    // a pathological run (mass failures, a runaway stack trace) from bloating the run row. ---
    private const LOG_MAX_FAILED_SCENARIOS  = 50;
    private const LOG_FAILURE_EXCERPT_BYTES = 4096;
    private const LOG_FALLBACK_TAIL_LINES   = 40;
    private const LOG_TOTAL_MAX_BYTES       = 65536;

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

        // Open the living run-log now, right after the run row exists, so that even a coordinator error
        // BEFORE the fleet launches (e.g. a feature-file parse error) is captured under "Run summary"
        // and still reaches the DB via the close-out. Lane sections are added later, as lanes launch.
        $this->runLog = new MarkdownLogBook('BDT parallel run ' . $runUid);
        $this->runLog->addSection('Run summary');
        $this->runLog->addLine('Run UID: ' . $runUid, 1);

        // Hoisted so the single close-out below can log AND finalize on BOTH the normal path and a
        // coordinator-level failure. $failures stays empty unless runFleet() assigns it; a coordinator
        // error is recorded and re-thrown only AFTER the run row is logged and finalized, so a failure
        // that aborts the fleet still pulls whatever lane output exists into the DB.
        $failures = [];
        $coordinatorError = null;
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
            // Mirror the resolved configuration into the run-log so the DB record opens with the same
            // expectation the console showed (worker count, headless state, debugger state).
            $this->runLog->addLine('Configuration:', 1, 'Run summary');
            $this->runLog->addCodeBlock($banner, '', 'Run summary');

            // --- Step 5: run the fleet. Wrapped so a worker failure still reaches finalize. ---
            $failures = $this->runFleet($cwd, $behatConfig, $runUid, $chromePath, $tags, $buckets, $banner);

        } catch (\Throwable $e) {
            // Record the coordinator failure but do NOT finalize/throw yet: the single close-out below
            // must run first so the run row is logged AND finalized on this path too. Re-thrown after.
            $coordinatorError = $e;
        } finally {
            // Backstop: fleet workers launch Chrome detached (start /B), so a timed-out/hard-killed
            // worker leaves an orphaned Chrome tree still holding its profile dir. Reap those trees
            // and purge the lane profile dirs on every exit path, so nothing lingers between runs.
            // Runs only after runFleet() has fully drained the fleet, so any Chrome still bound to a
            // lane profile dir here is provably an orphan. Never throws (see the method).
            $this->cleanupLaneChromes($cwd);
        }

        // --- Single close-out: stage the run-log digest, then finalize exactly once. ---
        // Runs on BOTH the normal path and the coordinator-error path, so a run that failed at
        // coordinator level still pulls whatever lane logs exist on disk into the DB. The digest is
        // staged onto the run sheet and persisted by finalize()'s single dataUpdate - no extra
        // optimistic-lock round-trip. stageRunLog() swallows its own errors, so a digest problem can
        // never stop finished_on from being written.
        $this->stageRunLog($runRecordWriter, $runUid, $coordinatorError);
        $runRecordWriter->finalize($this->runDataSheet);

        // A coordinator-level failure is re-thrown now that the run row is closed AND logged.
        if ($coordinatorError !== null) {
            throw new RuntimeException('Parallel run coordinator failed: ' . $coordinatorError->getMessage(), null, $coordinatorError);
        }

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
            . "    " . $extensionFqn . ":\n"
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
     * Resolves the per-worker TOTAL (wall-clock) timeout from app config, falling back to the constant.
     *
     * Why never the runCliCommand 60s default: a Behat lane runs minutes, not seconds. Symfony
     * Process enforces this timeout per worker and throws on exceedance; that throw is caught in
     * the per-lane drain, so a hung worker is recorded as a failure without blocking the others.
     *
     * This is a TOTAL ceiling - it fires even while the lane is still producing output. A value of
     * 0 (or a negative one) in PARALLEL.WORKER_TIMEOUT_SECONDS DISABLES it (returns null), so the
     * run is bounded only by the idle timeout - use that for long-but-progressing suites.
     *
     * @return float|null Seconds, or null when the total ceiling is disabled.
     */
    private function resolveWorkerTimeout(): ?float
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        // Distinguish "not configured" (fall back to the constant) from an explicit 0 (disable).
        if (! $cfg->hasOption(self::CFG_WORKER_TIMEOUT)) {
            return (float) self::WORKER_TIMEOUT_SECONDS;
        }
        $seconds = (float) $cfg->getOption(self::CFG_WORKER_TIMEOUT);
        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Resolves the per-worker IDLE (inactivity) timeout from app config, falling back to the constant.
     *
     * The idle timer RESETS on every chunk of worker output, so a lane that keeps printing progress -
     * even for a very long time - is never killed by it; only a lane that has emitted NO output for
     * this many seconds (a genuine hang) times out. Symfony enforces it via the same checkTimeout()
     * call as the total timeout, so an idle-timed-out worker is a recorded failure, not a stall.
     *
     * A value of 0 (or negative) in PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS DISABLES the idle timeout
     * (returns null). Disabling BOTH timeouts lets a truly hung worker block its lane forever, so at
     * least one should stay enabled.
     *
     * @return float|null Seconds, or null when the idle timeout is disabled.
     */
    private function resolveWorkerIdleTimeout(): ?float
    {
        $cfg = $this->getWorkbench()->getApp('axenox.BDT')->getConfig();
        if (! $cfg->hasOption(self::CFG_WORKER_IDLE_TIMEOUT)) {
            return (float) self::WORKER_IDLE_TIMEOUT_SECONDS;
        }
        $seconds = (float) $cfg->getOption(self::CFG_WORKER_IDLE_TIMEOUT);
        return $seconds > 0 ? $seconds : null;
    }

    /**
     * Counts how many run_step rows exist in the DB for the given run, WITHOUT loading them.
     *
     * This is the drain loop's progress heartbeat. Attach-mode workers insert one run_step row per
     * Behat step AND per substep (see DatabaseFormatter::logStepStart), so a long works-as-expected
     * step that emits no console output STILL grows this count steadily. Comparing successive counts
     * therefore tells the coordinator whether the fleet is alive even when every worker pipe is silent.
     *
     * The count is fleet-wide (all lanes of this run), reached via the relation path
     * run_scenario -> run_feature -> run, which is why a single COUNT aggregation is used instead of
     * reading rows: the value can grow into the thousands and we only ever need its size.
     *
     * Never throws: a transient DB hiccup during polling must not abort an otherwise healthy fleet, so
     * a failure is logged and returned as null ("unknown"), letting the caller fall back to
     * output-only progress for that pass.
     *
     * @param string $runUid The run whose child run_step rows to count.
     * @return int|null The current row count, or null if the query failed.
     */
    private function countRunSteps(string $runUid): ?int
    {
        try {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.run_step');
            $ds->getFilters()->addConditionFromString('run_scenario__run_feature__run', $runUid, ComparatorDataType::EQUALS);
            // A lone aggregated column with no group-by yields a single-row COUNT, like SQL COUNT(*).
            $col = $ds->getColumns()->addFromExpression('UID');
            $ds->dataRead();
            $val = $col->getValue(0);
            return $val === null ? 0 : (int) $val;
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return null;
        }
    }

    /**
     * Terminates a lane the drain loop has given up on (idle hang or total-timeout), reaps its
     * detached Chrome tree and closes its log. Centralized so the idle-timeout and the wall-clock
     * timeout paths kill a lane identically instead of duplicating the stop/reap/log sequence.
     *
     * The caller is responsible for setting $failures[$lane] and removing the lane from the active
     * set - this method only performs the teardown and the accompanying log lines.
     *
     * @param int      $lane    The lane being killed.
     * @param Process  $process The lane's worker process.
     * @param resource $laneLog The lane's open log handle.
     * @param resource $diagLog The coordinator diagnostic log handle.
     * @param string   $reason  Human-readable reason, reused verbatim in both logs.
     * @param string   $cwd     Run working dir (for reapLaneProfile).
     */
    private function killHungLane(int $lane, Process $process, $laneLog, $diagLog, string $reason, string $cwd): void
    {
        $this->writeRunLog($laneLog, 'LANE ' . $lane . ' ' . strtoupper($reason));
        $this->writeRunLog($diagLog, sprintf('DIAG drain: lane %d %s at +%.1f s', $lane, $reason, microtime(true) - $this->runStart));
        $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' ' . $reason);
        $process->stop(0); // SIGKILL-equivalent; release the slot immediately
        // Kill the DETACHED Chrome tree this worker left behind and drop its profile dir NOW, while the
        // coordinator is still alive - an all-timeout run may never reach the end-of-run backstop.
        $this->reapLaneProfile($lane, $cwd);
        $this->finishLaneLog($laneLog);
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
        $idleTimeout = $this->resolveWorkerIdleTimeout();
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
        $laneLastActivity = []; // lane => microtime of this lane's last observed progress (output OR DB growth)
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
                // NOTE: we deliberately do NOT use Symfony's own setIdleTimeout() here. Its idle timer
                // only resets on PROCESS OUTPUT, but a long works-as-expected step emits NO stdout while
                // it runs - it only keeps INSERTing run_step rows into the DB (one per substep). An
                // output-based idle timeout would therefore kill a lane that is actually progressing.
                // Instead the drain loop below detects idleness itself, treating BOTH new lane output AND
                // a growing run_step count in the DB as "this lane made progress". Only the TOTAL
                // wall-clock ceiling ($timeout) stays enforced by Symfony via checkTimeout().
                $process->start();
                $callMs = (microtime(true) - $callStart) * 1000;

                $processes[$lane] = $process;
                $laneLogs[$lane]  = $laneLog;
                $laneStart[$lane] = microtime(true);
                $laneLastActivity[$lane] = microtime(true); // seed the idle clock at launch

                // Create this lane's run-log section NOW, while launches run in lane order, so the DB
                // record lists lanes 1..N in order even though they finish out of order later. The
                // section is filled in when the lane ends (see appendLaneOutcome).
                $this->runLog?->addSection('Lane ' . $lane);
                $this->runLog?->addLine('Port: ' . $port, 1);

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
                // Record firsthand: a launch failure often means no lane log file was ever opened, so
                // there is nothing to parse later - the coordinator's own error message is the record.
                $this->runLog?->addSection('Lane ' . $lane);
                $this->runLog?->addLine('Worker: failed', 1);
                $this->runLog?->addLine('Worker error: ' . $failures[$lane], 1);
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
        // Fleet-wide DB progress heartbeat. A long works-as-expected step produces NO console output
        // while it runs, but the attach-mode worker keeps INSERTing one run_step row per substep. So a
        // growing run_step count for THIS run proves the fleet is alive even when every pipe is silent.
        // We seed with the current count and treat any later increase as "the fleet just made progress",
        // which resets every running lane's idle clock below. Seeded to "now" so a slow first step does
        // not trip the idle timeout before the very first poll.
        $dbProgressAt = microtime(true);
        $lastDbPollAt = 0.0; // 0 forces an immediate poll on the first pass
        $lastDbCount  = $this->countRunSteps($runUid) ?? 0;
        while (! empty($active)) {
            // Throttled DB progress poll (once every DB_PROGRESS_POLL_SECONDS, not every 100 ms pass):
            // a COUNT round-trip is far heavier than a pipe read. Only meaningful when an idle timeout is
            // configured - with it disabled there is nothing to reset, so we skip the query entirely.
            $now = microtime(true);
            if ($idleTimeout !== null && ($now - $lastDbPollAt) >= self::DB_PROGRESS_POLL_SECONDS) {
                $lastDbPollAt = $now;
                $currentDbCount = $this->countRunSteps($runUid);
                if ($currentDbCount !== null && $currentDbCount > $lastDbCount) {
                    $lastDbCount  = $currentDbCount;
                    $dbProgressAt = $now;
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: DB progress - %d run_step rows at +%.1f s into run',
                        $currentDbCount,
                        $now - $this->runStart
                    ));
                }
            }
            foreach ($active as $lane => $process) {
                // Stream whatever new output arrived since the last pass.
                $wrote = $this->streamLaneOutput($process, $laneLogs[$lane]);
                if ($wrote) {
                    // Console output is itself a progress signal - record it as this lane's activity so
                    // a chatty lane never trips the idle timeout regardless of DB polling.
                    $laneLastActivity[$lane] = microtime(true);
                }
                if ($wrote && ! isset($firstOutputAt[$lane])) {
                    $firstOutputAt[$lane] = true;
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: lane %d - first output at +%.1f s into run',
                        $lane,
                        microtime(true) - $this->runStart
                    ));
                }

                // Idle detection (DB-aware). A lane is "making progress" if EITHER it produced output
                // recently OR the fleet's run_step count grew recently ($dbProgressAt). Only when a lane
                // has done NEITHER for the whole idle window is it a genuine hang. This is the fix for the
                // false timeout: a silent-but-DB-writing works-as-expected step keeps $dbProgressAt fresh,
                // so it is never killed here; the TOTAL wall-clock ceiling ($timeout, enforced by Symfony
                // below) remains the absolute backstop.
                if ($idleTimeout !== null) {
                    $lastActive = max($laneLastActivity[$lane], $dbProgressAt);
                    if ((microtime(true) - $lastActive) > $idleTimeout) {
                        $reason = 'idle timed out after ' . $idleTimeout . ' s with no output and no new run_step in the DB';
                        $failures[$lane] = $reason;
                        $this->killHungLane($lane, $process, $laneLogs[$lane], $diagLog, $reason, $cwd);
                        // killHungLane has closed the lane log, so its partial output is flushed and safe
                        // to parse into the run-log now.
                        $this->appendLaneOutcome($logDir, $runUid, $lane, $reason);
                        unset($active[$lane]);
                        continue;
                    }
                }

                // Enforce the per-worker TOTAL wall-clock timeout. In async mode Symfony only checks the
                // timeout when we ask it to, so a hung worker would otherwise never time out.
                try {
                    $process->checkTimeout();
                } catch (ProcessTimedOutException $e) {
                    // Symfony now enforces only the TOTAL ceiling (we no longer set its output-based idle
                    // timeout), so any throw here is the wall-clock ceiling - a lane that ran too long even
                    // if it was still making progress. The DB-aware idle case is handled above instead.
                    $reason = 'timed out after ' . $timeout . ' s (total wall-clock ceiling)';
                    $failures[$lane] = $reason;
                    $this->killHungLane($lane, $process, $laneLogs[$lane], $diagLog, $reason, $cwd);
                    // killHungLane has closed the lane log; parse its partial output into the run-log.
                    $this->appendLaneOutcome($logDir, $runUid, $lane, $reason);
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
                    // The lane log is now closed and complete - parse it once into the run-log. Pass the
                    // worker-level failure if any (crash/signal); a normal exit (0/1) passes null, so the
                    // section still records the Behat summary and any failed scenarios from the output.
                    $this->appendLaneOutcome($logDir, $runUid, $lane, $failures[$lane] ?? null);
                    // On a clean exit the worker's own ChromeManager already stopped Chrome, so this
                    // usually just removes the profile dir; on a crash/signal it also kills the orphan.
                    $this->reapLaneProfile($lane, $cwd);
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
     * End-of-run backstop that reaps orphaned lane Chrome trees and purges their profile dirs.
     *
     * WHY THIS EXISTS: fleet workers launch Chrome detached via "start /B", so a worker that is
     * hard-killed on timeout (Process::stop()) leaves its Chrome process tree running - the parent
     * worker dies but the detached browser does not. Nothing else in the coordinator removes these:
     * ChromeManager::isOwnLeftover() only reaps a leftover at the NEXT run's launch on the same
     * profile, so between runs the orphan tree and its locked profile dir linger (the observed
     * symptom: six chrome.exe under \lane1 surviving a timed-out lane). This runs after the fleet,
     * on every exit path, to leave the machine clean.
     *
     * WHY SCOPED TO lane* DIRS ONLY (not the whole chrome_profiles root): interactive tester runs
     * use chrome_profiles\interactive<port> and may run concurrently - killing those would sabotage
     * a live tester. Only one parallel run can exist at a time (lane profile dirs are fixed names,
     * so a second coordinator would collide on them), therefore any Chrome still bound to a lane*
     * profile dir here is provably THIS run's orphan and safe to kill.
     *
     * WHY IT NEVER THROWS: it runs in perform()'s finally, so a throw would mask the real run
     * outcome (or the original exception on the failure path). Every failure is logged loudly
     * instead - a leftover Chrome is a nuisance, not a reason to fail an otherwise-finalized run.
     *
     * @param string $workingDir Installation root (the base all lane profile dirs are relative to)
     */
    private function cleanupLaneChromes(string $workingDir): void
    {
        try {
            // Must mirror writeLaneConfig()'s user_data_dir construction; kept in sync by hand.
            $profilesRoot = $workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'chrome_profiles';
            $laneDirs = glob($profilesRoot . DIRECTORY_SEPARATOR . 'lane*', GLOB_ONLYDIR) ?: [];
            if ($laneDirs === []) {
                return;
            }

            $logger = $this->getWorkbench()->getLogger();
            $normalize = static function (string $path): string {
                return strtolower(str_replace('/', '\\', $path));
            };

            // One process snapshot for the whole cleanup, so chrome.exe is scanned exactly once.
            $chromeProcesses = $this->listChromeProcessCommandLines();

            $killedAny = false;
            foreach ($laneDirs as $laneDir) {
                $absLaneDir = realpath($laneDir) ?: $laneDir;
                $killed = $this->reapChromeProfileDir($absLaneDir, $chromeProcesses);
                foreach ($killed as $pid) {
                    $logger->info('BDT parallel cleanup: killed orphan Chrome PID ' . $pid . ' bound to ' . $absLaneDir);
                }
                if ($killed !== []) {
                    $killedAny = true;
                }
            }
            // Chrome releases its profile file handles (ProcessSingleton lock, etc.) asynchronously
            // after taskkill returns; removing a dir immediately would race those handles and leave a
            // half-deleted profile. A short settle avoids that without polling.
            if ($killedAny) {
                usleep(1_000_000);
            }

            foreach ($laneDirs as $laneDir) {
                if (! $this->removeDirectoryTree($laneDir)) {
                    $logger->warning('BDT parallel cleanup: could not fully remove lane profile dir ' . $laneDir
                        . ' - a Chrome handle may still be open. It will be overwritten on the next run.');
                }
            }
        } catch (\Throwable $e) {
            // Backstop for the backstop: never let cleanup break finalize.
            try {
                $this->getWorkbench()->getLogger()->logException($e);
            } catch (\Throwable $ignored) {
                // Logging itself failed (e.g. workbench already torn down) - nothing safe left to do.
            }
        }
    }

    /**
     * Reaps the orphaned Chrome tree of a SINGLE finished/abandoned lane and removes its profile dir.
     *
     * WHY INLINE, NOT ONLY AT END-OF-RUN: fleet workers launch Chrome detached (start /B), so
     * Process::stop() on a timed-out worker kills the worker but leaves its Chrome tree alive.
     * Relying on a single end-of-run cleanup is not enough when EVERY lane runs to the wall-clock
     * ceiling: the coordinator itself may be killed by its scheduler/queue budget at that same
     * ceiling, so a final cleanup step might never execute. Reaping here - the instant we give up on
     * a lane, while the coordinator is provably still alive - guarantees the orphan and its locked
     * profile dir are gone regardless of what happens to the coordinator afterwards.
     *
     * WHY IT NEVER THROWS: it runs inside the drain loop; a throw would abort the whole fleet wait
     * and strand the other lanes. Failures are logged - a leftover browser is a nuisance, not a run
     * failure.
     *
     * @param int    $lane The lane number whose Chrome/profile to reap
     * @param string $cwd  The run working dir (same base writeLaneConfig() built the profile under)
     */
    private function reapLaneProfile(int $lane, string $cwd): void
    {
        try {
            // Must mirror writeLaneConfig()'s user_data_dir construction.
            $absLaneDir = $cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR
                . 'chrome_profiles' . DIRECTORY_SEPARATOR . 'lane' . $lane;
            $absLaneDir = realpath($absLaneDir) ?: $absLaneDir;

            $logger = $this->getWorkbench()->getLogger();
            $killed = $this->reapChromeProfileDir($absLaneDir, $this->listChromeProcessCommandLines());
            foreach ($killed as $pid) {
                $logger->info('BDT parallel cleanup: lane ' . $lane . ' killed orphan Chrome PID ' . $pid);
            }
            if ($killed !== []) {
                usleep(500_000);
            }
            if (! $this->removeDirectoryTree($absLaneDir)) {
                $logger->warning('BDT parallel cleanup: lane ' . $lane . ' profile dir not fully removed ('
                    . $absLaneDir . ') - a Chrome handle may still be open; it will be overwritten next run.');
            }
        } catch (\Throwable $e) {
            try {
                $this->getWorkbench()->getLogger()->logException($e);
            } catch (\Throwable $ignored) {
                // Logging itself failed (e.g. workbench torn down) - nothing safe left to do.
            }
        }
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

    /**
     * Finalises the living run-log (adds the coordinator error, if any, and the generated timestamp)
     * and stages it as Markdown text on the run sheet, swallowing any log-side error.
     *
     * Why it never throws: it runs in the single close-out immediately before finalize(), so a throw
     * here would stop finished_on from being written and leave the run row open - the exact orphaned-
     * run failure we are trying to avoid. The run-log is diagnostics; it must never mask or block the
     * run's finalization, so on any failure we store a tiny Markdown marker instead. It does NOT call
     * dataUpdate: the value rides along in finalize()'s single update, avoiding a second optimistic-
     * locking round-trip on a row whose only writer is the coordinator.
     *
     * @param \Throwable|null $coordinatorError Coordinator-level failure to record under "Run summary".
     */
    private function stageRunLog(RunRecordWriter $writer, string $runUid, ?\Throwable $coordinatorError): void
    {
        try {
            // Defensive: the member is set in perform() before anything can fail into the close-out,
            // but if an extremely early failure left it null, build a minimal book so we still store
            // something useful rather than nothing.
            $log = $this->runLog ?? (new MarkdownLogBook('BDT parallel run ' . $runUid))
                ->addSection('Run summary')
                ->addLine('Run UID: ' . $runUid, 1);
            if ($coordinatorError !== null) {
                $log->addLine('Coordinator error: ' . $coordinatorError->getMessage(), 1, 'Run summary');
            }
            $log->addLine('Generated: ' . DateTimeDataType::now(), 1, 'Run summary');
            $markdown = $this->capRunLogText((string) $log);
        } catch (\Throwable $e) {
            // The log column is Markdown text (not JSON), so the failure marker is plain Markdown too -
            // still human-readable straight from the DB.
            $markdown = '## Run summary' . "\n\n"
                . 'Run UID: ' . $runUid . "\n\n"
                . 'Run log build failed: ' . $e->getMessage();
        }
        $writer->setRunLog($this->runDataSheet, $markdown);
    }

    /**
     * Appends one lane's outcome to its (already-created) run-log section: the worker status, the
     * Behat run summary, the failed-scenario list and a bounded failure excerpt - parsed once from the
     * lane's now-closed log file.
     *
     * Why parse here, at lane end, rather than at run end: the lane has just finished, so its log is
     * flushed and complete, and parsing it now keeps everything about a lane in one place and out of a
     * separate end-of-run pass. Reading the closed file (not a live buffer) means a lane that never
     * produced output degrades to a clear marker instead of breaking the log.
     *
     * Why "worker done" is not "tests passed": worker status reports whether the PROCESS completed -
     * Behat exit 1 (a completed run with test failures) is a healthy worker. The test outcome is
     * reported separately from the parsed "Failed scenarios" block, so a DB reader is not misled.
     *
     * @param int         $lane        Lane id (1-based, matching the launch loop and the log filename).
     * @param string|null $workerError Worker-level failure for this lane, or null on a normal exit.
     */
    private function appendLaneOutcome(string $logDir, string $runUid, int $lane, ?string $workerError): void
    {
        if ($this->runLog === null) {
            return;
        }
        $section = 'Lane ' . $lane;
        $this->runLog->addLine('Worker: ' . ($workerError === null ? 'done' : 'failed'), 1, $section);
        if ($workerError !== null) {
            $this->runLog->addLine('Worker error: ' . $workerError, 1, $section);
        }

        $logFile = $logDir . DIRECTORY_SEPARATOR . $runUid . '_lane' . $lane . '.log';
        if (! is_file($logFile) || ! is_readable($logFile)) {
            $this->runLog->addLine('Lane log file missing or unreadable', 1, $section);
            return;
        }

        $raw = (string) @file_get_contents($logFile);
        // Strip any ANSI colour codes so the stored digest is plain text (Behat usually disables
        // colour when its output is piped, but a configured formatter may still emit codes).
        $text  = preg_replace('/\e\[[0-9;]*m/', '', $raw) ?? $raw;
        $lines = preg_split('/\R/', $text) ?: [];

        $summary         = $this->extractBehatSummary($lines);
        $failedScenarios = $this->extractFailedScenarios($lines);
        $excerpt         = $this->extractFailureExcerpt($lines);

        // Fallback: if the worker itself failed but Behat printed no recognizable failure block (e.g.
        // it crashed before reaching a scenario), keep the last few non-empty lines so the section is
        // not empty for a lane we KNOW went wrong. Bounded by LOG_FALLBACK_TAIL_LINES.
        if ($excerpt === null && $workerError !== null) {
            $nonEmpty = array_values(array_filter($lines, static fn($l) => trim($l) !== ''));
            $tail     = array_slice($nonEmpty, -self::LOG_FALLBACK_TAIL_LINES);
            $excerpt  = $tail === [] ? null : implode("\n", $tail);
        }

        $this->runLog->addLine('Test failures: ' . ($failedScenarios !== [] ? 'yes' : 'no'), 1, $section);
        if ($summary !== null) {
            $this->runLog->addLine('Summary:', 1, $section);
            $this->runLog->addCodeBlock($summary, '', $section);
        }
        if ($failedScenarios !== []) {
            $this->runLog->addLine('Failed scenarios:', 1, $section);
            foreach ($failedScenarios as $ref) {
                $this->runLog->addLine($ref, 2, $section);
            }
        }
        if ($excerpt !== null) {
            $this->runLog->addLine('Failure excerpt:', 1, $section);
            $this->runLog->addCodeBlock($excerpt, '', $section);
        }
    }

    /**
     * Pulls Behat's end-of-run counts block ("N scenarios (...)", "M steps (...)", timing) from the
     * lane log lines.
     *
     * Why the counts block specifically: it is the one line group Behat prints on EVERY completed run
     * regardless of the configured output formatter, so it is the most reliable "what happened" signal
     * for the digest.
     *
     * @param string[] $lines
     * @return string|null The joined summary lines, or null if the run never reached a summary.
     */
    private function extractBehatSummary(array $lines): ?string
    {
        $summary = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (preg_match('/^\d+\s+scenario(s)?\s+\(/', $trimmed)
                || preg_match('/^\d+\s+step(s)?\s+\(/', $trimmed)
                || preg_match('/^\d+m[\d.]+s\s+\(/', $trimmed)
            ) {
                $summary[] = $trimmed;
            }
        }
        return $summary === [] ? null : implode("\n", $summary);
    }

    /**
     * Collects the feature:line references from Behat's "Failed scenarios:" block.
     *
     * Why this block: when scenarios fail, Behat lists each failing scenario's location in a compact
     * trailing block - exactly the "which scenarios failed" the digest needs, without the verbose
     * inline output. Capped at LOG_MAX_FAILED_SCENARIOS so a mass-failure run cannot bloat the row.
     *
     * @param string[] $lines
     * @return string[] Feature:line references (possibly empty).
     */
    private function extractFailedScenarios(array $lines): array
    {
        $refs    = [];
        $inBlock = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (! $inBlock) {
                if (stripos($trimmed, 'Failed scenarios:') !== false) {
                    $inBlock = true;
                }
                continue;
            }
            // The block is a run of indented feature:line refs; the first line that is not such a ref
            // (a blank line or the counts summary) ends it.
            if (preg_match('/\.feature:\d+$/', $trimmed)) {
                $refs[] = $trimmed;
                if (count($refs) >= self::LOG_MAX_FAILED_SCENARIOS) {
                    break;
                }
            } elseif ($trimmed !== '') {
                break;
            }
        }
        return $refs;
    }

    /**
     * Builds a bounded excerpt of the lines that look like failure detail (exception messages,
     * assertion failures), so the digest carries the "why" and not just the "what".
     *
     * Why line-matching rather than a full parser: the inline failure detail's exact shape depends on
     * the output formatter and the thrown exception, so a strict parser would be brittle. Collecting
     * the recognizable failure lines up to LOG_FAILURE_EXCERPT_BYTES is formatter-agnostic and cannot
     * run away in size.
     *
     * @param string[] $lines
     * @return string|null The excerpt, or null when no failure lines were found.
     */
    private function extractFailureExcerpt(array $lines): ?string
    {
        $hits  = [];
        $bytes = 0;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            if (preg_match('/(Exception|Error|Failed asserting|assert|✘|\bFAILED\b)/i', $trimmed)) {
                $hits[] = $trimmed;
                $bytes += strlen($trimmed) + 1;
                if ($bytes >= self::LOG_FAILURE_EXCERPT_BYTES) {
                    $hits[] = '... (failure excerpt truncated)';
                    break;
                }
            }
        }
        return $hits === [] ? null : implode("\n", $hits);
    }

    /**
     * Caps the Markdown run-log at LOG_TOTAL_MAX_BYTES, appending a truncation marker when it overruns.
     *
     * Why a cap at all: the log is only summary + failure blocks, but a pathological run (a runaway
     * stack trace, mass failures) can still produce a large failure excerpt. The run row must stay
     * small, so an oversized log is trimmed to a bounded, still-readable Markdown string rather than
     * stored whole.
     *
     * Why mb_strcut rather than substr: it trims on a byte budget WITHOUT splitting a multi-byte UTF-8
     * character, so the truncated tail can never become an invalid-encoding fragment in the DB.
     */
    private function capRunLogText(string $markdown): string
    {
        if (strlen($markdown) <= self::LOG_TOTAL_MAX_BYTES) {
            return $markdown;
        }
        $marker = "\n\n... (run log truncated at " . self::LOG_TOTAL_MAX_BYTES . ' bytes)';
        $budget = self::LOG_TOTAL_MAX_BYTES - strlen($marker);
        return mb_strcut($markdown, 0, max(0, $budget)) . $marker;
    }
}