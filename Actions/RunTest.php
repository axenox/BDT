<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Behat\Common\Traits\ChromeProfileReaperTrait;
use axenox\BDT\Behat\Common\Traits\PortProbingTrait;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Actions\ServiceParameter;
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
 * WHAT THIS ACTION DOES NOT DO: unlike RunParallel, it creates NO run record and passes NO
 * run_uid/lane_id. A plain single-process Behat run opens and finalizes its own run record
 * through DatabaseFormatter, exactly as before - this wrapper only injects a collision-free
 * port, an isolated Chrome profile and the direct chrome.exe path around it.
 *
 * OUTPUT IS STREAMED LIVE: the server console is attended during interactive runs, so the
 * action returns a message-STREAM result and drives Behat through a generator, forwarding
 * output as it arrives instead of returning one block at the end. That lets a tester watch a
 * long run progress in real time.
 */
class RunTest extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    use PortProbingTrait;
    use ChromeProfileReaperTrait;
    // CLI option names - kept as constants so the option declarations in getCliOptions()
    // and the reads via getTaskParam() can never drift apart.
    private const OPT_BEHAT_CONFIG = 'behat_config';
    private const OPT_CHROME_PATH  = 'chrome_path';
    private const OPT_SUITE        = 'suite';
    private const OPT_TAGS         = 'tags';
    private const OPT_FEATURE      = 'feature';

    // App-config fallbacks for the two paths, so a tester (or a button) can launch with just a
    // tag/suite/feature selection and let the server-wide config supply behat_config and
    // chrome_path. The option ALWAYS wins when given; the config key is the fallback; if NEITHER
    // is set we still fail loudly (see resolvePathParam) - we never silently guess a path.
    private const CFG_BEHAT_CONFIG = 'PARALLEL.BEHAT_CONFIG';
    private const CFG_CHROME_PATH  = 'PARALLEL.CHROME_PATH';

    // Base config filename the behat_config resolution defaults to when neither the option nor the
    // app-config key is set. Mirrors RunParallel::DEFAULT_BEHAT_CONFIG: the interactive page runs
    // Behat init on open, which (re)creates this file at the installation root, so it is always
    // present and current by the time a tester triggers a run - defaulting to it is not guessing.
    private const DEFAULT_BEHAT_CONFIG = 'behat.yml';

    // App-config keys. The interactive band is DELIBERATELY separate from the scheduled band
    // (PARALLEL.PORT_BAND_SCHEDULED): a tester pressing the button while the nightly fleet is
    // running must be steered into a disjoint port window, so even a probe race can only hit
    // another interactive run - which ChromeManager's foreign-Chrome guard then fails loudly.
    private const CFG_PORT_BAND_INTERACTIVE = 'PARALLEL.PORT_BAND_INTERACTIVE';
    private const CFG_WORKER_TIMEOUT        = 'PARALLEL.WORKER_TIMEOUT_SECONDS';

    // Per-project bdt_parallel.yml key for the interactive band. Lives NEXT TO the scheduled
    // "port_band" key in the same override file; either may be present independently.
    private const OVERRIDE_KEY_INTERACTIVE = 'port_band_interactive';

    // Wall-clock ceiling fallback when PARALLEL.WORKER_TIMEOUT_SECONDS is not configured.
    // Mirrors RunParallel: a Behat run needs far longer than any generic CLI default.
    private const RUN_TIMEOUT_SECONDS = 1800;

    // Poll cadence while draining the Behat process. 100 ms streams output near-live while the
    // busy-wait between reads costs negligible CPU over a multi-minute run.
    private const DRAIN_POLL_MICROSECONDS = 100000;

    private const APP_ALIAS = 'axenox.BDT';

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
     * @return array{0:string,1:string,2:string,3:string,4:string,5:string} arguments for performDeferred()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result): array
    {
        // --- Resolve inputs (option first, then app-config fallback, else loud failure) ---
        // behat_config additionally falls back to the installation-root behat.yml (see
        // resolveBehatConfig), so a tester never has to set anything; chrome_path has no safe
        // default and still fails loudly when unresolved.
        $behatConfig = $this->resolveBehatConfig($task);
        $chromePath  = $this->resolvePathParam($task, self::OPT_CHROME_PATH, self::CFG_CHROME_PATH, 'chrome.exe');
        $suite       = $this->getTaskParam($task, self::OPT_SUITE, '');
        $tags        = $this->getTaskParam($task, self::OPT_TAGS, '');
        $feature     = $this->getTaskParam($task, self::OPT_FEATURE, '');

        if (! is_file($chromePath)) {
            throw new RuntimeException('chrome_path is not a file: ' . $chromePath
                . ' (must be chrome.exe, NOT GoogleChromePortable.exe - its single-instance lock breaks concurrent runs)');
        }
        if ($feature !== '' && ! file_exists($feature)) {
            throw new RuntimeException('feature does not exist: ' . $feature);
        }

        $cwd = $this->getWorkbench()->getInstallationPath();

        // These become the positional arguments of performDeferred() - order MUST match its
        // signature. The deferred generator only starts when the facade drains the result, so the
        // init/port/config/run work is still lazy; we merely resolved its inputs eagerly.
        return [$cwd, $behatConfig, $chromePath, $suite, $tags, $feature];
    }

    /**
     * Deferred phase: the streamed generator that performs init, allocates a port, writes the
     * config, runs Behat and yields its output as it arrives. Invoked by AbstractActionDeferred
     * with the array performImmediately() returned, only once the facade drains the result.
     *
     * WHY EVERY PARAMETER IS OPTIONAL: AbstractActionDeferred declares performDeferred() abstract,
     * and PHP forbids an override from adding required parameters an abstract signature lacks. The
     * real values always arrive (performImmediately() supplies all six), so the defaults are never
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
     * WHY A CRASH YIELDS A BANNER INSTEAD OF THROWING: by the time Behat has run, its run record
     * and per-scenario rows already exist in the DB. Throwing after streaming would abort the
     * stream mid-flight with a stack trace on top of already-printed output. A bold CRASHED
     * banner on the live console is the loud, legible failure signal for the interactive path;
     * the authoritative outcome remains in the DB rows. A TIMEOUT, by contrast, is an abort we
     * cannot complete, so it throws (after cleanup) to fail the action.
     */
    protected function performDeferred(
        string $cwd = '',
        string $behatConfig = '',
        string $chromePath = '',
        string $suite = '',
        string $tags = '',
        string $feature = ''
    ): \Generator {
        // --- Step 0: init exactly like every Behat run does, streaming its output too ---
        // `Behat init` (re)creates the global behat.yml, registers app suites and refreshes
        // base_url to the current workbench URL. Skipping it would let the generated config
        // import a stale base and break the run - same reasoning as RunParallel::runInit().
        yield "===== BDT interactive run =====\n";

        // --- Step 1: allocate a collision-free port from the INTERACTIVE band (late, on purpose) ---
        [$portStart, $portEnd] = $this->resolvePortBand(
            $behatConfig,
            self::OVERRIDE_KEY_INTERACTIVE,
            self::CFG_PORT_BAND_INTERACTIVE
        );
        // Reserve the port ACROSS PROCESSES, not just probe it: independent concurrent interactive
        // runs each pick a port seconds before Chrome binds it, so a bare probe hands the same port
        // to all of them (and, because the port also names the profile dir, collapses their Chrome
        // profiles into one). The cross-process lock keeps this port ours from here until the run
        // ends - released in the outer finally below so the next tester can claim it.
        $reservation = $this->reserveFreePort($portStart, $portEnd);
        $port = $reservation['port'];
        yield 'Allocated port ' . $port . ' (band ' . $portStart . '-' . $portEnd . ")\n";

        try {
            // --- Step 2: generate the per-run config carrying the port into Behat ---
            $configPath = $this->writeInteractiveConfig($cwd, $behatConfig, $port, $chromePath);
            yield 'Config: ' . basename($configPath) . "\n\n";

            // --- Step 3: run Behat, streaming output; classify the exit code at the end ---
            $timeout = $this->resolveRunTimeout();
            $cmd     = $this->buildBehatCommand($configPath, $suite, $tags, $feature);
            // The environment is inherited unchanged EXCEPT for one injected variable:
            // BDT_RUN_COMMAND carries the durable, rerunnable action command so the formatter
            // records it instead of this run's ephemeral "vendor\bin\behat --config
            // behat_interactive_<port>.yml ..." argv. Passing an env array to Symfony Process ADDS
            // this var while still inheriting everything else, so a developer running under a
            // debugger keeps normal single-session debugging (the Xdebug env stays intact).
            $runCommand = $this->describeInvocation($suite, $tags, $feature);
            $process = Process::fromShellCommandline(
                $cmd,
                $cwd,
                ['BDT_RUN_COMMAND' => $runCommand],
                null,
                $timeout
            );
            // Environment is inherited UNCHANGED: unlike fleet workers there is exactly one child
            // here, so a developer running this under a debugger keeps normal single-session debugging.
            $process->start();

            try {
                while ($process->isRunning()) {
                    $chunk = $this->drainIncremental($process);
                    if ($chunk !== '') {
                        yield $chunk;
                    }
                    // In async mode Symfony only enforces the timeout when asked, so a hung run would
                    // otherwise never time out.
                    try {
                        $process->checkTimeout();
                    } catch (ProcessTimedOutException $e) {
                        // Kill the child, flush its tail, then fail loudly - a timeout is an abort we
                        // cannot complete. cleanup in finally still removes the generated config.
                        $process->stop(0);
                        $tail = $this->drainIncremental($process);
                        if ($tail !== '') {
                            yield $tail;
                        }
                        throw new RuntimeException(
                            'Interactive Behat run timed out after ' . $timeout . ' s. Adjust '
                            . self::CFG_WORKER_TIMEOUT . ' if the selection legitimately needs longer.'
                        );
                    }
                    usleep(self::DRAIN_POLL_MICROSECONDS);
                }
                // Flush whatever arrived after the last read inside the loop.
                $tail = $this->drainIncremental($process);
                if ($tail !== '') {
                    yield $tail;
                }

                // Exit-code classification: 0 (all passed) and 1 (ran to completion, some tests
                // failed) both mean Behat itself did its job - authoritative per-scenario pass/fail
                // lives in the DB rows written by DatabaseFormatter. Anything else is a crash.
                $exitCode = $process->getExitCode(); // null if terminated by a signal
                if ($exitCode === 0) {
                    yield "\n===== Behat finished: all scenarios passed. =====\n";
                } elseif ($exitCode === 1) {
                    yield "\n===== Behat finished: some scenarios FAILED (see run results). =====\n";
                } else {
                    yield "\n***** Behat CRASHED with exit code "
                        . ($exitCode === null ? 'n/a (terminated)' : $exitCode)
                        . " - this is NOT a normal test failure. Check the output above and the run record. *****\n";
                }
            } finally {
                // Delete the generated config regardless of how the run ended. The port-based
                // filename means the next run on this port simply overwrites it, so a leftover on a
                // hard failure is harmless - but tidy up on the normal path.
                @unlink($configPath);
            }
        } finally {
            // Reap any orphaned Chrome bound to THIS run's interactive profile and remove the profile
            // dir BEFORE releasing the port. The Behat child launches Chrome detached (start /B), so a
            // timed-out/crashed run can leave the browser alive holding the profile - hence an explicit
            // reap, not a bare rmdir. Done before releaseReservedPort() so the freed port cannot be
            // reclaimed by another interactive run whose fresh interactive<port> dir we would then delete.
            $this->cleanupInteractiveChrome($cwd, (int) $port);
            // Release the cross-process port reservation no matter how the run ended (including a throw
            // from writeInteractiveConfig before the inner try opens).
            $this->releaseReservedPort($reservation);
        }
    }

    /**
     * Reads and clears a process's incremental stdout+stderr in one call.
     *
     * WHY incremental + clearOutput: getIncrementalOutput()/getIncrementalErrorOutput() are
     * non-blocking and return only what arrived since the previous call, which is exactly what a
     * streaming loop needs; clearOutput()/clearErrorOutput() then drop the already-forwarded
     * bytes so a run that logs for minutes does not accumulate its whole output in memory.
     */
    private function drainIncremental(Process $process): string
    {
        $out = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
        $process->clearOutput();
        $process->clearErrorOutput();
        return $out;
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
     * WHY DECLARED EXPLICITLY: Symfony Console rejects unknown options. All five are optional at
     * the CLI layer - behat_config resolves via option, then app-config, then the installation-root
     * behat.yml (see resolveBehatConfig); chrome_path falls back to app config and only THEN fails
     * loudly if unresolved; while suite/tags/feature are genuine pass-throughs a tester may combine
     * freely and are omitted from the Behat command when unset.
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions(): array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::OPT_BEHAT_CONFIG)
                ->setDescription('Path to the base behat.yml the generated config imports. Falls back to app-config ' . self::CFG_BEHAT_CONFIG . ', then to the installation-root behat.yml, when omitted.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(self::OPT_CHROME_PATH)
                ->setDescription('Path to chrome.exe (NOT GoogleChromePortable.exe). Falls back to app-config ' . self::CFG_CHROME_PATH . ' when omitted.')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(self::OPT_SUITE)
                ->setDescription('Optional Behat suite name, passed through as --suite')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(self::OPT_TAGS)
                ->setDescription('Optional Behat tag filter, passed through as --tags')
                ->setDefaultValue(''),
            (new ServiceParameter($this))
                ->setName(self::OPT_FEATURE)
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
     * It only ADDS the per-run overrides: the Mink api_url and the chrome section (port, direct
     * chrome.exe, isolated profile). NO run_uid/lane_id is written - an interactive run creates
     * and finalizes its own run record, attach-mode is a fleet-only concept.
     *
     * WHY A PORT-BASED FILENAME AND PROFILE DIR: the port is unique among concurrently running
     * BDT instances by construction (the probe skipped bound ports), so behat_interactive_<port>.yml
     * and chrome_profiles\interactive<port> can never be shared by two live runs - and both are
     * bounded by the band width, so nothing accumulates across runs; a later run on the same
     * port reuses/overwrites them.
     *
     * IMPORTANT: user_data_dir is written as a RELATIVE path because ChromeManager::start()
     * prepends getcwd(); an absolute path would be double-prepended into a broken
     * "C:\...\C:\..." path and Chrome would fall back to the real default profile.
     */
    private function writeInteractiveConfig(string $workingDir, string $behatConfig, int $port, string $chromePath): string
    {
        $userDataDirRelative = 'data' . DIRECTORY_SEPARATOR
            . 'axenox' . DIRECTORY_SEPARATOR
            . 'BDT' . DIRECTORY_SEPARATOR
            . 'chrome_profiles' . DIRECTORY_SEPARATOR . 'interactive' . $port;
        $userDataDirAbsolute = $workingDir . DIRECTORY_SEPARATOR . $userDataDirRelative;
        if (! is_dir($userDataDirAbsolute) && ! @mkdir($userDataDirAbsolute, 0755, true) && ! is_dir($userDataDirAbsolute)) {
            throw new RuntimeException('Could not create interactive user_data_dir: ' . $userDataDirAbsolute);
        }

        $configPath = dirname($behatConfig) . DIRECTORY_SEPARATOR . 'behat_interactive_' . $port . '.yml';
        // Import the base config by its real filename so the import matches even on
        // case-sensitive systems instead of assuming "behat.yml".
        $importConfigName = basename($behatConfig);

        $extensionFqn = \axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension::class;

        $yaml = "# AUTO-GENERATED interactive run config - overwritten per run on this port. Do not edit by hand.\n"
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
            . "      chrome:\n"
            . "        port: " . $port . "\n"
            . "        executable: '" . $this->yamlEscapeWindowsPath($chromePath) . "'\n"
            . "        user_data_dir: '" . $this->yamlEscapeWindowsPath($userDataDirRelative) . "'\n";

        if (file_put_contents($configPath, $yaml) === false) {
            throw new RuntimeException('Failed to write interactive config: ' . $configPath);
        }
        return $configPath;
    }

    /**
     * Builds the Behat command with the tester's pass-through selection.
     *
     * WHY POSITIONAL FEATURE OUTSIDE --config: Behat only honours a feature path given as a
     * positional argument; concatenating it into the config option silently runs everything -
     * the exact trap already documented for the fleet's worker command. We deliberately do NOT
     * add --colors: this output is forwarded to a captured stream, and forcing ANSI colours on a
     * non-TTY would inject escape codes into the result; Behat's own TTY auto-detection is right.
     */
    private function buildBehatCommand(string $configPath, string $suite, string $tags, string $feature): string
    {
        $cmd = 'vendor\\bin\\behat ';
        if ($suite !== '') {
            $cmd .= ' --suite="' . $suite . '"';
        }
        if ($tags !== '') {
            $cmd .= ' --tags="' . $tags . '"';
        }
        if ($feature !== '') {
            $cmd .= ' "' . $feature . '"';
        }
        return $cmd . ' --config "' . $configPath . '"';
    }

    /**
     * Builds the durable, rerunnable command stored in the run's behat_command column.
     *
     * WHY NOT buildBehatCommand()'s STRING: that one is "vendor\bin\behat --config
     * behat_interactive_<port>.yml ...", whose config file is per-run, port-specific and deleted
     * when the run ends - useless when the Test Runs page reruns it later. The reproducible value
     * is the ACTION invocation with the selectors the tester actually chose, exactly as
     * RunParallel::describeInvocation() records for the fleet, so both run types rerun the same way.
     */
    private function describeInvocation(string $suite, string $tags, string $feature): string
    {
        $cmd = 'vendor\\bin\\action ' . self::APP_ALIAS . ':RunTest';
        if ($tags !== '') {
            $cmd .= ' --tags="' . $tags . '"';
        }
        if ($feature !== '') {
            $cmd .= ' --feature="' . $feature . '"';
        }
        if ($suite !== '') {
            $cmd .= ' --suite="' . $suite . '"';
        }
        return $cmd;
    }
    

    /**
     * Resolves the base behat.yml the generated interactive config imports: the --behat_config
     * option when given, else the PARALLEL.BEHAT_CONFIG app-config key, else the installation-root
     * behat.yml. The resolved file is validated and a missing one throws.
     *
     * WHY A CWD FALLBACK (unlike the generic chrome_path resolve): the interactive page runs
     * Behat init when it opens and a tester can only trigger a run afterwards, so the global
     * behat.yml at the installation root is guaranteed present and current here - the exact same
     * invariant that lets RunParallel::resolveBehatConfig() default to it. Defaulting to that known
     * file is therefore not the "guess a path and run green against nothing" footgun this framework
     * guards against; it just spares the tester from configuring anything. chrome.exe has no such
     * guaranteed location, which is why chrome_path keeps failing loudly when unresolved.
     *
     * @throws RuntimeException if the resolved file does not exist
     */
    private function resolveBehatConfig(TaskInterface $task): string
    {
        $path = null;

        // 1) Explicit option always wins.
        if ($task->hasParameter(self::OPT_BEHAT_CONFIG)) {
            $val = $task->getParameter(self::OPT_BEHAT_CONFIG);
            if ($val !== null && $val !== '') {
                $path = (string) $val;
            }
        }

        // 2) App-config key, kept for installations that already set it centrally.
        if ($path === null) {
            $cfg = $this->getWorkbench()->getApp(self::APP_ALIAS)->getConfig();
            if ($cfg->hasOption(self::CFG_BEHAT_CONFIG)) {
                $fromCfg = $cfg->getOption(self::CFG_BEHAT_CONFIG);
                if ($fromCfg !== null && $fromCfg !== '') {
                    $path = (string) $fromCfg;
                }
            }
        }

        // 3) Installation-root behat.yml that the on-open Behat init keeps current.
        if ($path === null) {
            $path = $this->getWorkbench()->getInstallationPath()
                . DIRECTORY_SEPARATOR . self::DEFAULT_BEHAT_CONFIG;
        }

        if (! is_file($path)) {
            throw new RuntimeException('behat_config is not a file: ' . $path);
        }
        return $path;
    }
    
    /**
     * Reaps any orphaned Chrome bound to THIS run's interactive profile and removes the profile dir.
     *
     * WHY IT EXISTS: the Behat child launches Chrome detached (start /B), so a timed-out or crashed
     * interactive run can leave the browser alive holding data\...\chrome_profiles\interactive<port>.
     * Nothing else removes it, and because the dir is named by PORT (not reused like the fleet's fixed
     * lane dirs), every run leaves a fresh one - so without this they accumulate without bound.
     *
     * WHY IT NEVER THROWS: it runs in performDeferred()'s finally, so a throw would mask the real run
     * outcome (or a propagating timeout exception). Failures are logged loudly instead.
     *
     * @param string $workingDir The run's working dir (same base writeInteractiveConfig() used)
     * @param int    $port        The interactive port that names this run's profile dir
     */
    private function cleanupInteractiveChrome(string $workingDir, int $port): void
    {
        try {
            // Must mirror writeInteractiveConfig()'s user_data_dir construction.
            $absProfileDir = $workingDir . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR
                . 'axenox' . DIRECTORY_SEPARATOR . 'BDT' . DIRECTORY_SEPARATOR
                . 'chrome_profiles' . DIRECTORY_SEPARATOR . 'interactive' . $port;
            $absProfileDir = realpath($absProfileDir) ?: $absProfileDir;

            $logger = $this->getWorkbench()->getLogger();
            $killed = $this->reapChromeProfileDir($absProfileDir, $this->listChromeProcessCommandLines());
            foreach ($killed as $pid) {
                $logger->info('BDT interactive cleanup: killed orphan Chrome PID ' . $pid . ' bound to ' . $absProfileDir);
            }
            // Chrome releases its profile file handles (ProcessSingleton lock, etc.) asynchronously
            // after taskkill returns; a short settle avoids racing them into a half-deleted dir.
            if ($killed !== []) {
                usleep(1_000_000);
            }
            if (! $this->removeDirectoryTree($absProfileDir)) {
                $logger->warning('BDT interactive cleanup: could not fully remove profile dir ' . $absProfileDir
                    . ' - a Chrome handle may still be open. It will be overwritten on the next run on this port.');
            }
        } catch (\Throwable $e) {
            // Backstop for the backstop: never let cleanup break the run's finally chain.
            try {
                $this->getWorkbench()->getLogger()->logException($e);
            } catch (\Throwable $ignored) {
                // Logging itself failed (e.g. workbench already torn down) - nothing safe left to do.
            }
        }
    }

    /**
     * Resolves a path parameter: the CLI option when given, else the app-config fallback, else a
     * loud failure.
     *
     * WHY THIS SHAPE: the interactive path should be launchable with just a tag/suite/feature
     * selection (server-wide config supplies the paths), but "no path anywhere" must never
     * silently default to a guessed location and produce a green run that tested nothing - the
     * exact failure mode this framework exists to catch. So the option wins, the config is the
     * fallback, and an unresolved path throws.
     */
    private function resolvePathParam(TaskInterface $task, string $optName, string $cfgKey, string $label): string
    {
        if ($task->hasParameter($optName)) {
            $val = $task->getParameter($optName);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        $cfg = $this->getWorkbench()->getApp(self::APP_ALIAS)->getConfig();
        if ($cfg->hasOption($cfgKey)) {
            $fromCfg = $cfg->getOption($cfgKey);
            if ($fromCfg !== null && $fromCfg !== '') {
                return (string) $fromCfg;
            }
        }
        throw new RuntimeException(
            'No ' . $label . ' path: pass --' . $optName . ' or set app-config ' . $cfgKey . '.'
        );
    }

    /**
     * Resolves the run's wall-clock timeout from app config, falling back to the constant.
     *
     * WHY THE SAME KEY AS THE FLEET: an interactive selection is at most as large as a fleet
     * lane's bucket, so one operator-tuned ceiling serves both paths without a second knob.
     */
    private function resolveRunTimeout(): float
    {
        $cfg = $this->getWorkbench()->getApp(self::APP_ALIAS)->getConfig();
        return (float) ($cfg->getOption(self::CFG_WORKER_TIMEOUT) ?: self::RUN_TIMEOUT_SECONDS);
    }

    /**
     * Doubles backslashes for safe single-quoted YAML on Windows paths.
     *
     * WHY: identical helper to RunParallel's - doubling avoids ambiguity if the generated file
     * is ever re-parsed by a stricter loader and keeps it readable.
     */
    private function yamlEscapeWindowsPath(string $path): string
    {
        return str_replace('\\', '\\\\', $path);
    }

    /**
     * Reads an optional (non-path) task parameter, falling back to a default.
     *
     * WHY separate from resolvePathParam: suite/tags/feature are genuine optionals with an empty
     * default and no config fallback - an absent one simply means "do not add this flag", so it
     * must never throw the way a missing mandatory path does.
     */
    private function getTaskParam(TaskInterface $task, string $name, string $default = ''): string
    {
        if ($task->hasParameter($name)) {
            $val = $task->getParameter($name);
            if ($val !== null && $val !== '') {
                return (string) $val;
            }
        }
        return $default;
    }
}