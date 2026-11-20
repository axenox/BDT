<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\DataTypes\MarkdownDataType;

/**
 * Builds the bounded digest of a Behat process output file that goes into a run's "log" column.
 *
 * WHY ONE CLASS FOR BOTH ACTIONS: RunParallel (one Behat process per feature) and RunTest (one Behat
 * process per run) must describe a finished process the same way - status, log file name, Behat's
 * counts block and, only for a dead process, its last output. Two implementations would drift, and a
 * reader comparing the two kinds of run in the DB would have to know which dialect each one speaks.
 *
 * The digest is an ORCHESTRATION log, not a test report: per-scenario and per-step outcomes are
 * persisted authoritatively as child rows by the attach-mode DatabaseFormatter, so repeating them here
 * only pushed the diagnostics (worker errors, launch failures, the coordinator error itself) past the
 * size cap. What stays is the run configuration, the worker status and Behat's counts block; the
 * verbose test output stays in the on-disk log, which every section names.
 */
final class RunLogDigest
{
    private const LOG_TAIL_READ_BYTES  = 65536;
    private const LOG_CRASH_TAIL_LINES = 40;
    // This tail is only produced for a process that actually DIED, which is rare, so a slightly larger
    // budget costs nothing on a healthy run but buys headroom for a stack trace.
    private const LOG_CRASH_TAIL_BYTES = 4096;
    private const LOG_TOTAL_MAX_BYTES  = 65536;

    /**
     * Appends one Behat process's outcome to the living run-log: worker status, the on-disk log name and
     * Behat's counts block - parsed once from that process's now-closed log file.
     *
     * Why no test detail here: the failed-scenario list and the inline failure output are TEST results,
     * and the attach-mode DatabaseFormatter already writes those per scenario and per step as child rows
     * of this very run. Duplicating them into the run row added nothing a reader could not query, while
     * a failure-heavy run filled the whole byte budget and truncated away the diagnostics this log
     * exists for. The run log answers "did the process work?"; the child rows answer "did the tests pass?".
     *
     * Why "worker done" is not "tests passed": worker status reports whether the PROCESS completed -
     * Behat exit 1 (a completed run with test failures) is a healthy worker.
     *
     * @param MarkdownLogBook $log         The run's living log
     * @param string          $logDir      Absolute directory holding the process log
     * @param string          $section     Run-log section of this process, created by the caller so
     *                                     sections stay in dispatch order.
     * @param string          $logName     Basename of this process's own log file.
     * @param string|null     $workerError Process-level failure (crash, kill, timeout), or null on a normal exit.
     */
    public static function appendWorkerOutcome(MarkdownLogBook $log, string $logDir, string $section, string $logName, ?string $workerError): void
    {
        $log->addLine('Worker: ' . ($workerError === null ? 'done' : 'failed'), 1, $section);
        if ($workerError !== null) {
            $log->addLine('Worker error: ' . $workerError, 1, $section);
        }
        // Always name the on-disk log: the verbose Behat output is deliberately NOT copied into the run
        // row, so the reader must be told where the full truth lives.
        $log->addLine('Worker log: ' . MarkdownDataType::escapeCodeInline($logName), 1, $section);

        $lines = self::readLaneLogTail($logDir . DIRECTORY_SEPARATOR . $logName);
        if ($lines === null) {
            $log->addLine('Worker log file missing or unreadable', 1, $section);
            return;
        }

        $summary = self::extractBehatSummary($lines);
        if ($summary !== null) {
            // The counts block already states how many scenarios/steps failed, so no separate
            // failed-scenario list is needed to see whether the tests were green.
            $log->addLine('Behat summary:', 1, $section);
            $log->addCodeBlock($summary, '', $section);
        } else {
            $log->addLine('Behat summary: not reached - the worker never printed a run summary', 1, $section);
        }

        // Crash tail only. A process that RAN to completion (exit 0/1) has its outcome in the child rows,
        // so none of its output belongs here. A process that crashed or was killed produced NO child row
        // explaining itself, so its last lines are the only diagnosis available anywhere.
        if ($workerError !== null) {
            $tail = self::extractCrashTail($lines);
            if ($tail !== null) {
                $log->addLine('Last output before failure:', 1, $section);
                $log->addCodeBlock($tail, '', $section);
            }
        }
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
    public static function capRunLogText(string $markdown): string
    {
        if (strlen($markdown) <= self::LOG_TOTAL_MAX_BYTES) {
            return $markdown;
        }
        $marker = "\n\n... (run log truncated at " . self::LOG_TOTAL_MAX_BYTES . ' bytes)';
        $budget = self::LOG_TOTAL_MAX_BYTES - strlen($marker);
        return mb_strcut($markdown, 0, max(0, $budget)) . $marker;
    }

    /**
     * Pulls Behat's end-of-run counts block ("N scenarios (...)", "M steps (...)", timing) from the
     * process log lines.
     *
     * Why the counts block specifically: it is the one line group Behat prints on EVERY completed run
     * regardless of the configured output formatter, so it is the most reliable "what happened" signal
     * for the digest.
     *
     * @param string[] $lines
     * @return string|null The joined summary lines, or null if the run never reached a summary.
     */
    private static function extractBehatSummary(array $lines): ?string
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
     * Reads the last LOG_TAIL_READ_BYTES of a process log and returns it as ANSI-stripped lines.
     *
     * Why a bounded tail instead of reading the whole file: a UI5 run can emit a very large log, and
     * pulling it fully into memory - once per feature run - just to look at its last lines is both
     * wasteful and a real out-of-memory risk on a run with many failures. Everything the digest still
     * needs (Behat's counts block, and the last output before a crash) sits at the END of the file, so
     * a tail read is sufficient by construction.
     *
     * @return string[]|null Lines of the tail, or null when the file is missing or unreadable.
     */
    private static function readLaneLogTail(string $logFile): ?array
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
     * Builds a bounded tail of the last meaningful output lines for a process that DIED.
     *
     * Why only for a crashed process: a process that completed has its per-scenario outcome in the child
     * rows, so its output is noise in the run row. A process that died (crash, taskkill, fatal error)
     * left no row behind at all, and its final lines are the only evidence of why.
     *
     * Why the noise filter: Behat's Symfony console prints its full command synopsis (a ~700 byte
     * single line) AFTER a fatal, and frames its error blocks in box-drawing characters. Both are pure
     * padding, and on a byte-bounded tail they push the one line that names the cause - the framed
     * "In <file> line <n>" block - out of the budget. Dropping them first means the budget is spent on
     * diagnosis rather than on decoration.
     *
     * @param string[] $lines
     * @return string|null The tail, or null when the process produced no meaningful output at all.
     */
    private static function extractCrashTail(array $lines): ?string
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
}
