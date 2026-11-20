<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\BDTApp;
use axenox\BDT\Actions\RunTest;
use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\Common\GeneratedBehatConfig;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers what RunTest hands to the coordinator manager before generating its config.
 *
 * WHY: --base_url means two different things. For an attached (remote) browser it is the manager's own
 * base_url setting; for a browser BDT launches itself it is only the Mink base_url. Handing it to a local
 * manager is rejected as an unknown setting, and writing it into the wrong place silently points the
 * browser at the wrong application URL. An attached browser must also never receive a local executable.
 *
 * The workbench is a real, unstarted one; the browser settings are set in memory on its BDT app config. The
 * task, transaction and result stream handed to performImmediately() are plain value objects of the action
 * contract, not workbench services, so they stay stubs.
 */
class RunTestInteractiveConfigTest extends TestCase
{
    private const FORMATTER = 'axenox\\BDT\\Behat\\DatabaseFormatter\\DatabaseFormatterExtension';

    // Unique file-name prefix of the throwaway base configs, and how old a leftover must be before the sweep in
    // setUpBeforeClass() removes it (the age keeps it away from a test running concurrently in another process).
    private const CONFIG_PREFIX = 'behat_phpunit_';
    private const LEFTOVER_MIN_AGE_SECONDS = 3600;

    private string $behatConfig = '';

    /**
     * Removes throwaway base configs that EARLIER runs left in the installation root.
     *
     * WHY: tearDown() removes this run's file after every test, failed or not - but a process that dies
     * mid-test (fatal error, killed runner) never reaches it, and the file would then stay in the real
     * installation root next to behat.yml for good. Only files with the unique prefix and a minimum age are
     * touched.
     */
    public static function setUpBeforeClass(): void
    {
        $root = self::newWorkbench()->getInstallationPath();
        foreach (glob($root . DIRECTORY_SEPARATOR . self::CONFIG_PREFIX . '*.yml') ?: [] as $leftover) {
            if (is_file($leftover) && time() - (int) @filemtime($leftover) > self::LEFTOVER_MIN_AGE_SECONDS) {
                @unlink($leftover);
            }
        }
    }

    /**
     * Creates a throwaway base config inside the REAL installation root.
     *
     * WHY: behat_config is resolved in performImmediately() before chrome_path and must live in the
     * installation root (the same rule as RunParallel). A test that only needs to get past that check to
     * reach the chrome_path validation therefore needs a config that satisfies the rule - an arbitrary
     * existing file elsewhere is refused. The unique name keeps parallel test runs from sharing it, and
     * tearDown removes it even when the test fails.
     */
    protected function setUp(): void
    {
        $this->behatConfig = self::newWorkbench()->getInstallationPath() . DIRECTORY_SEPARATOR
            . self::CONFIG_PREFIX . bin2hex(random_bytes(6)) . '.yml';
        file_put_contents($this->behatConfig, "default: ~\n");
    }

    protected function tearDown(): void
    {
        if ($this->behatConfig !== '') {
            @unlink($this->behatConfig);
        }
    }

    private static function newWorkbench(): Workbench
    {
        return new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-interactive-config-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
    }

    /**
     * A real workbench whose BDT app config carries the given browser settings, in memory only.
     *
     * The default selects a locally launched browser, so a test does not depend on which BROWSER.MANAGER the
     * machine running it has configured.
     *
     * @param array<string,mixed> $options App config options to override
     */
    private function workbench(array $options = []): WorkbenchInterface
    {
        $options = $options ?: [
            'BROWSER.MANAGER' => LocalChromeManager::class,
            'BROWSER.MANAGER_CONFIG' => ['executable' => 'base\\chrome.exe']
        ];
        $workbench = self::newWorkbench();
        $config = $workbench->getApp(BDTApp::APP_ALIAS)->getConfig();
        foreach ($options as $key => $value) {
            $config->setOption($key, $value);
        }
        // The shipped value may be overridden by the installation; an empty one means "root behat.yml".
        $config->setOption('PARALLEL.BEHAT_CONFIG', '');
        return $workbench;
    }

