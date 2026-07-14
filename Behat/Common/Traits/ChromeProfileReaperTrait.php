<?php
namespace axenox\BDT\Behat\Common\Traits;

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
     * Extracts the value of the --user-data-dir switch from a Chrome command line.
     *
     * WHY THIS EXISTS: matching the profile dir with a plain substring search was unreliable in two
     * ways. (1) Our launch command writes --user-data-dir="<dir>" with quotes, but Chrome re-serializes
     * the switch for its own renderer/gpu/utility children WITHOUT quotes when the path has no spaces -
     * so a quoted-only needle matched the browser process and never its children. That was invisible
     * while the browser was alive (taskkill /T took the tree down), but any child orphaned by a crashed
     * or separately-killed parent could never be matched again and lingered forever. (2) A substring
     * match has the prefix trap (...\lane1 is a substring of ...\lane10). Parsing the value out and
     * comparing it for EQUALITY removes both problems by construction.
     *
     * @param string $commandLine Full command line of a chrome.exe process
     * @return string|null Normalized (lowercase, backslashes, no trailing separator) dir, or NULL if the switch is absent
     */
    protected function extractUserDataDir(string $commandLine): ?string
    {
        $prefix = '--user-data-dir=';
        $pos = strpos($commandLine, $prefix);
        if ($pos === false) {
            return null;
        }
        $value = substr($commandLine, $pos + strlen($prefix));
        if (str_starts_with($value, '"')) {
            $end = strpos($value, '"', 1);
            $value = $end === false ? substr($value, 1) : substr($value, 1, $end - 1);
        } else {
            $value = substr($value, 0, strcspn($value, " \t"));
        }
        return $this->normalizeWindowsPath($value);
    }

    /**
     * Normalizes a Windows path for comparison between CIM output and our own path strings.
     *
     * WHY: CIM and PHP may disagree on casing and slash direction, and a trailing separator would
     * break an otherwise-correct equality check. Every path comparison in this trait goes through
     * here so the two sides can never drift.
     *
     * @param string $path Any absolute or relative Windows path
     * @return string Lowercased, backslash-separated, without a trailing separator
     */
    protected function normalizeWindowsPath(string $path): string
    {
        return rtrim(strtolower(str_replace('/', '\\', trim($path))), '\\');
    }

    /**
     * Kills every Chrome process tree bound to the given profile dir; returns the killed PIDs.
     *
     * WHY EQUALITY ON THE PARSED VALUE (not a substring of the command line): see extractUserDataDir().
     * This now matches Chrome's own children as well as the browser process, so an orphaned renderer
     * whose parent already died is still reaped. taskkill /T additionally tears down any tree that is
     * still intact, so a live browser and its children are killed in one call.
     *
     * @param string             $absProfileDir   Absolute profile dir whose Chrome tree(s) to kill
     * @param array<int,string>  $chromeProcesses Snapshot from listChromeProcessCommandLines()
     * @return int[] PIDs that were sent a kill (empty if none matched)
     */
    protected function reapChromeProfileDir(string $absProfileDir, array $chromeProcesses): array
    {
        $target = $this->normalizeWindowsPath($absProfileDir);
        $killed = [];
        foreach ($chromeProcesses as $pid => $commandLine) {
            if ($this->extractUserDataDir($commandLine) === $target) {
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

    /**
     * Kills Chromes bound to stale/vanished profile dirs under our profiles root and removes those dirs.
     *
     * WHY THIS EXISTS: the per-run cleanup in the coordinator's finally block only runs if the
     * coordinator survives. A hard kill (IIS app-pool recycle, scheduler budget, fatal error) skips it,
     * and because profile dirs are run-scoped the NEXT run does not know the previous run's dir names -
     * so nothing ever reclaims them. Chrome trees and profile dirs then accumulate without bound. This
     * sweep is age-based instead of identity-based, so it reclaims artefacts of runs it knows nothing
     * about. It is meant to be called at the START of a run, before any lane profile is created.
     *
     * WHY IT ALSO KILLS CHROMES WHOSE DIR NO LONGER EXISTS: a partially-removed profile leaves the
     * browser alive with a user-data-dir that is gone from disk; such a process can never be matched by
     * dir age again and would survive forever.
     *
     * WHY AGE-BASED AND NOT "not my run_uid": a concurrent run's freshly created profile must never be
     * reaped. The age threshold must comfortably exceed the longest expected run duration.
     *
     * WHY IT NEVER THROWS: callers invoke it as a housekeeping step; a leftover browser must never be
     * the reason a run refuses to start. Failures are returned as log lines for the caller to record.
     *
     * @param string $profilesRoot   Absolute path of the chrome_profiles root
     * @param int    $maxAgeSeconds  Profiles untouched for longer than this are considered abandoned
     * @return string[] Human-readable log lines describing what was reaped
     */
    protected function reapStaleChromeProfiles(string $profilesRoot, int $maxAgeSeconds): array
    {
        $lines = [];
        try {
            $root = $this->normalizeWindowsPath($profilesRoot);
            if (! is_dir($profilesRoot)) {
                return $lines;
            }
            $now = time();

            // Decide per directory whether it is abandoned. mtime (not creation time) is used so a
            // long-running but still-active profile is never reaped.
            $stale = [];
            foreach (glob($profilesRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $mtime = @filemtime($dir);
                if ($mtime !== false && ($now - $mtime) > $maxAgeSeconds) {
                    $stale[$this->normalizeWindowsPath($dir)] = $dir;
                }
            }

            // Kill every Chrome whose profile lives under our root and is either stale or already gone
            // from disk. Chromes of a live concurrent run are untouched: their dir exists and is fresh.
            $killedAny = false;
            foreach ($this->listChromeProcessCommandLines() as $pid => $commandLine) {
                $dir = $this->extractUserDataDir($commandLine);
                if ($dir === null || ! str_starts_with($dir, $root . '\\')) {
                    continue;
                }
                if (isset($stale[$dir]) || ! is_dir($dir)) {
                    exec('taskkill /F /T /PID ' . (int) $pid . ' 2>nul');
                    $lines[] = 'Reaped abandoned Chrome PID ' . (int) $pid . ' bound to ' . $dir;
                    $killedAny = true;
                }
            }

            // Chrome releases its profile handles asynchronously after taskkill returns; removing the
            // dirs immediately would race those handles and leave half-deleted trees behind.
            if ($killedAny) {
                usleep(1_000_000);
            }

            foreach ($stale as $dir) {
                if ($this->removeDirectoryTree($dir)) {
                    $lines[] = 'Removed abandoned profile dir ' . $dir;
                } else {
                    $lines[] = 'WARNING: could not remove abandoned profile dir ' . $dir . ' - a Chrome handle may still be open';
                }
            }
        } catch (\Throwable $e) {
            $lines[] = 'WARNING: stale profile sweep failed: ' . $e->getMessage();
        }
        return $lines;
    }

    /**
     * Kills Chromes and removes profile dirs that belong to runs which are provably no longer active.
     *
     * WHY THIS EXISTS ALONGSIDE reapStaleChromeProfiles(): the age-based sweep only reclaims artefacts
     * that have been untouched for many hours, because age is the only safety signal it has - a fresh
     * profile might belong to a run that is still executing. That leaves the common case uncovered: a
     * fleet that crashed twenty minutes ago has fresh profile dirs, so the age sweep skips it and its
     * Chrome trees keep burning CPU and memory until the threshold expires. But a lane profile dir is
     * named "<run_uid>_laneN", so it CARRIES the identity of its owning run. If that run is not the
     * current one and is not still active, every Chrome bound to it is an orphan regardless of age.
     * Identity is the correct criterion here; age is only the fallback for artefacts we cannot identify.
     *
     * WHY THE CALLER DECIDES WHAT "ACTIVE" MEANS: this trait must not know about the run data source.
     * The caller passes the set of run UIDs it considers live (its own, plus any other coordinator run
     * that has not finished yet), and everything else under the root is fair game.
     *
     * WHY NON-LANE DIRS ARE NEVER TOUCHED HERE: the interactive RunTest action uses
     * "interactive<port>" profile dirs, which carry no run UID and may belong to a tester working right
     * now. Those stay the exclusive business of the age-based sweep.
     *
     * WHY IT NEVER THROWS: housekeeping must never be the reason a run refuses to start.
     *
     * @param string   $profilesRoot Absolute path of the chrome_profiles root
     * @param string[] $activeRunUids Run UIDs whose lane profiles must be left alone (including the current run)
     * @return string[] Human-readable log lines describing what was reaped
     */
    protected function reapProfilesOfInactiveRuns(string $profilesRoot, array $activeRunUids): array
    {
        $lines = [];
        try {
            if (! is_dir($profilesRoot)) {
                return $lines;
            }
            $active = [];
            foreach ($activeRunUids as $uid) {
                $active[strtolower($uid)] = true;
            }

            // Collect the lane profile dirs whose owning run is not active any more. The dir name is
            // "<run_uid>_lane<N>", so the UID is everything before the last "_lane".
            $orphanDirs = [];
            foreach (glob($profilesRoot . DIRECTORY_SEPARATOR . '*_lane*', GLOB_ONLYDIR) ?: [] as $dir) {
                $name = basename($dir);
                $pos = strrpos($name, '_lane');
                if ($pos === false || $pos === 0) {
                    continue;
                }
                $ownerUid = strtolower(substr($name, 0, $pos));
                if (! isset($active[$ownerUid])) {
                    $orphanDirs[$this->normalizeWindowsPath($dir)] = $dir;
                }
            }
            if ($orphanDirs === []) {
                return $lines;
            }

            $killedAny = false;
            foreach ($this->listChromeProcessCommandLines() as $pid => $commandLine) {
                $dir = $this->extractUserDataDir($commandLine);
                if ($dir !== null && isset($orphanDirs[$dir])) {
                    exec('taskkill /F /T /PID ' . (int) $pid . ' 2>nul');
                    $lines[] = 'Reaped orphan Chrome PID ' . (int) $pid . ' of inactive run, profile ' . $dir;
                    $killedAny = true;
                }
            }

            // Chrome releases its profile file handles asynchronously after taskkill returns; deleting
            // immediately would race those handles and leave half-removed trees behind.
            if ($killedAny) {
                usleep(1_000_000);
            }

            foreach ($orphanDirs as $dir) {
                if ($this->removeDirectoryTree($dir)) {
                    $lines[] = 'Removed profile dir of inactive run: ' . $dir;
                } else {
                    $lines[] = 'WARNING: could not fully remove profile dir ' . $dir
                        . ' - a Chrome file handle may still be open';
                }
            }
        } catch (\Throwable $e) {
            $lines[] = 'WARNING: inactive-run profile sweep failed: ' . $e->getMessage();
        }
        return $lines;
    }
}