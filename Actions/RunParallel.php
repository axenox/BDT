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

    // Per-project orchestration override. A SEPARATE file from behat.yml on purpose: behat.yml
    // is worker config, this is coordinator config. Mixing them would let a worker accidentally
    // read or break the band. Optional - absent means "use app-config defaults".
    private const OVERRIDE_FILE = 'bdt_parallel.yml';

    // Meta object alias - identical to the one DatabaseFormatter writes to, so the
    // coordinator and the worker operate on the very same run row.
    private const OBJ_RUN = 'axenox.BDT.run';

    /**
     * Captured at run-row creation so the finalize step can compute the same wall-clock
     * duration the single-process formatter computes (microtime delta, in seconds - the
     * duration_ms column historically stores seconds in this codebase).
     */
    private float $runStart = 0.0;

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

            // --- Step 5: run the fleet. Wrapped so a worker failure still reaches finalize. ---
            $failures = $this->runFleet($cwd, $behatConfig, $runUid, $chromePath, $tags, $buckets);

        } catch (\Throwable $e) {
            // Any coordinator-side failure must still close the run row, otherwise it stays open
            // forever with no finished_on. Finalize, then re-throw so the CLI sees it.
            $this->finalizeRunRow($runUid);
            throw new RuntimeException('Parallel run coordinator failed: ' . $e->getMessage(), null, $e);
        }

        // --- Finalize exactly once, after all workers exit. Child-row outcomes already live in
        // the DB, written by the workers in attach-mode; we only stamp the run as finished. ---
        $this->finalizeRunRow($runUid);

        // The normal Behat flow was written to data/axenox/BDT/Logs/<run_uid>.log. The scheduled
        // queue message stays terse: only worker errors are returned (with the log path to dig in);
        // if every lane passed, just a short confirmation referencing the log.
        $logPath = 'data/axenox/BDT/Logs/' . $runUid . '.log';
        if (! empty($failures)) {
            $lines = [];
            foreach ($failures as $lane => $err) {
                $lines[] = 'Lane ' . $lane . ': ' . $err;
            }
            $msg = sprintf('Parallel run %s finished with %d worker error(s):', $runUid, count($failures))
                . "\n" . implode("\n", $lines)
                . "\nSee full log: " . $logPath;
        } else {
            $msg = sprintf('Parallel run %s finished, no worker errors. See log: %s', $runUid, $logPath);
        }

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
        if (! is_dir($userDataDirRelative) && ! @mkdir($userDataDirRelative, 0777, true) && ! is_dir($userDataDirRelative)) {
            throw new RuntimeException('Could not create lane user_data_dir: ' . $userDataDirRelative);
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
     * Allocates the first currently-free port in the band via a probe, skipping already-held ones.
     *
     * Why probe instead of just reserving: the band is shared across projects, so a statically
     * assigned port can already be taken. fsockopen with a short timeout tells us if a port is
     * busy (open socket = busy, refused = free). The caller verifies the bind after Chrome starts
     * and reallocates on failure, handling the probe->bind race.
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
     * Launches the whole fleet concurrently via CliCommandRunner, then drains every lane.
     *
     * The verified runtime is CLI with no SERVER_SOFTWARE, so canUseSymfonyProcess() is TRUE and
     * the IIS exec() branch never applies - there is exactly ONE spawn path. We exploit that on
     * the Symfony branch runCliCommand() calls Process::start() BEFORE returning its generator:
     * launching all lanes (Phase A) gets N workers running in parallel, and iterating each
     * generator (Phase B) only waits, so wall-clock is ~max(worker), not sum(worker). silent=false
     * + ignoredExitCodes=[1] means a normal "some tests failed" (exit 1) is fine, while a crash
     * (exit 2) or timeout throws during drain - caught per lane so one failure cannot abort the
     * others or skip finalize. $failures is coordinator-side logging only; run status lives in the
     * attach-mode child rows.
     *
     * @param string[][] $buckets
     * @return array<int,string> Worker failures keyed by lane; only these reach the queue message
     * @throws \Throwable
     */
    private function runFleet(string $cwd, string $behatConfig, string $runUid, string $chromePath, string $tags, array $buckets): array
    {
        [$portStart, $portEnd] = $this->resolvePortBand($behatConfig);
        $timeout = $this->resolveWorkerTimeout();
        $heldPorts = [];
        // Import the base config by its real filename (e.g. "Behat.yml"), so the lane import
        // matches the actual file even on case-sensitive systems instead of assuming "behat.yml".
        $importConfigName = basename($behatConfig);

        // The normal Behat stream is verbose; instead of returning it to the scheduled queue we
        // write it to one file per run named by run_uid under data/axenox/BDT/Logs. The queue gets
        // only error messages (see perform()), the full flow stays inspectable on disk.
        $logFile = $this->openRunLog($cwd, $runUid);

        // Phase A - launch: each runCliCommand() starts its worker and returns a generator without
        // blocking, so all lanes run concurrently. Each lane gets a runtime-free port + lane config.
        $streams = [];
        foreach ($buckets as $idx => $bucket) {
            $lane = $idx + 1;
            $port = $this->allocateFreePort($portStart, $portEnd, $heldPorts);
            $heldPorts[] = $port;
            $laneConfig = $this->writeLaneConfig($cwd, $lane, $runUid, $port, $chromePath, $importConfigName);
            $cmd = $this->buildWorkerCommand($laneConfig, $tags, $bucket);
            $streams[$lane] = CliCommandRunner::runCliCommand($cmd, [], $timeout, $cwd, false, [1]);
        }

        // Phase B - drain: iterating is where the wait happens, but processes already run in
        // parallel. Each drain is wrapped so one lane's exit-2 crash or timeout is recorded WITHOUT
        // aborting the others; finalize still runs once afterwards (no orphaned open run).
        $failures = [];
        foreach ($streams as $lane => $stream) {
            $this->writeRunLog($logFile, '===== Lane ' . $lane . ' =====');
            try {
                foreach ($stream as $chunk) {
                    $this->writeRunLog($logFile, $chunk); // normal flow goes to the log, not the queue
                }
            } catch (\Throwable $e) {
                $failures[$lane] = $e->getMessage();
                $this->writeRunLog($logFile, 'LANE ' . $lane . ' FAILED: ' . $e->getMessage());
                $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' failed: ' . $e->getMessage());
            }
        }

        if (is_resource($logFile)) {
            fclose($logFile);
        }
        return $failures;
    }

    /**
     * Opens (creating the dir) data/axenox/BDT/Logs/<run_uid>.log for append.
     *
     * One file per run keeps every lane's normal Behat output together and findable by UID. Append
     * mode tolerates re-runs without truncating earlier diagnostics.
     *
     * @return resource
     */
    private function openRunLog(string $cwd, string $runUid)
    {
        $dir = $cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'Logs';
        if (! is_dir($dir) && ! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create BDT log directory: ' . $dir);
        }
        $handle = @fopen($dir . DIRECTORY_SEPARATOR . $runUid . '.log', 'a');
        if ($handle === false) {
            throw new RuntimeException('Could not open run log file for run ' . $runUid);
        }
        return $handle;
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
}