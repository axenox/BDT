<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\AbstractBrowserManager;
use axenox\BDT\Behat\BrowserManagers\BrowserStartResult;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the isAlive() confirmation policy that decides whether recovery may kill a browser.
 *
 * WHY: a single failed fast probe must never be enough to declare a slow-but-healthy browser dead -
 * that was the cause of needless restarts under load. The policy lives in AbstractBrowserManager, so
 * it is tested with a scripted probe instead of a real browser.
 */
class AbstractBrowserManagerHealthTest extends TestCase
{
    private function createManager(array $probeResults, bool $hasSession = true): ScriptedProbeManager
    {
        return new ScriptedProbeManager($this->createStub(WorkbenchInterface::class), $probeResults, $hasSession);
    }

    public function testAnswerOnFirstFastProbeIsAliveWithoutConfirmation(): void
    {
        $manager = $this->createManager([true]);

        $this->assertTrue($manager->isAlive());
        $this->assertSame([2.0], $manager->probeTimeouts);
    }

    public function testSlowButAnsweringBrowserIsAliveAfterConfirmationProbe(): void
    {
        $manager = $this->createManager([false, true]);

        $this->assertTrue($manager->isAlive());
        $this->assertSame([2.0, 4.0], $manager->probeTimeouts);
    }

    public function testBrowserIsDeadOnlyAfterFastProbeAndAllConfirmationProbesFail(): void
    {
        $manager = $this->createManager([false, false, false]);

        $this->assertFalse($manager->isAlive());
        $this->assertSame([2.0, 4.0, 4.0], $manager->probeTimeouts);
    }

    public function testWithoutActiveSessionNothingIsProbed(): void
    {
        $manager = $this->createManager([true], false);

        $this->assertFalse($manager->isAlive());
        $this->assertSame([], $manager->probeTimeouts);
    }
}

/**
 * Minimal manager whose probe() answers from a script, so the shared health policy runs in isolation.
 */
class ScriptedProbeManager extends AbstractBrowserManager
{
    /** @var float[] Timeouts of every probe() call in order */
    public array $probeTimeouts = [];

    public function __construct(WorkbenchInterface $workbench, private array $probeResults, private bool $hasSession)
    {
        parent::__construct($workbench);
    }

    public function validateConfig(): void
    {
    }

    protected function doStart(): BrowserStartResult
    {
        return new BrowserStartResult(9222, null, 0.0);
    }

    protected function probe(float $timeoutSeconds): bool
    {
        $this->probeTimeouts[] = $timeoutSeconds;
        return (bool) array_shift($this->probeResults);
    }

    public function hasActiveSession(): bool
    {
        return $this->hasSession;
    }

    public function stop(): void
    {
    }

    public function getApiUrl(): string
    {
        return 'http://127.0.0.1:9222';
    }

    public function describe(): string
    {
        return 'scripted test browser';
    }
}
