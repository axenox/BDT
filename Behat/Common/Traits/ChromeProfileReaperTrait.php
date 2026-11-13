<?php
namespace axenox\BDT\Behat\Common\Traits;

use axenox\BDT\Behat\BrowserManagers\ProcessHosts\ProcessHostInterface;
use axenox\BDT\Behat\BrowserManagers\ProcessHosts\ProcessHostFactory;

/**
 * Shared reaper for orphaned Chrome process trees and their profile directories.
 *
 * WHY A TRAIT: both RunParallel (scheduled fleet, lane<N> profiles) and RunTest (interactive
 * single run, interactive<port> profile) launch Chrome DETACHED. A worker/child
 * that is hard-killed on timeout leaves its Chrome tree alive, still holding a locked profile
 * dir. Both actions therefore need the identical end-of-run cleanup: find every Chrome bound to a
 * given profile dir, kill the tree, then remove the dir. Keeping one implementation guarantees the
 * two paths can never drift in HOW they match a Chrome to its profile (the lane1/lane10 prefix trap
 * is easy to reintroduce) or how they remove a locked profile tree.
 *
 * The process host owns operating-system-specific process and directory operations. None of these
 * cleanup methods throw - a leftover browser is a nuisance to log, never a reason to abort an
 * otherwise-finished run - so callers invoke them from finally blocks.
 *
 * This trait has NO dependencies on the using class (unlike PortProbingTrait it needs no
 * getWorkbench()); callers own their own logging.
 */
trait ChromeProfileReaperTrait
{
    private ?ProcessHostInterface $chromeProfileProcessHost = null;

    /**
     * Shares one lazily selected process host across launch, discovery, and profile cleanup.
     */
    protected function getProcessHost(): ProcessHostInterface
    {
        if ($this->chromeProfileProcessHost === null) {
            $this->chromeProfileProcessHost = ProcessHostFactory::createForCurrentOs();
        }
        return $this->chromeProfileProcessHost;
    }

    /**
     * Supplies the configured browser executable from which the image name to enumerate is derived.
     *
     * WHY ABSTRACT: only the using class knows where its executable comes from (manager config,
     * app config, CLI argument); NULL means "not known" and yields the host's default image name.
     */
    abstract protected function getBrowserExecutable(): ?string;

    /**
     * Returns the process image name used to enumerate this class's browser processes.
     *
     * WHY ONE IMPLEMENTATION HERE: three classes each derived the name with their own copy of the
     * rule, which was Windows-only and could not be fixed for Linux in one place. The OS-specific
     * derivation now lives in the process host; this method only connects it to the executable.
     */
    protected function getBrowserImageName(): string
    {
        return $this->getProcessHost()->getImageNameForExecutable($this->getBrowserExecutable());
    }

    /**
     * Returns a single process snapshot so multiple profile sweeps scan the browser image only once.
     *
     * WHY A THIN WRAPPER: enumeration is OS-specific, but all reaper paths need the same command-line
     * snapshot and the same NULL-means-failure contract.
     *
     * @return array<int,string>|null PID => full command line, or NULL if enumeration failed
     */
    protected function listChromeProcessCommandLines(): ?array
    {
        return $this->getProcessHost()->listCommandLines($this->getBrowserImageName());
    }

    /**
     * Extracts the value of the --user-data-dir switch from a Chrome command line.
     *
     * WHY THIS EXISTS: matching the profile dir with a plain substring search was unreliable in two
     * ways. (1) Our launch command writes --user-data-dir="<dir>" with quotes, but Chrome re-serializes
     * the switch for its own renderer/gpu/utility children WITHOUT quotes when the path has no spaces -
     * so a quoted-only needle matched the browser process and never its children. That was invisible
     * while the browser was alive (tree cleanup took the children down), but any child orphaned by a crashed
     * or separately-killed parent could never be matched again and lingered forever. (2) A substring
     * match has the prefix trap (...\lane1 is a substring of ...\lane10). Parsing the value out and
     * comparing it for EQUALITY removes both problems by construction.
     *
     * @param string $commandLine Full command line of a chrome.exe process
     * @return string|null Normalized path, or NULL if the switch is absent
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
        return $this->normalizeProcessPath($value);
    }

    /**
     * Normalizes a process path for comparison with the configured profile directory.
     *
     * WHY: process command lines and PHP may disagree on separator style, and a trailing separator
     * would break an otherwise-correct equality check. The process host owns OS-specific normalization.
     *
     * @param string $path Any absolute or relative process path
     * @return string Normalized path without a trailing separator
     */
    protected function normalizeProcessPath(string $path): string
    {
        return $this->getProcessHost()->normalizePath($path);
    }

