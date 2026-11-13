<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Actions;

use axenox\BDT\Actions\RunTest;
use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\Common\GeneratedBehatConfig;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
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
 */
class RunTestInteractiveConfigTest extends TestCase
{
    private const FORMATTER = '\\axenox\\BDT\\Behat\\DatabaseFormatter\\DatabaseFormatterExtension';

    private function workbench(array $options = ['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']): WorkbenchInterface
    {
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('hasOption')->willReturnCallback(fn(string $key) => array_key_exists($key, $options));
        $config->method('getOption')->willReturnCallback(fn(string $key) => $options[$key] ?? null);
        $app = $this->createStub(AppInterface::class);
        $app->method('getConfig')->willReturn($config);
        $workbench = $this->createStub(WorkbenchInterface::class);
        $workbench->method('getApp')->willReturn($app);
        return $workbench;
    }

    /**
     * Exercises immediate validation without executing the deferred browser or Behat run.
     */
    private function validateInputs(WorkbenchInterface $workbench, array $parameters): array
    {
        $action = $this->getMockBuilder(RunTest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkbench'])
            ->getMock();
        $action->method('getWorkbench')->willReturn($workbench);
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
        $workbench->method('getInstallationPath')->willReturn(__DIR__);
        $arguments = $this->validateInputs($workbench, [
            'tags' => 'test_7',
            'behat_config' => __FILE__,
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
        $workbench->method('getInstallationPath')->willReturn(__DIR__);
        $arguments = $this->validateInputs($workbench, [
            'tags' => 'test_7',
            'behat_config' => __FILE__,
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
            'tags' => 'test_7',
            'behat_config' => __FILE__,
            'chrome_path' => 'missing\\chrome.exe'
        ]);
    }

    /**
     * The remote CLI case must tell the operator why the supplied executable is ignored.
     */
    public function testForcedRemoteReportsIgnoredChromePathInRunOutput(): void
    {
        $action = (new \ReflectionClass(RunTest::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);
        $output = $method->invoke($action, '', '', 'missing\\chrome.exe', '', 'test_7', '', 'http://localhost:9405', '');

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
        $action = $this->getMockBuilder(RunTest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getWorkbench'])
            ->getMock();
        $action->method('getWorkbench')->willReturn($workbench);
        $method = new \ReflectionMethod(RunTest::class, 'performDeferred');
        $method->setAccessible(true);
        $output = $method->invoke($action, '', '', 'missing\\chrome.exe', '', 'test_7');

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
