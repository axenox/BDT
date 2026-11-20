<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\RunScope;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the scope rules both actions now share: the recorded rerun command and --feature with --suite.
 *
 * The workbench is a real, unstarted one. Its vendor folder cannot be redirected, and feature paths are
 * shown relative to it, so the fixture lives in a uniquely named throwaway directory directly below the real
 * vendor folder - which also exercises the vendor-relative form the actions record.
 *
 * CLEANUP: tearDown() removes the fixture after every test, failed or not. A process that dies mid-test
 * (fatal error, killed runner) never reaches tearDown(), so setUpBeforeClass() also sweeps leftovers of
 * EARLIER runs, recognised by the FIXTURE_PREFIX and an age above LEFTOVER_MIN_AGE_SECONDS - the age keeps
 * the sweep away from the fixture of a test running concurrently in another process.
 */
class RunScopeTest extends TestCase
{
    private const FIXTURE_PREFIX = 'bdt_phpunit_runscope_';
    private const LEFTOVER_MIN_AGE_SECONDS = 3600;

    private Workbench $workbench;

    private string $root = '';

    public static function setUpBeforeClass(): void
    {
        $vendor = self::newWorkbench()->filemanager()->getPathToVendorFolder();
        foreach (glob($vendor . DIRECTORY_SEPARATOR . self::FIXTURE_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $leftover) {
            if (time() - (int) @filemtime($leftover) > self::LEFTOVER_MIN_AGE_SECONDS) {
                self::removeTree($leftover);
            }
        }
    }

    private static function newWorkbench(): Workbench
    {
        return new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-run-scope-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
    }

    /**
     * Removes a fixture directory completely; a missing one is not an error.
     */
    private static function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }

    protected function setUp(): void
    {
        $this->workbench = self::newWorkbench();
        // The shipped or installation value of PARALLEL.BEHAT_CONFIG must not steer the resolution under
        // test; an empty value means "the behat.yml of the given root".
        $this->workbench->getApp('axenox.BDT')->getConfig()->setOption('PARALLEL.BEHAT_CONFIG', '');
        $this->root = $this->workbench->filemanager()->getPathToVendorFolder() . DIRECTORY_SEPARATOR . self::FIXTURE_PREFIX . bin2hex(random_bytes(6));
        try {
            mkdir($this->root . DIRECTORY_SEPARATOR . 'alpha', 0777, true);
            mkdir($this->root . DIRECTORY_SEPARATOR . 'beta', 0777, true);
            file_put_contents($this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'a.feature', "Feature: A\n");
            file_put_contents($this->root . DIRECTORY_SEPARATOR . 'beta' . DIRECTORY_SEPARATOR . 'b.feature', "Feature: B\n");
            file_put_contents(
                $this->root . DIRECTORY_SEPARATOR . 'behat.yml',
                "imports:\n  - suites.yml\n"
            );
            file_put_contents(
                $this->root . DIRECTORY_SEPARATOR . 'suites.yml',
                "default:\n  suites:\n    alpha:\n      paths:\n        - '%paths.base%/alpha'\n    beta:\n      paths:\n        - '%paths.base%/beta'\n"
            );
        } catch (\Throwable $e) {
            // Not a suppression: re-thrown at once. A half-built fixture is removed first, because a failing
            // setUp() is not reliably followed by tearDown().
            self::removeTree($this->root);
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            self::removeTree($this->root);
        }
    }

    /**
     * The fixture folder's name, i.e. how a feature below it is spelled relative to the vendor folder.
     */
    private function fixtureName(): string
    {
        return basename($this->root);
    }

    private function scope(): RunScope
    {
        return new RunScope($this->workbench);
    }

