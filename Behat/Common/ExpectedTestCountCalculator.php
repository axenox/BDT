<?php
namespace axenox\BDT\Behat\Common;

use Behat\Gherkin\Keywords\ArrayKeywords;
use Behat\Gherkin\Lexer;
use Behat\Gherkin\Parser;
use Behat\Gherkin\Filter\TagFilter;
use Behat\Gherkin\Exception\ParserException;
use exface\Core\Exceptions\RuntimeException;

/**
 * Computes how many features and scenarios Behat is expected to run - applying the same
 * tag filter Behat applies - and validates the feature files in the same pass, BEFORE the
 * actual run starts.
 */
final class ExpectedTestCountCalculator
{
    /**
     * Reusable parser instance.
     *
     * Why keep it as a field: building the lexer/keywords once and reusing it across every
     * file avoids re-instantiating the keyword tables for each of potentially hundreds of
     * feature files in a nightly suite.
     */
    private Parser $parser;

    public function __construct()
    {
        $this->parser = $this->createParser();
    }

    /**
     * Scans the given files/directories and returns the expected feature/scenario counts,
     * applying the same tag filter Behat applies, plus any per-file parse errors.
     *
     * Why it does not stop at the first broken file: a nightly suite usually has many files,
     * and the operator needs the full list of offenders in one pass. Each file is parsed in
     * isolation; a failure is recorded and the scan continues.
     *
     * Why the tag filter: nightly always runs with "--tags" (only @ready scenarios). Without
     * filtering here, the expected count would include every scenario in the files and never
     * match what actually ran, making silent-stop detection useless.
     *
     * @param string[]    $paths
     * @param string|null $tagExpression Behat tag filter, e.g. "@ready". Null/empty = count all.
     */
    public function calculate(array $paths, ?string $tagExpression = null): ExpectedTestCountResult
    {
        $features = 0;
        $scenarios = 0;
        $scanned = [];
        $errors = [];
        $matchedFiles = [];

        // Build the tag filter once and reuse it for every feature.
        $tagFilter = ($tagExpression !== null && trim($tagExpression) !== '')
            ? new TagFilter($tagExpression)
            : null;

        foreach ($this->collectFeatureFiles($paths) as $file) {
            $scanned[] = $file;
            try {
                // Pass the filename so ParserException messages carry the location.
                $feature = $this->parser->parse(file_get_contents($file), $file);
            } catch (ParserException $e) {
                $errors[$file] = $e->getMessage();
                continue;
            }

            // An empty or comment-only file parses to null and contains no feature.
            if ($feature === null) {
                continue;
            }

            // Mirror Behat\Gherkin\Gherkin::load(): filterFeature() strips non-matching
            // scenarios (and example rows of outlines); a feature with no remaining scenarios
            // is dropped unless its own feature-level tags match. This is exactly how Behat
            // decides what to run, so the counts stay aligned with the run_feature /
            // run_scenario rows written during the actual run.
            if ($tagFilter !== null) {
                $feature = $tagFilter->filterFeature($feature);
                if (! $feature->hasScenarios() && ! $tagFilter->isFeatureMatch($feature)) {
                    continue;
                }
            }

            $features++;
            // Record the path of every feature that survived the tag filter. The count alone
            // is enough for silent-stop detection (DatabaseFormatter's use), but the parallel
            // coordinator needs the actual file list to split work across workers.
            $matchedFiles[] = $file;
            $scenarios += count($feature->getScenarios());
        }

        return new ExpectedTestCountResult($features, $scenarios, $scanned, $errors, $matchedFiles);
    }

