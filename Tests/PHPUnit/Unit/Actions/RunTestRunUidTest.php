<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\BDTApp;
use axenox\BDT\Actions\RunTest;
use axenox\BDT\Behat\Common\RunLifecycle;
use axenox\BDT\Behat\Common\RunScope;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\DataSheets\DataColumnInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Pins the run UID contract of RunTest for a failure AFTER the run row exists.
 *
 * WHY: agents find a run - and the reason their feature is broken - in the DB through its UID. Once the row
 * exists, the UID must therefore be reported before anything that can fail, every exception must carry it
 * (a generator that throws shows its caller only the exception), and the row must be finalized with the
 * failure recorded so the run status can never read as passed.
 *
 * The workbench is a real, unstarted one. The run row is the only part that needs a database, so it is a
 * stand-in sheet handed in through the lifecycle seam (see RunLifecycle::createRunSheet()). The browser is
 * an attached one, selected through the CLI endpoint, so the outcome does not depend on the machine's own
 * BROWSER.MANAGER configuration.
 */
class RunTestRunUidTest extends TestCase
{
    private const UID = '0xtestrun';
    private const ENDPOINT = 'http://localhost:9405';
    private const BASE_URL = 'http://app/';

    private string $root;

    /** @var array<string,mixed> Values written to the run row, by column */
    private array $cells = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdt_uid_' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'features', 0777, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'behat.yml', "imports:\n  - suites.yml\n");
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'suites.yml',
            "default:\n  suites:\n    alpha:\n      paths:\n        - '%paths.base%/features'\n"
        );
        // Two Background blocks: a real parse error, found when the expected counts are calculated.
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'features' . DIRECTORY_SEPARATOR . 'broken.feature',
            "Feature: Broken\n  Background:\n    Given x\n  Background:\n    Given y\n"
        );
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->root);
    }

    private function workbench(): WorkbenchInterface
    {
        return new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-run-uid-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
    }

    /**
     * A run row that exists without a database and records everything written to it.
     */
    private function runSheet(WorkbenchInterface $workbench): DataSheetInterface
    {
        $uidColumn = $this->createStub(DataColumnInterface::class);
        $uidColumn->method('getValue')->willReturn(self::UID);
        $sheet = $this->createStub(DataSheetInterface::class);
        $sheet->method('getUidColumn')->willReturn($uidColumn);
        $sheet->method('getRow')->willReturn(['started_on' => '2026-11-20 08:00:00']);
        $sheet->method('getWorkbench')->willReturn($workbench);
        $sheet->method('dataUpdate')->willReturn(1);
        $sheet->method('setCellValue')->willReturnCallback(function (string $column, int $row, $value) use ($sheet) {
            $this->cells[$column] = $value;
            return $sheet;
        });
        return $sheet;
    }

    private function lifecycle(WorkbenchInterface $workbench): RunLifecycle
    {
        return new class($workbench, 'BDT test run', $this->runSheet($workbench)) extends RunLifecycle {
            public function __construct(WorkbenchInterface $workbench, string $title, private DataSheetInterface $stub)
            {
                parent::__construct($workbench, $title);
            }

            protected function createRunSheet(string $behatCommand): DataSheetInterface
            {
                return $this->stub;
            }
        };
    }

    /**
     * A RunTest whose run row is the stand-in sheet.
     */
    private function action(WorkbenchInterface $workbench, RunLifecycle $lifecycle): RunTest
    {
        $app = $workbench->getApp(BDTApp::APP_ALIAS);
        return new class($app, $lifecycle) extends RunTest {
            public function __construct(\exface\Core\Interfaces\AppInterface $app, private RunLifecycle $stubLifecycle)
            {
                parent::__construct($app);
            }

            protected function createLifecycle(): RunLifecycle
            {
                return $this->stubLifecycle;
            }
        };
    }

    /**
     * Drives the deferred phase of a tags-only run to its end or its first exception.
     *
     * @return array{0:string[],1:?\Throwable} What was streamed and what was thrown
     */
    private function drain(string $tags): array
    {
        $workbench = $this->workbench();
        $action = $this->action($workbench, $this->lifecycle($workbench));
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);

        $chunks = [];
        $error = null;
        try {
            foreach ($method->invoke($action, $this->root, $this->root . DIRECTORY_SEPARATOR . 'behat.yml', '', '', $tags, '', self::ENDPOINT, self::BASE_URL) as $chunk) {
                $chunks[] = $chunk;
            }
        } catch (\Throwable $e) {
            $error = $e;
        }
        return [$chunks, $error];
    }

    /**
     * The deferred phase of a tags-only run: the UID line comes right after the row exists, the parse
     * error that follows carries the UID, and the row is finalized with the failure recorded.
     */
    public function testErrorAfterRowCreationReportsTheRunUidAndFinalizesTheRow(): void
    {
        [$chunks, $error] = $this->drain('@test_7');

        $this->assertInstanceOf(RuntimeException::class, $error);
        // 1) The UID is the last thing streamed before the failure: nothing that can fail came first.
        $this->assertSame('Run UID: ' . self::UID . "\n", end($chunks));
        // 2) The exception message carries it, and still says what failed.
        $this->assertStringContainsString("\nRun UID: " . self::UID, $error->getMessage());
        $this->assertStringContainsString('Feature files failed to parse', $error->getMessage());
        // 3) The row was finalized, with the failure on it - so its status cannot be "passed".
        $this->assertArrayHasKey('finished_on', $this->cells);
        $this->assertStringContainsString('Feature files failed to parse', $this->cells['error_message']);
        $this->assertStringContainsString('Feature files failed to parse', $this->cells['log']);
    }

    /**
     * A scope that matches no scenario fails the run BEFORE Behat starts: the row is finalized with the
     * failure in error_message and the exception carries the UID. Behat itself would exit 0 on it and the
     * closing banner would claim that all scenarios passed.
     */
    public function testEmptyScopeFailsTheRunBeforeBehatStarts(): void
    {
        $featuresDir = $this->root . DIRECTORY_SEPARATOR . 'features' . DIRECTORY_SEPARATOR;
        unlink($featuresDir . 'broken.feature');
        file_put_contents($featuresDir . 'ok.feature', "Feature: Ok\n  @present\n  Scenario: S\n    Given x\n");

        [$chunks, $error] = $this->drain('@absent');

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString(RunScope::EMPTY_SCOPE_MESSAGE, $error->getMessage());
        $this->assertStringContainsString("\nRun UID: " . self::UID, $error->getMessage());
        $this->assertSame('Run UID: ' . self::UID . "\n", end($chunks));
        $this->assertStringNotContainsString('all scenarios passed', implode('', $chunks));
        $this->assertSame(RunScope::EMPTY_SCOPE_MESSAGE, $this->cells['error_message']);
        $this->assertArrayHasKey('finished_on', $this->cells);
    }

    /**
     * Before the row exists there is no UID, so the helpers that stamp it must refuse instead of printing an
     * empty "Run UID:" line that an agent would parse as a real run.
     */
    public function testRunBoundHelpersRefuseToRunBeforeTheRowExists(): void
    {
        $lifecycle = $this->lifecycle($this->workbench());

        foreach ([
            fn() => $lifecycle->appendRunUid('x'),
            fn() => $lifecycle->withRunUid(new \RuntimeException('x')),
            fn() => $lifecycle->getRunLog(),
        ] as $call) {
            try {
                $call();
                $this->fail('Must refuse before open()');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('before open()', $e->getMessage());
            }
        }
    }

    /**
     * The UID line is added once, not repeated, and an exception that already has it is thrown as is.
     */
    public function testUidHelpersAreIdempotent(): void
    {
        $lifecycle = $this->lifecycle($this->workbench());
        $lifecycle->open('vendor/bin/action axenox.BDT:RunTest --tags=@test_7');

        $once = $lifecycle->appendRunUid('Something failed');
        $this->assertSame("Something failed\nRun UID: " . self::UID, $once);
        $this->assertSame($once, $lifecycle->appendRunUid($once));

        $plain = new \RuntimeException('Plain');
        $wrapped = $lifecycle->withRunUid($plain);
        $this->assertNotSame($plain, $wrapped);
        $this->assertSame($plain, $wrapped->getPrevious());
        $this->assertStringContainsString('Run UID: ' . self::UID, $wrapped->getMessage());
        $this->assertSame($wrapped, $lifecycle->withRunUid($wrapped));
    }
}
