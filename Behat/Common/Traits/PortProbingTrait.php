<?php
namespace axenox\BDT\Behat\Common\Traits;

use exface\Core\Exceptions\RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Shared port-band resolution and free-port probing for BDT coordinator actions.
 *
 * WHY A TRAIT: both RunParallel (scheduled fleet) and RunTest (interactive single run)
 * must allocate collision-free Chrome remote-debugging ports on a shared server. Keeping
 * one implementation guarantees the two paths can never drift apart in how they read the
 * per-project override file or how they probe for a free port. The trait expects the using
 * class to provide getWorkbench() (all AbstractAction subclasses do).
 *
 * Collision-safety is LAYERED rather than reserved up front:
 *   1. Within one coordinator run, the $held list guarantees two lanes never receive the
 *      same port.
 *   2. Across runs/projects, separate port bands make overlap unlikely, and the probe skips
 *      any already-bound port (open socket = busy, refused = free).
 *   3. The residual probe->bind race (a port that turns busy after we picked it) is NOT
 *      silent: ChromeManager refuses to kill a live foreign Chrome on its port and fails
 *      loudly instead, so the affected run is recorded as a failure rather than sabotaging
 *      another run's browser.
 */
trait PortProbingTrait
{
    /**
     * Returns the per-project orchestration override filename.
     *
     * WHY A METHOD INSTEAD OF A CONSTANT: trait constants require PHP 8.2+; a method keeps
     * the trait compatible with every PHP 8.x runtime in use. It also keeps the filename in
     * exactly one place for both coordinator actions. The file is SEPARATE from behat.yml on
     * purpose: behat.yml is worker config, the bands are coordinator config - mixing them
     * would let a worker accidentally read or break the band.
     */
    private function getPortBandOverrideFileName(): string
    {
        return 'bdt_parallel.yml';
    }

    /**
     * Resolves the [start, end] port band for one execution path (scheduled or interactive).
     *
     * WHY PARAMETERIZED: the override file may carry SEVERAL band keys side by side (e.g.
     * "port_band" for the scheduled fleet and "port_band_interactive" for tester runs), each
     * falling back to its own app-config key. Resolution order per key:
     *   1. The key in bdt_parallel.yml next to the base behat.yml, when present.
     *   2. Otherwise the given app-config option (logged explicitly, never a silent guess).
     * A key that is PRESENT but malformed fails loudly - a typo must never silently fall back
     * to a default band and reintroduce the very port collision the band exists to prevent.
     * A key that is simply ABSENT is legitimate (the project may only override the other
     * path's band) and falls back to app config.
     *
     * The band is only a SEARCH WINDOW - real collision-safety comes from the runtime
     * free-port probe (allocateFreePort), so two projects with overlapping bands still never
     * clash on a port that is actually in use.
     *
     * @param string $behatConfig     Absolute path to the base behat.yml (locates the override file)
     * @param string $overrideYamlKey Key to read from bdt_parallel.yml (e.g. 'port_band_interactive')
     * @param string $appConfigKey    App-config option used as fallback (e.g. 'PARALLEL.PORT_BAND_INTERACTIVE')
     * @return int[] [startPort, endPort]
     */
    private function resolvePortBand(string $behatConfig, string $overrideYamlKey, string $appConfigKey): array
    {
        $overrideFile = $this->getPortBandOverrideFileName();
        $overridePath = dirname($behatConfig) . DIRECTORY_SEPARATOR . $overrideFile;
        $band = null;
        if (is_file($overridePath)) {
            $parsed = Yaml::parseFile($overridePath);
            $band = $parsed[$overrideYamlKey] ?? null;
            if ($band !== null && (! is_string($band) || ! preg_match('/^\d+-\d+$/', $band))) {
                throw new RuntimeException(
                    'Malformed ' . $overrideFile . ': "' . $overrideYamlKey
                    . '" must be like "9301-9400", got ' . var_export($band, true)
                );
            }
        }
        if ($band === null) {
            // Explicit log line rather than a silent guess, so the source of the band is traceable.
            $this->getWorkbench()->getLogger()->info(
                'No "' . $overrideYamlKey . '" override in ' . $overrideFile . '; using app-config ' . $appConfigKey
            );
            $band = (string) $this->getWorkbench()->getApp('axenox.BDT')->getConfig()->getOption($appConfigKey);
        }
        [$start, $end] = array_map('intval', explode('-', $band));
        if ($end < $start) {
            throw new RuntimeException('Invalid port band ' . $band . ' for ' . $overrideYamlKey);
        }
        return [$start, $end];
    }

    /**
     * Allocates the first currently-free port in the band via a probe, skipping ports already
     * handed to other lanes in THIS run.
     *
     * WHY PROBE INSTEAD OF RESERVE: the worker - not the coordinator - owns its Chrome
     * lifecycle, so there is no post-launch port the coordinator could "verify and reallocate"
     * without either killing/relaunching the worker or fighting it for that Chrome. The probe
     * plus band separation makes collisions rare; the residual race is caught loudly by
     * ChromeManager's foreign-process guard instead of silently killing someone else's Chrome.
     *
     * @param int   $start First port of the band (inclusive)
     * @param int   $end   Last port of the band (inclusive)
     * @param int[] $held  Ports already assigned to other lanes in this run
     */
    private function allocateFreePort(int $start, int $end, array $held = []): int
    {
        for ($port = $start; $port <= $end; $port++) {
            if (in_array($port, $held, true)) {
                continue;
            }
            if (! $this->isPortBound($port)) {
                return $port;
            }
        }
        throw new RuntimeException('Port band ' . $start . '-' . $end . ' exhausted - no free port for the next worker');
    }

