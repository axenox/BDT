<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\BehatSuiteResolver;
use axenox\BDT\Behat\Common\ExpectedTestCountCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Pins that the expected counts of a coordinator reproduce what Behat actually runs.
 *
 * WHY: the coordinators counted with the CLI --tags alone, while Behat also applies the suite's own
 * filters.tags when no --tags is given and runs a positional feature only inside its owning suite. The
 * counts exceeded what ran, so a --suite run without --tags looked like it had stopped early.
 */
class ExpectedTestCountScopeTest extends TestCase
{
    private string $root;

    /**
     * Builds two suite folders: "alpha" holds a tagged and an untagged scenario, "beta" one tagged scenario.
     */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdt_scope_' . uniqid();
        mkdir($this->root . DIRECTORY_SEPARATOR . 'alpha', 0777, true);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'beta', 0777, true);
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'a.feature',
            "Feature: Alpha\n  @ready\n  Scenario: tagged\n    Given something\n  Scenario: untagged\n    Given something\n"
        );
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'beta' . DIRECTORY_SEPARATOR . 'b.feature',
            "Feature: Beta\n  @other\n  Scenario: tagged\n    Given something\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (['alpha' . DIRECTORY_SEPARATOR . 'a.feature', 'beta' . DIRECTORY_SEPARATOR . 'b.feature'] as $file) {
            @unlink($this->root . DIRECTORY_SEPARATOR . $file);
        }
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'alpha');
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'beta');
        @rmdir($this->root);
    }

    /**
     * Suite "alpha" carries its own tag filter, suite "beta" has none.
     *
     * @return array<string,array{paths:string[],tags:?string}>
     */
    private function suites(): array
    {
        return [
            'alpha' => ['paths' => [$this->root . DIRECTORY_SEPARATOR . 'alpha'], 'tags' => '@ready'],
            'beta' => ['paths' => [$this->root . DIRECTORY_SEPARATOR . 'beta'], 'tags' => null]
        ];
    }

    /**
     * The regression: --suite without --tags must apply the suite's own filter, not count every scenario.
     */
    public function testSuiteWithoutTagsAppliesTheSuitesOwnFilter(): void
    {
        $result = (new ExpectedTestCountCalculator())->calculateForScope($this->suites(), 'alpha', null);

        $this->assertSame(1, $result->featureCount);
        $this->assertSame(1, $result->scenarioCount);
    }

    /**
     * A CLI --tags replaces the suite's filter, exactly as in Behat.
     */
    public function testCliTagsOverrideTheSuitesOwnFilter(): void
    {
        $result = (new ExpectedTestCountCalculator())->calculateForScope($this->suites(), 'alpha', '~@ready');

        $this->assertSame(1, $result->scenarioCount);
        $this->assertCount(1, $result->matchedFiles);
    }

    /**
     * Without --suite every suite is counted with its own filter.
     */
    public function testNoSuiteCountsEverySuiteWithItsOwnFilter(): void
    {
        $result = (new ExpectedTestCountCalculator())->calculateForScope($this->suites(), null, null);

        $this->assertSame(2, $result->featureCount);
        $this->assertSame(2, $result->scenarioCount);
        $this->assertCount(2, $result->matchedFiles);
    }

    /**
     * --feature together with --suite counts the feature once, with the selected suite's filter.
     */
    public function testFeatureInsideSelectedSuiteUsesThatSuitesFilter(): void
    {
        $feature = $this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'a.feature';

        $result = (new ExpectedTestCountCalculator())->calculateForScope($this->suites(), 'alpha', null, $feature);

        $this->assertSame(1, $result->scenarioCount);
    }

    /**
     * A feature outside the selected suite is filtered out by Behat, so nothing is expected.
     */
    public function testFeatureOutsideSelectedSuiteCountsNothing(): void
    {
        $feature = $this->root . DIRECTORY_SEPARATOR . 'beta' . DIRECTORY_SEPARATOR . 'b.feature';

        $result = (new ExpectedTestCountCalculator())->calculateForScope($this->suites(), 'alpha', null, $feature);

        $this->assertSame(0, $result->featureCount);
        $this->assertSame([], $result->matchedFiles);
    }

    /**
     * The ownership rule is shared with the formatter and must respect directory boundaries.
     */
    public function testOwnerLookupMatchesFilesInsideTheSuiteDirectoryOnly(): void
    {
        $suites = $this->suites();

        $this->assertSame('beta', BehatSuiteResolver::findSuiteOwningPath($suites, $this->root . DIRECTORY_SEPARATOR . 'beta' . DIRECTORY_SEPARATOR . 'b.feature'));
        $this->assertNull(BehatSuiteResolver::findSuiteOwningPath($suites, $this->root));
    }
}
