<?php
namespace axenox\BDT\Behat\Common;

/**
 * Shared reaper for orphaned Chrome process trees and their profile directories.
 *
 * WHY A TRAIT: both RunParallel (scheduled fleet, lane<N> profiles) and RunTest (interactive
 * single run, interactive<port> profile) launch Chrome DETACHED via "start /B". A worker/child
 * that is hard-killed on timeout leaves its Chrome tree alive, still holding a locked profile
 * dir. Both actions therefore need the identical end-of-run cleanup: find every Chrome bound to a
 * given profile dir, kill the tree, then remove the dir. Keeping one implementation guarantees the
 * two paths can never drift in HOW they match a Chrome to its profile (the lane1/lane10 prefix trap
 * is easy to reintroduce) or how they remove a Windows-locked profile tree.
 *
 * WHY WINDOWS-ONLY PRIMITIVES: the whole browser lifecycle here is Windows by construction
 * (start /B launch, taskkill teardown, netstat/CIM discovery), so rd/taskkill/Get-CimInstance are
 * the native, fastest tools. None of these methods throw - a leftover browser is a nuisance to log,
 * never a reason to abort an otherwise-finished run - so callers invoke them from finally blocks.
 *
 * This trait has NO dependencies on the using class (unlike PortProbingTrait it needs no
 * getWorkbench()); callers own their own logging.
 */
trait ChromeProfileReaperTrait
{
    /**
     * Returns the command line of every running chrome.exe, keyed by PID.
     *
     * WHY THE COMMAND LINE (not just the PID): the profile dir - the only marker tying a Chrome to a
     * specific lane/port - lives in the --user-data-dir launch argument, not in any CIM property. A
     * single snapshot is taken so a caller matching several dirs scans chrome.exe only once.
     *
     * WHY Get-CimInstance: wmic is removed on current Windows; Get-CimInstance reads the same
     * Win32_Process data. Output is "<pid>|<commandline>" per line; the PID is numeric and the
     * command line is only str_contains-matched later, so the pipe delimiter is safe. No caller input
     * reaches this command, so there is no injection surface.
     *
     * @return array<int,string> PID => full command line (processes with no readable command line are omitted)
     */
    protected function listChromeProcessCommandLines(): array
    {
        $cmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process '
            . '| Where-Object { $_.Name -eq \'chrome.exe\' } '
            . '| ForEach-Object { $_.ProcessId.ToString() + \'|\' + $_.CommandLine }"';
        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        if ($exitCode !== 0) {
            return [];
        }
        $result = [];
        foreach ($output as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) === 2 && is_numeric(trim($parts[0]))) {
                $result[(int) trim($parts[0])] = $parts[1];
            }
        }
        return $result;
    }

    /**
     * Kills every Chrome process tree bound to the given profile dir; returns the killed PIDs.
     *
     * WHY MATCH THE FULL QUOTED ARGUMENT: a bare dir substring has a prefix trap - "...\lane1" is a
     * substring of "...\lane10", so lane 1 would kill lane 10's live Chrome. Every Chrome carries
     * exactly --user-data-dir="<absolute dir>", so matching that complete quoted form is precise.
     * The comparison is Windows-tolerant (lowercased, backslash-normalized) because CIM output and
     * our path may disagree on casing or slash direction. taskkill /T tears down the child
     * renderer/gpu/utility processes in one call.
     *
     * @param string             $absProfileDir   Absolute profile dir whose Chrome tree(s) to kill
     * @param array<int,string>  $chromeProcesses Snapshot from listChromeProcessCommandLines()
     * @return int[] PIDs that were sent a kill (empty if none matched)
     */
    protected function reapChromeProfileDir(string $absProfileDir, array $chromeProcesses): array
    {
        $normalize = static function (string $path): string {
            return strtolower(str_replace('/', '\\', $path));
        };
        $needle = '--user-data-dir="' . $normalize($absProfileDir) . '"';
        $killed = [];
        foreach ($chromeProcesses as $pid => $commandLine) {
            if (str_contains($normalize($commandLine), $needle)) {
                exec('taskkill /F /T /PID ' . (int) $pid . ' 2>nul');
                $killed[] = (int) $pid;
            }
        }
        return $killed;
    }

    /**
     * Recursively removes a directory and its contents, returning whether it is gone afterwards.
     *
     * WHY "rd /s /q" over a PHP RecursiveIterator: Chrome profile trees are deep and full of files
     * with Windows-locked/long paths; the native command handles those and is faster. The result is
     * verified with is_dir() rather than trusted from the exit code, because a still-open Chrome
     * handle can make the removal partial - the caller logs that case rather than assuming success.
     *
     * @param string $dir Absolute path of the directory to remove
     * @return bool TRUE if the directory no longer exists after the attempt
     */
    protected function removeDirectoryTree(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }
        exec('rd /s /q "' . $dir . '" 2>nul');
        clearstatcache(true, $dir);
        return ! is_dir($dir);
    }
}