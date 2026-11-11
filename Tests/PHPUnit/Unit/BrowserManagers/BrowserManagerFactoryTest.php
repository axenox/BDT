<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the legacy class selection and the override precedence of BrowserManagerFactory::create().
 *
 * WHY: the class choice is the single point the BROWSER.MANAGER config key will replace, and the
 * override order decides whether per-run values (port, profile, endpoint) beat installation defaults.
 */
class BrowserManagerFactoryTest extends TestCase
{
    /**
     * Builds a workbench whose BDT app config answers from the given legacy options.
     *
     * @param array<string,mixed> $options App config options by key
     */
    private function workbenchWithConfig(array $options): WorkbenchInterface
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

    public function testWithoutEndpointTheLocalManagerIsSelected(): void
    {
        $workbench = $this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']);

        $manager = BrowserManagerFactory::create($workbench, new UxonObject(['port' => 9401]));

        $this->assertInstanceOf(LocalChromeManager::class, $manager);
    }

    public function testWithoutAnyOverridesTheLocalManagerIsSelected(): void
    {
        $workbench = $this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']);

        $this->assertInstanceOf(LocalChromeManager::class, BrowserManagerFactory::create($workbench));
    }

    public function testNonEmptyEndpointSelectsTheRemoteManagerWithoutReadingLocalConfig(): void
    {
        $workbench = $this->createMock(WorkbenchInterface::class);
        $workbench->expects($this->never())->method('getApp');

        $manager = BrowserManagerFactory::create($workbench, new UxonObject([
            'endpoint' => 'http://bdt-chrome.internal:9222',
            'base_url' => 'http://app-under-test.internal/'
        ]));

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
    }

    public function testEmptyEndpointDoesNotSelectTheRemoteManager(): void
    {
        $workbench = $this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']);

        // Local does not know "endpoint", so UxonMapError proves the local class was chosen.
        $this->expectException(UxonMapError::class);
        BrowserManagerFactory::create($workbench, new UxonObject(['endpoint' => '']));
    }

    public function testExplicitRemoteClassWinsOverTheDefaultWhenNoEndpointIsGiven(): void
    {
        $workbench = $this->createStub(WorkbenchInterface::class);

        // A remote manager without endpoint fails validation - proves the explicit class was used.
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/endpoint/');
        BrowserManagerFactory::create($workbench, new UxonObject([]), RemoteChromeManager::class);
    }

    public function testExplicitLocalClassWinsOverAnEndpointInTheOverrides(): void
    {
        $workbench = $this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']);

        // Local does not know "endpoint", so UxonMapError proves the explicit class beat the heuristic.
        $this->expectException(UxonMapError::class);
        BrowserManagerFactory::create(
            $workbench,
            new UxonObject(['endpoint' => 'http://bdt-chrome.internal:9222']),
            LocalChromeManager::class
        );
    }

    public function testClassNotImplementingTheInterfaceIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        BrowserManagerFactory::create($this->createStub(WorkbenchInterface::class), null, \stdClass::class);
    }

    public function testRuntimeOverridesWinOverTheBaseConfig(): void
    {
        $workbench = $this->workbenchWithConfig([
            'PARALLEL.CHROME_PATH' => 'base\\chrome.exe',
            'PARALLEL.CHROME_HEADLESS' => true
        ]);

        $base = BrowserManagerFactory::create($workbench);
        $overridden = BrowserManagerFactory::create($workbench, new UxonObject([
            'executable' => 'override\\chrome.exe',
            'headless' => false,
            'port' => 9401
        ]));

        $this->assertStringContainsString('headless', $base->describe());
        $this->assertStringContainsString('visible', $overridden->describe());
        $this->assertSame('http://127.0.0.1:9401', $overridden->getApiUrl());
        $this->assertSame('base\\chrome.exe', $this->executableOf($base));
        $this->assertSame('override\\chrome.exe', $this->executableOf($overridden));
    }

    public function testBaseConfigValuesSurviveWhenNotOverridden(): void
    {
        $workbench = $this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'base\\chrome.exe']);

        $manager = BrowserManagerFactory::create($workbench, new UxonObject(['port' => 9402]));

        $this->assertSame('base\\chrome.exe', $this->executableOf($manager));
        $this->assertSame('http://127.0.0.1:9402', $manager->getApiUrl());
    }

    public function testLocalConfigWithoutExecutableFailsValidation(): void
    {
        $workbench = $this->workbenchWithConfig([]);

        $this->expectException(ConfigException::class);
        BrowserManagerFactory::create($workbench);
    }

    /**
     * Reads the executable through the protected reaper hook so the test needs no config accessor.
     */
    private function executableOf(LocalChromeManager $manager): ?string
    {
        return (new \ReflectionMethod($manager, 'getBrowserExecutable'))->invoke($manager);
    }
}
