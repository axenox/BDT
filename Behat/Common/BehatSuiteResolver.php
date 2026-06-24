<?php
namespace axenox\BDT\Behat\Common;

use Symfony\Component\Yaml\Yaml;
use exface\Core\Exceptions\RuntimeException;

/**
 * Simple resolver for Behat suite paths used by the parallel coordinator.
 *
 * Reads the root `behat.yml`, follows its `imports` entries, collects every
 * `suites.*.paths` value and expands the `%paths.base%` placeholder with
 * `getcwd()` as required by Phase 2. If any `%...%` placeholder remains after
 * expansion the resolver throws loudly.
 */
final class BehatSuiteResolver
{
    /**
     * @param string $globalYmlPath Absolute path to the global `behat.yml` file
     * @return string[] Array of path expressions from all imported files (placeholders expanded)
     * @throws RuntimeException on missing imports or unresolved placeholders
     */
    public function resolvePathsFromGlobalYml(string $globalYmlPath): array
    {
        if (! file_exists($globalYmlPath)) {
            throw new RuntimeException('Global behat.yml not found: ' . $globalYmlPath);
        }

        // Derive the base directory from the behat.yml location itself rather than getcwd().
        // Imports are relative to the behat.yml file, and Behat's %paths.base% placeholder also
        // resolves to that same directory. Depending on getcwd() would break when the coordinator
        // (a PHP action) runs with a working directory other than the installation root.
        $baseDir = dirname($globalYmlPath);

        $root = Yaml::parseFile($globalYmlPath);
        $imports = $root['imports'] ?? [];
        $collected = [];

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
            foreach ($suites as $suiteName => $suiteCfg) {
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

        return array_values(array_unique($collected));
    }
}