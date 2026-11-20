<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Owns ONE run row on behalf of an action: create, expected counts, run log, finalize.
 *
 * WHY THIS CLASS EXISTS: RunParallel and RunTest follow the same ownership model - the ACTION creates
 * the run row, writes the expected counts, and closes the row at the end, while the Behat child always
 * runs in attach-mode and only writes children under the UID. Before this class RunParallel carried
 * that lifecycle in private methods and RunTest relied on the formatter's owner mode, which meant the
 * action never knew the run UID an agent needs to read the results. One implementation for both keeps
 * the two paths from drifting apart again.
 *
 * Why the per-run log directory is here too: the run log names the on-disk Behat output, and the
 * directory that holds it is derived from the run UID, so both belong to the same owner.
 */
class RunLifecycle
{
    private WorkbenchInterface $workbench;
    private RunRecordWriter $writer;
    private string $logTitle;
    private ?DataSheetInterface $runSheet = null;
    private ?MarkdownLogBook $runLog = null;
    private string $runUid = '';
    private float $startedAt = 0.0;
    private string $logDirRelative = '';

    /**
     * @param WorkbenchInterface $workbench
     * @param string             $logTitle  Title prefix of the run log, e.g. "BDT parallel run"; the UID is appended
     */
    public function __construct(WorkbenchInterface $workbench, string $logTitle)
    {
        $this->workbench = $workbench;
        $this->logTitle = $logTitle;
        $this->writer = new RunRecordWriter();
    }

    /**
     * Creates the run row and opens the living run log with its "Run summary" section.
     *
     * WHY THE LOG IS OPENED RIGHT HERE: even a failure immediately after row creation (a feature-file
     * parse error, a port band that is exhausted) must still reach the DB through the close-out, so the
     * log has to exist from the first instant the row does.
     *
     * WHY THE INVOCATION IS IN THE SUMMARY: a reader of the DB row sees WHAT was asked for without
     * having to find the behat_command column.
     *
     * @param string $behatCommand Reproducible invocation, stored in the behat_command column
     * @return string The new run UID
     */
    public function open(string $behatCommand): string
    {
        $this->runSheet = $this->createRunSheet($behatCommand);
        $this->startedAt = microtime(true);
        $this->runUid = (string) $this->runSheet->getUidColumn()->getValue(0);

        $this->runLog = new MarkdownLogBook($this->logTitle . ' ' . $this->runUid);
        $this->runLog->addSection('Run summary');
        $this->runLog->addLine(self::runUidLine($this->runUid), 1);
        $this->runLog->addLine('Invocation: ' . MarkdownDataType::escapeCodeInline($behatCommand), 1, 'Run summary');
        return $this->runUid;
    }

    /**
     * Formats the line that reports a run's UID to whoever started it.
     *
     * WHY A SHARED FORMATTER: AI agents parse this line to find the run in the DB, so its format is a
     * contract - "Run UID: <uid>" on a line of its own. Both actions build it here so the two can never
     * report it differently.
     */
    public static function runUidLine(string $runUid): string
    {
        return 'Run UID: ' . $runUid;
    }

    /**
     * Appends this run's UID line to a message, unless it already carries it.
     *
     * WHY ONE PLACE: once the run row exists, EVERY outcome must report its UID, because that is how an
     * agent finds the run (and the reason for a broken feature) in the DB. Each action used to append the
     * line by hand at its own call sites, and the sites that were missed (a coordinator error, a failing
     * close-out, an exception after row creation) reported a failure the reader could not locate. Routing
     * every message and exception through here makes "forgot the UID" impossible to write.
     *
     * WHY "UNLESS IT ALREADY CARRIES IT": a message built earlier may contain the line already; repeating
     * it would only add noise to the output agents parse.
     */
    public function appendRunUid(string $message): string
    {
        $this->assertOpened(__FUNCTION__);
        $line = self::runUidLine($this->runUid);
        if (strpos($message, $line) !== false) {
            return $message;
        }
        return rtrim($message) . "\n" . $line;
    }

    /**
     * Returns the exception to throw for a failure that happened after the run row was created: the given
     * one when its message already carries the UID line, otherwise a RuntimeException with the line added
     * and the original as its previous exception.
     *
     * WHY WRAP INSTEAD OF MUTATING: an exception message cannot be changed after construction, and the
     * original (with its stack trace and type) stays reachable through getPrevious() for the workbench log.
     * The wrapper is for the reader of the message; the log keeps the root cause.
     */
    public function withRunUid(\Throwable $e): \Throwable
    {
        $this->assertOpened(__FUNCTION__);
        $message = $this->appendRunUid($e->getMessage());
        if ($message === $e->getMessage()) {
            return $e;
        }
        return new RuntimeException($message, null, $e);
    }

    /**
     * Returns the UID of the run row this lifecycle created.
     */
    public function getRunUid(): string
    {
        return $this->runUid;
    }