    /**
     * Computes the expected counts of a whole Behat invocation: which suites run, which feature path
     * (if any) is passed positionally and which tag filter Behat applies to each of them.
     *
     * WHY THIS LIVES HERE AND IS SHARED: the formatter (single process) and the coordinators
     * (RunParallel, RunTest) must predict exactly what Behat will run. The formatter already knew two
     * rules the coordinators did not: Behat applies the owning suite's own "filters.tags" when no
     * --tags is given, and a positional path runs only inside the suite that owns it. A coordinator
     * that counted with the CLI --tags alone over-counted every --suite run without --tags, expected
     * exceeded actual, and the run looked like a silent stop. One implementation for all callers
     * makes that drift impossible.
     *
     * RULES (mirroring Behat):
     * - --suite restricts the run to that suite only;
     * - a positional feature path runs only inside the suite that owns it; with --suite given, a path
     *   outside that suite runs NOTHING and counts as zero (the coordinators refuse it before this point);
     * - tag filter per suite = CLI --tags when given, otherwise the suite's own filters.tags;
     * - a positional path owned by no suite (and no --suite) is counted with the CLI --tags alone.
     *
     * @param array<string,array{paths:string[],tags:?string}> $suites Suite definitions (see BehatSuiteResolver::resolveSuites())
     * @param string|null $cliSuite    Value of --suite, if given
     * @param string|null $cliTags     Value of --tags, if given (an empty string means "no filter", as for Behat)
     * @param string|null $featurePath Absolute positional feature file or directory, if given
     */
    public function calculateForScope(array $suites, ?string $cliSuite, ?string $cliTags, ?string $featurePath = null): ExpectedTestCountResult
    {
        if ($cliSuite !== null) {
            $suites = isset($suites[$cliSuite]) ? [$cliSuite => $suites[$cliSuite]] : [];
        }

        if ($featurePath !== null) {
            $owner = BehatSuiteResolver::findSuiteOwningPath($suites, $featurePath);
            if ($owner === null && $cliSuite !== null) {
                return new ExpectedTestCountResult(0, 0, [], []);
            }
            $tagExpression = $owner !== null ? ($cliTags ?? $suites[$owner]['tags']) : $cliTags;
            return $this->calculate([$featurePath], $tagExpression);
        }

        $total = new ExpectedTestCountResult(0, 0, [], []);
        foreach ($suites as $suite) {
            if (empty($suite['paths'])) {
                continue;
            }
            $total = self::merge($total, $this->calculate($suite['paths'], $cliTags ?? $suite['tags']));
        }
        return $total;
    }

    /**
     * Adds two results together.
     *
     * WHY THE COUNTS ARE SUMMED BUT THE FILE LIST IS NOT: Behat runs a feature once per suite that
     * contains it, so the counts add up per suite - but the coordinators dispatch one worker per FILE,
     * so a file reachable through two suites must appear in the work list once.
     */
    private static function merge(ExpectedTestCountResult $a, ExpectedTestCountResult $b): ExpectedTestCountResult
    {
        return new ExpectedTestCountResult(
            $a->featureCount + $b->featureCount,
            $a->scenarioCount + $b->scenarioCount,
            array_values(array_unique(array_merge($a->scannedFiles, $b->scannedFiles))),
            array_merge($a->errors, $b->errors),
            array_values(array_unique(array_merge($a->matchedFiles, $b->matchedFiles)))
        );
    }

    /**
     * Expands directories to the .feature files they contain and passes through file paths.
     *
     * Why recurse manually instead of glob(): suites commonly nest features in sub-folders,
     * and a non-recursive glob would silently miss them, producing a count that is too low
     * and defeating the silent-stop detection this class is meant to enable.
     *
     * @param string[] $paths
     * @return string[]
     */
    private function collectFeatureFiles(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;
                continue;
            }
            if (! is_dir($path)) {
                // A configured path that resolves to neither file nor directory is a
                // configuration error worth surfacing loudly rather than silently skipping.
                return [];
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $entry) {
                /** @var \SplFileInfo $entry */
                if ($entry->isFile() && strtolower($entry->getExtension()) === 'feature') {
                    $files[] = $entry->getPathname();
                }
            }
        }
        // De-duplicate in case a file is reachable via two configured paths.
        return array_values(array_unique($files));
    }

    /**
     * Builds a Gherkin parser with the English keyword set.
     *
     * Why hard-code English keywords instead of loading gherkin's i18n resource: the resource
     * file path differs between gherkin major versions, whereas the BDT feature files use the
     * standard English keywords. ArrayKeywords removes that version-specific dependency and
     * keeps the scan deterministic. If localized "# language:" files are ever introduced, the
     * missing language surfaces as a ParserException, i.e. a visible error rather than a
     * wrong count.
     */
    private function createParser(): Parser
    {
        $keywords = new ArrayKeywords([
            'en' => [
                'feature'          => 'Feature',
                'background'       => 'Background',
                'scenario'         => 'Scenario',
                'scenario_outline' => 'Scenario Outline|Scenario Template',
                'examples'         => 'Examples|Scenarios',
                'given'            => 'Given',
                'when'             => 'When',
                'then'             => 'Then',
                'and'              => 'And',
                'but'              => 'But',
            ],
        ]);

        return new Parser(new Lexer($keywords));
    }
}