<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\RunRecordWriter;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers how a run-level failure is staged on the run row.
 *
 * WHY: the run status is derived from child rows, so a failure of the run itself (coordinator error, crash,
 * timeout) is only visible to it through error_message. An empty message staged as a failure would turn every
 * run red, a dropped one turns a failed run green, and an unbounded one bloats the row.
 */
class RunRecordWriterErrorTest extends TestCase
{
    /**
     * A real failure is staged in the error_message column of the run row.
     */
    public function testErrorIsStagedInTheErrorMessageColumn(): void
    {
        $sheet = $this->createMock(DataSheetInterface::class);
        $sheet->expects($this->once())->method('setCellValue')->with('error_message', 0, 'Coordinator error: boom');

        (new RunRecordWriter())->setError($sheet, "  Coordinator error: boom\n");
    }

    /**
     * An empty or blank message means "nothing failed" and stages nothing.
     */
    public function testBlankErrorStagesNothing(): void
    {
        $sheet = $this->createMock(DataSheetInterface::class);
        $sheet->expects($this->never())->method('setCellValue');

        (new RunRecordWriter())->setError($sheet, " \n ");
    }

    /**
     * An oversized message is cut on a byte budget without breaking a multi-byte character.
     */
    public function testOversizedErrorIsCappedWithoutSplittingACharacter(): void
    {
        $staged = null;
        $sheet = $this->createStub(DataSheetInterface::class);
        $sheet->method('setCellValue')->willReturnCallback(function ($column, $row, $value) use (&$staged, $sheet) {
            $staged = $value;
            return $sheet;
        });

        (new RunRecordWriter())->setError($sheet, str_repeat('ä', 20000));

        $this->assertLessThan(8100, strlen($staged));
        $this->assertTrue(mb_check_encoding($staged, 'UTF-8'));
        $this->assertStringEndsWith('(truncated)', $staged);
    }
}