    /**
     * Builds a REAL RunTest around the real workbench.
     *
     * WHY NOT A MOCK OF THE ACTION: mocking the class under test only to hand it a workbench leaves a mock
     * with no expectations (PHPUnit flags it) and tests a subclass instead of the action itself.
     */
    private function action(WorkbenchInterface $workbench): RunTest
    {
        return new RunTest($workbench->getApp(BDTApp::APP_ALIAS));
    }

    /**
     * Exercises immediate validation without executing the deferred browser or Behat run.
     */
    private function validateInputs(WorkbenchInterface $workbench, array $parameters): array
    {
        $action = $this->action($workbench);
        $task = $this->createStub(TaskInterface::class);
        $task->method('hasParameter')->willReturnCallback(fn($name) => array_key_exists($name, $parameters));
        $task->method('getParameter')->willReturnCallback(fn($name) => $parameters[$name] ?? null);
        $method = new \ReflectionMethod(RunTest::class, 'performImmediately');
        $method->setAccessible(true);

        return $method->invoke(
            $action,
            $task,
            $this->createStub(DataTransactionInterface::class),
            $this->createStub(ResultMessageStreamInterface::class)
        );
    }

    // --- buildCoordinatorOverrides ---

    public function testBaseUrlIsPassedToAnAttachedManager(): void
    {
        $this->assertSame(
            ['endpoint' => 'http://localhost:9405', 'base_url' => 'http://app/'],
            RunTest::buildCoordinatorOverrides(true, 'http://localhost:9405', 'http://app/', '')
        );
    }

    public function testBaseUrlIsNotPassedToALocalManager(): void
    {
        $this->assertSame(
            ['executable' => 'chrome.exe'],
            RunTest::buildCoordinatorOverrides(false, '', 'http://app/', 'chrome.exe')
        );
    }

    /**
     * Attached managers must not receive the local-only executable even when both CLI options are set.
     */
    public function testChromePathIsIgnoredForAnAttachedManager(): void
    {
        $overrides = RunTest::buildCoordinatorOverrides(true, 'http://localhost:9405', 'http://app/', 'missing\\chrome.exe');
        $this->assertSame(['endpoint' => 'http://localhost:9405', 'base_url' => 'http://app/'], $overrides);
        $workbench = $this->workbench([
            'BROWSER.MANAGER' => LocalChromeManager::class,
            'BROWSER.MANAGER_CONFIG' => ['executable' => 'configured\\chrome.exe']
        ]);
        $manager = BrowserManagerFactory::create($workbench, new UxonObject($overrides), RemoteChromeManager::class);
        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $extensions = GeneratedBehatConfig::buildExtensions($manager, 0, '', 'missing\\chrome.exe', 'http://localhost:9405', 'http://app/');
        $this->assertArrayNotHasKey('executable', $extensions[self::FORMATTER]['browser']['config']);
    }

    /**
     * Locally owned browsers still receive the executable selected by the operator.
     */
    public function testChromePathIsPassedToALocalManager(): void
    {
        $overrides = RunTest::buildCoordinatorOverrides(false, '', '', 'custom\\chrome.exe');
        $this->assertSame(['executable' => 'custom\\chrome.exe'], $overrides);
        $manager = BrowserManagerFactory::create($this->workbench(), new UxonObject($overrides));
        $this->assertSame('custom\\chrome.exe', $manager->getExecutable());
    }

    /**
     * An unused path must not be validated before the remote CLI run starts.
     */
    public function testForcedRemoteIgnoresMissingChromePathDuringImmediateValidation(): void
    {
        $workbench = $this->workbench();
        $arguments = $this->validateInputs($workbench, [
            'tags' => '@test_7',
            'behat_config' => $this->behatConfig,
            'chrome_path' => 'missing\\chrome.exe',
            'chrome_endpoint' => 'http://localhost:9405'
        ]);
        $this->assertSame('missing\\chrome.exe', $arguments[2]);
        $this->assertSame('http://localhost:9405', $arguments[6]);
    }

