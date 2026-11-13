<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\Actions\RunParallel;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\TestCase;

/**
 * Protects the coordinator's upfront executable check without starting browsers or lanes.
 */
class RunParallelExecutableTest extends TestCase
{
    /**
     * Invokes the coordinator check directly so path handling is tested without a full fleet run.
     */
    private function validate(?string $executable, string $workingDir): void
    {
        $method = new \ReflectionMethod(RunParallel::class, 'validateCoordinatorExecutable');
        $method->setAccessible(true);
        $method->invoke(null, $executable, $workingDir);
    }

    /**
     * Absolute paths must not be resolved against the installation root.
     */
    public function testAbsoluteExistingFilePasses(): void
    {
        $this->validate(__FILE__, dirname(__DIR__));
        $this->addToAssertionCount(1);
    }

    /**
     * Relative paths must resolve against the installation root, not the test process directory.
     */
    public function testRelativeExistingFileUsesInstallationRoot(): void
    {
        $this->validate(basename(__FILE__), __DIR__);
        $this->addToAssertionCount(1);
    }

    /**
     * A missing file must fail before any lane or run record is created.
     */
    public function testMissingExecutableFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Browser executable is not a file');
        $this->validate('missing-browser.exe', __DIR__);
    }

    /**
     * A directory is not a browser executable even when it exists.
     */
    public function testDirectoryFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validate(__DIR__, __DIR__);
    }

    /**
     * Capability implementations must not bypass the check by returning no executable.
     */
    public function testNullExecutableFails(): void
    {
        $this->expectException(RuntimeException::class);
        $this->validate(null, __DIR__);
    }
}