    /**
     * Inserts the run row and returns its sheet.
     *
     * WHY A SEPARATE, OVERRIDABLE STEP: it is the one place that needs a database. Keeping it apart lets a
     * test double supply a row without one, so the behaviour AROUND the row - the UID reported right after
     * it exists, the failure recorded and the row finalized when a later step throws - can be tested
     * without standing up a database. That behaviour is the contract agents depend on.
     */
    protected function createRunSheet(string $behatCommand): DataSheetInterface
    {
        return $this->writer->create($this->workbench, $behatCommand);
    }

    /**
     * Returns the living run log, which the owner appends to as the run happens.
     */
    public function getRunLog(): MarkdownLogBook
    {
        $this->assertOpened(__FUNCTION__);
        return $this->runLog;
    }

    /**
     * Refuses to hand out run-bound values before the run row exists.
     *
     * WHY IT THROWS: before open() there is no UID and no log. Without this guard appendRunUid() would stamp
     * "Run UID: " with an EMPTY uid onto an error message - a line an agent parses as a real run - and
     * getRunLog() would return null into a typed signature far from the actual mistake. The contract is that
     * a failure before the row exists carries NO UID line, so calling these too early is a programming error
     * that must fail where it is made.
     *
     * @param string $caller Name of the public method that was called too early
     * @throws RuntimeException if open() has not created the run row yet
     */
    private function assertOpened(string $caller): void
    {
        if ($this->runUid === '' || $this->runLog === null) {
            throw new RuntimeException('RunLifecycle::' . $caller . '() was called before open() created the run row.');
        }
    }

    /**
     * Logs an exception to the workbench log and NEVER throws - the shared "never throws" logging boundary of
     * the run close-out.
     *
     * WHEN TO USE IT: in a catch that is itself a documented boundary - diagnostics, housekeeping, the digest
     * and staging steps right before finalize() - where the original problem is being deliberately survived
     * and only has to leave a trace. The actions' close-out calls it too (RunTest::closeOutRun()), instead of
     * each writing its own guarded logger call. Do NOT use it to hide a failure the caller must see: a catch
     * that should stop the run still re-throws.
     *
     * WHY IT IS GUARDED: the workbench logger writes to a database that may be exactly what is broken. A
     * logger call that throws from inside a boundary would skip everything after it - above all
     * finished_on - and would replace the failure that was being survived. So a failing logger is swallowed
     * here, as the very last thing, and nothing safe is left to do about it.
     *
     * WHY IT STAYS AFTER OS RESOURCES: it is DB-backed logging, so callers free processes, ports and file
     * handles first (see "Resources before logging").
     *
     * @param \Throwable $e The exception being survived
     */
    public function logQuietly(\Throwable $e): void
    {
        try {
            $this->workbench->getLogger()->logException($e);
        } catch (\Throwable $ignored) {
            // The logger itself failed (e.g. its database is down); nothing safe is left to do.
        }
    }

    /**
     * Returns the microtime at which the run row was created, the origin of every run duration.
     */
    public function getStartedAt(): float
    {
        return $this->startedAt;
    }

    /**
     * Returns the relative path of the run's log directory, or '' while ensureLogDir() has not run.
     *
     * Why relative and not absolute: it is only ever shown to a human, who reads it against the
     * installation root they already know; an absolute path on a Windows server adds noise.
     */
    public function getLogDirRelative(): string
    {
        return $this->logDirRelative;
    }

    /**
     * Writes the expected feature/scenario counts onto the run row (once, for the whole run).
     *
     * WHY THE ACTION WRITES THEM: an attach-mode Behat child deliberately skips this, and the expected
     * totals must exist even when the child dies partway through - otherwise silent-stop detection is
     * impossible.
     */
    public function setExpectedCounts(int $featureCount, int $scenarioCount): void
    {
        $this->writer->setExpectedCounts($this->runSheet, $featureCount, $scenarioCount);
    }

    /**
     * Ensures THIS run's own log directory exists and returns its absolute path.
     *
     * Layout: data/axenox/BDT/Logs/<run_uid>/
     *
     * WHY ONE DIRECTORY PER RUN: since a Behat process runs a single feature (RunParallel) or a whole
     * selection (RunTest), a run produces its own set of log files. Dropped into a shared directory they
     * bury each other, and files from different runs interleave alphabetically, so finding "the logs of
     * last night's run" means reading run UIDs off filenames. Giving each run its own directory makes a
     * run's logs a single unit that can be opened, zipped or deleted as one.
     *
     * WHY THERE IS NO DAILY LEVEL ABOVE IT: the daily folder only bounded how many entries sit in one
     * directory, and it cost more than it bought. A run is identified everywhere - in the run row, in the
     * CLI message, in an error report - by its UID alone, so a reader holding a UID had to first work out
     * which day that run started on before they could find its files. A run that starts at 23:55 makes
     * that guess wrong, which is exactly when the logs are wanted. The UID is unique on its own, so it
     * is the only level.
     *
     * Anchored at $cwd (installation root) via BdtPaths, so it never depends on the process cwd and never
     * spells out BDT's data folder itself.
     *
     * @param string $cwd Installation root
     * @return string Absolute path to data/axenox/BDT/Logs/<run_uid>
     */
    public function ensureLogDir(string $cwd): string
    {
        if ($this->runUid === '') {
            throw new RuntimeException('Cannot create a log directory: the run has no UID yet.');
        }
        $this->logDirRelative = BdtPaths::relative(BdtPaths::FOLDER_LOGS, $this->runUid);
        return BdtPaths::ensure($cwd, BdtPaths::FOLDER_LOGS, $this->runUid);
    }