    /**
     * A configured attached manager must also ignore an unused, nonexistent local path.
     */
    public function testConfiguredRemoteIgnoresMissingChromePathDuringImmediateValidation(): void
    {
        $workbench = $this->workbench(['BROWSER.MANAGER' => RemoteChromeManager::class]);
        $arguments = $this->validateInputs($workbench, [
            'tags' => '@test_7',
            'behat_config' => $this->behatConfig,
            'chrome_path' => 'missing\\chrome.exe'
        ]);
        $this->assertSame('missing\\chrome.exe', $arguments[2]);
    }

    /**
     * Local runs must still reject a missing explicit executable before starting any browser.
     */
    public function testLocalRunRejectsMissingChromePathDuringImmediateValidation(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('chrome_path is not a file');
        $this->validateInputs($this->workbench(), [
            'tags' => '@test_7',
            'behat_config' => $this->behatConfig,
            'chrome_path' => 'missing\\chrome.exe'
        ]);
    }

    /**
     * A base config outside the installation root is refused, because the generated config imports it
     * by file name only and would silently get the root behat.yml instead.
     */
    public function testBehatConfigOutsideTheInstallationRootIsRefusedBeforeChromePath(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('behat_config must live in the installation root');
        $this->validateInputs($this->workbench(), [
            'tags' => '@test_7',
            'behat_config' => __FILE__,
            'chrome_path' => 'missing\\chrome.exe'
        ]);
    }

    /**
     * A tag expression the rerun console would refuse is refused when the run starts, before any port,
     * run row or browser - the rerun button would otherwise answer "Command not allowed!" with no reason.
     */
    public function testATagExpressionTheRerunConsoleWouldRefuseIsRefusedAtStart(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--tags value cannot be re-run from the Test Runs console');
        $this->validateInputs($this->workbench(), [
            'tags' => '@a;b',
            'behat_config' => $this->behatConfig
        ]);
    }

    /**
     * Same for a value the endpoint normalizer lets through (a URL path may contain ";" or "$") but the
     * console would refuse.
     */
    public function testAnEndpointTheRerunConsoleWouldRefuseIsRefusedAtStart(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('--chrome_endpoint value cannot be re-run from the Test Runs console');
        $this->validateInputs($this->workbench(), [
            'tags' => '@test_7',
            'behat_config' => $this->behatConfig,
            'chrome_endpoint' => 'http://localhost:9405/a;b'
        ]);
    }

    /**
     * The remote CLI case must tell the operator why the supplied executable is ignored.
     */
    public function testForcedRemoteReportsIgnoredChromePathInRunOutput(): void
    {
        $action = $this->action($this->workbench());
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);
        $output = $method->invoke($action, '', '', 'missing\\chrome.exe', '', '@test_7', '', 'http://localhost:9405', '');

        $this->assertSame("===== BDT interactive run =====\n", $output->current());
        $output->next();
        $this->assertSame("INFO: --chrome_path ignored because --chrome_endpoint is set.\n", $output->current());
    }

    /**
     * An attached manager from app configuration also explains why the local path is unused.
     */
    public function testConfiguredRemoteReportsIgnoredChromePathInRunOutput(): void
    {
        $workbench = $this->workbench([
            'BROWSER.MANAGER' => RemoteChromeManager::class,
            'BROWSER.MANAGER_CONFIG' => ['endpoint' => 'http://localhost:9405', 'base_url' => 'http://app/']
        ]);
        $action = $this->action($workbench);
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);
        $output = $method->invoke($action, '', '', 'missing\\chrome.exe', '', '@test_7');

        $this->assertSame("===== BDT interactive run =====\n", $output->current());
        $output->next();
        $this->assertSame("INFO: --chrome_path ignored because the configured browser is attached.\n", $output->current());
    }

    public function testALocalManagerRejectsBaseUrlAsUnknownSetting(): void
    {
        // This is exactly what buildCoordinatorOverrides() must prevent for a local manager.
        $this->expectException(UxonMapError::class);
        BrowserManagerFactory::create($this->workbench(), new UxonObject(['base_url' => 'http://app/']));
    }

    public function testCoordinatorForALocalManagerWithBaseUrlIsBuildable(): void
    {
        $overrides = RunTest::buildCoordinatorOverrides(false, '', 'http://app/', '');

        $this->assertInstanceOf(LocalChromeManager::class, BrowserManagerFactory::create($this->workbench(), new UxonObject($overrides)));
    }

}
