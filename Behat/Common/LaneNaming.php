<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\Exceptions\RuntimeException;

/**
 * Builds and parses the lane identities of a run: "<run_uid>_laneN" (RunParallel lane N) and
 * "<run_uid>_interactiveN" (RunTest, test-user slot N).
 *
 * WHY ONE PLACE: the same names are produced and taken apart by several classes. RunParallel builds the lane_id
 * and the lane's Chrome profile dir from it, RunTest builds its own lane_id, UI5Browser derives the test
 * username from the trailing token and recognises test users by it, and ChromeProfileReaperTrait finds the
 * owning run of a lane profile dir by splitting at the token. They used to spell "_lane" independently - a
 * builder and a parser that disagree do not fail, they silently stop matching: a profile dir nobody reaps, a
 * test user nobody cleans up.
 */
final class LaneNaming
{
    /**
     * Token before the lane number in a RunParallel lane_id and in its profile dir name.
     */
    public const TOKEN_LANE = '_lane';

    /**
     * Token before the slot number in the lane_id of a RunTest run.
     */
    public const TOKEN_INTERACTIVE = '_interactive';

    /**
     * Builds the lane_id of RunParallel lane N: "<run_uid>_laneN". It is also the lane's profile dir name.
     *
     * WHY THE RUN UID IS PART OF IT: the profile dir must be unique per run, not reused across runs - the
     * scheduled fleet and interactive runs execute under different Windows accounts, and a profile created by
     * one account cannot be opened by another (DPAPI state, ProcessSingleton lock).
     *
     * @param string $runUid UID of the run owning the lane
     * @param int    $lane   Lane number, 1..N
     */
    public static function laneId(string $runUid, int $lane): string
    {
        return $runUid . self::TOKEN_LANE . $lane;
    }

    /**
     * Builds the lane_id of the single Behat process of a RunTest run: "<run_uid>_interactive<slot>".
     *
     * WHY A LANE_ID AT ALL: a Behat child that is given a run_uid runs in attach-mode, and DatabaseFormatter
     * refuses a run_uid without a lane_id on purpose (a lost lane_id would silently put concurrent runs on one
     * shared test-user row).
     *
     * WHY A RESERVED SLOT: the lane_id also names the test user. Concurrent RunTests (agents and testers) that
     * resolve to the same role would otherwise share one USER_AUTHENTICATOR row and collide on optimistic
     * locking. The slot is a number 1..N reserved across processes for the whole run
     * (TEST_USER.INTERACTIVE_SLOTS sets N), so it is unique among concurrent interactive runs and N caps the
     * number of distinct interactive test users - the same bound laneN gives the fleet.
     *
     * WHY NOT THE BROWSER PORT OR THE ENDPOINT URL: a slot must not depend on how the browser is attached. A
     * local port range is wide (it would allow hundreds of users), an attached browser has no port of its
     * own, and endpoint URLs mostly share one (443).
     *
     * @param string $runUid UID of the run
     * @param int    $slot   Test-user slot reserved for this run, 1..N
     * @throws RuntimeException if the slot is not >= 1
     */
    public static function interactiveLaneId(string $runUid, int $slot): string
    {
        if ($slot < 1) {
            throw new RuntimeException('Cannot build an interactive lane_id without a reserved slot, got ' . $slot . '.');
        }
        return $runUid . self::TOKEN_INTERACTIVE . $slot;
    }

    /**
     * Returns the glob pattern matching the lane profile dirs of one run, or of every run when no UID is given.
     *
     * WHY HERE: the glob is the reverse of laneId(); built anywhere else it is a second spelling of the token.
     *
     * @param string|null $runUid UID of the run, or NULL for all runs
     */
    public static function laneDirGlob(?string $runUid = null): string
    {
        return ($runUid ?? '*') . self::TOKEN_LANE . '*';
    }

    /**
     * Returns the UID of the run that owns a lane profile dir, or NULL if the name is not a lane dir.
     *
     * WHY THE LAST TOKEN: the UID is everything before the last "_lane", so a UID that happens to contain the
     * token itself still resolves to the right owner.
     *
     * @param string $dirName Base name of the profile dir
     */
    public static function ownerRunUidOfLaneDir(string $dirName): ?string
    {
        $pos = strrpos($dirName, self::TOKEN_LANE);
        if ($pos === false || $pos === 0) {
            return null;
        }
        return substr($dirName, 0, $pos);
    }

    /**
     * Derives the stable slot token ("_laneN" / "_interactiveN") from a lane_id.
     *
     * WHY ONLY THE TOKEN AND NOT THE WHOLE lane_id: the run_uid changes every run, so a username that embedded
     * it would create a brand new user row on every run and let test users accumulate without bound. Keeping
     * just the slot token makes the username identical across runs, so find-or-create reuses the same row and
     * the user set stays capped - at the worker count for the fleet, at TEST_USER.INTERACTIVE_SLOTS for
     * RunTest.
     *
     * WHY IT THROWS INSTEAD OF FALLING BACK: in an attach-mode run (lane_id present) a missing token means a
     * broken lane_id contract. Silently using the base username would put every run back on one shared row -
     * the exact collision the per-lane users exist to prevent.
     *
     * @param string $laneId lane_id as injected into the Behat process
     * @throws RuntimeException if the lane_id does not end with a slot token
     */
    public static function slotToken(string $laneId): string
    {
        if (preg_match('/' . self::slotTokenPattern() . '$/', $laneId, $matches) !== 1) {
            throw new RuntimeException('Cannot derive lane user suffix: lane_id "' . $laneId . '" has no "'
                . self::TOKEN_LANE . 'N" or "' . self::TOKEN_INTERACTIVE . 'N" token.');
        }
        return $matches[0];
    }

    /**
     * Returns the regex fragment (unanchored, delimiter "/") that matches one slot token: "_laneN" or "_interactiveN".
     *
     * WHY ONE PATTERN FOR BOTH DIRECTIONS: slotToken() derives the suffix from a lane_id and
     * UI5Browser::isTestUsername() recognises it on a stored username. With separate patterns, a new slot kind
     * added to one would create users the data cleanup never recognises as test users and never removes.
     */
    public static function slotTokenPattern(): string
    {
        return '(?:' . preg_quote(self::TOKEN_LANE, '/') . '|' . preg_quote(self::TOKEN_INTERACTIVE, '/') . ')\d+';
    }
}