    /**
     * Opens one log file inside the run's log directory, for append.
     *
     * WHY ONE OPENER FOR EVERY LOG FILE: the worker logs, the coordinator log and RunTest's output log
     * are all "a named file in the run's directory". Append mode tolerates a re-run without truncating
     * earlier diagnostics, and a failure to open must be loud: a run whose output has nowhere to go
     * would otherwise look like a run that printed nothing.
     *
     * @param string $logDir  Absolute directory returned by ensureLogDir()
     * @param string $logName Basename of the log file
     * @return resource
     * @throws RuntimeException if the file cannot be opened
     */
    public function openLogFile(string $logDir, string $logName)
    {
        $handle = @fopen($logDir . DIRECTORY_SEPARATOR . $logName, 'a');
        if ($handle === false) {
            throw new RuntimeException('Could not open log file ' . $logName . ' of run ' . $this->runUid);
        }
        return $handle;
    }

    /**
     * Finalises the living run-log (adds the error, if any, and the generated timestamp) and stages it as
     * Markdown text on the run sheet, swallowing any log-side error.
     *
     * Why it never throws: it runs in the single close-out immediately before finalize(), so a throw
     * here would stop finished_on from being written and leave the run row open - the exact orphaned-
     * run failure we are trying to avoid. The run-log is diagnostics; it must never mask or block the
     * run's finalization, so on any failure we store a tiny Markdown marker instead. It does NOT call
     * dataUpdate: the value rides along in finalize()'s single update, avoiding a second optimistic-
     * locking round-trip on a row whose only writer is the owning action.
     *
     * @param \Throwable|null $error Run-level failure to record under "Run summary".
     */
    public function stageRunLog(?\Throwable $error): void
    {
        try {
            // Defensive: the log is created by open() before anything can fail into the close-out, but if
            // an extremely early failure left it null, build a minimal book so we still store something
            // useful rather than nothing.
            $log = $this->runLog ?? (new MarkdownLogBook($this->logTitle . ' ' . $this->runUid))
                ->addSection('Run summary')
                ->addLine(self::runUidLine($this->runUid), 1);
            if ($error !== null) {
                $log->addLine('Coordinator error: ' . $error->getMessage(), 1, 'Run summary');
            }
            $log->addLine('Generated: ' . DateTimeDataType::now(), 1, 'Run summary');
            $markdown = RunLogDigest::capRunLogText((string) $log);
        } catch (\Throwable $e) {
            // Documented boundary: the log is diagnostics. The marker below is stored instead, and the
            // cause is logged so the failure is not lost.
            $this->logQuietly($e);
            // The log column is Markdown text (not JSON), so the failure marker is plain Markdown too -
            // still human-readable straight from the DB.
            $markdown = '## Run summary' . "\n\n"
                . self::runUidLine($this->runUid) . "\n\n"
                . 'Run log build failed: ' . $e->getMessage();
        }
        // Staging the value is inside its own guard because the docblock's promise ("never throws")
        // was not actually kept: setCellValue() sits on the run sheet and can fail on its own - an
        // unknown column, a value the column's data type rejects - and that throw would have escaped
        // straight past finalize(), leaving the run row open with no finished_on. Losing the digest is
        // an acceptable outcome; losing the run's close-out because of the digest is not.
        try {
            $this->writer->setRunLog($this->runSheet, $markdown);
        } catch (\Throwable $e) {
            $this->logQuietly($e);
        }
    }

    /**
     * Stages the run-level failure on the run sheet, so the finalize update records it and the run status
     * can never read as passed.
     *
     * WHY THE LIFECYCLE AND NOT EACH ACTION: both actions must record a failure the same way, or one of them
     * drifts back into reporting a failed run as green.
     *
     * Why it never throws: exactly like stageRunLog() it runs right before finalize(); a staging failure
     * must not stop finished_on from being written. Losing the failure text is bad - the run would then
     * read from its child rows alone - but losing the close-out is worse.
     *
     * @param string $message What failed; empty means "nothing failed" and stages nothing
     */
    public function stageFailure(string $message): void
    {
        try {
            $this->writer->setError($this->runSheet, $message);
        } catch (\Throwable $e) {
            $this->logQuietly($e);
        }
    }

    /**
     * Closes the run row: finished_on, duration and the staged run log in one update.
     *
     * @throws \Throwable if the update fails - the caller decides whether that may hide the run outcome
     */
    public function finalize(): void
    {
        $this->writer->finalize($this->runSheet);
    }
}
