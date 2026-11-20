<?php
namespace axenox\BDT\Behat\Common;

use axenox\BDT\BDTApp;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * App-config keys of axenox.BDT that more than one class reads, and the resolvers whose meaning must be
 * identical for every reader.
 *
 * WHY IT EXISTS: the TEST_USER.* keys were spelled out in UI5Browser, TestDataReaper and RunTest, and
 * PARALLEL.WORKER_TIMEOUT_SECONDS was read by RunTest and RunParallel with two different meanings - RunParallel
 * treated 0 as "disabled" while RunTest's `?:` turned the same 0 into 1800 seconds, and the 1800 default was
 * written down twice. An operator who set 0 to disable the ceiling got exactly that for the fleet and a
 * 30-minute kill for every interactive run. One key, one default and one resolver make that impossible.
 */
final class BdtConfig
{
    public const TEST_USER_USERNAME = 'TEST_USER.USERNAME';
    public const TEST_USER_PASSWORD = 'TEST_USER.PASSWORD';
    public const TEST_USER_FIRST_NAME = 'TEST_USER.FIRST_NAME';
    public const TEST_USER_LAST_NAME = 'TEST_USER.LAST_NAME';

    /**
     * Size of the pool of interactive test-user slots (see LaneNaming::interactiveLaneId()).
     */
    public const TEST_USER_INTERACTIVE_SLOTS = 'TEST_USER.INTERACTIVE_SLOTS';

    /**
     * Per-process wall-clock limit in seconds, read by both RunTest and RunParallel.
     */
    public const PARALLEL_WORKER_TIMEOUT_SECONDS = 'PARALLEL.WORKER_TIMEOUT_SECONDS';

    /**
     * Wall-clock ceiling used when PARALLEL.WORKER_TIMEOUT_SECONDS is not configured at all.
     *
     * WHY 1800: a Behat process needs far longer than any generic CLI default (60 s), and half an hour is a
     * realistic upper bound for one feature (fleet) or one interactive selection. It is a fallback only;
     * the shipped config sets the key explicitly.
     */
    public const DEFAULT_WORKER_TIMEOUT_SECONDS = 1800;

    /**
     * Resolves the wall-clock timeout of one Behat process - the ONE meaning of PARALLEL.WORKER_TIMEOUT_SECONDS.
     *
     * WHY ONE RESOLVER: see the class docblock - two readers had drifted into opposite meanings of 0.
     *
     * Meaning: key absent -> DEFAULT_WORKER_TIMEOUT_SECONDS; a positive value -> that many seconds; 0 or a
     * negative value -> disabled (NULL), so the process is bounded only by whatever else bounds it (the idle
     * timeout of a fleet lane, the launcher's lifetime). Symfony Process accepts NULL as "no timeout".
     *
     * TRADE-OFF OF DISABLING: RunParallel lanes stay protected by PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS, but
     * RunTest has no idle timeout. With 0 a hung interactive Behat process keeps its port, its test-user slot
     * and its open run row until somebody kills it by hand.
     *
     * @param WorkbenchInterface $workbench Workbench whose BDT app config is read
     * @return float|null Seconds, or NULL when the ceiling is disabled
     */
    public static function resolveWorkerTimeout(WorkbenchInterface $workbench): ?float
    {
        $cfg = $workbench->getApp(BDTApp::APP_ALIAS)->getConfig();
        // Distinguish "not configured" (fall back to the default) from an explicit 0 (disable).
        if (! $cfg->hasOption(self::PARALLEL_WORKER_TIMEOUT_SECONDS)) {
            return (float) self::DEFAULT_WORKER_TIMEOUT_SECONDS;
        }
        $seconds = (float) $cfg->getOption(self::PARALLEL_WORKER_TIMEOUT_SECONDS);
        return $seconds > 0 ? $seconds : null;
    }
}
