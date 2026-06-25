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

    // Phase 3 uses a single fixed port from the nightly band. Free-port discovery within a
    // band is a Phase 4 concern (multiple workers competing for ports); a single worker has
    // no contender, so a constant keeps Phase 3 minimal and deterministic.
    private const PHASE3_FIXED_PORT = 9301;

    // Wall-clock ceiling for the single worker. Even without parallelism we never want a hung
    // Chrome/CDP session to block the coordinator forever; on timeout we kill the worker and
    // finalize the run so it is never left open.
    private const WORKER_TIMEOUT_SECONDS = 1800;

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
     * Entry point. Drives the five-step lifecycle once, with a single worker.
     *
     * The steps mirror exactly what was done by hand in Phase 1, now automated:
     * create run -> compute expected scope -> write lane config -> run worker -> finalize.
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

        // --- Step 1: open the run record (sole creator, so the worker can attach to its UID) ---
        $runUid = $this->createRunRow($tags);

        try {
            // --- Step 2: compute the full expected scope up front ---
            // Done here, not in the worker, because attach-mode workers skip this, and because
            // the expected totals must reflect ALL matched features even if the worker dies
            // partway through (otherwise silent-stop detection is impossible).
            $expected = (new \axenox\BDT\Behat\Common\ExpectedTestCountCalculator())
                ->calculate([$featurePath], $tags);

            // A broken feature file aborts the whole Behat run at parse time, so surface the
            // offenders now rather than letting the worker crash opaquely with exit code 255.
            if ($expected->hasErrors()) {
                throw new RuntimeException(
                    'Feature files failed to parse: ' . implode('; ', array_keys($expected->errors))
                );
            }

            // --- Step 3: persist the expected counts onto the run row ---
            // ExpectedTestCountResult exposes public properties (no getters): featureCount /
            // scenarioCount. We re-read the row fresh by UID before updating (see updateRunRow)
            // to avoid the optimistic-locking ConcurrentWriteError caused by a stale modified_on.
            $this->updateRunRow($runUid, [
                'expected_feature_count'  => $expected->featureCount,
                'expected_scenario_count' => $expected->scenarioCount,
            ]);

            // --- Step 4: generate the single lane config ---
            // run_uid is written INTO the file (not an env var) because the team chose
            // debuggability over env-var fragility - a developer can open the lane file and
            // see exactly which run this worker attaches to.
            $laneConfigPath = $this->writeLaneConfig(
                $cwd,
                1,
                $runUid,
                self::PHASE3_FIXED_PORT,
                $chromePath
            );

            // --- Step 5a: run the single worker synchronously ---
            $this->runSingleWorker($cwd, $laneConfigPath, $tags, $featurePath);

        } catch (\Throwable $e) {
            // Any coordinator-side failure must still close the run row, otherwise it stays
            // open forever with no finished_on. Finalize, then re-throw so the CLI sees it.
            $this->finalizeRunRow($runUid);
            throw new RuntimeException('Parallel run coordinator failed: ' . $e->getMessage(), null, $e);
        }

        // --- Step 5b: finalize (finished_on + duration). Child-row outcomes already live in
        // the DB, written by the worker in attach-mode; we only stamp the run as finished. ---
        $this->finalizeRunRow($runUid);

        return ResultFactory::createMessageResult(
            $task,
            sprintf(
                'Parallel run %s finished. Expected %d features / %d scenarios.',
                $runUid,
                $expected->featureCount,
                $expected->scenarioCount
            )
        );
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
        string $chromePath
    ): string {
        // Per-lane isolated profile dir. A distinct user_data_dir per worker is what lets
        // multiple Chrome instances coexist; for a single Phase 3 worker it still must be a
        // path Chrome can create and own.
        //
        // IMPORTANT: ChromeManager::start() builds the final path as
        // getcwd() . DIRECTORY_SEPARATOR . <user_data_dir>, i.e. it expects a path RELATIVE
        // to the installation root (exactly like BaseConfig.yml's "data\axenox\BDT\ChromeUserData").
        // If we wrote an ABSOLUTE path here, ChromeManager would prepend getcwd() to it and
        // produce a broken "C:\...\C:\..." path. Chrome would then silently fall back to the
        // real user profile dir and show the "Who's using Chrome?" profile picker, hanging the
        // run. So we write the RELATIVE path into the YAML and only use the absolute form to
        // create the directory up front.
        $userDataDirRelative = 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'BDT' . DIRECTORY_SEPARATOR
            . 'chrome_profiles' . DIRECTORY_SEPARATOR . 'lane' . $lane;
        if (! is_dir($userDataDirRelative) && ! @mkdir($userDataDirRelative, 0777, true) && ! is_dir($userDataDirRelative)) {
            throw new RuntimeException('Could not create lane user_data_dir: ' . $userDataDirRelative);
        }

        // Lane file uses a fixed name per band and is overwritten each run - durable truth
        // lives in the DB, so we deliberately do not accumulate lane files.
        $laneConfigPath = $workingDir . DIRECTORY_SEPARATOR . 'behat_scheduled_lane' . $lane . '.yml';

        $extensionFqn = \axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension::class;

        $yaml = "# AUTO-GENERATED lane config - overwritten every run. Do not edit by hand.\n"
            . "imports:\n"
            . "  - behat.yml\n"
            . "default:\n"
            . "  extensions:\n"
            . "    Behat\\MinkExtension:\n"
            . "      sessions:\n"
            . "        CHROME_DEBUG_API:\n"
            . "          chrome:\n"
            . "            api_url: 'http://localhost:" . $port . "'\n"
            . "    \\" . $extensionFqn . ":\n"
            . "      run_uid: '" . $runUid . "'\n"
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
     * Runs the single worker via the Core CLI runner and returns its collected output.
     *
     * Why CliCommandRunner instead of a hand-rolled proc_open: this is the same primitive
     * CliTaskQueue uses to run nightly commands, so worker execution behaves identically to
     * the existing path. It already (1) falls back from Symfony Process to exec() under IIS -
     * exactly the IIS constraint this stack hits - and (2) understands Behat exit codes: we
     * pass ignoredExitCodes=[1] so a normal "some tests failed" (exit 1) is NOT treated as a
     * worker crash, while an internal Behat crash (exit 2) still surfaces as a hard failure.
     *
     * Why silent=false: we WANT exit code 2 to throw, so a genuinely broken worker propagates
     * up to perform()'s catch, which finalizes the run instead of leaving it open.
     *
     * NOTE (Phase 4): runCliCommand() BLOCKS - its generator waits for the process to finish.
     * That is correct for ONE worker, but calling it N times in a row runs workers
     * SEQUENTIALLY, not in parallel. Phase 4 must manage a fleet of async processes itself
     * (Symfony Process::start() + polling, gated by the same IIS detection), not loop this.
     */
    private function runSingleWorker(string $cwd, string $laneConfigPath, string $tags, ?string $featurePath = null): string
    {
        // Positional feature path overrides suite paths ("run only this") - this is how Phase 4
        // hands each worker its bucket. Phase 3: optional. Omit to run the whole tag-filtered
        // suite, or pass one feature to prove a single PASSED case. It must sit OUTSIDE --config.
        $cmd = sprintf('vendor\\bin\\behat --config "%s" --tags="%s"', $laneConfigPath, $tags);
        if ($featurePath !== null && $featurePath !== '') {
            $cmd .= ' "' . $featurePath . '"';
        }

        $output = '';
        foreach (CliCommandRunner::runCliCommand($cmd, [], (float) self::WORKER_TIMEOUT_SECONDS, $cwd, false, [1]) as $chunk) {
            $output .= $chunk;
        }
        return $output;
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