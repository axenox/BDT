<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\Configuration;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\ConfigurationInterface;
use exface\Core\Interfaces\Log\LoggerInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers class selection, base-config application and override precedence of BrowserManagerFactory::create().
 *
 * WHY: the class choice (explicit > BROWSER.MANAGER > legacy rule > default) decides which settings are
 * valid at all, local defaults must never leak into a remote manager, and the legacy keys must keep
 * working with exactly one deprecation warning per process.
 */
class BrowserManagerFactoryTest extends TestCase
{
    private const LOCAL = '\\axenox\\BDT\\Behat\\BrowserManagers\\LocalChromeManager';
    private const REMOTE = '\\axenox\\BDT\\Behat\\BrowserManagers\\RemoteChromeManager';
    private array $scopeFiles = [];

    /**
     * Resets the once-per-process warning flag so every test starts as a fresh process would.
     *
     * {@inheritDoc}
     * @see TestCase::setUp()
     */
    protected function setUp(): void
    {
        (new \ReflectionProperty(BrowserManagerFactory::class, 'legacyWarningLogged'))->setValue(null, false);
    }

    /**
     * Removes only files created by the scoped configuration fixture, including after test failures.
     *
     * {@inheritDoc}
     * @see TestCase::tearDown()
     */
    protected function tearDown(): void
    {
        foreach ($this->scopeFiles as $path) {
            if (is_file($path) && ! unlink($path)) {
                throw new RuntimeException('Cannot remove temporary configuration file: ' . $path);
            }
        }
    }

    /**
     * Builds a workbench whose BDT app config answers from the given options.
     *
     * @param array<string,mixed> $options App config options by key
     */
    private function workbenchWithConfig(array $options, ?LoggerInterface $logger = null): WorkbenchInterface
    {
        $config = $this->createStub(ConfigurationInterface::class);
        $config->method('hasOption')->willReturnCallback(fn(string $key) => array_key_exists($key, $options));
        $config->method('getOption')->willReturnCallback(function (string $key) use ($options) {
            $value = $options[$key] ?? null;
            return is_array($value) ? new UxonObject($value) : $value;
        });
        $app = $this->createStub(AppInterface::class);
        $app->method('getConfig')->willReturn($config);
        $workbench = $this->createStub(WorkbenchInterface::class);
        $workbench->method('getApp')->willReturn($app);
        $workbench->method('getLogger')->willReturn($logger ?? $this->createStub(LoggerInterface::class));
        return $workbench;
    }

