<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\Actions\RunTest;
use axenox\BDT\Behat\Common\RunLifecycle;
use axenox\BDT\Behat\Common\Traits\PortProbingTrait;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the interactive test-user slot registry: reservation, release, exhaustion and the pool size setting.
 *
 * WHY: the slot names the test user of a RunTest run. Two concurrent runs on one slot share a user row and
 * collide on optimistic locking, a slot that is never given back shrinks the pool until every run is refused,
 * and a run that falls back to the base user or a shared slot silently brings the collision back.
 *
 * The workbench is a real, unstarted one (the pool size is set in memory on its app config, never written to
 * a file). The run row is the only thing that needs a database; where a test needs the row to fail, it hands
 * the action a lifecycle whose row creation throws (see RunTest::createLifecycle()).
 *
 * The exit paths after the run row exists (normal end, crash, timeout) need Behat and a database and are
 * covered by the manual checks; the failure path after the row exists is covered in RunTestRunUidTest.
 *
 * WHICH LOCK FILES THESE TESTS TOUCH: the registry tests use their own random lock prefix, so they never
 * contend with a real run. The two tests that drive RunTest itself must use the production prefix and
 * therefore take real lock files under data/axenox/BDT/portlocks for a moment; they only use slots 1..N of a
 * pool size they set themselves and release everything in tearDown.
 *
 * NOTE ON HELD RESERVATIONS: a reservation's lock lives exactly as long as its open handle, so every
 * reservation a test wants to stay held is kept in $this->held - a discarded return value would close the
 * handle and silently release the slot.
 */
class RunTestUserSlotTest extends TestCase
{
    private string $root;

    /** Lock prefix of the registry tests; random so that no real run and no other test shares it */
    private string $prefix;

    /** @var array[] Reservations the test keeps locked */
    private array $held = [];

    /** @var object Helper exposing the trait's reservation methods */
    private object $locker;

    protected function setUp(): void
    {
        $this->prefix = 'phpunit_slot_' . bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdt_slots_' . bin2hex(random_bytes(6));
        mkdir($this->root . DIRECTORY_SEPARATOR . 'features', 0777, true);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'behat.yml', "imports:\n  - suites.yml\n");
        file_put_contents(
            $this->root . DIRECTORY_SEPARATOR . 'suites.yml',
            "default:\n  suites:\n    alpha:\n      paths:\n        - '%paths.base%/features'\n"
        );