    /**
     * Returns TRUE if something is listening on the port (successful socket connect = busy).
     *
     * WHY A SHORT TIMEOUT: 0.2 s per probe keeps a full band scan fast while still being far
     * above localhost connect latency, so the busy/free verdict stays reliable.
     */
    private function isPortBound(int $port): bool
    {
        $sock = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($sock !== false) {
            fclose($sock);
            return true;
        }
        return false;
    }

    /**
     * Atomically reserves the first free port in the band ACROSS PROCESSES and returns a
     * reservation the caller MUST release when the run ends.
     *
     * WHY THIS EXISTS ALONGSIDE allocateFreePort: allocateFreePort only deconflicts lanes within
     * ONE coordinator process (the in-memory $held list) and otherwise relies on isPortBound().
     * That is unsafe for INDEPENDENT concurrent runs - e.g. several interactive RunTest processes
     * started before any of them launched Chrome. There is a multi-second gap between picking a
     * port and Chrome actually binding it (Behat init, container build, ChromeManager launch), so
     * every racing process sees the same port as free and all pick it. In-memory $held cannot help
     * because the racers are separate OS processes. A cross-process advisory lock closes that gap:
     * a port is only handed out once, and stays reserved from selection until the caller releases
     * it - well past the moment Chrome binds it.
     *
     * WHY flock AND NOT MERE FILE EXISTENCE: an flock is released automatically when its owning
     * process dies, so a lock file left behind by a crashed run never permanently blocks a port -
     * the next run simply re-locks the stale file. Testing existence instead would strand ports on
     * every crash.
     *
     * The caller keeps ['handle'] OPEN for the life of the run; closing it (or process exit) drops
     * the lock. Release via releaseReservedPort().
     *
     * @param int   $start First port of the band (inclusive)
     * @param int   $end   Last port of the band (inclusive)
     * @param int[] $held  Ports already reserved earlier in THIS process (same-process fast skip)
     * @return array{port:int,handle:resource,lockPath:string}
     * @throws RuntimeException if every port in the band is bound or reserved by another run
     */
    private function reserveFreePort(int $start, int $end, array $held = []): array
    {
        $lockDir = $this->getPortLockDir();
        for ($port = $start; $port <= $end; $port++) {
            if (in_array($port, $held, true)) {
                continue;
            }
            $lockPath = $lockDir . DIRECTORY_SEPARATOR . 'port_' . $port . '.lock';
            // 'c' opens for writing and creates the file if missing WITHOUT truncating it: the file
            // is only a cross-process mutex, its bytes are diagnostic (the owner PID).
            $handle = @fopen($lockPath, 'c');
            if ($handle === false) {
                // Cannot even open the lock file (permissions/disk) - never claim the port blindly.
                continue;
            }
            // LOCK_EX|LOCK_NB: take the lock only if no LIVE process holds it; skip immediately
            // otherwise instead of blocking the whole band scan on one contended port.
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }
            // Only after winning the reservation do we ask the OS whether the port is actually free:
            // it could be bound by something outside BDT, or by a run still inside its own
            // reservation-to-bind window. If busy, release and keep scanning.
            if ($this->isPortBound($port)) {
                flock($handle, LOCK_UN);
                fclose($handle);
                continue;
            }
            // Stamp a human-readable, log-style line so a leftover lock file tells you WHO reserved a
            // port and WHEN - handy when a port looks stuck. Purely diagnostic: the flock above, not
            // this text, is what actually enforces mutual exclusion. A write failure must never void an
            // already-won reservation, hence the silenced calls.
            @ftruncate($handle, 0);
            @fwrite($handle, sprintf(
                'pid=%d port=%d reserved_at=%s%s',
                getmypid(), $port, date('Y-m-d H:i:s'), PHP_EOL
            ));
            @fflush($handle);
            // IMPORTANT: return with $handle still OPEN - closing it would drop the flock and
            // reopen the very race this method exists to prevent.
            return ['port' => $port, 'handle' => $handle, 'lockPath' => $lockPath];
        }
        throw new RuntimeException(
            'Port band ' . $start . '-' . $end . ' exhausted - every port is bound or reserved by another run'
        );
    }

    /**
     * Releases a reservation returned by reserveFreePort() so the next run can claim the port.
     *
     * WHY UNLOCK-THEN-CLOSE AND NEVER UNLINK: dropping the flock and closing the handle is enough
     * to free the port for the next reserver. We deliberately do NOT delete the file: a concurrent
     * reserver may already hold it open, and unlinking a locked file out from under it is racy on
     * Windows. The stub files are bounded by the band size (one per port), so leaving them is far
     * cheaper than fighting that race. Safe to call with an already-released or malformed
     * reservation - it simply does nothing.
     */
    private function releaseReservedPort(array $reservation): void
    {
        $handle = $reservation['handle'] ?? null;
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Returns the directory holding the per-port lock files, creating it on first use.
     *
     * WHY A DEDICATED DIR UNDER THE INSTALLATION: the locks must live on a path shared by every
     * BDT run on this server (so the flocks actually contend) yet outside behat.yml/profile trees
     * that get regenerated per run. A stable installation-relative folder gives all coordinator
     * processes the same lock namespace without touching worker config.
     *
     * @throws RuntimeException if the directory cannot be created
     */
    private function getPortLockDir(): string
    {
        $dir = $this->getWorkbench()->getInstallationPath()
            . DIRECTORY_SEPARATOR . 'data'
            . DIRECTORY_SEPARATOR . 'axenox'
            . DIRECTORY_SEPARATOR . 'BDT'
            . DIRECTORY_SEPARATOR . 'portlocks';
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create port-lock directory: ' . $dir);
        }
        return $dir;
    }
}