    /**
     * Builds a logger that must receive exactly the given number of warnings.
     */
    private function loggerExpectingWarnings(int $count): LoggerInterface
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly($count))->method('warning');
        return $logger;
    }

    /**
     * Loads real scoped config files so core's scope checks see the same provenance as production.
     *
     * A stub containing only override keys would miss a shipped selector that masks legacy settings.
     */
    private function workbenchWithShippedConfig(array $overrides, LoggerInterface $logger, ?array $userOverrides = null): WorkbenchInterface
    {
        $workbench = $this->createStub(WorkbenchInterface::class);
        $config = new Configuration($workbench);
        $configPath = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'axenox.BDT.config.json';
        $config->loadConfigFile($configPath);
        $config->loadConfigFile($this->createScopeFile($overrides), AppInterface::CONFIG_SCOPE_INSTALLATION);
        if ($userOverrides !== null) {
            $config->loadConfigFile($this->createScopeFile($userOverrides), AppInterface::CONFIG_SCOPE_USER);
        }
        $app = $this->createStub(AppInterface::class);
        $app->method('getConfig')->willReturn($config);
        $workbench->method('getApp')->willReturn($app);
        $workbench->method('getLogger')->willReturn($logger);
        return $workbench;
    }

    /**
     * Gives core a real scope file because in-memory loadConfigUxon() discards layer provenance.
     */
    private function createScopeFile(array $options): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bdt_config_');
        if ($path === false) {
            throw new RuntimeException('Cannot create temporary configuration file.');
        }
        $this->scopeFiles[] = $path;
        if (file_put_contents($path, json_encode($options, JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write temporary configuration file: ' . $path);
        }
        return $path;
    }

    private function newConfig(array $extra = []): array
    {
        return array_merge([
            'BROWSER.MANAGER' => self::LOCAL,
            'BROWSER.MANAGER_CONFIG' => ['executable' => 'base\\chrome.exe']
        ], $extra);
    }

    // --- class resolution: explicit > BROWSER.MANAGER > legacy > default ---

    public function testBrowserManagerKeySelectsTheClassEvenWithAnEndpointInTheOverrides(): void
    {
        $workbench = $this->workbenchWithConfig([
            'BROWSER.MANAGER' => self::REMOTE,
            'BROWSER.MANAGER_CONFIG' => ['endpoint' => 'http://bdt-chrome.internal:9222', 'base_url' => 'http://app.internal/']
        ]);

        $manager = BrowserManagerFactory::create($workbench, new UxonObject(['endpoint' => 'http://other.internal:9222']));

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://other.internal:9222', $manager->getApiUrl());
    }

    public function testBrowserManagerKeyBeatsTheLegacyEndpointKey(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig(['REMOTE.CHROME_ENDPOINT' => 'http://bdt-chrome.internal:9222']), $this->loggerExpectingWarnings(0));

        $this->assertInstanceOf(LocalChromeManager::class, BrowserManagerFactory::create($workbench));
    }

    public function testExplicitClassBeatsBrowserManagerKey(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig());

        $manager = BrowserManagerFactory::create(
            $workbench,
            new UxonObject(['endpoint' => 'http://bdt-chrome.internal:9222', 'base_url' => 'http://app.internal/']),
            RemoteChromeManager::class
        );

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
    }

    public function testLegacyEndpointKeySelectsTheRemoteManagerWhenBrowserManagerIsUnset(): void
    {
        $workbench = $this->workbenchWithConfig([
            'REMOTE.CHROME_ENDPOINT' => 'http://bdt-chrome.internal:9222',
            'REMOTE.BASE_URL' => 'http://app.internal'
        ], $this->loggerExpectingWarnings(1));

        $manager = BrowserManagerFactory::create($workbench);

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://bdt-chrome.internal:9222', $manager->getApiUrl());
        $this->assertSame('http://app.internal/', $manager->getBaseUrl());
    }

    public function testEmptyBrowserManagerKeyFallsBackToTheLegacyRule(): void
    {
        $workbench = $this->workbenchWithConfig([
            'BROWSER.MANAGER' => '',
            'PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe'
        ]);

        $this->assertInstanceOf(LocalChromeManager::class, BrowserManagerFactory::create($workbench));
    }

    public function testNonEmptyEndpointInTheOverridesSelectsTheRemoteManagerWhenBrowserManagerIsUnset(): void
    {
        $workbench = $this->workbenchWithConfig([]);

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

    public function testWithoutAnyKeysTheLocalManagerIsTheDefaultButFailsValidationWithoutExecutable(): void
    {
        $workbench = $this->workbenchWithConfig([]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/executable/');
        BrowserManagerFactory::create($workbench);
    }

    public function testExplicitRemoteClassWinsOverTheDefaultWhenNoEndpointIsGiven(): void
    {
        $workbench = $this->workbenchWithConfig([]);

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

    // --- BROWSER.MANAGER_CONFIG is applied only to the configured class ---

    public function testBrowserManagerConfigIsAppliedToTheConfiguredClass(): void
    {
        $workbench = $this->workbenchWithConfig([
            'BROWSER.MANAGER' => self::LOCAL,
            'BROWSER.MANAGER_CONFIG' => [
                'executable' => 'base\\chrome.exe',
                'headless' => false,
                'port_band_interactive' => '9401-9410',
                'port_band_scheduled' => '9411-9420'
            ]
        ], $this->loggerExpectingWarnings(0));

        $manager = BrowserManagerFactory::create($workbench);

        $this->assertSame('base\\chrome.exe', $manager->getExecutable());
        $this->assertStringContainsString('visible', $manager->describe());
        $this->assertSame('9401-9410', $manager->getPortBandInteractive());
        $this->assertSame('9411-9420', $manager->getPortBandScheduled());
    }

    public function testBrowserManagerConfigIsNotAppliedWhenAnotherClassIsForced(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig([
            'BROWSER.MANAGER_CONFIG' => ['executable' => 'base\\chrome.exe', 'port_band_interactive' => '9401-9410']
        ]));

        // The local base config (executable, port bands) would fail with UxonMapError on a remote manager.
        $manager = BrowserManagerFactory::create(
            $workbench,
            new UxonObject(['endpoint' => 'http://bdt-chrome.internal:9222', 'base_url' => 'http://app.internal/']),
            RemoteChromeManager::class
        );

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
    }

    public function testBrowserManagerConfigAppliesWhenTheForcedClassEqualsTheConfiguredOne(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig());

        $manager = BrowserManagerFactory::create($workbench, null, LocalChromeManager::class);

        $this->assertSame('base\\chrome.exe', $manager->getExecutable());
    }

    public function testRemoteConfigWithAPortBandFailsWithAUxonMapError(): void
    {
        $workbench = $this->workbenchWithConfig([
            'BROWSER.MANAGER' => self::REMOTE,
            'BROWSER.MANAGER_CONFIG' => [
                'endpoint' => 'http://bdt-chrome.internal:9222',
                'base_url' => 'http://app.internal/',
                'port_band_interactive' => '9201-9251'
            ]
        ]);

        $this->expectException(UxonMapError::class);
        $this->expectExceptionMessageMatches('/port_band_interactive/i');
        BrowserManagerFactory::create($workbench);
    }

    public function testClassNotExtendingTheAbstractManagerIsRejectedWithKeyAndValue(): void
    {
        $workbench = $this->workbenchWithConfig(['BROWSER.MANAGER' => '\\stdClass']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/BROWSER\.MANAGER.*stdClass/');
        BrowserManagerFactory::create($workbench);
    }

    public function testExplicitClassNotExtendingTheAbstractManagerIsRejected(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/stdClass/');
        BrowserManagerFactory::create($this->workbenchWithConfig([]), null, \stdClass::class);
    }

    // --- legacy keys ---

    /**
     * A fresh installation needs local defaults without a spurious legacy warning.
     */
    public function testShippedDefaultsSelectLocalWithoutLegacyWarning(): void
    {
        $workbench = $this->workbenchWithShippedConfig([], $this->loggerExpectingWarnings(0));
        $manager = BrowserManagerFactory::create($workbench);

        $this->assertInstanceOf(LocalChromeManager::class, $manager);
    }

    /**
     * Installation-level legacy local settings must override shipped local defaults, once warned.
     */
    public function testLegacyLocalOverridesShippedDefaults(): void
    {
        $workbench = $this->workbenchWithShippedConfig([
            'PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe',
            'PARALLEL.CHROME_HEADLESS' => false,
            'PARALLEL.PORT_BAND_INTERACTIVE' => '9301-9310'
        ], $this->loggerExpectingWarnings(1));

        $manager = BrowserManagerFactory::create($workbench);
        $this->assertSame('legacy\\chrome.exe', $manager->getExecutable());
        $this->assertStringContainsString('visible', $manager->describe());
        $this->assertSame('9301-9310', $manager->getPortBandInteractive());
        BrowserManagerFactory::create($workbench);
    }

    /**
     * Core replaces config objects wholesale, but the legacy path must retain packaged local defaults.
     */
    public function testLegacyPathDoesNotImportAnUnselectedManagerConfigOverride(): void
    {
        $workbench = $this->workbenchWithShippedConfig([
            'BROWSER.MANAGER_CONFIG' => ['endpoint' => 'http://remote:9222'],
            'PARALLEL.CHROME_HEADLESS' => false
        ], $this->loggerExpectingWarnings(1));

        $manager = BrowserManagerFactory::create($workbench);
        $this->assertInstanceOf(LocalChromeManager::class, $manager);
        $this->assertStringContainsString('visible', $manager->describe());
    }

    /**
     * A legacy remote installation must neither launch local Chrome nor import local-only defaults.
     */
    public function testLegacyRemoteOverridesShippedLocalDefaults(): void
    {
        $workbench = $this->workbenchWithShippedConfig([
            'REMOTE.CHROME_ENDPOINT' => 'http://remote:9222',
            'REMOTE.BASE_URL' => 'http://app/'
        ], $this->loggerExpectingWarnings(1));

        $manager = BrowserManagerFactory::create($workbench);
        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://remote:9222', $manager->getApiUrl());
        $this->assertSame('http://app/', $manager->getBaseUrl());
        BrowserManagerFactory::create($workbench);
    }

    /**
     * Explicit remote configuration replaces, rather than inherits, the shipped local config object.
     */
    public function testConfiguredRemoteReplacesShippedLocalConfig(): void
    {
        $workbench = $this->workbenchWithShippedConfig([
            'BROWSER.MANAGER' => self::REMOTE,
            'BROWSER.MANAGER_CONFIG' => ['endpoint' => 'http://remote:9222', 'base_url' => 'http://app/']
        ], $this->loggerExpectingWarnings(0));

        $this->assertInstanceOf(RemoteChromeManager::class, BrowserManagerFactory::create($workbench));
    }

    /**
     * An explicit installation manager must override legacy keys even if it matches the shipped class.
     */
    public function testInstallationManagerWinsOverLegacyRemoteSettings(): void
    {
        $workbench = $this->workbenchWithShippedConfig([
            'BROWSER.MANAGER' => self::LOCAL,
            'REMOTE.CHROME_ENDPOINT' => 'http://remote:9222',
            'REMOTE.BASE_URL' => 'http://app/'
        ], $this->loggerExpectingWarnings(0));

        $this->assertInstanceOf(LocalChromeManager::class, BrowserManagerFactory::create($workbench));
    }

    /**
     * A CLI-forced remote browser must not receive the unselected shipped local defaults.
     */
    public function testForcedRemoteIgnoresShippedLocalConfig(): void
    {
        $workbench = $this->workbenchWithShippedConfig([], $this->loggerExpectingWarnings(0));
        $manager = BrowserManagerFactory::create(
            $workbench,
            new UxonObject(['endpoint' => 'http://remote:9222', 'base_url' => 'http://app/']),
            RemoteChromeManager::class
        );

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://remote:9222', $manager->getApiUrl());
    }

    /**
     * Per-run overrides remain authoritative after legacy settings overlay the shipped defaults.
     */
    public function testRuntimeExecutableOverridesLegacyAndShippedDefaults(): void
    {
        $workbench = $this->workbenchWithShippedConfig(
            ['PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe'],
            $this->loggerExpectingWarnings(1)
        );
        $manager = BrowserManagerFactory::create($workbench, new UxonObject(['executable' => 'runtime\\chrome.exe']));

        $this->assertSame('runtime\\chrome.exe', $manager->getExecutable());
    }

    /**
     * A manager selected by the user scope takes precedence over installation-level legacy keys.
     */
    public function testUserManagerWinsOverInstallationLegacySettings(): void
    {
        $workbench = $this->workbenchWithShippedConfig(
            ['REMOTE.CHROME_ENDPOINT' => 'http://legacy:9222', 'REMOTE.BASE_URL' => 'http://legacy-app/'],
            $this->loggerExpectingWarnings(0),
            ['BROWSER.MANAGER' => self::LOCAL]
        );
        $manager = BrowserManagerFactory::create($workbench);

        $this->assertInstanceOf(LocalChromeManager::class, $manager);
    }

    /**
     * User-scoped legacy keys must be recognized even when no installation override exists.
     */
    public function testUserLegacySettingsOverrideShippedManager(): void
    {
        $workbench = $this->workbenchWithShippedConfig([], $this->loggerExpectingWarnings(1), [
            'REMOTE.CHROME_ENDPOINT' => 'http://remote:9222',
            'REMOTE.BASE_URL' => 'http://app/'
        ]);
        $manager = BrowserManagerFactory::create($workbench);

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://remote:9222', $manager->getApiUrl());
    }

    /**
     * A blank selector deliberately enables the legacy path and scoped warnings name only actual keys.
     */
    public function testBlankManagerUsesLegacyAndWarningNamesOnlyHigherScopeKeys(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->callback(function (string $message): bool {
            return strpos($message, 'PARALLEL.CHROME_HEADLESS') !== false
                && strpos($message, 'PARALLEL.CHROME_PATH') === false
                && strpos($message, 'REMOTE.CHROME_ENDPOINT') === false;
        }));
        $workbench = $this->workbenchWithShippedConfig([
            'BROWSER.MANAGER' => '',
            'PARALLEL.CHROME_HEADLESS' => false
        ], $logger);

        $this->assertStringContainsString('visible', BrowserManagerFactory::create($workbench)->describe());
    }

    /**
     * A user legacy value supersedes the same installation value after core merges the layers.
     */
    public function testUserLegacyValueWinsOverInstallationLegacyValue(): void
    {
        $workbench = $this->workbenchWithShippedConfig(
            ['PARALLEL.CHROME_PATH' => 'installation\\chrome.exe'],
            $this->loggerExpectingWarnings(1),
            ['PARALLEL.CHROME_PATH' => 'user\\chrome.exe']
        );

        $this->assertSame('user\\chrome.exe', BrowserManagerFactory::create($workbench)->getExecutable());
    }

    /**
     * An explicit class and runtime endpoints must override legacy class selection and settings.
     */
    public function testForcedRemoteAndRuntimeEndpointWinOverLegacyLocalSettings(): void
    {
        $workbench = $this->workbenchWithShippedConfig(
            ['PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe'],
            $this->loggerExpectingWarnings(1)
        );
        $manager = BrowserManagerFactory::create(
            $workbench,
            new UxonObject(['endpoint' => 'http://remote:9222', 'base_url' => 'http://app/']),
            RemoteChromeManager::class
        );

        $this->assertInstanceOf(RemoteChromeManager::class, $manager);
        $this->assertSame('http://remote:9222', $manager->getApiUrl());
    }

    public function testLegacyLocalKeysAreMappedAndWarnExactlyOncePerProcess(): void
    {
        $workbench = $this->workbenchWithConfig([
            'PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe',
            'PARALLEL.CHROME_HEADLESS' => false,
            'PARALLEL.PORT_BAND_INTERACTIVE' => '9301-9310',
            'PARALLEL.PORT_BAND_SCHEDULED' => '9311-9320'
        ], $this->loggerExpectingWarnings(1));

        $first = BrowserManagerFactory::create($workbench);
        $second = BrowserManagerFactory::create($workbench);

        $this->assertSame('legacy\\chrome.exe', $first->getExecutable());
        $this->assertStringContainsString('visible', $first->describe());
        $this->assertSame('9301-9310', $first->getPortBandInteractive());
        $this->assertSame('9311-9320', $second->getPortBandScheduled());
    }

    public function testDeprecationWarningNamesTheNewKeys(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->logicalAnd(
            $this->stringContains('BROWSER.MANAGER'),
            $this->stringContains('BROWSER.MANAGER_CONFIG'),
            $this->stringContains('PARALLEL.CHROME_PATH')
        ));

        BrowserManagerFactory::create($this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe'], $logger));
    }

    public function testLegacyHeadlessIsOnlyAppliedWhenTheOptionExists(): void
    {
        $withOption = BrowserManagerFactory::create($this->workbenchWithConfig([
            'PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe',
            'PARALLEL.CHROME_HEADLESS' => true
        ]));
        $withoutOption = BrowserManagerFactory::create($this->workbenchWithConfig(['PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe']));

        $this->assertStringContainsString('headless', $withOption->describe());
        // Without the option the Xdebug fallback decides, which is headless when no debugger is attached.
        $this->assertStringContainsString(
            $this->debuggerActive() ? 'visible' : 'headless',
            $withoutOption->describe()
        );
    }

    public function testNoDeprecationWarningWhenBrowserManagerIsConfigured(): void
    {
        BrowserManagerFactory::create(
            $this->workbenchWithConfig($this->newConfig(['PARALLEL.CHROME_PATH' => 'legacy\\chrome.exe']), $this->loggerExpectingWarnings(0))
        );
        $this->addToAssertionCount(1);
    }

    // --- resolveClass ---

    public function testResolveClassFollowsTheSameOrderAsCreate(): void
    {
        $this->assertSame(
            LocalChromeManager::class,
            BrowserManagerFactory::resolveClass($this->workbenchWithConfig([]))
        );
        $this->assertSame(
            RemoteChromeManager::class,
            BrowserManagerFactory::resolveClass($this->workbenchWithConfig([]), new UxonObject(['endpoint' => 'http://a:9222']))
        );
        $this->assertSame(
            RemoteChromeManager::class,
            BrowserManagerFactory::resolveClass($this->workbenchWithConfig(['BROWSER.MANAGER' => self::REMOTE]))
        );
        $this->assertSame(
            LocalChromeManager::class,
            BrowserManagerFactory::resolveClass(
                $this->workbenchWithConfig(['BROWSER.MANAGER' => self::REMOTE]),
                null,
                LocalChromeManager::class
            )
        );
    }

    // --- runtime overrides ---

    public function testRuntimeOverridesWinOverTheBaseConfig(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig([
            'BROWSER.MANAGER_CONFIG' => ['executable' => 'base\\chrome.exe', 'headless' => true]
        ]));

        $base = BrowserManagerFactory::create($workbench);
        $overridden = BrowserManagerFactory::create($workbench, new UxonObject([
            'executable' => 'override\\chrome.exe',
            'headless' => false,
            'port' => 9401
        ]));

        $this->assertStringContainsString('headless', $base->describe());
        $this->assertStringContainsString('visible', $overridden->describe());
        $this->assertSame('http://127.0.0.1:9401', $overridden->getApiUrl());
        $this->assertSame('base\\chrome.exe', $base->getExecutable());
        $this->assertSame('override\\chrome.exe', $overridden->getExecutable());
    }

    public function testBaseConfigValuesSurviveWhenNotOverridden(): void
    {
        $workbench = $this->workbenchWithConfig($this->newConfig());

        $manager = BrowserManagerFactory::create($workbench, new UxonObject(['port' => 9402]));

        $this->assertSame('base\\chrome.exe', $manager->getExecutable());
        $this->assertSame('http://127.0.0.1:9402', $manager->getApiUrl());
    }

    public function testLocalConfigWithoutExecutableFailsValidation(): void
    {
        $workbench = $this->workbenchWithConfig(['BROWSER.MANAGER' => self::LOCAL]);

        $this->expectException(ConfigException::class);
        BrowserManagerFactory::create($workbench);
    }

    /**
     * Mirrors the Xdebug fallback LocalChromeManager uses when "headless" is not configured.
     */
    private function debuggerActive(): bool
    {
        return extension_loaded('xdebug')
            && function_exists('xdebug_is_debugger_active')
            && xdebug_is_debugger_active();
    }
}