    /**
     * Returns the "\<profiles-root-folder>\" marker used to recognize any BDT Chrome profile path,
     * regardless of which installation path it was launched from.
     *
     * WHY THIS EXISTS: the absolute profiles root contains the deployment release folder
     * (…\releases\<version>\data\…), and "data" is a junction into the SHARED data tree. So the same
     * physical profile is reachable under a different absolute path after every deployment. Matching
     * on the absolute root therefore made every Chrome started by a PREVIOUS release invisible to all
     * sweeps forever, while the shared profile dir itself was still deleted by the next run - the exact
     * fingerprint of "many un-killable chrome.exe, but no profile dirs on disk". The marker is derived
     * from the caller's own root (never hardcoded), so it stays correct if the folder is ever renamed.
     *
     * @param string $profilesRoot Absolute path of the chrome_profiles root
     * @return string|null Normalized marker like "<separator>chrome_profiles<separator>", or NULL if unusable
     */
    protected function profilesRootMarker(string $profilesRoot): ?string
    {
        $separator = $this->getProcessHost()->getDirectorySeparator();
        $root = $this->normalizeProcessPath($profilesRoot);
        $sepPos = strrpos($root, $separator);
        $rootName = $sepPos === false ? $root : substr($root, $sepPos + 1);
        if ($rootName === '') {
            return null;
        }
        return $separator . $rootName . $separator;
    }

    /**
     * Returns the release-independent identity of a Chrome profile path: everything from the
     * profiles-root folder name onwards, e.g. "chrome_profiles\a1b2c3_lane4".
     *
     * WHY AN IDENTITY INSTEAD OF THE ABSOLUTE PATH: see profilesRootMarker(). Comparing identities
     * lets a sweep started from release N recognize and reap a Chrome launched by release N-1, which
     * absolute-path equality could never do.
     *
     * WHY IT IS STILL SAFE: the identity always carries the profile dir NAME, which is either
     * "<run_uid>_laneN" (globally unique per run) or "interactive<port>" (unique per bound port), so a
     * genuinely foreign browser cannot collide with it. A path that does not contain the marker at all
     * returns NULL and is treated as foreign - the safe default of "never kill what we cannot claim".
     *
     * @param string $path         Any profile dir path, ours or from a foreign command line
     * @param string $profilesRoot Absolute path of the chrome_profiles root the caller works with
     * @return string|null Normalized identity, or NULL if the path is not under a BDT profiles root
     */
    protected function profileIdentity(string $path, string $profilesRoot): ?string
    {
        $marker = $this->profilesRootMarker($profilesRoot);
        if ($marker === null) {
            return null;
        }
        $normalized = $this->normalizeProcessPath($path);
        $pos = strrpos($normalized, $marker);
        if ($pos === false) {
            return null;
        }
        // +1 drops the leading separator so the identity starts at the root folder name itself.
        return substr($normalized, $pos + 1);
    }

    /**
     * Kills every Chrome process tree bound to the given profile dir; returns the killed PIDs.
     *
     * WHY IDENTITY AND NOT ABSOLUTE-PATH EQUALITY: the absolute path embeds the deployment release
     * folder, so a Chrome launched before the last deployment carries a different string for the very
     * same physical profile and was never matched. profileIdentity() compares the release-independent
     * tail instead, so leftovers survive neither a deployment nor a coordinator restart.
     *
     * This matches Chrome's own children as well as the browser process, so an orphaned renderer whose
     * parent already died is still reaped. The host additionally tears down any tree that is still
     * intact, so a live browser and its children are killed in one call.
     *
     * @param string             $absProfileDir   Absolute profile dir whose Chrome tree(s) to kill
     * @param array<int,string>  $chromeProcesses Snapshot from listChromeProcessCommandLines()
     * @return int[] PIDs that were sent a kill (empty if none matched)
     */
    protected function reapChromeProfileDir(string $absProfileDir, array $chromeProcesses): array
    {
        // The profiles root is the parent of every lane/interactive profile dir, so it can be derived
        // here instead of being threaded through every call site.
        $profilesRoot = dirname($absProfileDir);
        $targetIdentity = $this->profileIdentity($absProfileDir, $profilesRoot);
        if ($targetIdentity === null) {
            // Not a path under a profiles root - claiming anything for it would be a guess.
            return [];
        }

        $killed = [];
        foreach ($chromeProcesses as $pid => $commandLine) {
            $dir = $this->extractUserDataDir($commandLine);
            if ($dir === null) {
                continue;
            }
            if ($this->profileIdentity($dir, $profilesRoot) === $targetIdentity) {
                $this->getProcessHost()->killTree((int) $pid);
                $killed[] = (int) $pid;
            }
        }
        return $killed;
    }

