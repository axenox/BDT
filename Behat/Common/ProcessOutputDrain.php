<?php
namespace axenox\BDT\Behat\Common;

use Symfony\Component\Process\Process;

/**
 * Drains a running Behat process's output into its log file - shared by RunTest and every RunParallel lane.
 *
 * WHY ONE HELPER: RunTest (drainIncremental) and RunParallel (streamLaneOutput) each had their own copy of
 * the read-write-clear sequence and their own copy of the poll cadence. A fix to one - e.g. the clear step
 * that keeps a long run from holding its whole output in memory - would not reach the other.
 */
final class ProcessOutputDrain
{
    /**
     * Pause between two drain passes, in microseconds.
     *
     * WHY 100 ms: output is streamed near-live (to the console of an interactive run, to the lane logs of a
     * fleet), while the busy-wait between reads costs negligible CPU over a run of many minutes.
     */
    public const POLL_MICROSECONDS = 100000;

    /**
     * Reads and clears the process's incremental stdout+stderr, copying it into the log file on the way.
     *
     * WHY incremental + clear: getIncrementalOutput()/getIncrementalErrorOutput() are non-blocking and return
     * only what arrived since the previous call, which is what a polling loop needs; clearOutput() and
     * clearErrorOutput() then drop the forwarded bytes so a process that logs for minutes does not accumulate
     * its entire output in memory.
     *
     * WHY THE FILE IS WRITTEN HERE: the chunk is drained exactly once, so this is the only place that sees all
     * of the output. Writing it at the same moment it is returned keeps the file identical to what the caller
     * forwards, and the run-log digest is built from that file at close-out. Raw fwrite (no added newline)
     * preserves the process's own line breaks.
     *
     * @param resource|null $logHandle Open handle of the output file, or NULL to only drain
     * @return string What arrived since the previous call ('' when nothing did)
     */
    public static function drain(Process $process, $logHandle = null): string
    {
        $out = $process->getIncrementalOutput() . $process->getIncrementalErrorOutput();
        $process->clearOutput();
        $process->clearErrorOutput();
        if ($out !== '' && is_resource($logHandle)) {
            @fwrite($logHandle, $out);
        }
        return $out;
    }
}
