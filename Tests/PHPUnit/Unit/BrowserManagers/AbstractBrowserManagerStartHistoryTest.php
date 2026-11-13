<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\AbstractBrowserManager;
use axenox\BDT\Behat\BrowserManagers\BrowserStartResult;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers which start() results end up in the start history.
 *
 * WHY: the history feeds the Chrome section of the run record. Reusing an already running browser is
 * not a start; recording it would show a phantom 0 ms start in the report.
 */
class AbstractBrowserManagerStartHistoryTest extends TestCase
{
    /**
     * A real launch or attach must appear in the history exactly once.
     */
    public function testRealStartIsRecorded(): void
    {
        $manager = new ScriptedStartManager($this->createStub(WorkbenchInterface::class), [false]);

        $manager->start();

        $this->assertCount(1, $manager->getStartHistory());
    }

    /**
     * A second start() that only reuses the running browser must not add a history row.
     */
    public function testReusedBrowserIsNotRecorded(): void
    {
        $manager = new ScriptedStartManager($this->createStub(WorkbenchInterface::class), [false, true]);

        $manager->start();
        $manager->start();

        $this->assertCount(1, $manager->getStartHistory());
    }
}

/**
 * Minimal manager whose doStart() returns scripted reuse flags, so the history rule runs in isolation.
 */
class ScriptedStartManager extends AbstractBrowserManager
{
    /**
     * Takes the reuse flag for each consecutive doStart() call.
     *
     * @param bool[] $reuseFlags
     */
    public function __construct(WorkbenchInterface $workbench, private array $reuseFlags)
    {
        parent::__construct($workbench);
    }

    /**
     * Nothing to validate for the scripted manager.
     */
    public function validateConfig(): void
    {
    }

    /**
     * Returns a result whose reuse flag comes from the script.
     */
    protected function doStart(): BrowserStartResult
    {
        $result = new BrowserStartResult(9222, null, 0.0);
        $result->reused = (bool) array_shift($this->reuseFlags);
        return $result;
    }

    /**
     * Not used by these tests.
     */
    protected function probe(float $timeoutSeconds): bool
    {
        return true;
    }

    /**
     * Not used by these tests.
     */
    public function hasActiveSession(): bool
    {
        return false;
    }

    /**
     * Not used by these tests.
     */
    public function stop(): void
    {
    }

    /**
     * Not used by these tests.
     */
    public function getApiUrl(): string
    {
        return 'http://127.0.0.1:9222';
    }

    /**
     * Not used by these tests.
     */
    public function describe(): string
    {
        return 'scripted start browser';
    }
}