<?php
namespace axenox\BDT\Behat\Common;

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
}