    /**
     * HTML-encoded selectors are refused before they can end as a silent "nothing to run".
     */
    #[DataProvider('htmlEncodedSelectors')]
    public function testHtmlEncodedSelectorsAreRefused(?string $tags, ?string $suite, ?string $feature, string $entity): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('looks HTML-encoded (it contains the entity "' . $entity . '")');
        RunScope::assertNotHtmlEncoded($tags, $suite, $feature);
    }

    public static function htmlEncodedSelectors(): array
    {
        return [
            'named entity in tags' => ['@Foo&amp;&amp;@Bar', null, null, '&amp;'],
            'numeric entity in tags' => ['@A&#38;&#38;@B', null, null, '&#38;'],
            'hex entity in tags' => ['@A&#x26;@B', null, null, '&#x26;'],
            'entity in suite' => [null, 'my&amp;suite', null, '&amp;'],
            'entity in feature' => [null, null, 'vendor/a&lt;b/x.feature', '&lt;']
        ];
    }

    /**
     * Valid selectors - including a bare "&&" and "::" - pass untouched.
     */
    public function testPlainSelectorsAreNotMistakenForEntities(): void
    {
        RunScope::assertNotHtmlEncoded('@Group::Name&&~@Skip', 'my.APP', 'vendor/my/app/R&D/x.feature');
        RunScope::assertNotHtmlEncoded('@A||@B,@C', null, null);
        RunScope::assertNotHtmlEncoded(null, null, null);

        $this->addToAssertionCount(1);
    }

    /**
     * A feature file that fails to parse is reported with its own parse error, not just its name, so the
     * reader can fix it without re-running a parser.
     *
     * Only the file name and the fact that a reason follows are asserted: the wording of the reason belongs
     * to the Gherkin parser and changes between its versions.
     */
    public function testParseErrorMessageNamesTheFileAndTheReason(): void
    {
        $broken = $this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'broken.feature';
        file_put_contents($broken, "Feature: Broken\n  Background:\n    Given x\n  Background:\n    Given y\n");
        try {
            $this->scope()->calculateExpectedScope($this->root . DIRECTORY_SEPARATOR . 'behat.yml', null, 'alpha', null);
            $this->fail('A feature file that cannot be parsed must be reported.');
        } catch (RuntimeException $e) {
            $this->assertMatchesRegularExpression(
                '#Feature files failed to parse \(1\): ' . preg_quote($this->fixtureName(), '#') . '/alpha/broken\.feature: \S#',
                $e->getMessage()
            );
            $this->assertStringNotContainsString($this->root, $e->getMessage());
        } finally {
            @unlink($broken);
        }
    }

    /**
     * Init is the caller's step; a missing root config must say so instead of leaving the caller to guess.
     */
    public function testMissingRootConfigTellsTheCallerToRunInit(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Run `vendor/bin/action axenox.BDT:Behat init` first');
        $this->scope()->resolveBehatConfig(null, $this->root . DIRECTORY_SEPARATOR . 'alpha');
    }

    /**
     * The root behat.yml that init wrote is used as is, without any init of our own.
     */
    public function testExistingRootConfigIsResolvedWithoutInit(): void
    {
        $path = $this->scope()->resolveBehatConfig(null, $this->root);

        $this->assertSame(realpath($this->root . DIRECTORY_SEPARATOR . 'behat.yml'), realpath($path));
    }

    /**
     * RunTest used to quote only on a space, so "@A&&@B" was recorded bare and broke when re-run.
     */
    public function testTagExpressionWithAmpersandsIsQuotedInTheRecordedCommand(): void
    {
        $command = $this->scope()->describeInvocation('axenox.BDT:RunTest', '@A&&@B', null, 'alpha');

        $this->assertSame('vendor/bin/action axenox.BDT:RunTest --tags="@A&&@B" --suite=alpha', $command);
    }

    /**
     * Both selectors are recorded, the feature in its vendor-relative, forward-slashed form.
     */
    public function testFeatureAndSuiteAreBothRecorded(): void
    {
        $feature = $this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'a.feature';

        $command = $this->scope()->describeInvocation('axenox.BDT:RunParallel', null, $feature, 'alpha', ['base_url' => '']);

        $this->assertSame(
            'vendor/bin/action axenox.BDT:RunParallel --feature=' . $this->fixtureName() . '/alpha/a.feature --suite=alpha',
            $command
        );
    }

    /**
     * A feature inside the suite is the one scan root.
     */
    public function testFeatureInsideTheSuiteIsAccepted(): void
    {
        $feature = $this->root . DIRECTORY_SEPARATOR . 'alpha' . DIRECTORY_SEPARATOR . 'a.feature';

        $roots = $this->scope()->resolveScanRoots($this->root . DIRECTORY_SEPARATOR . 'behat.yml', $feature, 'alpha');

        $this->assertSame([realpath($feature)], $roots);
    }

    /**
     * A feature outside the suite would run nothing and report green, so it is refused.
     */
    public function testFeatureOutsideTheSuiteIsRefused(): void
    {
        $feature = $this->root . DIRECTORY_SEPARATOR . 'beta' . DIRECTORY_SEPARATOR . 'b.feature';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not inside suite "alpha"');
        $this->scope()->resolveScanRoots($this->root . DIRECTORY_SEPARATOR . 'behat.yml', $feature, 'alpha');
    }
}
