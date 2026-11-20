<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\BDTApp;
use axenox\BDT\Behat\Common\BdtConfig;
use axenox\BDT\Behat\Common\RunOptions;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\Tasks\TaskInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the shared input readers RunTest and RunParallel both depend on.
 *
 * WHY: PARALLEL.WORKER_TIMEOUT_SECONDS = 0 disabled the fleet's ceiling but gave every RunTest run a 1800 s
 * kill, and RunParallel read --tags=" " as "given" for its scope guard while dropping it from the worker
 * command. Both actions now go through these two readers, so their meaning is pinned here once.
 */
class SharedRunInputsTest extends TestCase
{
    /**
     * 0 and negative values disable the ceiling for both actions; a positive value is used as given.
     */
    #[DataProvider('timeoutValues')]
    public function testWorkerTimeoutHasOneMeaning(mixed $configured, ?float $expected): void
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-shared-run-inputs-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        $workbench->getApp(BDTApp::APP_ALIAS)->getConfig()->setOption(BdtConfig::PARALLEL_WORKER_TIMEOUT_SECONDS, $configured);

        $this->assertSame($expected, BdtConfig::resolveWorkerTimeout($workbench));
    }

    public static function timeoutValues(): array
    {
        return [
            'zero disables' => [0, null],
            'negative disables' => [-1, null],
            'positive is used' => [7200, 7200.0],
        ];
    }

    /**
     * A whitespace-only option is the same as an absent one; a real value is trimmed.
     */
    #[DataProvider('optionValues')]
    public function testOptionReaderTrims(?string $given, ?string $expected): void
    {
        $task = $this->createStub(TaskInterface::class);
        $task->method('hasParameter')->willReturn($given !== null);
        $task->method('getParameter')->willReturn($given);

        $this->assertSame($expected, RunOptions::read($task, RunOptions::TAGS));
    }

    public static function optionValues(): array
    {
        return [
            'absent' => [null, null],
            'whitespace only' => ["  \t ", null],
            'padded value' => [' @Smoke ', '@Smoke'],
        ];
    }
}
