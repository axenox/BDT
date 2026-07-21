<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
use axenox\BDT\Behat\Common\Traits\PortProbingTrait;
use axenox\BDT\Behat\Common\RunRecordWriter;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
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
 * Coordinator action for parallel BDT test execution, driven by a DYNAMIC WORK QUEUE.
 *
 * WHY A QUEUE INSTEAD OF STATIC BUCKETS:
 * Features used to be split into N disjoint buckets up front, one long-lived Behat process per
 * bucket. That distribution had two structural defects. First, when a lane was killed (idle hang or
 * wall-clock ceiling) every remaining feature in its bucket was simply never executed - it produced
 * no child row at all, so the run's shortfall showed up only as "expected > actual", the silent
 * outcome this framework exists to prevent. Second, buckets never rebalance: a lane that drew light
 * features finished early and then sat idle while a lane with heavy features still ran, so
 * wall-clock was dictated by the unluckiest bucket rather than by the total workload.
 *
 * The queue fixes both. All matched features go into ONE ordered queue owned by the coordinator.
 * Each lane is a SLOT (fixed port, fixed lane config, fixed profile dir) that executes exactly ONE
 * feature per Behat process; when that process exits, the slot takes the next feature from the
 * queue. Work therefore flows to whichever lane is free, so no lane idles while work remains.
 *
 * WHY THE QUEUE IS COORDINATOR-OWNED AND IN-MEMORY: the obvious alternative - a shared queue table
 * that workers claim rows from - would need atomic claim logic (row locks, in-flight vs. unstarted
 * bookkeeping, stale-claim recovery) and would add a fresh contention point to a system that already
 * fights optimistic-locking conflicts. Here the coordinator is the only process that ever touches
 * the queue, so there is no concurrency to arbitrate: no claim protocol, no lock, no new table.
 *
 * WHY ONE FEATURE PER PROCESS: it makes the process boundary carry the bookkeeping. The feature a
 * lane was executing when it timed out is unambiguous (there is exactly one), so it can be recorded
 * as failed instead of vanishing, and the untouched remainder of the queue keeps flowing to other
 * lanes. It also bounds the timeouts to a single feature rather than to a whole bucket, which turns
 * them from a blunt ceiling into a precise "this feature hung" signal.
 *
 * POISON-FEATURE POLICY: a feature killed by a timeout is NEVER requeued. Re-serving it to the next
 * free lane would let one pathological feature hang every lane in turn and consume the whole run.
 * It is recorded as a worker failure exactly once and the run continues without it.
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

    // Wall-clock ceiling per FEATURE RUN, used as the FALLBACK when PARALLEL.WORKER_TIMEOUT_SECONDS
    // is not set in app config. We never use runCliCommand's 60s default - a Behat run needs far
    // longer. Symfony Process enforces this per worker process and throws on exceedance; that throw
    // is caught per-lane in the drain phase, so a hung worker is a recorded failure, not a stall.
    //
    // NOTE ON SEMANTICS SINCE THE QUEUE: a worker process now executes exactly ONE feature, so this
    // ceiling bounds a single feature rather than a whole bucket. That makes it far sharper than it
    // used to be - it can be tightened to a realistic per-feature duration, and when it fires it
    // names the one feature that hung instead of aborting everything a lane had left to do.
    // This is a TOTAL (wall-clock) ceiling: it fires even while the worker is still making
    // progress. Set PARALLEL.WORKER_TIMEOUT_SECONDS to 0 in app config to disable it entirely
    // and rely purely on the idle timeout below.
    private const WORKER_TIMEOUT_SECONDS = 1800;

    // Idle (inactivity) ceiling per worker, used as the FALLBACK when
    // PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS is not set in app config. In contrast to the TOTAL
    // timeout above, the idle timer RESETS whenever the lane shows PROGRESS - and progress here means
    // EITHER new console output OR a  growing run_step count for this lane's own featuresin the DB (the coordinator
    // polls that count during the drain). This DB-aware definition is deliberate: a long
    // works-as-expected step emits NO stdout while it runs, it only keeps INSERTing run_step rows per
    // substep, so an output-only idle timeout would wrongly kill a lane that is actually progressing.
    // Only a lane that has produced neither signal for this many seconds (a genuine hang) times out.
    // Since the queue landed, the DB half of that signal is scoped to the ONE feature the lane is
    // currently executing, which is strictly more precise than the old whole-bucket sum.
    // Set to 0 in app config to disable.
    private const WORKER_IDLE_TIMEOUT_SECONDS = 600;

    // How many CONSECUTIVE timeouts a lane may suffer before it is retired from the queue rotation.
    //
    // WHY A LANE CAN BE RETIRED AT ALL: after a timeout we cannot tell whether the FEATURE hung or
    // the LANE itself is broken (a Chrome that never comes up on that port, a profile dir we cannot
    // clear). The poison-feature policy handles the first case by never requeuing the feature, but
    // if the lane is the broken party it would keep accepting features and time out on each one,
    // burning the entire queue at one timeout per feature. Requiring several consecutive timeouts
    // before retiring the lane distinguishes the two: an isolated bad feature leaves the lane usable
    // (the next feature completes and resets the counter), while a genuinely broken lane trips the
    // limit quickly and steps out so the remaining work goes to lanes that still function.
    private const LANE_MAX_CONSECUTIVE_TIMEOUTS = 2;

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
        'XDEBUG_MODE'         => 'off',
        'XDEBUG_SESSION'      => false,
        'XDEBUG_TRIGGER'      => false,
        // Force the ExFace Monitor OFF in every lane worker. UI5BrowserContext boots its own workbench
        // with monitoring ON by default (so manual runs keep it), and reads this var to override that.
        // Set as a string because Symfony Process removes a var only when the value is false; a string
        // value is what actually SETS the var in the child environment.
        'BDT_MONITOR_ENABLED' => '0',
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
     * Relative path of THIS run's own log directory (data/axenox/BDT/Logs/<YYYYMMDD>/<run_uid>),
     * remembered so the final CLI message and the run-log can point at it without rebuilding the
     * path - and without the caller having to know how the directory is laid out.
     *
     * Why relative and not absolute: it is only ever shown to a human, who reads it against the
     * installation root they already know; an absolute path on a Windows server adds noise.
     */
    private string $runLogDirRelative = '';

    /**
     * The living run-log for the DB "log" column. It is a MarkdownLogBook (the framework's existing
     * structured-log type, also used by DatabaseFormatter for step logbooks) that the coordinator
     * appends to AS THE RUN HAPPENS: run facts up front, then one section per FEATURE RUN, created when that
     * feature is dispatched to a lane and filled in when its worker finishes, times out or fails. Building
     * it live means the events the coordinator observes firsthand (launch, exit, timeout, worker failure)
     * are recorded without re-parsing, and each feature's Behat summary is parsed once from its own
     * now-closed worker log at the moment it ends.
     * Persisted (capped) onto the run row in the single close-out. Nullable so a very early failure
     * before it is initialised still finalises cleanly.
     */
    private ?MarkdownLogBook $runLog = null;

    // --- Run-log digest bounds. The run log is an ORCHESTRATION log, not a test report: per-scenario
    // and per-step outcomes are already persisted authoritatively as child rows by the attach-mode
    // DatabaseFormatter, so repeating them here only pushed the coordinator-level diagnostics (worker
    // errors, launch failures, the coordinator error itself) past the size cap. What stays is the run
    // configuration, the per-lane worker status and Behat's counts block; the verbose test output stays
    // in the on-disk worker log, which every feature section now names. ---
    private const LOG_TAIL_READ_BYTES  = 65536;
    private const LOG_CRASH_TAIL_LINES = 40;
    // Raised: this tail is only produced for a lane whose worker actually DIED, which is rare, so a
    // slightly larger budget costs nothing on a healthy run but buys headroom for a stack trace.
    private const LOG_CRASH_TAIL_BYTES = 4096;
    private const LOG_TOTAL_MAX_BYTES  = 65536;
    /**
     * Age past which an unfinished run row can no longer own a live Chrome.
     *
     * WHY: a coordinator killed by an app-pool recycle never writes finished_on, so its row stays
     * "active" forever. Nothing legitimate can still be running that long after the row was opened,
     * so past this age its profiles are reclaimable.
     */
    private const RUN_MAX_AGE_MINUTES = 180;

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

        // Reclaim Chrome processes and profile dirs of runs that are provably no longer active. This is
        // identity-based (the profile dir name carries its owning run UID), so it reclaims a fleet that
        // crashed minutes ago - unlike the age-based sweep below, which cannot touch anything recent
        // without risking a live run's browser. Both are needed: identity for lane profiles, age for
        // everything we cannot attribute (interactive profiles, half-deleted leftovers).
        $activeRunUids = $this->findActiveRunUids($runUid);
        if ($activeRunUids !== null) {
            foreach ($this->reapProfilesOfInactiveRuns($this->chromeProfilesRoot($cwd), $activeRunUids) as $line) {
                $this->getWorkbench()->getLogger()->info('BDT orphan run sweep: ' . $line);
                $this->runLog->addLine($line, 1, 'Run summary');
            }
        }
        foreach ($this->reapStaleChromeProfiles($this->chromeProfilesRoot($cwd), 6 * 3600) as $line) {
            $this->getWorkbench()->getLogger()->info('BDT stale profile sweep: ' . $line);
            $this->runLog->addLine($line, 1, 'Run summary');
        }

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
            // An empty scope must not fall through to a lane with no feature to run: buildWorkerCommand()
            // with no positional path makes Behat run the ENTIRE suite, contradicting an expected count of 0 and
            // producing a wildly wrong run. Finalize cleanly and report instead.
            if ($matchedFiles === []) {
                $runRecordWriter->finalize($this->runDataSheet);
                return ResultFactory::createMessageResult(
                    $task,
                    sprintf('Parallel run %s: no feature files matched the requested scope/tags. Nothing to run.', $runUid)
                );
            }
            // Lane count is still capped by the number of matched features - starting more lanes than
            // there is work for would only allocate ports and profile dirs that never run anything.
            // Beyond that cap the features are NOT pre-assigned: they form one queue that the lanes
            // pull from as they become free (see runFleet).
            $maxWorkers   = $this->resolveMaxWorkers();
            $workerCount  = max(1, min($maxWorkers, count($matchedFiles)));

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
            $failures = $this->runFleet($cwd, $behatConfig, $runUid, $chromePath, $tags, $matchedFiles, $workerCount, $banner);

        } catch (\Throwable $e) {
            // Record the coordinator failure but do NOT finalize/throw yet: the single close-out below
            // must run first so the run row is logged AND finalized on this path too. Re-thrown after.
            $coordinatorError = $e;
        } finally {
            // WHY THE GUARD: this is the last line of defence against leaked Chrome trees. It must be
            // impossible for it to be skipped - including by an exception from the logger it uses, which
            // writes to a database that may be exactly what is broken. Housekeeping never propagates.
            try {
                $this->cleanupLaneChromes($cwd, $runUid);
            } catch (\Throwable $e) {
                $this->getWorkbench()->getLogger()->logException($e);
            }
        }

        // --- Single close-out: stage the run-log digest, then finalize exactly once. ---
        // Runs on BOTH the normal path and the coordinator-error path, so a run that failed at
        // coordinator level still pulls whatever worker logs exist on disk into the DB. The digest is
        // staged onto the run sheet and persisted by finalize()'s single dataUpdate - no extra
        // optimistic-lock round-trip. stageRunLog() swallows its own errors, so a digest problem can
        // never stop finished_on from being written.
        $this->stageRunLog($runRecordWriter, $runUid, $coordinatorError);
        $runRecordWriter->finalize($this->runDataSheet);

        // A coordinator-level failure is re-thrown now that the run row is closed AND logged.
        if ($coordinatorError !== null) {
            throw new RuntimeException('Parallel run coordinator failed: ' . $coordinatorError->getMessage(), null, $coordinatorError);
        }

        // Worker output lives in one directory per run:
        // data/axenox/BDT/Logs/<YYYYMMDD>/<run_uid>/, holding coordinator.log plus one
        // lane<N>_<seq>_<feature>.log per feature run. Grouping by run keeps a run's logs a single unit
        // and stops hundreds of per-feature files from burying each other in one flat directory.
        //
        // A failure here means the WORKER ITSELF failed (crash, signal/timeout termination, a launch
        // failure, or a feature that never got a lane) - NOT that some of its tests failed. Behat's
        // exit 1 is treated as a normal completion because per-scenario pass/fail is recorded
        // authoritatively in the attach-mode child rows. When a worker fails fatally, the whole run
        // must fail so a scheduled task/queue marks it red. We therefore THROW when $failures is
        // non-empty - AFTER finalize above, so the run row is always closed. The exception message
        // names each failing feature and points at its own log. If no worker failed fatally, we return
        // a terse success message referencing the run's log directory (individual test failures are
        // still visible in the child rows).
        //
        // The directory is resolved by runFleet; if the run failed before that, fall back to the base
        // Logs path so the message still points somewhere real instead of at an empty string.
        $logDirRel = $this->runLogDirRelative !== '' ? $this->runLogDirRelative : 'data/axenox/BDT/Logs';
        if (! empty($failures)) {
            $lines = [];
            foreach ($failures as $failure) {
                // A feature that never started has no log file of its own - say so instead of naming a
                // file that does not exist and sending the reader on a hunt for it.
                $where = $failure['log'] !== null
                    ? ' (' . $failure['log'] . ')'
                    : ' (no log - never started)';
                $lines[] = 'Lane ' . $failure['lane'] . ' feature ' . $failure['feature'] . $where . ': ' . $failure['error'];
            }
            throw new RuntimeException(
                $this->startupBanner . "\n"
                . sprintf('Parallel run %s finished with %d worker error(s):', $runUid, count($failures))
                . "\n" . implode("\n", $lines)
                . "\nRun logs: " . $logDirRel
            );
        }

        $msg = $this->startupBanner . "\n"
            . sprintf('Parallel run %s finished, no worker errors. Run logs: %s', $runUid, $logDirRel);
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
        // IMPORTANT: the config value must stay RELATIVE - ChromeManager::start() prepends getcwd().
        // An absolute path would be double-prepended and make Chrome fall back to the real default
        // profile. It is DERIVED from the absolute path rather than rebuilt by hand: two independent
        // constructions of the same path are exactly how the reapers drifted away from the profile
        // dir they were supposed to clean up.
        $userDataDirAbsolute = $this->laneProfileDir($workingDir, $runUid, $lane);
        $userDataDirRelative = ltrim(substr($userDataDirAbsolute, strlen($workingDir)), DIRECTORY_SEPARATOR);
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
     * Prepares a Windows path for embedding in a SINGLE-QUOTED YAML scalar.
     *
     * WHY NO BACKSLASH DOUBLING: in single-quoted YAML a backslash is a literal character -
     * the only escape is '' for a quote. The previous doubling therefore CHANGED the value:
     * Symfony Yaml handed the doubled string to ChromeManager, Chrome was launched with
     * "data\\axenox\\..." and, while Win32 path handling tolerates repeated separators, every
     * string-equality check in the Chrome reapers compared against the coordinator's
     * single-separator paths and silently matched nothing - so orphaned Chrome trees and their
     * locked profile dirs leaked on every killed lane. Only single quotes need escaping here.
     */
    private function yamlEscapeWindowsPath(string $path): string
    {
        return str_replace("'", "''", $path);
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
            'Lanes:    ' . $workerCount . ' (' . $reason . ')',
            'Features: ' . $matchedFeatures . ' queued, dispatched one per worker process as lanes free up',
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
     * Builds the Behat worker command for ONE feature: lane config + optional tag filter + the single
     * feature path as a positional argument.
     *
     * The positional feature path goes OUTSIDE --config so Behat runs exactly that one file. The lane
     * config carries run_uid + lane_id + Chrome port, so configuration flows only through the generated
     * YAML - no BEHAT_PARAMS overrides.
     *
     * WHY EXACTLY ONE FEATURE: the process boundary is what makes the queue safe. With one feature per
     * process, the feature a lane was running when it was killed is unambiguous, so it can be recorded
     * as failed while the rest of the queue keeps flowing; with several, a kill would again lose an
     * unknown remainder silently.
     *
     * Why --tags is conditional: when scope came from --feature/--suite with no tag filter, we OMIT
     * --tags rather than pass --tags="". An empty tag expression is not "no filter" to Behat, and it
     * would diverge from ExpectedTestCountCalculator (which counts ALL scenarios when the tag expression
     * is empty), breaking expected==actual.
     */
    private function buildWorkerCommand(string $laneConfigPath, ?string $tags, string $feature): string
    {
        // Every interpolated value is validated before it reaches the shell string - see
        // assertShellSafe() for why this is a hard failure rather than an escaping attempt.
        $cmd = sprintf('vendor\\bin\\behat --config "%s"', $this->assertShellSafe($laneConfigPath, 'behat config path'));
        if ($tags !== null && trim($tags) !== '') {
            $cmd .= sprintf(' --tags="%s"', $this->assertShellSafe(trim($tags), 'tag expression'));
        }
        $cmd .= ' "' . $this->assertShellSafe($feature, 'feature path') . '"';
        return $cmd;
    }

    /**
     * Rejects any value that would break out of the double-quoted argument it is interpolated into.
     *
     * WHY THIS EXISTS: the worker command is assembled as a shell string, so an operator-supplied value
     * containing a quote or a cmd metacharacter could terminate the argument and append commands of its
     * own, which would then run with the coordinator's privileges. The tag expression in particular
     * comes straight from the caller, and this action is intended to be reachable from a web UI by
     * users far less privileged than the account the fleet runs under.
     *
     * WHY IT REFUSES INSTEAD OF ESCAPING: quoting rules on Windows cmd are genuinely ambiguous - the
     * same string is parsed differently by cmd, by the .bat stub and by the PHP process it finally
     * reaches - so any escaping scheme silently mangles some legitimate inputs while still leaving
     * gaps. None of these characters has a legitimate place in a tag expression or a feature path, so
     * refusing loudly is both safe and lossless.
     *
     * @param string $value The value about to be interpolated into the command string.
     * @param string $what  Human-readable name of the value, used in the error message.
     * @return string The value unchanged, so this can be used inline at the interpolation site.
     * @throws RuntimeException if the value contains a shell metacharacter.
     */
    private function assertShellSafe(string $value, string $what): string
    {
        if (preg_match('/["`^&|<>%!\r\n]/', $value) === 1) {
            throw new RuntimeException(
                'Refusing to build a worker command: the ' . $what . ' contains a shell metacharacter. Got: '
                . var_export($value, true)
            );
        }
        return $value;
    }

    /**
     * Makes sure a lane's Chrome profile dir exists immediately before a worker is launched into it.
     *
     * WHY IT IS NEEDED PER FEATURE AND NOT ONLY PER LANE: writeLaneConfig() creates the dir once when
     * the slot is set up, but reapLaneProfile() DELETES it after every feature run - deliberately, so
     * the next feature in that slot cannot inherit a locked ProcessSingleton file or a half-written
     * profile from the previous one. Without recreating it here, every feature after the first in a
     * lane would launch Chrome against a missing directory.
     *
     * @param string $workingDir Installation root (same base writeLaneConfig() built the profile under)
     * @param string $runUid     UID of the run owning the lane
     * @param int    $lane       Lane number
     * @throws RuntimeException if the directory cannot be created
     */
    private function ensureLaneProfileDir(string $workingDir, string $runUid, int $lane): void
    {
        $dir = $this->laneProfileDir($workingDir, $runUid, $lane);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create lane user_data_dir: ' . $dir);
        }
    }

    /**
     * Records one worker-level failure in the structured shape perform() reports from.
     *
     * WHY STRUCTURED RATHER THAN A lane => message MAP: a lane now runs many features, so a map keyed
     * by lane would let a later failure in the same lane overwrite an earlier one - losing failures is
     * precisely what the queue was built to stop. A flat list keeps every failure, and carrying the
     * feature and its own log file with each entry means the final message can point the reader
     * straight at the evidence.
     *
     * @param array<int,array> $failures  Failure list, appended to in place.
     * @param int              $lane      Lane the feature was assigned to.
     * @param string           $feature   Feature file that failed.
     * @param string|null      $logName   Basename of that feature run's log, or NULL if it never started.
     * @param string           $error     Human-readable worker-level failure reason.
     */
    private function recordFailure(array &$failures, int $lane, string $feature, ?string $logName, string $error): void
    {
        $failures[] = [
            'lane'    => $lane,
            'feature' => $feature,
            'log'     => $logName,
            'error'   => $error,
        ];
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
     * Returns the current run_step count of the given run, keyed by the feature file each step belongs to,
     * WITHOUT loading the step rows themselves.
     *
     * Why the count is read from run_feature and not from run_step: reading run_step with a plain
     * "filename" column plus an aggregated count forces the SQL builder into a GROUP BY, and it also adds
     * the object's system columns (modified_on) to the SELECT - which belong to neither the aggregate nor
     * the GROUP BY clause and make MS SQL reject the whole statement. The query therefore failed on EVERY
     * poll, was swallowed by the catch below and returned null, silently disabling the per-lane heartbeat
     * for the entire lifetime of this feature. Aggregating over the reverse relation instead gives one row
     * per feature by construction, so no GROUP BY (and no system-column trap) is involved at all.
     *
     * Why grouped per feature and not one fleet-wide total: the drain loop needs a PER-LANE liveness signal.
     * A fleet-wide count grows as soon as ANY lane inserts a step, which would reset the idle clock of a lane
     * that is genuinely hung just because a sibling lane is healthy. Since a lane executes exactly ONE feature
     * at a time and no feature is ever handed to two lanes, per-feature counts let each lane be judged purely
     * by the steps of the feature it is running right now.
     *
     * Never throws: a transient DB hiccup during polling must not abort an otherwise healthy fleet, so a
     * failure is logged and returned as null ("unknown"), letting the caller keep the previous per-lane
     * counters for that pass.
     *
     * @param string $runUid The run whose child run_step rows to count.
     * @return array<string,int>|null Map of feature key (see featureKeyFromPath) => step count, or null on failure.
     */
    private function countRunStepsByFeature(string $runUid): ?array
    {
        try {
            // Count per feature in PHP from a plain (non-aggregated) read instead of a grouped SQL query.
            // Every SQL-side variant failed on MS SQL Server: the original run_feature reverse aggregation
            // produced SUM((SELECT COUNT(...))) (aggregate over a subquery with an aggregate), and reading
            // from run_step with a :COUNT column never emitted a GROUP BY - the sheet auto-adds the
            // modified_on system column as a bare, ungrouped column, which MS SQL rejects ("... not
            // contained in either an aggregate function or the GROUP BY clause"). MySQL tolerates both,
            // hence the local/server split. A plain read carries system columns harmlessly, so we group the
            // rows ourselves. The per-poll row volume is O(steps in the run); acceptable for a heartbeat, but
            // if a very large run makes this heavy, switch to one scoped COUNT query per feature.
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.run_step');
            $ds->getFilters()->addConditionFromString(
                'run_scenario__run_feature__run',
                $runUid,
                ComparatorDataType::EQUALS
            );
            $fileCol = $ds->getColumns()->addFromExpression('run_scenario__run_feature__filename');
            $ds->dataRead();

            $counts = [];
            foreach (array_keys($ds->getRows()) as $i) {
                $file = (string) $fileCol->getValue($i);
                if ($file === '') {
                    continue;
                }
                // Normalize the same way featureKeyFromPath() does (forward slashes + lower case) so these
                // keys match the lane bucket keys byte for byte; a back-slashed DB filename would otherwise
                // never equal a forward-slashed lane key and silently blind the per-lane heartbeat.
                $key = mb_strtolower(FilePathDataType::normalize($file, '/'));
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            return $counts;
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return null;
        }
    }

    /**
     * Converts a feature file path into the exact key the workers store in run_feature.filename.
     *
     * Why it must mirror DatabaseFormatter::onBeforeFeature() byte for byte: that hook normalizes the
     * Gherkin file path to forward slashes and strips the vendor folder prefix before the INSERT. If the
     * coordinator built its key any other way (raw absolute path, basename), a lane's bucket would never
     * match the rows its own worker writes - silently disabling the per-lane heartbeat and letting a
     * healthy lane be killed as "idle".
     *
     * Lower-cased because Windows paths are case-insensitive while the array lookup here is not.
     *
     * @param string $path Feature file path as handed to the worker.
     * @return string Vendor-relative, forward-slashed, lower-cased key.
     */
    private function featureKeyFromPath(string $path): string
    {
        $normalized = FilePathDataType::normalize($path, '/');
        $vendorPath = FilePathDataType::normalize($this->getWorkbench()->filemanager()->getPathToVendorFolder(), '/') . '/';
        return mb_strtolower(
            StringDataType::substringAfter($normalized, $vendorPath, $normalized));
    }

    /**
     * Sums the step counts of the feature file(s) a lane is currently accountable for.
     *
     * Exists so the drain loop can turn the fleet-wide grouped count into the single monotonically
     * growing number that represents THIS lane's progress. Since the queue landed, a lane runs one
     * feature at a time, so the key list normally holds exactly one entry - the sum then IS that
     * feature's step count. The array form is kept because it makes the caller independent of that
     * detail and costs nothing.
     *
     * @param array<string,int> $countsByFeature Grouped counts from countRunStepsByFeature().
     * @param string[]          $featureKeys     Keys of the feature(s) this lane is currently running.
     */
    private function sumLaneSteps(array $countsByFeature, array $featureKeys): int
    {
        $sum = 0;
        foreach ($featureKeys as $key) {
            $sum += $countsByFeature[$key] ?? 0;
        }
        return $sum;
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
    private function killHungLane(int $lane, Process $process, $laneLog, $diagLog, string $reason, string $cwd, string $runUid): void
    {
        // File-based logging first: it does not depend on the database and therefore cannot fail while
        // the DB is unavailable (a full PRIMARY filegroup, a lock, a dropped connection).
        $this->writeRunLog($laneLog, 'LANE ' . $lane . ' ' . strtoupper($reason));
        $this->writeRunLog($diagLog, sprintf('DIAG drain: lane %d %s at +%.1f s', $lane, $reason, microtime(true) - $this->runStart));

        // WHY RECLAMATION COMES BEFORE LOGGING: the workbench logger writes to the database. When the
        // database cannot accept writes - as happened when the PRIMARY filegroup ran full - that call
        // THROWS, and everything after it in this method is skipped. Previously that meant the detached
        // Chrome tree was never killed and its profile dir never removed, for every lane that timed out,
        // on every run. Freeing OS resources must never be gated on our ability to record that we did.
        $process->stop(0); // SIGKILL-equivalent; release the slot immediately
        try {
            // Kill the DETACHED Chrome tree this worker left behind and drop its profile dir NOW, while
            // the coordinator is still alive - an all-timeout run may never reach the end-of-run backstop.
            $this->reapLaneProfile($lane, $cwd, $runUid);
        } catch (\Throwable $e) {
            $this->writeRunLog($diagLog, 'DIAG drain: lane ' . $lane . ' profile reap failed: ' . $e->getMessage());
        }
        $this->finishLaneLog($laneLog);

        // Best-effort DB logging LAST, and never fatal: by this point the lane is already killed and its
        // resources reclaimed, so a failing logger can only cost us a log line, not a leaked browser.
        try {
            $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' ' . $reason);
        } catch (\Throwable $e) {
            $this->writeRunLog($diagLog, 'DIAG drain: lane ' . $lane . ' could not write the error to the log: ' . $e->getMessage());
        }
    }

    /**
     * Runs the whole feature queue across N lane slots, keeping every lane fed until the queue is empty.
     *
     * The verified runtime is CLI, so we drive Symfony Process directly here instead of through
     * CliCommandRunner's generator: a generator can only be drained with a blocking foreach, which
     * serializes the fleet (lane N+1 is not even read until lane N's process exits). That blocking
     * sequential drain - not workerCount, not the port band, not Process::start() - was the measured
     * cap that pinned real concurrency to a 2-wide pipeline. The structure is therefore:
     *
     *   Phase A - slot setup: allocate one port and write one lane config per lane, ONCE. A slot is
     *             durable for the whole run: same port, same lane config, same profile dir, reused by
     *             every feature that lane executes.
     *   Phase B - queue drain: fill every free slot with the next feature from the queue, then poll ALL
     *             running processes in a round-robin loop, reading each one's incremental output
     *             non-blockingly. When a process exits, its slot is released and IMMEDIATELY refilled
     *             from the queue. No lane can block another, and no lane sits idle while work remains,
     *             so wall-clock approaches total-work/lanes instead of being dictated by the unluckiest
     *             static bucket.
     *
     * WHY THE QUEUE IS SAFE WITHOUT LOCKING: this coordinator is the only process that ever reads or
     * writes it. Workers are handed a single feature on the command line and know nothing about the
     * queue, so there is no claim protocol to get wrong and no contention to arbitrate.
     *
     * FAILURE ACCOUNTING: every feature leaves a trace. One that runs produces child rows; one whose
     * worker is killed or crashes is recorded here as a worker failure naming that exact feature; one
     * that never got a lane at all (all lanes retired) is recorded as never started. Nothing is
     * dropped, which is the whole point - the old static buckets discarded a killed lane's remaining
     * features silently and left only an expected-vs-actual shortfall behind.
     *
     * Exit-code classification is unchanged: a worker fails only when the WORKER ITSELF fails - a crash
     * (exit 2/255) or a signal/timeout termination (null exit code). Behat's exit 1 ("some tests
     * failed") is NOT a worker failure, because authoritative per-scenario results live in the
     * attach-mode child rows. Failures are recorded WITHOUT aborting the run, so finalize still happens
     * once; the caller then throws if any were recorded.
     *
     * @param string[] $queue       All matched feature files, executed in this order as lanes free up
     * @param int      $workerCount Number of lane slots to set up
     * @param string   $banner      Startup banner echoed into the coordinator log so the run's log opens
     *                              with the same expectation the user saw on the console
     * @return array<int,array> Worker failures; non-empty means the run must fail
     * @throws \Throwable
     */
    private function runFleet(
        string $cwd,
        string $behatConfig,
        string $runUid,
        string $chromePath,
        ?string $tags,
        array $queue,
        int $workerCount,
        string $banner = ''
    ): array {
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
        // high-frequency orchestration traces. One coordinator log per run sits next to the per-worker
        // logs so the whole fleet's timeline is greppable in a single file.
        $logDir = $this->ensureRunLogDir($cwd, $runUid);
        // Record the directory on the run row itself: the run-log is what a user opens from the DB, and
        // from there the on-disk worker logs are only findable if their location is stated.
        $this->runLog?->addLine('Log directory: ' . $this->runLogDirRelative, 1, 'Run summary');
        $diagLog = $this->openCoordinatorLog($logDir, $runUid);
        $this->writeRunLog($diagLog, '===== Coordinator DIAG (run ' . $runUid . ') =====');
        if ($banner !== '') {
            $this->writeRunLog($diagLog, $banner);
        }

        $queue = array_values($queue);
        $totalFeatures = count($queue);
        // Feature keys of the WHOLE run, computed once. Used only to detect a normalization mismatch
        // between the coordinator's keys and what the workers actually write into run_feature.filename.
        $allFeatureKeys = array_map(fn(string $file): string => $this->featureKeyFromPath($file), $queue);
        $failures = [];

        // --- Phase A - slot setup. A port and a lane config are allocated ONCE per lane and then reused
        // by every feature that lane runs. Doing this per feature instead would re-probe the port band
        // hundreds of times and, worse, let a lane's Chrome port drift mid-run. A lane that cannot be set
        // up (exhausted band, unwritable config) is simply not opened; the run continues on the lanes
        // that could be, and the queue redistributes itself over them automatically. ---
        $laneConfigs = [];
        $lanePorts   = [];
        for ($lane = 1; $lane <= $workerCount; $lane++) {
            try {
                $port = $this->allocateFreePort($portStart, $portEnd, $heldPorts);
                $heldPorts[] = $port;
                $laneConfigs[$lane] = $this->writeLaneConfig($cwd, $lane, $runUid, $port, $chromePath, $importConfigName);
                $lanePorts[$lane]   = $port;
                $this->writeRunLog($diagLog, sprintf('DIAG setup: lane %d ready on port %d', $lane, $port));
            } catch (\Throwable $e) {
                // Not fatal on its own: fewer lanes means a slower run, not a wrong one, because the
                // queue is not pre-assigned to lanes. Only the total loss of ALL lanes is fatal (below).
                $this->writeRunLog($diagLog, 'DIAG setup: lane ' . $lane . ' unavailable: ' . $e->getMessage());
                $this->getWorkbench()->getLogger()->warning('BDT parallel: lane ' . $lane . ' could not be set up: ' . $e->getMessage());
            }
        }

        // No lane at all means nothing can run. Record every queued feature as never started rather than
        // returning an empty failure list, which would let a run that executed NOTHING report success.
        if ($laneConfigs === []) {
            $reason = 'no lane could be set up (port band exhausted or lane config unwritable)';
            foreach ($queue as $feature) {
                $this->recordFailure($failures, 0, $feature, null, 'never started - ' . $reason);
            }
            $this->runLog?->addSection('Fleet');
            $this->runLog?->addLine('Fleet: ' . $reason, 1, 'Fleet');
            $this->runLog?->addLine('Features never started: ' . $totalFeatures, 1, 'Fleet');
            $this->writeRunLog($diagLog, 'DIAG setup: ' . $reason . ' - aborting before any worker started');
            if (is_resource($diagLog)) {
                fclose($diagLog);
            }
            return $failures;
        }

        // Lanes currently free to accept a feature. A lane is pushed back here when its worker finishes,
        // and deliberately NOT pushed back when it is retired (repeated timeouts or a launch failure).
        $idleLanes = array_keys($laneConfigs);

        // --- Per-slot state. All keyed by lane and valid only while that lane has a running worker. ---
        $slotProcess      = []; // lane => Process currently running in this slot
        $slotLog          = []; // lane => open log handle for the CURRENT feature run
        $slotLogName      = []; // lane => basename of that log file
        $slotFeature      = []; // lane => feature file the slot is executing
        $slotSection      = []; // lane => run-log section title of the current feature run
        $slotStart        = []; // lane => microtime the current worker started
        $slotLastActivity = []; // lane => microtime of last observed progress (output OR its OWN DB growth)
        $slotKeys         = []; // lane => run_feature.filename key(s) of the feature being executed
        $slotDbCount      = []; // lane => run_step rows counted for the feature being executed
        $slotFirstOutput  = []; // lane => TRUE once the current worker has emitted anything

        // Consecutive timeouts per lane, reset by any worker that exits normally. See
        // LANE_MAX_CONSECUTIVE_TIMEOUTS for why a lane may be retired.
        $laneTimeouts = [];
        foreach (array_keys($laneConfigs) as $lane) {
            $laneTimeouts[$lane] = 0;
        }

        $seq = 0;                 // global feature-run counter, makes every worker log name unique
        $completed = 0;           // workers that exited on their own (any exit code)
        $lastDbPollAt = 0.0;      // 0 forces an immediate poll on the first pass
        $laneKeyWarned = false;
        $launchStartWall = microtime(true);

        // --- Phase B - fill and drain. The loop runs while any worker is alive OR there is still work
        // that a free lane could take. When neither holds, everything that could run has run. ---
        while ($slotProcess !== [] || ($queue !== [] && $idleLanes !== [])) {

            // Fill every free lane from the head of the queue. This runs on the first pass (initial fill)
            // and again on every pass where a worker exited, so a slot is refilled in the same iteration
            // it is released - a lane never waits for the next poll tick to pick up new work.
            while ($queue !== [] && $idleLanes !== []) {
                $lane    = array_shift($idleLanes);
                $feature = array_shift($queue);
                $seq++;
                $logHandle = null;
                try {
                    // The previous feature in this slot had its profile dir removed by reapLaneProfile(),
                    // so recreate it before Chrome is launched into it again.
                    $this->ensureLaneProfileDir($cwd, $runUid, $lane);

                    $cmd     = $this->buildWorkerCommand($laneConfigs[$lane], $tags, $feature);
                    $logName = $this->workerLogName($lane, $seq, $feature);

                    // Open the worker log BEFORE start() so a failure to open it cannot leave an orphan
                    // running worker with nowhere to stream its output.
                    $logHandle = $this->openWorkerLog($logDir, $logName);
                    $this->writeRunLog($logHandle, '===== Lane ' . $lane . ' (port ' . $lanePorts[$lane] . ') feature ' . $feature . ' =====');

                    // Drive Symfony Process directly so the drain can poll all lanes concurrently. The
                    // worker environment is the parent environment with the Xdebug DEBUGGER forced OFF
                    // (see WORKER_ENV): the coordinator is often launched under an IDE debugger, so its
                    // env carries an Xdebug trigger/session. Inherited unchanged, every worker would
                    // connect back to the single IDE debug client (port 9003) on startup; that client
                    // services only a couple of sessions at once, so the 3rd/4th worker blocks - silent,
                    // producing no output - until an earlier worker exits and frees a debug slot. THAT is
                    // the real "cap of 2". Disabling the debugger per worker restores true N-wide
                    // concurrency. Headless itself is decided by the worker's ChromeManager from
                    // app-config PARALLEL.CHROME_HEADLESS (with the debugger off, its Xdebug fallback is
                    // headless too). To step through a test, use the non-parallel single-worker path.
                    $callStart = microtime(true);
                    $process = Process::fromShellCommandline($cmd, $cwd, self::WORKER_ENV, null, $timeout);
                    // NOTE: we deliberately do NOT use Symfony's own setIdleTimeout() here. Its idle timer
                    // only resets on PROCESS OUTPUT, but a long works-as-expected step emits NO stdout while
                    // it runs - it only keeps INSERTing run_step rows into the DB (one per substep). An
                    // output-based idle timeout would therefore kill a lane that is actually progressing.
                    // Instead the drain loop below detects idleness itself, treating BOTH new worker output
                    // AND a growing run_step count in the DB as "this lane made progress". Only the TOTAL
                    // wall-clock ceiling ($timeout) stays enforced by Symfony via checkTimeout().
                    $process->start();
                    $callMs = (microtime(true) - $callStart) * 1000;

                    $section = '#' . $seq . ' ' . basename($feature) . ' (lane ' . $lane . ')';

                    $slotProcess[$lane]      = $process;
                    $slotLog[$lane]          = $logHandle;
                    $slotLogName[$lane]      = $logName;
                    $slotFeature[$lane]      = $feature;
                    $slotSection[$lane]      = $section;
                    $slotStart[$lane]        = microtime(true);
                    $slotLastActivity[$lane] = microtime(true); // seed the idle clock at launch
                    $slotFirstOutput[$lane]  = false;
                    // Only the feature this slot is running right now counts as its progress, so the idle
                    // heartbeat can never be propped up by a sibling lane or by a feature already done.
                    $slotKeys[$lane]         = [$this->featureKeyFromPath($feature)];
                    // Start from zero and let the first DB poll establish the real count: a feature is
                    // executed at most once per run, so any rows under its key belong to this very worker.
                    $slotDbCount[$lane]      = 0;

                    // Create this feature run's run-log section NOW, at launch, so sections appear in the
                    // order work was dispatched even though workers finish out of order. It is filled in
                    // when the worker ends (see appendFeatureOutcome).
                    $this->runLog?->addSection($section);
                    $this->runLog?->addLine('Feature: ' . $feature, 1, $section);
                    $this->runLog?->addLine('Lane: ' . $lane . ' (port ' . $lanePorts[$lane] . ')', 1, $section);

                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG launch: lane %d port %d feature %s - Process::start() returned in %.1f ms (%.1f s into run, %d/%d dispatched, %d queued)',
                        $lane,
                        $lanePorts[$lane],
                        $feature,
                        $callMs,
                        microtime(true) - $launchStartWall,
                        $seq,
                        $totalFeatures,
                        count($queue)
                    ));
                } catch (\Throwable $e) {
                    // This lane could not take this feature. The feature is recorded as failed (it will
                    // NOT be retried on another lane - a launch failure here is a coordinator-side setup
                    // problem, and silently re-dispatching it could mask a systemic fault), and the lane
                    // is RETIRED by not being returned to the idle pool. Remaining queue items flow to
                    // the lanes that still work; if none do, the leftover sweep after the loop records
                    // them as never started.
                    $error = 'launch failed: ' . $e->getMessage();
                    $this->recordFailure($failures, $lane, $feature, null, $error);
                    if (is_resource($logHandle)) {
                        $this->writeRunLog($logHandle, 'LANE ' . $lane . ' LAUNCH FAILED: ' . $e->getMessage());
                        $this->finishLaneLog($logHandle);
                    }
                    $this->writeRunLog($diagLog, 'DIAG launch: lane ' . $lane . ' FAILED for ' . $feature . ': ' . $e->getMessage());
                    $this->getWorkbench()->getLogger()->error('BDT parallel worker lane ' . $lane . ' launch failed: ' . $e->getMessage());
                    // Record firsthand: a launch failure often means no worker log was ever usable, so
                    // the coordinator's own error message is the record.
                    $section = '#' . $seq . ' ' . basename($feature) . ' (lane ' . $lane . ')';
                    $this->runLog?->addSection($section);
                    $this->runLog?->addLine('Feature: ' . $feature, 1, $section);
                    $this->runLog?->addLine('Worker: failed', 1, $section);
                    $this->runLog?->addLine('Worker error: ' . $error, 1, $section);
                    $this->runLog?->addLine('Lane ' . $lane . ' retired from the queue rotation', 1, $section);
                }
            }

            // Every lane may have failed to launch, leaving nothing running. Go back to the loop
            // condition, which ends the drain when no work can be dispatched any more.
            if ($slotProcess === []) {
                continue;
            }

            $now = microtime(true);

            // Per-lane DB progress heartbeat. A long works-as-expected step produces NO console output
            // while it runs, but the attach-mode worker keeps INSERTing one run_step row per substep - so
            // a growing step count is the only proof that a silent lane is alive. The count MUST be scoped
            // to the feature THAT lane is executing: a fleet-wide count would be pushed up by healthy
            // sibling lanes and would keep a genuinely hung lane alive forever, defeating the idle timeout.
            if ($idleTimeout !== null && ($now - $lastDbPollAt) >= self::DB_PROGRESS_POLL_SECONDS) {
                $lastDbPollAt = $now;
                $dbCounts = $this->countRunStepsByFeature($runUid);
                if ($dbCounts !== null) {
                    // Fail loudly on a key mismatch: if the DB reports steps for a feature that is not in
                    // this run's queue at all, our path normalization disagrees with what the workers wrote
                    // (e.g. a symlinked vendor dir resolved differently). Every lane would then look
                    // permanently idle and be killed although it is progressing, so we say so instead of
                    // failing silently.
                    if ($laneKeyWarned === false) {
                        $unknown = array_diff(array_keys($dbCounts), $allFeatureKeys);
                        if (! empty($unknown)) {
                            $laneKeyWarned = true;
                            $msg = 'BDT parallel: run_step rows found for features not in this run\'s queue ('
                                . implode(', ', $unknown) . ') - the per-lane idle heartbeat may be blind.';
                            $this->writeRunLog($diagLog, 'DIAG drain: ' . $msg);
                            $this->getWorkbench()->getLogger()->warning($msg);
                        }
                    }
                    foreach (array_keys($slotProcess) as $lane) {
                        $current = $this->sumLaneSteps($dbCounts, $slotKeys[$lane]);
                        if ($current > $slotDbCount[$lane]) {
                            $slotDbCount[$lane]      = $current;
                            $slotLastActivity[$lane] = $now; // this lane itself advanced - reset ITS idle clock
                            $this->writeRunLog($diagLog, sprintf(
                                'DIAG drain: lane %d DB progress - %d run_step rows for %s at +%.1f s into run',
                                $lane,
                                $current,
                                $slotFeature[$lane],
                                $now - $this->runStart
                            ));
                        }
                    }
                }
            }

            foreach ($slotProcess as $lane => $process) {
                // Stream whatever new output arrived since the last pass.
                $wrote = $this->streamLaneOutput($process, $slotLog[$lane]);
                if ($wrote) {
                    // Console output is itself a progress signal - record it as this lane's activity so
                    // a chatty lane never trips the idle timeout regardless of DB polling.
                    $slotLastActivity[$lane] = microtime(true);
                }
                if ($wrote && $slotFirstOutput[$lane] === false) {
                    $slotFirstOutput[$lane] = true;
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: lane %d - first output for %s at +%.1f s into run',
                        $lane,
                        $slotFeature[$lane],
                        microtime(true) - $this->runStart
                    ));
                }

                $killReason = null;

                // Idle detection (per-lane, DB-aware). $slotLastActivity[$lane] is refreshed by EITHER new
                // console output from this lane OR growth in the run_step count of the feature it is
                // running. A sibling lane's progress does not count - so a truly hung lane is caught even
                // while the rest of the fleet is healthy, and a silent-but-DB-writing lane is never killed.
                if ($idleTimeout !== null && (microtime(true) - $slotLastActivity[$lane]) > $idleTimeout) {
                    $killReason = 'idle timed out after ' . $idleTimeout . ' s with no output and no new run_step for its feature';
                } else {
                    // Enforce the per-worker TOTAL wall-clock timeout. In async mode Symfony only checks the
                    // timeout when we ask it to, so a hung worker would otherwise never time out.
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException $e) {
                        // Symfony now enforces only the TOTAL ceiling (we no longer set its output-based idle
                        // timeout), so any throw here is the wall-clock ceiling - a worker that ran too long
                        // even if it was still making progress. The DB-aware idle case is handled above.
                        $killReason = 'timed out after ' . $timeout . ' s (total wall-clock ceiling)';
                    }
                }

                if ($killReason !== null) {
                    $feature = $slotFeature[$lane];
                    $this->recordFailure($failures, $lane, $feature, $slotLogName[$lane], $killReason);
                    // Unchanged teardown: stop the worker, reap its detached Chrome tree, drop the profile
                    // dir, close the log - in that order, resources before any DB-backed logging.
                    $this->killHungLane($lane, $process, $slotLog[$lane], $diagLog, $killReason, $cwd, $runUid);
                    // killHungLane has closed the worker log, so its partial output is flushed and safe
                    // to parse into the run-log now.
                    $this->appendFeatureOutcome($logDir, $slotSection[$lane], $slotLogName[$lane], $killReason);

                    // POISON-FEATURE POLICY: the feature is NOT put back on the queue. Handing a feature
                    // that just hung a lane to the next free lane would let one pathological file hang
                    // every lane in turn and consume the entire run. It is recorded as failed, once.
                    $laneTimeouts[$lane]++;
                    if ($laneTimeouts[$lane] >= self::LANE_MAX_CONSECUTIVE_TIMEOUTS) {
                        // Repeated timeouts point at the LANE rather than at the features, so take it out
                        // of rotation instead of feeding it the rest of the queue one timeout at a time.
                        $this->writeRunLog($diagLog, sprintf(
                            'DIAG drain: lane %d retired after %d consecutive timeouts',
                            $lane,
                            $laneTimeouts[$lane]
                        ));
                        $this->getWorkbench()->getLogger()->warning(
                            'BDT parallel: lane ' . $lane . ' retired after ' . $laneTimeouts[$lane] . ' consecutive timeouts'
                        );
                    } else {
                        // An isolated bad feature does not condemn the lane - return it to the pool.
                        $idleLanes[] = $lane;
                    }

                    unset(
                        $slotProcess[$lane], $slotLog[$lane], $slotLogName[$lane], $slotFeature[$lane],
                        $slotSection[$lane], $slotStart[$lane], $slotLastActivity[$lane], $slotKeys[$lane],
                        $slotDbCount[$lane], $slotFirstOutput[$lane]
                    );
                    continue;
                }

                if (! $process->isRunning()) {
                    // Flush the tail that arrived after the last read, then classify the exit.
                    $this->streamLaneOutput($process, $slotLog[$lane]);
                    $exitCode  = $process->getExitCode(); // null if the worker was terminated by a signal
                    $durationS = microtime(true) - $slotStart[$lane];
                    $feature   = $slotFeature[$lane];
                    $workerError = null;
                    // Only the worker's OWN fatal failure is a failure here - a crash (exit 2/255) or a
                    // signal termination (null exit code, e.g. taskkill). Behat's exit 1 ("some tests
                    // failed") is deliberately NOT treated as a worker failure: authoritative per-scenario
                    // pass/fail already lives in the attach-mode child rows, so a worker that completed
                    // normally must not be reported as a worker error just because some of its tests
                    // failed. Exit 0 (all passed) and exit 1 (ran to completion, some tests failed) both
                    // mean the worker itself did its job.
                    if ($exitCode !== 0 && $exitCode !== 1) {
                        $workerError = $exitCode === null ? 'terminated without exit code' : 'exit code ' . $exitCode;
                        $this->recordFailure($failures, $lane, $feature, $slotLogName[$lane], $workerError);
                        $this->writeRunLog($slotLog[$lane], 'LANE ' . $lane . ' FAILED: ' . $workerError);
                        $this->getWorkbench()->getLogger()->error(
                            'BDT parallel worker lane ' . $lane . ' failed on ' . $feature . ': ' . $workerError
                        );
                    }
                    $completed++;
                    $this->writeRunLog($diagLog, sprintf(
                        'DIAG drain: lane %d finished %s - exit %s after %.1f s (+%.1f s into run, %d/%d done, %d queued)',
                        $lane,
                        $feature,
                        $exitCode === null ? 'n/a' : (string) $exitCode,
                        $durationS,
                        microtime(true) - $this->runStart,
                        $completed,
                        $totalFeatures,
                        count($queue)
                    ));
                    $this->finishLaneLog($slotLog[$lane]);
                    // The worker log is now closed and complete - parse it once into the run-log. Pass the
                    // worker-level failure if any (crash/signal); a normal exit (0/1) passes null, so the
                    // section still records the Behat summary from the output.
                    $this->appendFeatureOutcome($logDir, $slotSection[$lane], $slotLogName[$lane], $workerError);
                    // On a clean exit the worker's own ChromeManager already stopped Chrome, so this
                    // usually just removes the profile dir; on a crash/signal it also kills the orphan.
                    // It runs after EVERY feature, not only at the end of the lane, because the next
                    // feature reuses this same profile dir and must not inherit a live Chrome, a held
                    // ProcessSingleton lock or a half-written profile from the previous one.
                    $this->reapLaneProfile($lane, $cwd, $runUid);

                    // A worker that exited on its own proves the lane still works, so clear its timeout
                    // strike count and return it to the pool for the next queued feature.
                    $laneTimeouts[$lane] = 0;
                    $idleLanes[] = $lane;

                    unset(
                        $slotProcess[$lane], $slotLog[$lane], $slotLogName[$lane], $slotFeature[$lane],
                        $slotSection[$lane], $slotStart[$lane], $slotLastActivity[$lane], $slotKeys[$lane],
                        $slotDbCount[$lane], $slotFirstOutput[$lane]
                    );
                }
            }

            // Yield the CPU briefly between passes; nothing to do until workers produce more output.
            // Skipped when a slot was just freed and the queue still has work, so the refill at the top
            // of the next iteration is not delayed by a poll interval.
            if ($slotProcess !== [] && ! ($queue !== [] && $idleLanes !== [])) {
                usleep(self::DRAIN_POLL_MICROSECONDS);
            }
        }

        // Anything still queued here could never be dispatched, because every lane was retired. Record
        // each one explicitly: a feature that silently never ran is exactly the failure mode the queue
        // was introduced to eliminate, so it must surface as a worker error rather than as a quiet
        // expected-vs-actual shortfall.
        if ($queue !== []) {
            $reason = 'never started - all lanes were retired before it could be dispatched';
            foreach ($queue as $feature) {
                $this->recordFailure($failures, 0, $feature, null, $reason);
            }
            $this->writeRunLog($diagLog, 'DIAG drain: ' . count($queue) . ' feature(s) never dispatched - ' . $reason);
            $this->getWorkbench()->getLogger()->error(
                'BDT parallel: ' . count($queue) . ' feature(s) never ran because all lanes were retired'
            );
        }

        $this->writeRunLog($diagLog, sprintf(
            'DIAG drain: queue drained - %d/%d feature runs completed, %d failure(s), %.1f s total',
            $completed,
            $totalFeatures,
            count($failures),
            microtime(true) - $this->runStart
        ));

        if (is_resource($diagLog)) {
            fclose($diagLog);
        }
        return $failures;
    }

    /**
     * Returns the UIDs of coordinator runs that may still legitimately own a Chrome profile dir.
     *
     * WHY THIS EXISTS: reapProfilesOfInactiveRuns() must not kill the browsers of a run that is still
     * executing, but it also must not wait for an age threshold to expire before reclaiming the ones
     * that are not. The run table is the authority: a row with no finished_on is potentially still
     * running. A run whose row was never finalized because the coordinator was hard-killed would keep
     * its profiles alive forever, so rows older than the coordinator's own wall-clock ceiling are no
     * longer treated as active - past that point no legitimate Chrome of theirs can still exist.
     *
     * WHY IT FAILS SAFE: if the query fails we return only the current run UID... no. We must NOT do
     * that - it would authorize killing everything. On any error we return an empty result and the
     * caller skips the sweep entirely, falling back to the age-based one. Never guess.
     *
     * @param string $currentRunUid UID of the run being started (always considered active)
     * @return string[]|null Active run UIDs, or NULL if the state could not be determined
     */
    private function findActiveRunUids(string $currentRunUid): ?array
    {
        try {
            $cutoff = (new \DateTime())->sub(new \DateInterval('PT' . self::RUN_MAX_AGE_MINUTES . 'M'));
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.run');
            $ds->getColumns()->addFromUidAttribute();
            $ds->getFilters()->addConditionFromString('finished_on', '', ComparatorDataType::EQUALS);
            $ds->getFilters()->addConditionFromString(
                'started_on',
                $cutoff->format(DateTimeDataType::DATETIME_FORMAT_INTERNAL),
                ComparatorDataType::GREATER_THAN_OR_EQUALS
            );
            $ds->dataRead();
            $uids = $ds->getUidColumn()->getValues(false);
            $uids[] = $currentRunUid;
            return array_values(array_unique($uids));
        } catch (\Throwable $e) {
            $this->getWorkbench()->getLogger()->logException($e);
            return null;
        }
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
     * WHY SCOPED TO THIS RUN'S OWN lane DIRS: profile dirs are named "<run_uid>_laneN", so globbing on
     * * this run's UID touches nothing that belongs to a concurrent interactive tester run or to another
     * * coordinator. Artefacts of runs that died before reaching this backstop are NOT this method's job -
     * * they are reclaimed by the age-based reapStaleChromeProfiles() sweep at the start of the next run.
     *
     * WHY IT NEVER THROWS: it runs in perform()'s finally, so a throw would mask the real run
     * outcome (or the original exception on the failure path). Every failure is logged loudly
     * instead - a leftover Chrome is a nuisance, not a reason to fail an otherwise-finalized run.
     *
     * @param string $workingDir Installation root (the base all lane profile dirs are relative to)
     */
    private function cleanupLaneChromes(string $workingDir, string $runUid): void
    {
        try {
            // Path comes from the single source of truth, so it can no longer drift from writeLaneConfig().
            $profilesRoot = $this->chromeProfilesRoot($workingDir);
            $laneDirs = glob($profilesRoot . DIRECTORY_SEPARATOR . $runUid . '_lane*', GLOB_ONLYDIR) ?: [];
            if ($laneDirs === []) {
                return;
            }

            $logger = $this->getWorkbench()->getLogger();

            // One process snapshot for the whole cleanup, so chrome.exe is scanned exactly once.
            $chromeProcesses = $this->listChromeProcessCommandLines();

            $killedAny = false;
            foreach ($laneDirs as $laneDir) {
                // Compare in the SAME path namespace Chrome's command line uses. realpath() resolved the
                // deploy junction (releases\<ver>\data -> shared\data) and shifted the string into a
                // different namespace than the worker's getcwd()-based launch path, so the equality match
                // could never hit on a junctioned deployment layout. glob() already returns the absolute
                // dir in the launch namespace - use it as-is.
                $killed = $this->reapChromeProfileDir($laneDir, $chromeProcesses);
                foreach ($killed as $pid) {
                    $logger->info('BDT parallel cleanup: killed orphan Chrome PID ' . $pid . ' bound to ' . $laneDir);
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
     * @param int    $lane   The lane number whose Chrome/profile to reap
     * @param string $cwd    The run working dir (same base writeLaneConfig() built the profile under)
     * @param string $runUid UID of the run owning the lane - part of the profile dir name since profiles became run-scoped
     */
    private function reapLaneProfile(int $lane, string $cwd, string $runUid): void
    {
        try {
            // Must mirror writeLaneConfig()'s user_data_dir construction.
            $absLaneDir = $this->laneProfileDir($cwd, $runUid, $lane);

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
     * behat command per feature, differing by lane config and feature file, so there is no single behat
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
     * Builds the absolute profile dir of one lane of one run - the single source of truth for the path.
     *
     * WHY A HELPER: the path was previously rebuilt by hand in writeLaneConfig(), reapLaneProfile() and
     * cleanupLaneChromes(). When the dir was made run-scoped (<run_uid>_laneN, to stop cross-account
     * DPAPI/ProcessSingleton failures), only writeLaneConfig() was updated; the two reapers kept
     * building the old fixed "laneN" name. They then matched nothing, killed nothing and - because
     * removeDirectoryTree() reports a non-existent dir as successfully removed - reported success while
     * silently doing nothing. Every Chrome tree and profile dir of every run leaked from that point on.
     * Routing all three call sites through this method makes that class of drift impossible.
     *
     * @param string $workingDir Installation root
     * @param string $runUid     UID of the run owning the lane
     * @param int    $lane       Lane number
     * @return string Absolute profile dir path
     */
    private function laneProfileDir(string $workingDir, string $runUid, int $lane): string
    {
        return $this->chromeProfilesRoot($workingDir) . DIRECTORY_SEPARATOR . $runUid . '_lane' . $lane;
    }

    /**
     * Builds the absolute chrome_profiles root shared by all runs and by the interactive RunTest action.
     *
     * WHY SEPARATE FROM laneProfileDir(): the stale-profile sweep operates on the root, not on a single
     * lane, and must not re-derive the path independently.
     *
     * @param string $workingDir Installation root
     * @return string Absolute chrome_profiles root path
     */
    private function chromeProfilesRoot(string $workingDir): string
    {
        return $workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'chrome_profiles';
    }

    /**
     * Streams a worker's incremental stdout/stderr into its worker log, then frees the read buffer.
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
     * Closes a worker log handle if it is still open. Centralized so every drain exit path (normal
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
     * Ensures THIS run's own log directory exists and returns its absolute path.
     *
     * Layout: data/axenox/BDT/Logs/<YYYYMMDD>/<run_uid>/
     *
     * WHY TWO LEVELS INSTEAD OF ONE FLAT DIRECTORY: since a worker process runs a single feature,
     * a run produces one log file per feature instead of one per lane - a few hundred files for a
     * large suite. Dropped into one shared directory they bury each other, and files from different
     * runs interleave alphabetically, so finding "the logs of last night's run" means reading run
     * UIDs off filenames. Giving each run its own directory makes a run's logs a single unit that
     * can be opened, zipped or deleted as one, and the daily level keeps the number of run
     * directories per directory bounded.
     *
     * WHY THE DATE COMES FROM THE RUN START AND NOT FROM date() AT WRITE TIME: a nightly run that
     * begins at 23:55 would otherwise scatter its own feature logs across two daily folders, which
     * is precisely when someone is trying to read them as one unit. The run's start instant fixes
     * the folder for every file the run writes.
     *
     * Anchored at $cwd (installation root), so it never depends on this action's process cwd.
     *
     * @param string $cwd    Installation root
     * @param string $runUid UID of the run owning this directory
     * @return string Absolute path to data/axenox/BDT/Logs/<YYYYMMDD>/<run_uid>
     */
    private function ensureRunLogDir(string $cwd, string $runUid): string
    {
        // $this->runStart is set before the fleet launches; fall back to now only if this is somehow
        // reached earlier, so a missing timestamp can never produce a directory named "19700101".
        $day = date('Ymd', (int) ($this->runStart > 0 ? $this->runStart : microtime(true)));

        $this->runLogDirRelative = 'data/axenox/BDT/Logs/' . $day . '/' . $runUid;

        $dir = $cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR . 'Logs'
            . DIRECTORY_SEPARATOR . $day . DIRECTORY_SEPARATOR . $runUid;
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create BDT log directory: ' . $dir);
        }
        return $dir;
    }

    /**
     * Opens one log file per FEATURE RUN inside this run's own log directory, for append.
     *
     * Why a file per feature run rather than per lane: a lane executes many features in sequence, so a
     * single per-lane file would interleave them - and the crash tail of a worker that died would sit
     * behind the output of unrelated features that ran in the same slot earlier, making it impossible
     * to attribute. One file per feature run keeps every worker's output self-contained, so a failure
     * can be read on its own.
     *
     * The name no longer repeats the run UID, because the directory already carries it (see
     * ensureRunLogDir); it carries the lane, the dispatch sequence and the feature name instead, so the
     * directory listing alone tells the reader which feature each file belongs to without opening it.
     * Append mode tolerates a re-run without truncating earlier diagnostics.
     *
     * @param string $logName Basename of the log file, built by the caller via workerLogName().
     * @return resource
     */
    private function openWorkerLog(string $logDir, string $logName)
    {
        $handle = @fopen($logDir . DIRECTORY_SEPARATOR . $logName, 'a');
        if ($handle === false) {
            throw new RuntimeException('Could not open worker log file: ' . $logName);
        }
        return $handle;
    }

    /**
     * Builds the file name of one feature run's log: "lane<N>_<seq>_<feature>.log".
     *
     * WHY THE FEATURE NAME IS IN THE FILE NAME: with one file per feature, the reader's first question
     * is always "which file is the feature that failed" - and the run-log answers it only if they open
     * it. Putting the feature in the name answers it from the directory listing. The lane and sequence
     * stay in front so the files sort by dispatch order rather than alphabetically by feature, which
     * keeps a lane's history readable, and the sequence guarantees uniqueness even if the same feature
     * were ever dispatched twice.
     *
     * WHY THE FEATURE PART IS SANITIZED: feature files are named by testers, so the basename can
     * legitimately contain spaces or characters Windows forbids in a filename. Replacing anything
     * outside a conservative set keeps the log openable instead of failing the whole feature run over
     * its own log name.
     */
    private function workerLogName(int $lane, int $seq, string $feature): string
    {
        // Normalize separators before basename(): feature paths arrive with Windows backslashes, and
        // basename() only treats "\" as a separator when PHP itself runs on Windows. Doing it here keeps
        // the name identical no matter where the code runs, which matters for tests and for a future
        // move off Windows.
        $name = basename(str_replace('\\', '/', $feature), '.feature');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? '';
        // Trim dots as well as underscores: Windows silently strips trailing dots from file names, so a
        // name ending in one would not round-trip to the file actually written.
        $name = trim($name, '._-');
        // Keep the name bounded: Windows still enforces a total path limit, and the run directory
        // already contributes a date and a run UID to that budget.
        if (strlen($name) > 60) {
            $name = rtrim(substr($name, 0, 60), '._-');
        }
        if ($name === '') {
            $name = 'feature';
        }
        return 'lane' . $lane . '_' . $seq . '_' . $name . '.log';
    }

    /**
     * Opens the single coordinator diagnostic log "coordinator.log" inside this run's log directory.
     *
     * Why a dedicated coordinator log instead of the workbench logger: the launch/drain timings used
     * to localize the concurrency cap are high-frequency orchestration traces. The DB-backed workbench
     * log is meant for a few meaningful info/error rows, not a per-lane timeline, so the fleet
     * diagnostics live here next to the worker logs of the same run and stay greppable in one file.
     * The run UID is no longer needed in the name because the directory carries it.
     *
     * @return resource
     */
    private function openCoordinatorLog(string $logDir, string $runUid)
    {
        $handle = @fopen($logDir . DIRECTORY_SEPARATOR . 'coordinator.log', 'a');
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
     * Appends one feature run's section to the living run-log: worker status, the on-disk worker log
     * name and Behat's counts block - parsed once from that worker's now-closed log file.
     *
     * Why no test detail here: the failed-scenario list and the inline failure output are TEST results,
     * and the attach-mode DatabaseFormatter already writes those per scenario and per step as child rows
     * of this very run. Duplicating them into the run row added nothing a reader could not query, while
     * a failure-heavy run filled the whole byte budget and truncated away the coordinator-level
     * diagnostics this log exists for. The run log now answers "did the fleet work?"; the child rows
     * answer "did the tests pass?".
     *
     * Why "worker done" is not "tests passed": worker status reports whether the PROCESS completed -
     * Behat exit 1 (a completed run with test failures) is a healthy worker.
     *
     * @param string      $section     Run-log section of this feature run, created at launch so sections
     *                                 stay in dispatch order.
     * @param string      $logName     Basename of this feature run's own log file.
     * @param string|null $workerError Worker-level failure, or null on a normal exit.
     */
    private function appendFeatureOutcome(string $logDir, string $section, string $logName, ?string $workerError): void
    {
        if ($this->runLog === null) {
            return;
        }

        $this->runLog->addLine('Worker: ' . ($workerError === null ? 'done' : 'failed'), 1, $section);
        if ($workerError !== null) {
            $this->runLog->addLine('Worker error: ' . $workerError, 1, $section);
        }
        // Always name the on-disk worker log: the verbose Behat output is deliberately NOT copied into
        // the run row, so the reader must be told where the full truth lives.
        $this->runLog->addLine('Worker log: ' . $logName, 1, $section);

        $lines = $this->readLaneLogTail($logDir . DIRECTORY_SEPARATOR . $logName);
        if ($lines === null) {
            $this->runLog->addLine('Worker log file missing or unreadable', 1, $section);
            return;
        }

        $summary = $this->extractBehatSummary($lines);
        if ($summary !== null) {
            // The counts block already states how many scenarios/steps failed, so no separate
            // failed-scenario list is needed to see whether this feature's tests were green.
            $this->runLog->addLine('Behat summary:', 1, $section);
            $this->runLog->addCodeBlock($summary, '', $section);
        } else {
            $this->runLog->addLine('Behat summary: not reached - the worker never printed a run summary', 1, $section);
        }

        // Crash tail only. A worker that RAN to completion (exit 0/1) has its outcome in the child rows,
        // so none of its output belongs here. A worker that crashed or was killed produced NO child row
        // explaining itself, so its last lines are the only diagnosis available anywhere.
        if ($workerError !== null) {
            $tail = $this->extractCrashTail($lines);
            if ($tail !== null) {
                $this->runLog->addLine('Last output before failure:', 1, $section);
                $this->runLog->addCodeBlock($tail, '', $section);
            }
        }
    }

    /**
     * Pulls Behat's end-of-run counts block ("N scenarios (...)", "M steps (...)", timing) from the
     * worker log lines.
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
     * Reads the last LOG_TAIL_READ_BYTES of a worker log and returns it as ANSI-stripped lines.
     *
     * Why a bounded tail instead of reading the whole file: a UI5 lane can emit a very large log, and
     * pulling it fully into the coordinator's memory - once per feature run - just to look at its last lines is
     * both wasteful and a real out-of-memory risk on a run with many failures. Everything the digest
     * still needs (Behat's counts block, and the last output before a crash) sits at the END of the file,
     * so a tail read is sufficient by construction.
     *
     * @return string[]|null Lines of the tail, or null when the file is missing or unreadable.
     */
    private function readLaneLogTail(string $logFile): ?array
    {
        if (! is_file($logFile) || ! is_readable($logFile)) {
            return null;
        }
        $handle = @fopen($logFile, 'rb');
        if ($handle === false) {
            return null;
        }
        $size      = (int) @filesize($logFile);
        $truncated = $size > self::LOG_TAIL_READ_BYTES;
        if ($truncated) {
            fseek($handle, -self::LOG_TAIL_READ_BYTES, SEEK_END);
        }
        $raw = (string) stream_get_contents($handle);
        fclose($handle);

        // Strip ANSI colour codes so the stored digest stays plain text (Behat usually disables colour
        // when its output is piped, but a configured formatter may still emit codes).
        $text  = preg_replace('/\e\[[0-9;]*m/', '', $raw) ?? $raw;
        $lines = preg_split('/\R/', $text) ?: [];
        // A tail read almost always starts mid-line - drop that first fragment so no half line is parsed
        // or reported as if it were complete.
        if ($truncated && count($lines) > 1) {
            array_shift($lines);
        }
        return $lines;
    }

    /**
     * Builds a bounded tail of the lane's last meaningful output lines for a worker that DIED.
     *
     * Why only for a crashed worker: a worker that completed has its per-scenario outcome in the child
     * rows, so its output is noise in the run row. A worker that died (crash, taskkill, fatal error)
     * left no row behind at all, and its final lines are the only evidence of why.
     *
     * Why the noise filter: Behat's Symfony console prints its full command synopsis (a ~700 byte
     * single line) AFTER a fatal, and frames its error blocks in box-drawing characters. Both are pure
     * padding, and on a byte-bounded tail they push the one line that names the cause - the framed
     * "In <file> line <n>" block - out of the budget. Dropping them first means the budget is spent on
     * diagnosis rather than on decoration.
     *
     * @param string[] $lines
     * @return string|null The tail, or null when the lane produced no meaningful output at all.
     */
    private function extractCrashTail(array $lines): ?string
    {
        $meaningful = [];
        foreach ($lines as $line) {
            $trimmed = trim($line, " \t\r\n\0\x0B│└─");
            if ($trimmed === '') {
                continue;
            }
            // Behat's usage synopsis, echoed after every fatal - long, constant and content-free.
            if (str_starts_with($trimmed, 'behat [--')) {
                continue;
            }
            $meaningful[] = $trimmed;
        }
        $tail = array_slice($meaningful, -self::LOG_CRASH_TAIL_LINES);
        if ($tail === []) {
            return null;
        }
        $text = implode("\n", $tail);
        if (strlen($text) > self::LOG_CRASH_TAIL_BYTES) {
            // Keep the END: a fatal is the last thing the process manages to print.
            $text = "... (truncated)\n" . mb_strcut($text, -self::LOG_CRASH_TAIL_BYTES);
        }
        return $text;
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