        $this->locker = new class($this->workbench()) {
            use PortProbingTrait;

            public function __construct(private WorkbenchInterface $workbench)
            {
            }

            public function getWorkbench(): WorkbenchInterface
            {
                return $this->workbench;
            }

            public function reserve(string $prefix, int $count): ?array
            {
                return $this->reserveFreeSlot($prefix, $count);
            }

            public function release(array $reservation): void
            {
                $this->releaseReservedPort($reservation);
            }
        };
    }

    protected function tearDown(): void
    {
        $lockPaths = [];
        foreach ($this->held as $reservation) {
            $lockPaths[] = $reservation['lockPath'] ?? null;
            $this->locker->release($reservation);
        }
        // Only lock files of the random registry prefix are removed; the production lock files are shared
        // with real runs and stay.
        foreach (array_filter($lockPaths) as $path) {
            if (str_starts_with(basename($path), 'phpunit_slot_')) {
                @unlink($path);
            }
        }
        $this->removeTree($this->root);
    }

    private function removeTree(string $dir): void
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

    /**
     * Reserves a slot through the helper and keeps it locked until the test ends.
     */
    private function hold(string $prefix, int $count): ?array
    {
        $reservation = $this->locker->reserve($prefix, $count);
        if ($reservation !== null) {
            $this->held[] = $reservation;
        }
        return $reservation;
    }

    /**
     * A real, unstarted workbench; the options are set in memory on the BDT app config only.
     *
     * @param array<string,mixed> $options App config options to override for this test
     */
    private function workbench(array $options = []): Workbench
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-user-slot-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        $config = $workbench->getApp('axenox.BDT')->getConfig();
        foreach ($options as $key => $value) {
            $config->setOption($key, $value);
        }
        return $workbench;
    }

    /**
     * A real RunTest around a real workbench; $rowFailure makes creating the run row fail, the way a
     * database outage would.
     */
    private function action(WorkbenchInterface $workbench, ?\Throwable $rowFailure = null): RunTest
    {
        $app = $workbench->getApp('axenox.BDT');
        return new class($app, $rowFailure) extends RunTest {
            public function __construct(AppInterface $app, private ?\Throwable $rowFailure)
            {
                parent::__construct($app);
            }

            protected function createLifecycle(): RunLifecycle
            {
                if ($this->rowFailure === null) {
                    return parent::createLifecycle();
                }
                $failure = $this->rowFailure;
                return new class($this->getWorkbench(), 'BDT test run', $failure) extends RunLifecycle {
                    public function __construct(WorkbenchInterface $workbench, string $title, private \Throwable $failure)
                    {
                        parent::__construct($workbench, $title);
                    }

                    protected function createRunSheet(string $behatCommand): DataSheetInterface
                    {
                        throw $this->failure;
                    }
                };
            }
        };
    }

    private function lockPrefix(): string
    {
        return (new \ReflectionClassConstant(RunTest::class, 'USER_SLOT_LOCK_PREFIX'))->getValue();
    }

    /**
     * Drives the deferred phase of a tags-only run against an attached browser until it throws.
     *
     * WHY AN ATTACHED BROWSER SELECTED BY THE CLI ENDPOINT: it needs no executable or port band, and the CLI
     * value selects the manager class whatever BROWSER.MANAGER says on this machine, so the run reaches the
     * slot reservation with nothing but the arguments.
     */
    private function runUntilItThrows(int $slots, ?\Throwable $rowFailure = null): \Throwable
    {
        $workbench = $this->workbench(['TEST_USER.INTERACTIVE_SLOTS' => $slots]);
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);
        try {
            foreach ($method->invoke($this->action($workbench, $rowFailure), $this->root, $this->root . DIRECTORY_SEPARATOR . 'behat.yml', '', '', '@test_7', '', 'http://localhost:9405', 'http://app/') as $ignored) {
                // Drained only to advance the generator.
            }
        } catch (\Throwable $e) {
            return $e;
        }
        $this->fail('The deferred phase was expected to throw before reaching Behat.');
    }

    /**
     * Concurrent runs get different slots, lowest free number first.
     */
    public function testConcurrentReservationsGetDifferentSlots(): void
    {
        $first = $this->hold($this->prefix, 3);
        $second = $this->hold($this->prefix, 3);

        $this->assertSame(1, $first['slot']);
        $this->assertSame(2, $second['slot']);
    }

    /**
     * A released slot is available to the next run, so slots do not leak.
     */
    public function testReleasedSlotCanBeReservedAgain(): void
    {
        $first = $this->hold($this->prefix, 1);
        $this->assertNull($this->locker->reserve($this->prefix, 1));

        $this->locker->release($first);

        $again = $this->hold($this->prefix, 1);
        $this->assertSame(1, $again['slot']);
    }

    /**
     * Pools with different lock prefixes never block each other.
     */
    public function testPoolsWithDifferentPrefixesAreIndependent(): void
    {
        $this->hold($this->prefix, 1);

        $other = $this->hold($this->prefix . '_other', 1);

        $this->assertSame(1, $other['slot']);
    }

    /**
     * The pool size must be an integer >= 1; a present but invalid value fails instead of resizing the pool.
     */
    #[DataProvider('invalidSlotCounts')]
    public function testInvalidSlotCountIsRefused(mixed $value): void
    {
        $action = $this->action($this->workbench(['TEST_USER.INTERACTIVE_SLOTS' => $value]));
        $method = new \ReflectionMethod(RunTest::class, 'resolveInteractiveUserSlots');
        $method->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_USER.INTERACTIVE_SLOTS must be an integer >= 1');
        $method->invoke($action);
    }

    public static function invalidSlotCounts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'text' => ['many'],
            'fraction' => ['2.5'],
            'empty' => ['']
        ];
    }

    /**
     * Valid values are accepted as numbers or numeric strings.
     *
     * The "unset key falls back to the default" branch cannot be reached with a real workbench: the shipped
     * app config always defines the key, and a config option cannot be removed again. The shipped value is
     * checked to be valid instead.
     */
    public function testValidSlotCounts(): void
    {
        $method = new \ReflectionMethod(RunTest::class, 'resolveInteractiveUserSlots');
        $method->setAccessible(true);

        $this->assertSame(4, $method->invoke($this->action($this->workbench(['TEST_USER.INTERACTIVE_SLOTS' => 4]))));
        $this->assertSame(7, $method->invoke($this->action($this->workbench(['TEST_USER.INTERACTIVE_SLOTS' => '7']))));
        $this->assertGreaterThanOrEqual(1, $method->invoke($this->action($this->workbench())));
    }

    /**
     * With every slot taken the run is refused loudly, naming the setting - and before any run row exists.
     *
     * The action is handed a lifecycle whose row creation would fail with a different message, so getting
     * this exact message proves the refusal comes first.
     */
    public function testAllSlotsTakenFailsLoudlyBeforeTheRunRowExists(): void
    {
        // Take whatever is free of slots 1..2 (a real run may already hold one of them - then fewer are free).
        while ($this->hold($this->lockPrefix(), 2) !== null) {
        }

        $error = $this->runUntilItThrows(2, new \LogicException('The run row must not be created when no slot is free'));

        $this->assertInstanceOf(RuntimeException::class, $error);
        $this->assertStringContainsString('All 2 interactive test-user slots are in use', $error->getMessage());
        $this->assertStringContainsString('TEST_USER.INTERACTIVE_SLOTS', $error->getMessage());
        $this->assertStringNotContainsString('Run UID:', $error->getMessage());
    }

    /**
     * A slot taken for a run whose row cannot be created is free again afterwards, not kept locked.
     *
     * The action releases the slot explicitly in that path; closing the dead generator's handle would free it
     * too, so this pins the observable outcome, not the mechanism.
     */
    public function testSlotIsFreeAgainWhenRowCreationFails(): void
    {
        // The lowest free slot right now is the one the run is going to take; it must be free again after.
        $probe = $this->locker->reserve($this->lockPrefix(), 3);
        $this->assertNotNull($probe, 'No free slot among 1..3 - a real run is holding them');
        $this->locker->release($probe);

        $error = $this->runUntilItThrows(3, new RuntimeException('Simulated database outage'));

        $this->assertSame('Simulated database outage', $error->getMessage());
        $this->assertSame($probe['slot'], $this->hold($this->lockPrefix(), 3)['slot']);
    }
}
