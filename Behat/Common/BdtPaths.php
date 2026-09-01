<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\Exceptions\RuntimeException;

/**
 * Single source of truth for every path BDT writes under the installation folder.
 *
 * WHY IT EXISTS: the prefix "data/axenox/BDT" used to be spelled out independently in six places
 * (parallel run logs, the fleet's chrome profiles, the interactive run's profile and its cleanup,
 * the screenshot folder, the provisioning lock and the port locks). Nothing tied those spellings
 * together, so renaming or moving BDT's data folder meant finding all six by grep - and a missed one
 * would not fail loudly, it would silently write to, or clean up, the wrong directory. Two of them
 * were already required to MIRROR each other exactly (the interactive profile is created in one
 * method and deleted in another), which is precisely the kind of pair that drifts.
 *
 * WHY IT IS STATIC AND TAKES THE BASE PATH AS A PARAMETER: the callers have nothing in common to
 * inherit from. The actions hold the installation root in a local $workingDir, the traits read it
 * off the workbench, one caller is a static method, and the Behat context classes have no workbench
 * at all and only know getcwd(). A static helper that is HANDED the base path fits all of them
 * without dragging a workbench dependency into Behat contexts or forcing a common base class.
 *
 * WHY THE FOLDER NAMES ARE CONSTANTS: they are the part callers actually pass, so leaving them as
 * inline literals would just move the drift one level down - and "chrome_profiles" alone is built
 * from three different places.
 */
final class BdtPaths
{
    /**
     * BDT's own data folder, as segments relative to the installation root.
     *
     * WHY SEGMENTS AND NOT A STRING CONSTANT: the parts are joined with DIRECTORY_SEPARATOR at
     * build time, so the same declaration produces a valid path on Windows and on Linux without a
     * second, platform-specific copy of it.
     */
    private const ROOT_SEGMENTS = ['data', 'axenox', 'BDT'];

    public const FOLDER_LOGS = 'Logs';
    public const FOLDER_SCREENSHOTS = 'Screenshots';
    public const FOLDER_CHROME_PROFILES = 'chrome_profiles';
    public const FOLDER_LOCKS = 'locks';
    public const FOLDER_PORT_LOCKS = 'portlocks';

    /**
     * Builds a path relative to the installation root, e.g. "data\axenox\BDT\Logs\<run_uid>".
     *
     * WHY A RELATIVE VARIANT IS NEEDED AT ALL: two consumers must NOT receive an absolute path.
     * The interactive behat config's user_data_dir is resolved by ChromeManager::start(), which
     * prepends getcwd() - an absolute value there becomes a broken "C:\...\C:\..." and Chrome
     * silently falls back to the real user profile. The screenshot path is stored in the database
     * and read back against whatever installation serves the UI, so it must not carry this
     * machine's drive letter.
     *
     * @param string ...$segments Sub-path below BDT's data folder
     * @return string Path relative to the installation root, without a leading separator
     */
    public static function relative(string ...$segments) : string
    {
        return implode(DIRECTORY_SEPARATOR, array_merge(self::ROOT_SEGMENTS, self::normalizeSegments($segments)));
    }

    /**
     * Builds the absolute path of a BDT data folder below the given installation root.
     *
     * WHY THE ROOT IS NEVER READ FROM getcwd() HERE: the coordinator actions run with a process cwd
     * that is not guaranteed to be the installation (a scheduled task inherits the scheduler's), so
     * the caller - which knows its own reliable base - stays responsible for supplying it.
     *
     * @param string $basePath    Installation root
     * @param string ...$segments Sub-path below BDT's data folder
     */
    public static function absolute(string $basePath, string ...$segments) : string
    {
        return rtrim($basePath, DIRECTORY_SEPARATOR . '/') . DIRECTORY_SEPARATOR . self::relative(...$segments);
    }

    /**
     * Returns the absolute path like absolute(), creating the directory on first use.
     *
     * WHY CREATION LIVES HERE: every caller needed the identical "create recursively, tolerate a
     * concurrent creator, fail loudly otherwise" dance, and getting the race wrong matters - several
     * BDT processes create these folders at the same instant on a shared server. The re-check after
     * mkdir() is what makes a lost race a success instead of a spurious failure.
     *
     * @throws RuntimeException if the directory does not exist and cannot be created
     */
    public static function ensure(string $basePath, string ...$segments) : string
    {
        $dir = self::absolute($basePath, ...$segments);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Could not create BDT directory: ' . $dir);
        }
        return $dir;
    }

    /**
     * Splits, cleans and validates the caller's segments before they become a path.
     *
     * WHY IT VALIDATES INSTEAD OF JUST CONCATENATING: some segments are runtime values - a run UID,
     * a lane number, a port. They are trusted today, but this class is the single gate through which
     * BDT decides where to WRITE and, in the Chrome cleanup case, what to DELETE. Rejecting "." and
     * ".." here means no future caller can walk a directory removal out of BDT's own data folder,
     * and it costs one pass over a handful of short strings.
     *
     * Separators inside a segment are accepted and normalized rather than rejected, so a caller may
     * pass "interactive5" and "sub/folder" alike without knowing the platform's separator.
     *
     * @param string[] $segments
     * @return string[] Flat list of individual, non-empty path parts
     */
    private static function normalizeSegments(array $segments) : array
    {
        $parts = [];
        foreach ($segments as $segment) {
            // An EMPTY segment is always a caller bug, never a legitimate "no sub-folder": the caller
            // asked for a level and handed over nothing to name it with. Silently skipping it would
            // return the parent path instead - the run's log folder would collapse into the Logs root,
            // where it mixes with the queue's own output, and a screenshot would land one level above
            // the two-level glob the metamodel addresses it with. Both failures look like "the files
            // were never written" and are found days later, so the empty value is rejected where it
            // enters instead of being absorbed here. Callers that genuinely want no sub-folder simply
            // pass no segment at all.
            if (trim($segment) === '') {
                throw new RuntimeException('Empty BDT path segment - cannot build a path below "' . implode(DIRECTORY_SEPARATOR, self::ROOT_SEGMENTS) . '" from an empty value.');
            }
            $segment = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $segment);
            foreach (explode(DIRECTORY_SEPARATOR, $segment) as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if ($part === '.' || $part === '..') {
                    throw new RuntimeException('Invalid BDT path segment "' . $part . '" - path traversal is not allowed.');
                }
                $parts[] = $part;
            }
        }
        return $parts;
    }
}