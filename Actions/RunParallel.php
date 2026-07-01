<?php
namespace axenox\BDT\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
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
use Symfony\Component\Yaml\Yaml;

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
    // CLI option names - kept as constants so the option declarations in getCliOptions()
    // and the reads via getTaskParam() can never drift apart.
    private const OPT_TAGS         = 'tags';
    private const OPT_BEHAT_CONFIG = 'behat_config';
    private const OPT_FEATURE_PATH = 'feature_path';
    private const OPT_CHROME_PATH  = 'chrome_path';

    private const DEFAULT_TAGS = '@Status::Ready';

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

    // Per-project orchestration override. A SEPARATE file from behat.yml on purpose: behat.yml
    // is worker config, this is coordinator config. Mixing them would let a worker accidentally
    // read or break the band. Optional - absent means "use app-config defaults".
    private const OVERRIDE_FILE = 'bdt_parallel.yml';

    // Meta object alias - identical to the one DatabaseFormatter writes to, so the
    // coordinator and the worker operate on the very same run row.
    private const OBJ_RUN = 'axenox.BDT.run';


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
        // --- Read inputs (loud failure on anything missing; we never guess paths) ---
        $tags         = $this->getTaskParam($task, self::OPT_TAGS, self::DEFAULT_TAGS);
        $behatConfig  = $this->getTaskParam($task, self::OPT_BEHAT_CONFIG); // abs path to base nightly behat.yml
        $featurePath  = $this->getTaskParam($task, self::OPT_FEATURE_PATH); // abs path/dir scanned for expected counts
        $chromePath   = $this->getTaskParam($task, self::OPT_CHROME_PATH);  // abs path to chrome.exe (NOT GoogleChromePortable.exe)

        if (! is_file($behatConfig)) {
            throw new RuntimeException('behat_config is not a file: ' . $behatConfig);
        }
        if (! file_exists($featurePath)) {
            throw new RuntimeException('feature_path does not exist: ' . $featurePath);
        }

        $cwd = $this->getWorkbench()->getInstallationPath();

        // --- Step 0: prepare the environment exactly like a single Behat run does ---
        // Every non-parallel run is preceded by `Behat init`, which (re)creates the global
        // behat.yml, registers app suites and - most importantly - refreshes base_url to the
        // current workbench URL. Lanes never run init themselves, so a stale base_url would make
        // every worker fail. Running it once up front keeps the parallel path equivalent.
        $this->runInit($cwd);

        // --- Step 1: open the run record (sole creator, so the workers can attach to its UID) ---
        $runUid = $this->createRunRow($tags);

        try {
            // --- Step 2: compute the full expected scope up front over ALL matched features ---
            // Done here, not in the workers, because attach-mode workers skip this, and because
            // the expected totals must reflect ALL matched features even if a worker dies partway
            // through (otherwise silent-stop detection is impossible).
            $expected = (new \axenox\BDT\Behat\Common\ExpectedTestCountCalculator())
                ->calculate([$featurePath], $tags);

            // A broken feature file aborts the whole Behat run at parse time, so surface the
            // offenders now rather than letting a worker crash opaquely with exit code 255.
            if ($expected->hasErrors()) {
                throw new RuntimeException(
                    'Feature files failed to parse: ' . implode('; ', array_keys($expected->errors))
                );
            }

            // --- Step 3: persist the expected counts onto the run row (once, for the whole run) ---
            $this->updateRunRow($runUid, [
                'expected_feature_count'  => $expected->featureCount,
                'expected_scenario_count' => $expected->scenarioCount,
            ]);

            // --- Step 4: decide the fleet size. NO user pool exists - users are provisioned per
            // role at run-time by UI5Browser::setupUser(), so the only ceiling is "do not start
            // more workers than there are features to test". ---
            $matchedFiles = $expected->matchedFiles;
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
            $this->finalizeRunRow($runUid);
            throw new RuntimeException('Parallel run coordinator failed: ' . $e->getMessage(), null, $e);
        }

        // --- Finalize exactly once, after all workers exit. Child-row outcomes already live in
        // the DB, written by the workers in attach-mode; we only stamp the run as finished. ---
        $this->finalizeRunRow($runUid);

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
                ->setDescription('Behat tag filter to select scenarios, e.g. "@Status::Ready"')
                ->setDefaultValue(self::DEFAULT_TAGS),
            (new ServiceParameter($this))
                ->setName(self::OPT_BEHAT_CONFIG)
                ->setDescription('Path to the base nightly behat.yml the lane config imports'),
            (new ServiceParameter($this))
                ->setName(self::OPT_FEATURE_PATH)
                ->setDescription('Path (file or directory) scanned to compute the expected feature/scenario counts'),
            (new ServiceParameter($this))
                ->setName(self::OPT_CHROME_PATH)
                ->setDescription('Path to chrome.exe (NOT GoogleChromePortable.exe)')
        ];
    }

    /**
     * Creates the run row and returns its UID.
     *
     * Why this mirrors DatabaseFormatter::startRun() exactly: the coordinator and the worker
     * must agree on the run object's shape. addFromSystemAttributes() is required so the UID
     * column comes back populated after dataCreate(); behat_command records the coordinator
     * invocation for traceability, parallel to how the single-process formatter records it.
     */
    private function createRunRow(string $tags): string
    {
        $this->runStart = microtime(true);

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), self::OBJ_RUN);
        $ds->getColumns()->addFromSystemAttributes();
        $ds->addRow([
            'started_on'    => DateTimeDataType::now(),
            'behat_command' => 'RunParallel --tags=' . $tags,
        ]);
        $ds->dataCreate(false);

        $uid = $ds->getUidColumn()->getValue(0);
        if ($uid === null || $uid === '') {
            throw new RuntimeException('Run row was created but no UID was returned.');
        }
        return $uid;
    }

    /**
     * Re-reads the run row fresh by UID and applies the given column values.
     *
     * Why re-read instead of reusing the create-time sheet: the sheet captured at creation
     * keeps the create-time modified_on timestamp. Any later dataUpdate() from it fails the
     * optimistic-locking check (ConcurrentWriteError) because the stored row has since moved
     * on. Reading a fresh sheet by UID picks up the current modified_on and updates cleanly.
     *
     * @param array<string,mixed> $values
     */
    private function updateRunRow(string $runUid, array $values): void
    {
        $ds = $this->readRunRow($runUid);
        foreach ($values as $col => $val) {
            $ds->setCellValue($col, 0, $val);
        }
        $ds->dataUpdate();
    }

    /**
     * Stamps finished_on and duration on the run row.
     *
     * Why only these two columns: this matches DatabaseFormatter::onAfterExercise() one to one.
     * There is no status column written anywhere in the formatter, so the coordinator must not
     * invent one - the run's pass/fail picture lives in the child rows the worker wrote.
     * duration is microtime(true) - runStart (seconds), consistent with the existing data.
     */
    private function finalizeRunRow(string $runUid): void
    {
        $this->updateRunRow($runUid, [
            'finished_on' => DateTimeDataType::now(),
            'duration_ms' => microtime(true) - $this->runStart,
        ]);
    }

    /**
     * Loads a single run row by UID with system attributes, ready for update.
     *
     * Why a dedicated helper: every update path needs the same fresh-read-by-UID shape, and
     * centralizing it keeps the optimistic-locking guarantee in one place.
     */
    private function readRunRow(string $runUid): DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), self::OBJ_RUN);
        $ds->getColumns()->addFromSystemAttributes();
        $ds->getFilters()->addConditionFromString(
            $ds->getMetaObject()->getUidAttributeAlias(),
            $runUid,
            ComparatorDataType::EQUALS
        );
        $ds->dataRead();
        if ($ds->countRows() !== 1) {
            throw new RuntimeException('Expected exactly one run row for UID ' . $runUid . ', got ' . $ds->countRows());
        }
        return $ds;
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
        // Per-lane isolated profile dir. A distinct user_data_dir per worker is what lets multiple
        // Chrome instances coexist.
        //
        // IMPORTANT: ChromeManager::start() builds the final path as
        // getcwd() . DIRECTORY_SEPARATOR . <user_data_dir>, i.e. it expects a path RELATIVE
        // to the installation root. An ABSOLUTE path would get getcwd() prepended a second time,
        // produce a broken "C:\...\C:\..." path, and Chrome would fall back to the real default
        // profile and show the "Who's using Chrome?" picker, hanging the lane. So we write the
        // RELATIVE path into the YAML and only use the absolute form to create the directory.
        $userDataDirRelative = 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'BDT' . DIRECTORY_SEPARATOR
            . 'chrome_profiles' . DIRECTORY_SEPARATOR . 'lane' . $lane;
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
     * Resolves the [start, end] port band, preferring a per-project override file.
     *
     * Why a SEPARATE override file (bdt_parallel.yml) instead of a key in behat.yml: behat.yml is
     * worker config, the band is orchestration config. Mixing them risks a worker breaking the
     * band or vice versa. The band is only a SEARCH WINDOW - real collision-safety comes from the
     * runtime free-port probe (allocateFreePort), so two projects with overlapping bands still
     * never clash. A malformed override does not silently guess: it fails loudly.
     *
     * @return int[] [startPort, endPort]
     */
    private function resolvePortBand(string $behatConfig): array
    {
        $overridePath = dirname($behatConfig) . DIRECTORY_SEPARATOR . self::OVERRIDE_FILE;
        if (is_file($overridePath)) {
            $parsed = Yaml::parseFile($overridePath);
            $band = $parsed['port_band'] ?? null;
            if (! is_string($band) || ! preg_match('/^\d+-\d+$/', $band)) {
                throw new RuntimeException(
                    'Malformed ' . self::OVERRIDE_FILE . ': "port_band" must be like "9301-9400", got ' . var_export($band, true)
                );
            }
        } else {
            // Explicit log line rather than a silent guess, so the source of the band is traceable.
            $this->getWorkbench()->getLogger()->info('No ' . self::OVERRIDE_FILE . ' override; using app-config port band');
            $band = (string) $this->getWorkbench()->getApp('axenox.BDT')->getConfig()->getOption(self::CFG_PORT_BAND);
        }
        [$start, $end] = array_map('intval', explode('-', $band));
        if ($end < $start) {
            throw new RuntimeException('Invalid port band ' . $band);
        }
        return [$start, $end];
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
     * Allocates the first currently-free port in the band via a probe, skipping ports already
     * handed to other lanes in THIS run.
     *
     * Collision-safety is LAYERED rather than reserved up front, because the worker - not the
     * coordinator - owns its Chrome lifecycle, so there is no post-launch port the coordinator could
     * "verify and reallocate" without either killing/relaunching the worker or fighting it for that
     * Chrome (and Phase A is deliberately non-blocking, so per-bind verification would serialize
     * startup). The layers:
     *   1. Within this run, $held guarantees two lanes never receive the same port.
     *   2. Across runs/projects, the separate port bands make overlap unlikely, and the probe skips
     *      any already-bound port (open socket = busy, refused = free).
     *   3. The residual probe->bind race (a port that turns busy after we picked it) is NOT silent:
     *      the worker's ChromeManager fails to bring Chrome up on that port, Behat exits non-zero,
     *      and the lane is recorded as a failure during drain.
     * The single case NOT defended here is a LIVE foreign Chrome already on a probed port - the
     * worker's ChromeManager would kill it as a "leftover". Band separation is what prevents that; if
     * bands are ever overlapped across concurrent runs, add explicit reservation instead of relying
     * on the probe.
     *
     * @param int[] $held Ports already assigned to other lanes in this run
     */
    private function allocateFreePort(int $start, int $end, array $held): int
    {
        for ($port = $start; $port <= $end; $port++) {
            if (in_array($port, $held, true)) {
                continue;
            }
            if (! $this->isPortBound($port)) {
                return $port;
            }
        }
        throw new RuntimeException('Port band ' . $start . '-' . $end . ' exhausted - no free port for the next worker');
    }

    /**
     * Builds the Behat worker command: lane config + tags, with the bucket as positional args.
     *
     * Positional feature paths go OUTSIDE --config so Behat runs exactly that bucket. The lane
     * config carries run_uid + lane_id + Chrome port, so configuration flows only through the
     * generated YAML - no BEHAT_PARAMS overrides.
     *
     * @param string[] $bucket
     */
    private function buildWorkerCommand(string $laneConfigPath, string $tags, array $bucket): string
    {
        $cmd = sprintf('vendor\\bin\\behat --config "%s" --tags="%s"', $laneConfigPath, $tags);
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
    private function runFleet(string $cwd, string $behatConfig, string $runUid, string $chromePath, string $tags, array $buckets, string $banner = ''): array
    {
        [$portStart, $portEnd] = $this->resolvePortBand($behatConfig);
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
     * Returns TRUE if something is listening on the port (closed socket connects = busy).
     */
    private function isPortBound(int $port): bool
    {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock !== false) {
            fclose($sock);
            return true;
        }
        return false;
    }

    /**
     * Reads a task parameter, falling back to a default or failing loudly if required.
     *
     * Why fail loudly: a missing path silently defaulting to something wrong would produce a
     * run that looks green but tested nothing - the exact failure mode this framework exists
     * to catch. A required parameter with no value is a configuration error, surfaced here.
     */
    private function getTaskParam(TaskInterface $task, string $name, ?string $default = null): string
    {
        if ($task->hasParameter($name)) {
            $val = $task->getParameter($name);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        if ($default !== null) {
            return $default;
        }
        throw new RuntimeException('Required parameter "' . $name . '" is missing.');
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