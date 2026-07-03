<?php
namespace axenox\BDT\Behat\Common;

use Symfony\Component\Yaml\Yaml;
use exface\Core\Exceptions\RuntimeException;

/**
 * Simple resolver for Behat suite paths used by the parallel coordinator.
 *
 * Reads the root `behat.yml`, follows its `imports` entries, collects every
 * `suites.*.paths` value with the behat.yml directory as required by Phase 
 * 2. If any `%...%` placeholder remains after expansion the resolver throws 
 * loudly.
 */
final class BehatSuiteResolver
{
    /**
     * Resolves suite feature paths from the global behat.yml: all suites by default, or a single
     * named suite when $suiteName is given.
     *
     * @param string $globalYmlPath Absolute path to the global `behat.yml` file
     * @param string|null $suiteName When set, collect paths ONLY for this suite; when null, all suites
     * @return string[] Path expressions from all imported files (placeholders expanded, de-duplicated)
     * @throws RuntimeException on missing imports, unresolved placeholders, or an unknown suite name
     */
    public function resolvePathsFromGlobalYml(string $globalYmlPath, ?string $suiteName = null): array
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
        $collected = [];

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
            $suites = $default['suites'] ?? [];
            foreach ($suites as $name => $suiteCfg) {
                // When filtering to a single named suite, skip every other suite.
                if ($suiteName !== null && $name !== $suiteName) {
                    continue;
                }
                $suiteSeen = true;
                $paths = $suiteCfg['paths'] ?? [];
                if (! is_array($paths)) {
                    $paths = [$paths];
                }
                foreach ($paths as $p) {
                    // Expand %paths.base% with the behat.yml directory, not getcwd().
                    $expanded = str_replace('%paths.base%', $baseDir, $p);
                    if (strpos($expanded, '%') !== false) {
                        throw new RuntimeException('Unresolved placeholder in suite path: ' . $p . ' (expanded: ' . $expanded . ')');
                    }
                    $collected[] = $expanded;
                }
            }
        }

        // A requested suite that never appeared is an operator error (usually a typo), not "run nothing":
        // fail loudly AFTER the full scan so it cannot produce a green run that executed zero features.
        if ($suiteName !== null && ! $suiteSeen) {
            throw new RuntimeException('Suite "' . $suiteName . '" not found in ' . $globalYmlPath);
        }

        return array_values(array_unique($collected));
    }
}