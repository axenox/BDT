<?php
namespace axenox\BDT\Actions;

use axenox\BDT\BDTApp;
use axenox\BDT\Behat\Common\BdtConfig;
use axenox\BDT\Behat\Common\BdtPaths;
use axenox\BDT\Behat\Common\BehatCommandBuilder;
use axenox\BDT\Behat\Common\GeneratedBehatConfig;
use axenox\BDT\Behat\Common\LaneNaming;
use axenox\BDT\Behat\Common\ProcessOutputDrain;
use axenox\BDT\Behat\Common\RunLifecycle;
use axenox\BDT\Behat\Common\RunLogDigest;
use axenox\BDT\Behat\Common\RunOptions;
use axenox\BDT\Behat\Common\RunScope;
use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
use axenox\BDT\Behat\Common\Traits\FeaturePathResolverTrait;
use axenox\BDT\Behat\Common\Traits\PortProbingTrait;
use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\BrowserManagerInterface;
use axenox\BDT\Behat\BrowserManagers\ChromeDevToolsHttpClient;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\BrowserManagers\SupportsParallelLanesInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Wrapper action for a single INTERACTIVE Behat run with dynamic Chrome-port allocation.
 *
 * WHY THIS ACTION EXISTS: testers start BDT via UI buttons that used to invoke plain
 * `vendor\bin\behat` against a behat.yml with a FIXED remote-debugging port. On a shared
 * server that fixed port collides with the scheduled parallel fleet and with other testers.
 * The port cannot be reassigned at runtime inside the Behat process, because MinkExtension's
 * api_url freezes when the Behat container is built - so the free-port probe must run HERE,
 * before Behat boots, and flow into Behat through a generated config file (the same channel
 * the scheduled fleet's lane configs use).
 *
 * WHO OWNS THE RUN ROW: this action does, exactly like the RunParallel coordinator - with one Behat
 * process instead of a fleet. It creates the run row, writes the expected counts,
 * starts Behat in ATTACH-MODE (the generated config carries run_uid and lane_id, so DatabaseFormatter
 * only writes children under the UID) and finalizes the row on every exit path. WHY: the action must
 * know the run UID, because the AI agents that start a run through this action read its results from
 * the DB by that UID. While the formatter created the row inside the Behat child, the action could
 * never learn it. The UID is reported on a line of its own ("Run UID: <uid>") right after the row
 * exists and again in the closing banner. The formatter's owner mode remains only for a plain
 * `vendor\bin\behat` call.
 *
 * OUTPUT IS STREAMED LIVE: the server console is attended during interactive runs, so the
 * action returns a message-STREAM result and drives Behat through a generator, forwarding
 * output as it arrives instead of returning one block at the end. That lets a tester watch a
 * long run progress in real time. The same output is written to Logs/<run_uid>/interactive.log,
 * from which the run log digest is built at close-out.
 */
class RunTest extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    use PortProbingTrait;
    use ChromeProfileReaperTrait;
    use FeaturePathResolverTrait;

    private ?string $browserExecutable = null;

    // Name of the file in the run's log directory that receives the Behat output, and the run-log section
    // that describes the Behat process.
    private const LOG_FILE_NAME = 'interactive.log';
    private const LOG_SECTION   = 'Interactive run';

    // The interactive port band lives in the local manager's configuration (port_band_interactive)
    // and is DELIBERATELY separate from the scheduled band: a tester pressing the button while the
    // nightly fleet is running must be steered into a disjoint port window, so even a probe race can
    // only hit another interactive run - which the local manager's foreign-Chrome guard then fails loudly.

    // Per-project bdt_parallel.yml key for the interactive band. Lives NEXT TO the scheduled
    // "port_band" key in the same override file; either may be present independently.
    private const OVERRIDE_KEY_INTERACTIVE = 'port_band_interactive';

    // Default size of the pool of interactive test-user slots (see LaneNaming::interactiveLaneId()). The lock
    // prefix names the pool's lock files; it must differ from the port locks' prefix.
    private const DEFAULT_INTERACTIVE_USER_SLOTS = 10;
    private const USER_SLOT_LOCK_PREFIX = 'interactive_user_slot';

    /**
     * Immediate phase: resolve and validate every input up front, then hand the resolved values to
     * the deferred phase as its call arguments.
     *
     * WHY THIS RUNS IN performImmediately AND NOT IN THE GENERATOR: this action streams its output,
     * so its body must be deferred (AbstractActionDeferred wraps performDeferred() in the result's
     * generator, which the facade drains later). But cheap input checks - path resolution and file
     * existence - must still fail immediately and obviously at handle() time, before any streaming
     * starts, rather than mid-stream. So resolution/validation lives here; port allocation, config
     * writing and the Behat run itself stay deferred (see performDeferred).
     *
     * WHY DEFERRED AT ALL (not a plain AbstractAction returning a stream): the previous version
     * built the stream result via ResultFactory::createMessageStreamResult() from AbstractAction.
     * That is wrong for this core: the factory feeds a bare Generator into a stream that expects a
     * CALLABLE, and - just as this class's docblock warns - action postprocessing and autocommit
     * would fire before the generator ran. AbstractActionDeferred is the supported base for
     * streaming actions and wires all of that correctly.
     *
     * WHY VALIDATE HERE BUT ALLOCATE THE PORT LATER: a free port must be bound as close as possible
     * to launching Chrome to keep the probe->bind race window small, so it stays in performDeferred.
     *
     * WHY THE RUN SCOPE IS READ AND CHECKED FIRST: an unscoped invocation is the tester's own
     * mistake and the cheapest thing to detect, so it must be the first thing reported. Resolving
     * behat_config and chrome_path first would answer a mis-clicked, empty console preset with an
     * infrastructure error about a path the tester never typed, hiding what actually went wrong.
     *
     * An explicit --chrome_path is validated only for a browser BDT launches: an attached browser
     * ignores it, and checking an unused local path would incorrectly block a remote run.
     *
     * {@inheritDoc}
     * @see AbstractActionDeferred::performImmediately()
     * @return string[] arguments for performDeferred()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result): array
    {
        // --- Run scope first: read the three pass-through selectors and refuse an unscoped run ---
        // Without any of them Behat runs the ENTIRE test base with no filter - a run that takes
        // hours, reports on features nobody asked about and hides the one the tester meant to check.
        // The wording matches RunParallel's identical guard on purpose: both console-preset buttons
        // sit next to each other on the feature-files page, so a tester who leaves all three inputs
        // empty must get the same sentence whichever button was pressed.
        $suite       = RunOptions::read($task, RunOptions::SUITE) ?? '';
        $tags        = RunOptions::read($task, RunOptions::TAGS) ?? '';
        $feature     = RunOptions::read($task, RunOptions::FEATURE) ?? '';

        if ($tags === '' && $feature === '' && $suite === '') {
            throw new RuntimeException('Provide at least one of --tags, --feature or --suite; refusing to run the whole test base unscoped.');
        }
        // An HTML-encoded selector would match nothing and end as a silent "nothing to run"; refuse it
        // here, at handle() time, with the cause named (shared with RunParallel).
        RunScope::assertNotHtmlEncoded($tags, $suite, $feature);
        // --- Resolve inputs ---
        // The base config comes first among the infrastructure inputs: it is read from the existing
        // root behat.yml (written by the caller's `Behat init`, which this action does not repeat), and
        // everything after it - port, run row, Chrome - depends on it. A missing file therefore fails
        // right here, at handle() time, with the instruction to run init. The browser options are
        // CLI-only overrides here: the BrowserManagerFactory owns the app-config keys (BROWSER.MANAGER /
        // BROWSER.MANAGER_CONFIG and the legacy ones), so there is one reader.
        $cwd = $this->getWorkbench()->getInstallationPath();
        $scope = new RunScope($this->getWorkbench());
        $behatConfig = $scope->resolveBehatConfig(RunOptions::read($task, RunOptions::BEHAT_CONFIG), $cwd);
        $chromeEndpoint = RunOptions::read($task, RunOptions::CHROME_ENDPOINT) ?? '';
        $baseUrl = RunOptions::read($task, RunOptions::BASE_URL) ?? '';
        $chromePath = RunOptions::read($task, RunOptions::CHROME_PATH) ?? '';
        if ($chromeEndpoint !== '') {
            $chromeEndpoint = ChromeDevToolsHttpClient::normalizeEndpoint($chromeEndpoint);
            BehatCommandBuilder::quote($chromeEndpoint, 'Chrome endpoint');
        }
        if ($baseUrl !== '') {
            $baseUrl = ChromeDevToolsHttpClient::normalizeEndpoint($baseUrl) . '/';
            BehatCommandBuilder::quote($baseUrl, 'browser-facing base URL');
        }
        if ($chromePath !== '' && $chromeEndpoint === '') {
            $resolvedClass = BrowserManagerFactory::resolveClass($this->getWorkbench());
            if (is_subclass_of($resolvedClass, SupportsParallelLanesInterface::class) && ! is_file($chromePath)) {
                throw new RuntimeException('chrome_path is not a file: ' . $chromePath
                    . ' (must be chrome.exe, NOT GoogleChromePortable.exe - its single-instance lock breaks concurrent runs)');
            }
        }

        // The feature path is resolved through the SAME resolver RunParallel uses: vendor folder first,
        // then the installation root. The vendor-relative form is what this framework itself prints
        // (run log, run_feature.filename, recorded rerun command), so it is the form a tester copies
        // back in - and checking it only against the installation root rejected every such path.
        // The RESOLVED absolute path is handed on to Behat, not the raw value: Behat runs with the
        // installation root as CWD, so a vendor-relative value would not resolve there either.
        if ($feature !== '') {
            $feature = $this->resolveFeatureScanRoot($feature);
        }
        // A feature outside --suite would run nothing and report green, so the combination is refused
        // here, before any streaming, port or run row. It only reads the existing behat.yml and its imports.
        if ($feature !== '' && $suite !== '') {
            $scope->resolveScanRoots($behatConfig, $feature, $suite);
        }
        // A value the Test Runs console would refuse to re-run must not start a run: the rerun button would
        // answer "Command not allowed!" with no hint why. Judged on the EXACT command performDeferred()
        // records (same inputs), and last, so infrastructure errors above keep their own messages.
        $scope->assertRerunnable(
            'axenox.BDT:RunTest',
            $tags,
            $feature,
            $suite,
            [RunOptions::CHROME_ENDPOINT => $chromeEndpoint, RunOptions::BASE_URL => $baseUrl]
        );

        // These become the positional arguments of performDeferred() - order MUST match its
        // signature. The deferred generator only starts when the facade drains the result, so the
        // port/config/run work is still lazy; we merely resolved its inputs eagerly.
        return [$cwd, $behatConfig, $chromePath, $suite, $tags, $feature, $chromeEndpoint, $baseUrl];
    }

    /**
     * Deferred phase: the streamed generator that owns the whole run - run row, expected counts,
     * port, config, Behat, close-out - and yields Behat's output as it arrives. Invoked by
     * AbstractActionDeferred with the array performImmediately() returned, only once the facade drains
     * the result.
     *
     * It does NOT run `Behat init`: init is a setup step of its own that the caller runs beforehand
     * (scheduler step, console start command, agent setup). Running it here would duplicate that step and
     * rewrite the root behat.yml while other Behat processes may be reading it.
     *
     * ORDER (each step depends on the previous one):
     *  1. resolve the scope against the existing behat.yml - the base config itself was already resolved
     *     in performImmediately(); a feature outside --suite is refused BEFORE any row exists;
     *  2. reserve this run's test-user slot (all slots taken fails here) and create the run row
     *     (describeInvocation() is recorded as behat_command);
     *  3. yield "Run UID: <uid>" immediately, so even a run that dies later can be found in the DB;
     *  4. write the expected counts - attach-mode Behat skips this;
     *  5. reserve the port - as late as possible, to keep the probe->bind race window small;
     *  6. write the interactive config, carrying run_uid and lane_id;
     *  7. run Behat, teeing its output into the console stream and Logs/<run_uid>/interactive.log;
     *  8. finalize the row in the generator's finally, on EVERY exit path.
     *
     * WHY EVERY PARAMETER IS OPTIONAL: AbstractActionDeferred declares performDeferred() abstract,
     * and PHP forbids an override from adding required parameters an abstract signature lacks. The
     * real values always arrive (performImmediately() supplies all of them), so the defaults are never
     * used in practice - they exist purely to satisfy that language constraint.
     *
     * WHY A GENERATOR DRIVING Symfony Process DIRECTLY: two requirements meet here. First, we
     * want LIVE output on the attended server console, which means yielding chunks as they are
     * produced. Second, we must classify the Behat EXIT CODE ourselves - exit 1 ("some tests
     * failed") is a NORMAL interactive outcome that must not be treated as a crash, while a real
     * crash (2/255/terminated) must be reported loudly. CliCommandRunner's own generator cannot
     * give us both: silent=false would throw on Behat's exit 1, and silent=true would swallow a
     * genuine crash. So we start the Process, poll getIncrementalOutput() into the stream, and
     * read getExitCode() at the end to decide the closing message.
     *
     * WHY A CRASH YIELDS A BANNER INSTEAD OF THROWING: by the time Behat has run, its per-scenario
     * rows already exist in the DB. Throwing after streaming would abort the stream mid-flight with a
     * stack trace on top of already-printed output. A bold CRASHED banner on the live console is the
     * loud, legible failure signal for the interactive path; the authoritative outcome remains in the
     * DB rows. A TIMEOUT, by contrast, is an abort we cannot complete, so it yields its banner and then
     * throws (after the run row is finalized) to fail the action.
     *
     * WHY THE CLOSING BANNER REPEATS THE RUN UID FOR EVERY OUTCOME: agents parse that line to find the
     * run, and the opening line may have scrolled out of whatever they keep of a long output.
     *
     * A manager that does not own its browser gets an endpoint reservation and ignores a local
     * executable path; passing it to that manager would cause a UXON mapping error.
     *
     * {@inheritDoc}
     * @see AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(
        string $cwd = '',
        string $behatConfig = '',
        string $chromePath = '',
        string $suite = '',
        string $tags = '',
        string $feature = '',
        string $chromeEndpoint = '',
        string $baseUrl = ''
    ): \Generator {
        yield "===== BDT interactive run =====\n";
        if ($chromePath !== '' && $chromeEndpoint !== '') {
            yield "INFO: --chrome_path ignored because --chrome_endpoint is set.\n";
        }

        // Validate the caller-supplied selectors before anything is persisted. WHY HERE:
        // the run row stores the recorded rerun command, which the Test Runs console renders into its
        // start_commands JSON - an unvalidated value would be saved and shipped to the browser. Until the
        // run row became this action's job, the command build ran before anything was stored and
        // refused such values in time; now the row is created first, so the same check must come first.
        BehatCommandBuilder::assertSelectorsSafe($tags, $feature, $suite);
        // The coordinator manager is created from app config plus the CLI values and never started
        // for a local browser: it only tells this action WHAT kind of browser the run uses. A forced
        // endpoint selects RemoteChromeManager even when BROWSER.MANAGER is local (the factory then
        // skips the local base config). It is created BEFORE the run row for the same
        // reason RunParallel does it: a misconfigured browser must stop the run before a run record
        // exists that would have to be closed as failed.
        // WHY THE CLASS IS RESOLVED FIRST: --base_url is a setting of an attached browser, but for a
        // browser BDT launches itself it only overrides the Mink base_url. Handing it to a local manager
        // would be rejected as an unknown setting, and what it means depends on the class, which is only
        // known after resolution.
        $forcedClass = $chromeEndpoint !== '' ? RemoteChromeManager::class : null;
        $resolvedClass = BrowserManagerFactory::resolveClass(
            $this->getWorkbench(),
            new UxonObject(self::buildCoordinatorOverrides(false, $chromeEndpoint, $baseUrl, $chromePath)),
            $forcedClass
        );
        $coordinator = BrowserManagerFactory::create(
            $this->getWorkbench(),
            new UxonObject(self::buildCoordinatorOverrides(
                ! is_subclass_of($resolvedClass, SupportsParallelLanesInterface::class),
                $chromeEndpoint,
                $baseUrl,
                $chromePath
            )),
            $forcedClass
        );
        // Capability, not class name, decides the flow: a manager that supports parallel lanes owns a
        // browser process (port, profile dir, sweeps); any other manager attaches to an external
        // browser (endpoint lock, tab-only cleanup).
        $ownsBrowser = $coordinator instanceof SupportsParallelLanesInterface;
        if (! $ownsBrowser && $chromePath !== '' && $chromeEndpoint === '') {
            yield "INFO: --chrome_path ignored because the configured browser is attached.\n";
        }
        $this->browserExecutable = $ownsBrowser ? $coordinator->getExecutable() : null;

        // --- Step 1: resolve the scope against the existing behat.yml ---
        // The base config itself was resolved in performImmediately(). Still before any persistent state:
        // a feature outside --suite is refused here, so it never leaves a run row behind and never
        // reports green for a run that executes nothing.
        $scope = new RunScope($this->getWorkbench());
        $scope->resolveScanRoots($behatConfig, $feature, $suite);

        // --- Step 2: reserve this run's test-user slot, then create the run row ---
        // The slot decides which test user the run logs in with (see LaneNaming::interactiveLaneId()),
        // so it is taken BEFORE the row exists: when every slot is in use the run is refused loudly here,
        // leaving no row behind. There is deliberately no fallback to the base user or to a shared slot -
        // that would bring back the optimistic-locking collision the slots exist to prevent.
        $userSlots = $this->resolveInteractiveUserSlots();
        $slotReservation = $this->reserveFreeSlot(self::USER_SLOT_LOCK_PREFIX, $userSlots);
        if ($slotReservation === null) {
            throw new RuntimeException(
                'All ' . $userSlots . ' interactive test-user slots are in use by concurrent RunTest runs. '
                . 'Wait for one of them to finish or raise ' . BdtConfig::TEST_USER_INTERACTIVE_SLOTS . '.'
            );
        }
        $userSlot = $slotReservation['slot'];

        // The run row is this action's to own; Behat only attaches to it.
        $lifecycle = $this->createLifecycle();
        try {
            $runUid = $lifecycle->open($scope->describeInvocation(
                'axenox.BDT:RunTest',
                $tags,
                $feature,
                $suite,
                [RunOptions::CHROME_ENDPOINT => $chromeEndpoint, RunOptions::BASE_URL => $baseUrl]
            ));
        } catch (\Throwable $e) {
            // Not a suppression: re-thrown at once. The outer finally below does not exist yet, so the slot
            // has to be given back here or a failed row creation would keep it locked.
            $this->releaseReservedPort($slotReservation);
            throw $e;
        }

        $reservation = null;
        $port = 0;
        $configPath = null;
        $attachedManager = null;
        $process = null;
        $logDir = null;
        $logHandle = null;
        $processStarted = false;
        $workerError = null;
        $runError = null;

        try {
            // --- Step 3: report the UID IMMEDIATELY - before the scope calculation, the expected counts,
            // the port reservation or the config, any of which can fail - so even a run that dies right
            // here can be found in the DB (its error is recorded under this UID). ---
            yield RunLifecycle::runUidLine($runUid) . "\n";

            // --- Step 4: expected counts. Attach-mode Behat skips this, and the totals must exist even
            // when the child dies partway through - otherwise silent-stop detection is impossible. ---
            $expected = $scope->calculateExpectedScope($behatConfig, $feature, $suite, $tags);
            $lifecycle->setExpectedCounts($expected->featureCount, $expected->scenarioCount);

            // --- Step 4b: a scope that matches nothing is a FAILURE, decided before Behat starts. Behat
            // would exit 0 on it and the closing banner would say "all scenarios passed" for a run that
            // executed nothing - to an agent that reads as green. Thrown inside this try, so the close-out
            // records it in error_message, finalizes the row and the caller gets it with the UID line. ---
            if (RunScope::isEmptyScope($expected)) {
                throw new RuntimeException(RunScope::EMPTY_SCOPE_MESSAGE);
            }

            // --- Step 5: allocate a collision-free port from the INTERACTIVE band (late, on purpose) ---
            // Reserve the port ACROSS PROCESSES, not just probe it: independent concurrent interactive
            // runs each pick a port seconds before Chrome binds it, so a bare probe hands the same port
            // to all of them (and, because the port also names the profile dir, collapses their Chrome
            // profiles into one). The cross-process lock keeps this port ours from here until the run
            // ends - released in the resource cleanup below so the next tester can claim it.
            if (! $ownsBrowser) {
                $reservation = $this->reserveRemoteEndpoint($coordinator->getApiUrl());
                $allocationMessage = 'Reserved external Chrome at ' . $coordinator->getApiUrl() . "\n";
            } else {
                [$portStart, $portEnd] = $this->resolvePortBand(
                    $behatConfig,
                    self::OVERRIDE_KEY_INTERACTIVE,
                    $coordinator->getPortBandInteractive(),
                    'BROWSER.MANAGER_CONFIG port_band_interactive'
                );
                $reservation = $this->reserveFreePort($portStart, $portEnd);
                $port = $reservation['port'];
                $allocationMessage = 'Allocated port ' . $port . ' (band ' . $portStart . '-' . $portEnd . ")\n";
            }

            // The run's log directory and the file that receives Behat's output. Opened only once the
            // port is ours, so a run that never got to start Behat leaves no empty log behind.
            $logDir = $lifecycle->ensureLogDir($cwd);
            $logHandle = $lifecycle->openLogFile($logDir, self::LOG_FILE_NAME);
            $runLog = $lifecycle->getRunLog();
            $runLog->addLine(
                'Log directory: ' . MarkdownDataType::escapeCodeInline($lifecycle->getLogDirRelative()),
                1,
                'Run summary'
            );

            try {
                yield $allocationMessage;
                if (! $ownsBrowser) {
                    $attachedManager = $coordinator;
                    $attachedManager->start();
                }
                // --- Step 6: generate the per-run config carrying the port and the run identity into Behat ---
                $configPath = $this->writeInteractiveConfig($cwd, $behatConfig, $coordinator, $port, $chromePath, $chromeEndpoint, $baseUrl, $runUid, $userSlot);
                yield 'Config: ' . basename($configPath) . "\n\n";

                // --- Step 7: run Behat, streaming output; classify the exit code at the end ---
                // NULL = the ceiling is disabled (PARALLEL.WORKER_TIMEOUT_SECONDS <= 0); Symfony then never times out.
                $timeout = BdtConfig::resolveWorkerTimeout($this->getWorkbench());
                $cmd     = BehatCommandBuilder::build($configPath, $suite, $tags, $feature);

                $runLog->addLine('Configuration:', 1, 'Run summary');
                $runLog->addCodeBlock(implode("\n", [
                    trim($allocationMessage),
                    'Test user slot: ' . $userSlot . ' of ' . $userSlots . ' (lane_id ' . LaneNaming::interactiveLaneId($runUid, $userSlot) . ')',
                    'Browser: ' . $coordinator->describe(),
                    'Base config: ' . basename($behatConfig),
                    'Generated config: ' . basename($configPath),
                    'Timeout: ' . ($timeout === null ? 'disabled (' . BdtConfig::PARALLEL_WORKER_TIMEOUT_SECONDS . ' <= 0)' : $timeout . ' s')
                ]), '', 'Run summary');
                $runLog->addSection(self::LOG_SECTION);

                // The environment is inherited UNCHANGED: unlike fleet workers there is exactly one child
                // here, so a developer running this under a debugger keeps normal single-session
                // debugging (the Xdebug env stays intact).
                $process = Process::fromShellCommandline($cmd, $cwd, null, null, $timeout);
                $process->start();
                $processStarted = true;

                $timedOut = false;
                while ($process->isRunning()) {
                    $chunk = ProcessOutputDrain::drain($process, $logHandle);
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                    // In async mode Symfony only enforces the timeout when asked, so a hung run would
                    // otherwise never time out.
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException $e) {
                        // Not a suppression: the timeout is classified below, reported in the closing
                        // banner and re-thrown AFTER the run row is closed. Kill the child now so its
                        // tail can be flushed.
                        $process->stop(0);
                        $timedOut = true;
                        break;
                    }
                    usleep(ProcessOutputDrain::POLL_MICROSECONDS);
                }
                // Flush whatever arrived after the last read inside the loop (and after a kill).
                $tail = ProcessOutputDrain::drain($process, $logHandle);
                if ($tail !== '') {
                    yield $tail;
                }

                // Exit-code classification: 0 (all passed) and 1 (ran to completion, some tests
                // failed) both mean Behat itself did its job - authoritative per-scenario pass/fail
                // lives in the DB rows written by DatabaseFormatter. Anything else is a crash.
                $uidLine = RunLifecycle::runUidLine($runUid) . "\n";
                if ($timedOut) {
                    $workerError = 'Behat timed out after ' . $timeout . ' s and was killed';
                    $closing = "\n***** Behat TIMED OUT after " . $timeout . " s - the run was aborted and is NOT complete. *****\n" . $uidLine;
                } else {
                    $exitCode = $process->getExitCode(); // null if terminated by a signal
                    $runLog->addLine('Exit code: ' . ($exitCode ?? 'n/a (terminated)'), 1, self::LOG_SECTION);
                    if ($exitCode === 0) {
                        $closing = "\n===== Behat finished: all scenarios passed. =====\n" . $uidLine;
                    } elseif ($exitCode === 1) {
                        $closing = "\n===== Behat finished: some scenarios FAILED (see run results). =====\n" . $uidLine;
                    } else {
                        $workerError = 'Behat CRASHED with exit code ' . ($exitCode === null ? 'n/a (terminated)' : $exitCode);
                        $closing = "\n***** Behat CRASHED with exit code "
                            . ($exitCode === null ? 'n/a (terminated)' : $exitCode)
                            . " - this is NOT a normal test failure. Check the output above and the run record. *****\n" . $uidLine;
                    }
                }
                yield $closing;

                // A timeout is an abort we cannot complete, so it fails the action - but only after the
                // banner above, and the run row is finalized in the finally below before this propagates.
                if ($timedOut) {
                    throw new RuntimeException(
                        'Interactive Behat run timed out after ' . $timeout . ' s. Adjust '
                        . BdtConfig::PARALLEL_WORKER_TIMEOUT_SECONDS . ' if the selection legitimately needs longer.'
                    );
                }
            } finally {
                // OS resources first, DB-backed logging last (see closeOutRun()).
                // WHY A NESTED FINALLY AROUND THE REAP: the steps before it can throw (Symfony's
                // Process::stop() does when Windows refuses to kill the child, an attached manager's stop()
                // can fail), and a throw there used to skip the reap while the outer finally still released
                // the port - handing another run a port whose Chrome may still be alive. The nested finally
                // makes the reap run whatever happens above it; the exception itself still propagates.
                try {
                    if ($process !== null && $process->isRunning()) {
                        $process->stop(0);
                    }
                    if ($attachedManager !== null) {
                        $attachedManager->stop();
                    }
                    // Delete the generated config regardless of how the run ended. The port-based
                    // filename means the next run on this port simply overwrites it, so a leftover on a
                    // hard failure is harmless - but tidy up on the normal path.
                    if ($configPath !== null) {
                        @unlink($configPath);
                    }
                } finally {
                    // Reap any orphaned Chrome bound to THIS run's interactive profile and remove the profile
                    // dir BEFORE releasing the port. The Behat child launches Chrome detached, so a
                    // timed-out/crashed run can leave the browser alive holding the profile - hence an explicit
                    // reap, not a bare rmdir. Done before releaseReservedPort() (outer finally) so the freed
                    // port cannot be reclaimed by another interactive run whose fresh interactive<port> dir we
                    // would then delete. reapProfileDirQuietly() never throws.
                    if ($ownsBrowser) {
                        $this->reapProfileDirQuietly(
                            $cwd . DIRECTORY_SEPARATOR . $this->interactiveProfileDirRelative((int) $port),
                            'BDT interactive cleanup',
                            1_000_000
                        );
                    }
                }
            }
        } catch (\Throwable $e) {
            // Remembered ONLY so closeOutRun() can tell a failed run (whose outcome must never be hidden)
            // from a successful one, and so the run log and error_message record the ORIGINAL message.
            // What is thrown is the same failure WITH the run UID line (RunLifecycle::withRunUid()): the
            // row exists, so every exception from here on must say which run it belongs to - an agent finds
            // the reason for a broken feature in the DB through that UID, and a generator that throws shows
            // the caller only this message, not the lines it yielded before.
            $runError = $e;
            throw $lifecycle->withRunUid($e);
        } finally {
            // Release the cross-process reservations - port or endpoint, and the test-user slot - no matter
            // how the run ended. WHY HERE AND NOT IN THE INNER FINALLY: that one is only entered once Behat
            // is about to start, but both reservations are taken earlier (the port, then the log
            // directory and file, can still throw in between), and an exception in the inner finally
            // itself (a failing attached-browser stop) must not skip them either. They still follow the
            // Chrome reap above, so a freed port cannot be reclaimed by a run whose fresh interactive<port>
            // dir would then be deleted. Releasing is idempotent, and it comes BEFORE the DB-backed
            // close-out: freeing OS resources must never wait for the database.
            if ($reservation !== null) {
                $this->releaseReservedPort($reservation);
            }
            $this->releaseReservedPort($slotReservation);
            $this->closeOutRun($lifecycle, $logDir, $logHandle, $processStarted, $workerError, $runError);
        }
    }

    /**
     * Creates the lifecycle that owns this run's row.
     *
     * WHY A FACTORY METHOD: the lifecycle is the only part of the run that needs a database to start, so
     * a test can hand the action a lifecycle with a stand-in row and exercise everything that happens after
     * the row exists - the UID report, the error recording, the finalize - without one.
     */
    protected function createLifecycle(): RunLifecycle
    {
        return new RunLifecycle($this->getWorkbench(), 'BDT interactive run');
    }

    /**
     * Closes the run row on every exit path of performDeferred(): digest, finalize, then the log handle.
     *
     * WHY IT IS A FINALLY-SIDE CLOSE-OUT: the row exists from the moment the UID is yielded. Any way out
     * of the generator - normal end, crash, timeout, an exception from any later step - that skipped the
     * finalize would leave a row with no finished_on and no log, which looks exactly like a run that was
     * killed without a trace. Agents then wait for a run that has long ended.
     *
     * WHY THE DIGEST IS GUARDED SEPARATELY: it is diagnostics. A failure to build it must not stop the
     * finalize that follows, so it is a documented boundary: the failure is written to the output file
     * and the close-out goes on.
     *
     * WHY A FINALIZE FAILURE IS THROWN ONLY WHEN NOTHING ELSE FAILED: a throw from a finally block
     * replaces the exception that is already propagating, so a broken DB write at close-out would hide the
     * real reason the run failed (a timeout, a crash in an earlier step). When the run itself failed, the
     * close-out failure is logged and the original exception survives; when the run was otherwise
     * healthy, the failure is thrown, because a row that stays open must not go unnoticed.
     *
     * WHY THE LOG HANDLE IS CLOSED LAST: the digest reads the file and a close-out failure is written
     * into it; anything written after fclose() would be invisible by construction. The run row is
     * finalized AFTER the browser and port were released by the caller, because the workbench logger and
     * the DB write may be exactly what is broken, and freeing OS resources must never wait for them.
     *
     * @param resource|null $logHandle Open handle of interactive.log, or NULL if it was never opened
     * @throws RuntimeException if the finalize failed and the run itself had no other failure
     */
    private function closeOutRun(
        RunLifecycle $lifecycle,
        ?string $logDir,
        $logHandle,
        bool $processStarted,
        ?string $workerError,
        ?\Throwable $runError
    ): void {
        $finalizeError = null;
        try {
            if (is_resource($logHandle)) {
                @fflush($logHandle);
            }
            // Only a started process has an outcome to digest; a run that failed before Behat started
            // is described by the error recorded below.
            if ($processStarted && $logDir !== null) {
                try {
                    RunLogDigest::appendWorkerOutcome(
                        $lifecycle->getRunLog(),
                        $logDir,
                        self::LOG_SECTION,
                        self::LOG_FILE_NAME,
                        $workerError
                    );
                } catch (\Throwable $e) {
                    // Documented boundary: the digest is diagnostics and must never block the finalize. The
                    // cause goes to the interactive.log AND the workbench log, so it is not lost if the log
                    // file is never read.
                    $lifecycle->logQuietly($e);
                    if (is_resource($logHandle)) {
                        @fwrite($logHandle, 'DIAG close-out: run log digest failed - ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
                    }
                }
            }
            $lifecycle->stageRunLog($runError);
            // The failure goes into the same finalize update. A crash or timeout is no exception here
            // (the generator reports it in a banner), so $workerError must be recorded as well: a Behat
            // process that died between scenarios leaves only passed children, which the status
            // derivation alone would read as passed.
            $failure = $runError !== null ? $runError->getMessage() : ($workerError ?? '');
            $lifecycle->stageFailure($failure);
            $lifecycle->finalize();
        } catch (\Throwable $e) {
            $finalizeError = $e;
            if (is_resource($logHandle)) {
                @fwrite($logHandle, 'DIAG close-out: FAILED - ' . get_class($e) . ': ' . $e->getMessage() . PHP_EOL);
            }
        }
        if (is_resource($logHandle)) {
            @fclose($logHandle);
        }

        if ($finalizeError === null) {
            return;
        }
        if ($runError === null) {
            throw new RuntimeException(
                $lifecycle->appendRunUid('Interactive run close-out failed: ' . $finalizeError->getMessage()),
                null,
                $finalizeError
            );
        }
        // Documented boundary: the run already failed, and that failure is what the caller must see.
        try {
            $this->getWorkbench()->getLogger()->logException($finalizeError);
        } catch (\Throwable $ignored) {
            // The logger writes to the same DB that just failed; the failure is already in interactive.log.
        }
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
     * WHY DECLARED EXPLICITLY: Symfony Console rejects unknown options. All of them are optional at
     * the CLI layer - behat_config resolves via option, then app-config, then the installation-root
     * behat.yml (see resolveBehatConfig); chrome_path, chrome_endpoint and base_url are overrides of
     * BROWSER.MANAGER_CONFIG and need not be given when it is complete; while suite/tags/feature are
     * genuine pass-throughs a tester may combine freely and are omitted from the Behat command when unset.
     * The endpoint and browser URL options let one run use a remote browser without changing the
     * server configuration.
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(RunOptions::BEHAT_CONFIG)
                ->setDescription('Path to the base behat.yml the generated config imports; must be in the installation root. Falls back to app-config ' . RunScope::CFG_BEHAT_CONFIG . ', then to the installation-root behat.yml, when omitted. That file is written by `Behat init`, which must have been run before this action.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::CHROME_PATH)
                ->setDescription('Path to chrome.exe (NOT GoogleChromePortable.exe). Overrides "executable" of BROWSER.MANAGER_CONFIG for this run.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::CHROME_ENDPOINT)
                ->setDescription('CDP HTTP endpoint of externally managed Chrome. Forces the remote browser manager for this run and overrides "endpoint" of BROWSER.MANAGER_CONFIG.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::BASE_URL)
                ->setDescription('Application URL for this run. For a remote browser it is the URL as seen by that browser and overrides "base_url" of BROWSER.MANAGER_CONFIG; for a local browser it overrides the base_url of the base behat.yml.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::SUITE)
                ->setDescription('Optional Behat suite name, passed through as --suite')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::TAGS)
                ->setDescription('Optional Behat tag filter, passed through as --tags')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(RunOptions::FEATURE)
                ->setDescription('Optional feature file or directory, passed through as a positional argument')
                ->setDefaultValue('')
        ];
    }

    /**
     * Writes the per-run interactive config next to the base behat.yml and returns its path.
     *
     * WHY IMPORTS THE BASE INSTEAD OF DUPLICATING IT: the generated file sits in the same
     * directory as the base config, so "imports: [<base>]" resolves relatively and %paths.base%
     * stays identical to a plain run - exactly the lane-config pattern proven in RunParallel.
     * It only ADDS the per-run overrides: the Mink api_url, the browser node and the run identity.
     * The identity is run_uid plus lane_id ("<run_uid>_interactive<slot>", the slot being the run's
     * reserved test-user slot, independent of how the browser is attached): that makes the Behat child run in
     * ATTACH-MODE, binding to the run row this action created instead of creating one itself, exactly as
     * a RunParallel lane does. DatabaseFormatter refuses a run_uid without a lane_id on purpose, so both
     * are always written together. The lane_id also selects the test user (see
     * LaneNaming::slotToken()), so concurrent interactive runs never share a user row.
     *
     * WHAT GOES INTO THE browser NODE: only per-run values. For a browser BDT launches itself that is
     * the port and the isolated profile dir (plus the executable only when --chrome_path was given);
     * for an external browser it is the endpoint and base_url, and only when they came from the CLI.
     * browser.manager is written only when the CLI forced a class (--chrome_endpoint); otherwise it
     * stays null and the Behat child resolves BROWSER.MANAGER itself, so both processes always agree.
     *
     * WHY THE api_url COMES FROM THE MANAGER CODE: LocalChromeManager::buildApiUrl() and the remote
     * manager's own endpoint are what the manager uses at run time. A hand-built string here is how the
     * two drifted apart before (localhost vs 127.0.0.1, with an IPv6 lookup delay on Windows).
     *
     * WHY A PORT-BASED FILENAME AND PROFILE DIR: the port is unique among concurrently running
     * BDT instances by construction (the probe skipped bound ports), so behat_interactive_<port>.yml
     * and chrome_profiles\interactive<port> can never be shared by two live runs - and both are
     * bounded by the band width, so nothing accumulates across runs; a later run on the same
     * port reuses/overwrites them.
     *
     * IMPORTANT: user_data_dir is written as a RELATIVE path because the local manager
     * prepends getcwd(); an absolute path would be double-prepended into a broken
     * "C:\...\C:\..." path and Chrome would fall back to the real default profile.
     * An external browser gets no profile dir and no port.
     */
    private function writeInteractiveConfig(
        string $workingDir,
        string $behatConfig,
        BrowserManagerInterface $coordinator,
        int $port,
        string $chromePath,
        string $chromeEndpoint,
        string $baseUrl,
        string $runUid,
        int $userSlot
    ): string {
        $userDataDirRelative = '';
        if ($coordinator instanceof SupportsParallelLanesInterface) {
            $userDataDirRelative = $this->interactiveProfileDirRelative($port);
            // ensure() creates the dir or throws; the name comes from the same method the cleanup uses.
            BdtPaths::ensure($workingDir, BdtPaths::FOLDER_CHROME_PROFILES, $this->interactiveProfileDirName($port));
            $configPath = dirname($behatConfig) . DIRECTORY_SEPARATOR . 'behat_interactive_' . $port . '.yml';
        } else {
            $configPath = dirname($behatConfig) . DIRECTORY_SEPARATOR
                . 'behat_interactive_remote_' . hash('sha256', ChromeDevToolsHttpClient::normalizeEndpoint($coordinator->getApiUrl())) . '.yml';
        }
        $extensions = GeneratedBehatConfig::buildExtensions(
            $coordinator,
            $port,
            $userDataDirRelative,
            $chromePath,
            $chromeEndpoint,
            $baseUrl,
            $runUid,
            LaneNaming::interactiveLaneId($runUid, $userSlot)
        );

        return GeneratedBehatConfig::write(
            $configPath,
            $behatConfig,
            'AUTO-GENERATED interactive run config - overwritten per run. Do not edit by hand.',
            $extensions
        );
    }

    /**
     * Resolves how many interactive test-user slots exist (the size of the slot pool).
     *
     * WHY A SETTING: the number caps how many distinct interactive test users can ever exist, and how
     * many RunTest runs may be active at once. Both depend on the installation (how many agents and testers
     * run concurrently, how much user clutter is acceptable), so ops tune it without a code change.
     *
     * WHY A PRESENT BUT INVALID VALUE FAILS: a typo must never silently shrink the pool to one slot or
     * widen it to hundreds. A missing key falls back to the default, which the shipped config also sets.
     *
     * @return int Number of slots, at least 1
     * @throws RuntimeException if the configured value is not an integer >= 1
     */
    private function resolveInteractiveUserSlots(): int
    {
        $cfg = $this->getWorkbench()->getApp(BDTApp::APP_ALIAS)->getConfig();
        if (! $cfg->hasOption(BdtConfig::TEST_USER_INTERACTIVE_SLOTS)) {
            return self::DEFAULT_INTERACTIVE_USER_SLOTS;
        }
        $raw = $cfg->getOption(BdtConfig::TEST_USER_INTERACTIVE_SLOTS);
        $slots = filter_var($raw, FILTER_VALIDATE_INT);
        if ($slots === false || $slots < 1) {
            throw new RuntimeException(
                BdtConfig::TEST_USER_INTERACTIVE_SLOTS . ' must be an integer >= 1, got ' . var_export($raw, true)
            );
        }
        return $slots;
    }

    /**
     * Turns the CLI values into the runtime overrides of the coordinator manager.
     *
     * WHY base_url DEPENDS ON THE BROWSER KIND: for an attached browser it is the address the remote
     * browser uses to reach the application, i.e. a manager setting. A local manager has no such
     * setting and would reject it, but there --base_url keeps its original meaning (the Mink base_url,
     * written into the generated config), so it must not reach the manager. Conversely, an executable
     * is meaningful only for a locally owned browser and an attached manager cannot accept it.
     *
     * @param bool $attachedBrowser TRUE when the manager does not support parallel lanes (attaches to an external browser)
     * @return array<string,string>
     */
    public static function buildCoordinatorOverrides(
        bool $attachedBrowser,
        string $chromeEndpoint,
        string $baseUrl,
        string $chromePath
    ): array {
        $overrides = [];
        if ($chromeEndpoint !== '') {
            $overrides['endpoint'] = $chromeEndpoint;
        }
        if ($attachedBrowser && $baseUrl !== '') {
            $overrides['base_url'] = $baseUrl;
        }
        if (! $attachedBrowser && $chromePath !== '') {
            $overrides['executable'] = $chromePath;
        }
        return $overrides;
    }

    /**
     * Prevents concurrent interactive runs in this installation from sharing one remote browser.
     *
     * WHY THE FULL NORMALIZED ENDPOINT NAMES THE LOCK: many endpoints share a port (443), so only the whole
     * URL identifies the browser. It is hashed because a URL is not a valid file name.
     *
     * WHY reserveNamedLock(): the cross-process flock protocol (released by the OS when the owner dies, so a
     * crash never strands the endpoint) is the one ports and slots use; this method used to carry its own
     * fopen/flock copy of it.
     *
     * @return array{handle:resource,lockPath:string} Reservation to release via releaseReservedPort()
     * @throws RuntimeException if the endpoint is reserved by another run or its lock file cannot be opened
     */
    private function reserveRemoteEndpoint(string $endpoint): array
    {
        $lockName = 'chrome_endpoint_' . hash('sha256', ChromeDevToolsHttpClient::normalizeEndpoint($endpoint));
        $reservation = $this->reserveNamedLock($lockName);
        if ($reservation === null) {
            throw new RuntimeException(
                'Remote Chrome endpoint ' . $endpoint . ' could not be reserved: it is already reserved by another '
                . 'BDT run, or its lock file "' . $lockName . '.lock" in ' . BdtPaths::relative(BdtPaths::FOLDER_PORT_LOCKS)
                . ' cannot be opened.'
            );
        }
        return $reservation;
    }

    /**
     * Supplies the executable passed to this run so its browser processes can be found by image name.
     *
     * WHY NULLABLE: process enumeration can be reached before a local executable is known, and a
     * remote run has no local browser executable at all.
     */
    protected function getBrowserExecutable(): ?string
    {
        return $this->browserExecutable;
    }

    /**
     * Returns THIS interactive run's Chrome profile dir, relative to the installation root.
     *
     * WHY IT EXISTS: the profile dir is CREATED in writeInteractiveConfig() and DELETED by the reap in
     * performDeferred()'s finally. Those two built the path independently, with a comment demanding
     * they "mirror" each other - a mirror that only a human could enforce, on a path whose one use is
     * recursive deletion. Deriving both from one method makes the mirror structural instead of
     * documented.
     *
     * WHY RELATIVE: writeInteractiveConfig() must write exactly this value into the config's
     * user_data_dir, which the local manager resolves against getcwd(); the cleanup simply
     * prepends its own working dir. Returning the relative form serves both without a second method.
     */
    private function interactiveProfileDirRelative(int $port): string
    {
        return BdtPaths::relative(BdtPaths::FOLDER_CHROME_PROFILES, $this->interactiveProfileDirName($port));
    }

    /**
     * Returns the name of THIS interactive run's profile dir: "interactive<port>".
     *
     * WHY SEPARATE FROM interactiveProfileDirRelative(): writeInteractiveConfig() creates the dir through
     * BdtPaths::ensure(), which takes the segments rather than a finished path. It used to spell the name a
     * second time for that call - exactly the create/delete pair that must never drift.
     */
    private function interactiveProfileDirName(int $port): string
    {
        return 'interactive' . $port;
    }
}