    /**
     * Recursively removes a directory and its contents through the process host.
     *
     * WHY DELEGATE: native deletion is required for locked browser files, and the host preserves the
     * platform-specific removal behavior while this trait keeps the cleanup flow shared.
     *
     * @param string $dir Absolute path of the directory to remove
     * @return bool TRUE if the directory no longer exists after the attempt
     */
    protected function removeDirectoryTree(string $dir): bool
    {
        return $this->getProcessHost()->removeDirectoryTree($dir);
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
            if (! is_dir($profilesRoot)) {
                return $lines;
            }
            $now = time();

            // Decide per directory whether it is abandoned. mtime (not creation time) is used so a
            // long-running but still-active profile is never reaped. Keyed by identity, so a process
            // launched from another release path still resolves to the same entry.
            $stale = [];
            foreach (glob($profilesRoot . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $dir) {
                $mtime = @filemtime($dir);
                if ($mtime === false || ($now - $mtime) <= $maxAgeSeconds) {
                    continue;
                }
                $identity = $this->profileIdentity($dir, $profilesRoot);
                if ($identity !== null) {
                    $stale[$identity] = $dir;
                }
            }

            // WHY THE WHOLE SWEEP IS ABANDONED WHEN THE PROCESS LIST IS UNAVAILABLE: without it we
            // cannot know which Chromes are still bound to these dirs. Deleting a profile dir out from
            // under a LIVE browser is what creates a process nothing can attribute afterwards, so
            // proceeding blind would manufacture the exact orphan this sweep exists to remove. Reporting
            // and doing nothing is the only honest option.
            $chromeProcesses = $this->listChromeProcessCommandLines();
            if ($chromeProcesses === null) {
                $lines[] = 'WARNING: could not enumerate chrome.exe processes - the stale profile sweep was '
                    . 'SKIPPED (not completed). ' . count($stale) . ' stale profile dir(s) were left in place '
                    . 'because removing them without killing their browsers first would orphan those processes.';
                return $lines;
            }

            // Kill every Chrome whose profile is one of ours and is either stale or already gone from
            // disk. Chromes of a live concurrent run are untouched: their dir exists and is fresh.
            $killedAny = false;
            foreach ($chromeProcesses as $pid => $commandLine) {
                $dir = $this->extractUserDataDir($commandLine);
                if ($dir === null) {
                    continue;
                }
                $identity = $this->profileIdentity($dir, $profilesRoot);
                if ($identity === null) {
                    continue;
                }
                if (isset($stale[$identity]) || ! is_dir($dir)) {
                    $this->getProcessHost()->killTree((int) $pid);
                    $lines[] = 'Reaped abandoned Chrome PID ' . (int) $pid . ' bound to ' . $dir;
                    $killedAny = true;
                }
            }

            // Chrome releases its profile handles asynchronously after tree cleanup returns; removing the
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
                    // Keyed by release-independent identity, so a Chrome launched by a previous
                    // deployment still matches the very same physical lane profile.
                    $identity = $this->profileIdentity($dir, $profilesRoot);
                    if ($identity !== null) {
                        $orphanDirs[$identity] = $dir;
                    }
                }
            }
            if ($orphanDirs === []) {
                return $lines;
            }

            // Same reasoning as in reapStaleChromeProfiles(): with no process list we cannot tell which
            // of these dirs still has a live browser attached, and deleting one that does would create
            // an unattributable orphan. Report and leave everything untouched.
            $chromeProcesses = $this->listChromeProcessCommandLines();
            if ($chromeProcesses === null) {
                $lines[] = 'WARNING: could not enumerate chrome.exe processes - the inactive-run profile sweep was '
                    . 'SKIPPED (not completed). ' . count($orphanDirs) . ' orphan lane profile dir(s) were left in place.';
                return $lines;
            }

            $killedAny = false;
            foreach ($chromeProcesses as $pid => $commandLine) {
                $dir = $this->extractUserDataDir($commandLine);
                $identity = $dir === null ? null : $this->profileIdentity($dir, $profilesRoot);
                if ($identity !== null && isset($orphanDirs[$identity])) {
                    $this->getProcessHost()->killTree((int) $pid);
                    $lines[] = 'Reaped orphan Chrome PID ' . (int) $pid . ' of inactive run, profile ' . $dir;
                    $killedAny = true;
                }
            }

            // Chrome releases its profile file handles asynchronously after tree cleanup returns; deleting
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