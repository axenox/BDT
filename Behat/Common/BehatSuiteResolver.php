<?php
namespace axenox\BDT\Behat\Common;

use Symfony\Component\Yaml\Yaml;
use exface\Core\Exceptions\RuntimeException;

/**
 * Simple resolver for Behat suite definitions used by the parallel coordinator and RunTest.
 *
 * Reads the root `behat.yml`, follows its `imports` entries, collects every
 * `suites.*.paths` value with the behat.yml directory as required by Phase 
 * 2 and the suite's own `filters.tags`. If any `%...%` placeholder remains after expansion the
 * resolver throws loudly.
 *
 * The suite shape ("name => paths + tag filter") is deliberately plain arrays, because the very same
 * shape is built from Behat's live suite registry inside the formatter process. That lets the
 * coordinator side (which reads YAML) and the formatter side (which reads Suite objects) share ONE
 * implementation of "which suite owns this path" and "which tag filter applies".
 */
final class BehatSuiteResolver
{
    /**
     * Reads the suite definitions (paths and own tag filter) from the global behat.yml.
     *
     * WHY THE TAG FILTER IS RETURNED TOO: Behat applies a suite's own `filters.tags` whenever no
     * `--tags` is given on the command line. The coordinator used to read only the paths, so its
     * expected counts included scenarios Behat skipped and the run looked as if it had stopped early.
     *
     * @param string $globalYmlPath Absolute path to the global `behat.yml` file
     * @param string|null $onlySuite When set, collect ONLY this suite (other suites are not even expanded,
     *                               so an unrelated suite with a broken placeholder cannot fail this run)
     * @return array<string,array{paths:string[],tags:?string}> Suite name => its expanded paths and own tag filter
     * @throws RuntimeException on missing imports, unresolved placeholders, or an unknown suite name
     */
    public function resolveSuites(string $globalYmlPath, ?string $onlySuite = null): array
    {
        if (! file_exists($globalYmlPath)) {
            throw new RuntimeException('Global behat.yml not found: ' . $globalYmlPath);
        }

        // Derive the base directory from the behat.yml location itself rather than getcwd(). Imports and
        // Behat's %paths.base% both resolve relative to the behat.yml file, so depending on getcwd() would
        // break when the coordinator (a PHP action) runs with a different working directory.
        $baseDir = dirname($globalYmlPath);

        $root = Yaml::parseFile($globalYmlPath);
        $imports = $root['imports'] ?? [];
        // Behat normally declares `imports` as a list, but a single import may be written as a bare string.
        // Normalise to an array so the loop below iterates import ENTRIES rather than walking the characters
        // of a string one byte at a time (which is what foreach would do over a scalar string).
        if (! is_array($imports)) {
            $imports = [$imports];
        }
        $suites = [];

        // Track across ALL imports whether the requested named suite was ever found. It must be checked
        // after the full scan, because the suite may be declared in any imported file - checking inside
        // the loop would throw on the first import that lacks it. Inert when no suite filter is requested.
        $suiteSeen = false;

        foreach ($imports as $import) {
            $importPath = $import;
            if (! file_exists($importPath)) {
                $importPath = $baseDir . DIRECTORY_SEPARATOR . $import;
            }
            if (! file_exists($importPath)) {
                throw new RuntimeException('Imported behat config not found: ' . $import);
            }

            $yml = Yaml::parseFile($importPath);
            $default = $yml['default'] ?? [];
            $suiteConfigs = $default['suites'] ?? [];
            foreach ($suiteConfigs as $name => $suiteCfg) {
                $name = (string) $name;
                // When filtering to a single named suite, skip every other suite.
                if ($onlySuite !== null && $name !== $onlySuite) {
                    continue;
                }
                $suiteSeen = true;
                $paths = $suiteCfg['paths'] ?? [];
                if (! is_array($paths)) {
                    $paths = [$paths];
                }
                $suites[$name] ??= ['paths' => [], 'tags' => null];
                foreach ($paths as $p) {
                    // Expand %paths.base% with the behat.yml directory, not getcwd().
                    $expanded = str_replace('%paths.base%', $baseDir, $p);
                    if (strpos($expanded, '%') !== false) {
                        throw new RuntimeException('Unresolved placeholder in suite path: ' . $p . ' (expanded: ' . $expanded . ')');
                    }
                    $suites[$name]['paths'][] = $expanded;
                }
                $tags = $suiteCfg['filters']['tags'] ?? null;
                if ($tags !== null && $tags !== '') {
                    $suites[$name]['tags'] = (string) $tags;
                }
            }
        }

        // A requested suite that never appeared is an operator error (usually a typo), not "run nothing":
        // fail loudly AFTER the full scan so it cannot produce a green run that executed zero features.
        if ($onlySuite !== null && ! $suiteSeen) {
            throw new RuntimeException('Suite "' . $onlySuite . '" not found in ' . $globalYmlPath);
        }

        foreach ($suites as $name => $suite) {
            $suites[$name]['paths'] = array_values(array_unique($suite['paths']));
        }
        return $suites;
    }

    /**
     * Finds the suite whose configured paths contain the given feature path.
     *
     * WHY IT IS SHARED AND STATIC: the formatter (inside the Behat process, working on live Suite
     * objects) and the coordinator side (working on parsed YAML) must agree on which suite owns a
     * path - that decides which tag filter Behat applies, and whether a feature runs at all when
     * --suite is given. Two implementations would drift, and the drift shows up as an expected count
     * that no longer matches what ran.
     *
     * @param array<string,array{paths:string[],tags:?string}> $suites Suite definitions, see resolveSuites()
     * @param string $featurePath Absolute feature file or directory
     * @return string|null Name of the first owning suite, or null when no suite contains the path
     */
    public static function findSuiteOwningPath(array $suites, string $featurePath): ?string
    {
        $needle = self::normalizePathForCompare($featurePath);
        if ($needle === null) {
            return null;
        }
        foreach ($suites as $name => $suite) {
            foreach ($suite['paths'] as $suitePath) {
                $base = self::normalizePathForCompare((string) $suitePath);
                if ($base === null) {
                    continue;
                }
                // Exact file match, or the feature lives under the suite's directory. The trailing
                // separator guards a directory boundary so "features2" cannot match "features".
                if ($needle === $base
                    || str_starts_with($needle, rtrim($base, '/\\') . DIRECTORY_SEPARATOR)
                ) {
                    return (string) $name;
                }
            }
        }
        return null;
    }

    /**
     * Normalizes a path for cross-suite comparison: real absolute path, lower-cased. Returns null
     * for a path that does not resolve on disk.
     *
     * WHY realpath: suite paths and the CLI path can differ in separators and "." segments;
     * realpath collapses both to one canonical spelling so the prefix compare is reliable.
     *
     * WHY lower-case unconditionally: this framework runs on Windows, whose filesystem is
     * case-insensitive, so "Features\Login.feature" and "features\login.feature" are one file;
     * a case-sensitive compare would wrongly report no owning suite.
     */
    private static function normalizePathForCompare(string $path): ?string
    {
        $real = realpath($path);
        if ($real === false) {
            return null;
        }
        return strtolower($real);
    }
}
