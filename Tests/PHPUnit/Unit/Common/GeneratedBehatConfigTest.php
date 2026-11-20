<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\BrowserManagers\BrowserManagerFactory;
use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\Common\GeneratedBehatConfig;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Pins the shared config contract so interactive and scheduled browser settings cannot drift.
 */
class GeneratedBehatConfigTest extends TestCase
{
    private const MINK = 'Behat\\MinkExtension';
    private const FORMATTER = DatabaseFormatterExtension::class;

    /**
     * Supplies real managers with isolated configuration without starting a browser.
     *
     * The workbench is a real, unstarted one; the manager settings are set in memory on its BDT app config,
     * which replaces whatever the machine running the test has configured.
     */
    private function workbench(): WorkbenchInterface
    {
        $workbench = new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-generated-config-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]);
        $config = $workbench->getApp('axenox.BDT')->getConfig();
        $config->setOption('BROWSER.MANAGER', LocalChromeManager::class);
        $config->setOption('BROWSER.MANAGER_CONFIG', ['executable' => 'base\\chrome.exe']);
        return $workbench;
    }

    /**
     * Keeps the local branch on the same validated manager used by the actions.
     */
    private function local(): LocalChromeManager
    {
        return BrowserManagerFactory::create($this->workbench(), new UxonObject(['port' => 9201]));
    }

    /**
     * Exercises remote URL normalization without contacting an endpoint.
     */
    private function remote(): RemoteChromeManager
    {
        return BrowserManagerFactory::create(
            $this->workbench(),
            new UxonObject(['endpoint' => 'http://localhost:9405/', 'base_url' => 'http://localhost/exface/exface']),
            RemoteChromeManager::class
        );
    }

    /**
     * Prevents local --base_url from becoming an unsupported local-manager setting.
     */
    public function testLocalBaseUrlGoesIntoTheMinkBaseUrlOnly(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions($this->local(), 9201, 'profile\\interactive9201', '', '', 'http://app/');

        $this->assertSame([
            self::MINK => [
                'sessions' => ['CHROME_DEBUG_API' => ['chrome' => ['api_url' => 'http://127.0.0.1:9201']]],
                'base_url' => 'http://app/'
            ],
            self::FORMATTER => [
                'browser' => ['manager' => null, 'config' => ['port' => 9201, 'user_data_dir' => 'profile\\interactive9201']]
            ]
        ], $extensions);
    }

    /**
     * Keeps the base config's application URL and includes an executable only when supplied.
     */
    public function testLocalWithoutBaseUrlLeavesTheMinkBaseUrlToTheBaseConfig(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions($this->local(), 9201, 'profile\\interactive9201', 'chrome.exe');

        $this->assertArrayNotHasKey('base_url', $extensions[self::MINK]);
        $this->assertSame('chrome.exe', $extensions[self::FORMATTER]['browser']['config']['executable']);
        $this->assertArrayNotHasKey('run_uid', $extensions[self::FORMATTER]);
        $this->assertArrayNotHasKey('lane_id', $extensions[self::FORMATTER]);
    }

    /**
     * Keeps forced remote children on the CLI-selected manager and its browser-facing URL.
     */
    public function testAttachedBrowserFromTheCliWritesEndpointAndBaseUrlAndForcesTheClass(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions(
            $this->remote(), 0, '', 'ignored\\chrome.exe', 'http://localhost:9405', 'http://localhost/exface/exface'
        );

        $this->assertSame('http://localhost:9405', $extensions[self::MINK]['sessions']['CHROME_DEBUG_API']['chrome']['api_url']);
        $this->assertSame('http://localhost/exface/exface/', $extensions[self::MINK]['base_url']);
        $this->assertSame(
            [
                'manager' => RemoteChromeManager::class,
                'config' => ['endpoint' => 'http://localhost:9405', 'base_url' => 'http://localhost/exface/exface']
            ],
            $extensions[self::FORMATTER]['browser']
        );
        $this->assertArrayNotHasKey('run_uid', $extensions[self::FORMATTER]);
        $this->assertArrayNotHasKey('lane_id', $extensions[self::FORMATTER]);
    }

    /**
     * Avoids copying configured remote settings into overrides that belong to the child factory.
     */
    public function testAttachedBrowserFromConfigWritesNoCliValuesAndLeavesTheClassToTheChild(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions($this->remote(), 0, '');

        $this->assertSame(['manager' => null, 'config' => []], $extensions[self::FORMATTER]['browser']);
        $this->assertSame('http://localhost/exface/exface/', $extensions[self::MINK]['base_url']);
    }

    /**
     * Detects serialization changes that would alter lane identities or Windows paths after parsing.
     */
    #[DataProvider('laneExecutables')]
    public function testLaneConfigRoundTripPreservesAllValues(string $chromePath): void
    {
        $profile = "data\\axenox\\BDT\\chrome_profiles\\run's-id_lane2";
        $extensions = GeneratedBehatConfig::buildExtensions(
            $this->local(), 9252, $profile, $chromePath, '', '', 'run-uid', 'run-uid_lane2'
        );
        $browserConfig = ['port' => 9252, 'user_data_dir' => $profile];
        if ($chromePath !== '') {
            $browserConfig['executable'] = $chromePath;
        }
        $expected = [
            'imports' => ['Custom Behat.yml'],
            'default' => ['extensions' => [
                self::MINK => [
                    'sessions' => ['CHROME_DEBUG_API' => ['chrome' => ['api_url' => 'http://127.0.0.1:9252']]]
                ],
                DatabaseFormatterExtension::class => [
                    'browser' => ['manager' => null, 'config' => $browserConfig],
                    'run_uid' => 'run-uid',
                    'lane_id' => 'run-uid_lane2'
                ]
            ]]
        ];
        $path = tempnam(sys_get_temp_dir(), 'bdt_config_');
        $this->assertNotFalse($path);
        try {
            $header = 'AUTO-GENERATED lane config - overwritten every run. Do not edit by hand.';
            $this->assertSame($path, GeneratedBehatConfig::write($path, 'Custom Behat.yml', $header, $extensions));
            $yaml = file_get_contents($path);
            $this->assertStringStartsWith('# ' . $header . "\n", $yaml);
            $this->assertSame($expected, Yaml::parse($yaml));
        } finally {
            unlink($path);
        }
    }

    /**
     * Checks both inheritance from app config and an explicit path containing YAML-sensitive characters.
     */
    public static function laneExecutables(): array
    {
        return [
            'configured executable is not copied' => [''],
            'CLI executable survives unchanged' => ["C:\\Program Files\\Tester's Chrome\\chrome.exe"]
        ];
    }

    /**
     * Ensures each attach-mode identity is emitted only when that parameter was supplied.
     */
    public function testRunAndLaneIdentitiesAreIndependentlyOptional(): void
    {
        $runOnly = GeneratedBehatConfig::buildExtensions($this->local(), 9252, 'profile', '', '', '', 'run-uid');
        $laneOnly = GeneratedBehatConfig::buildExtensions($this->local(), 9252, 'profile', '', '', '', null, 'lane2');

        $this->assertSame('run-uid', $runOnly[DatabaseFormatterExtension::class]['run_uid']);
        $this->assertArrayNotHasKey('lane_id', $runOnly[DatabaseFormatterExtension::class]);
        $this->assertSame('lane2', $laneOnly[DatabaseFormatterExtension::class]['lane_id']);
        $this->assertArrayNotHasKey('run_uid', $laneOnly[DatabaseFormatterExtension::class]);
    }

    /**
     * A RunTest run is an attach-mode child: run_uid and lane_id are always written together, under the
     * same extension key the base behat.yml uses (no leading backslash), so the keys merge textually.
     */
    public function testInteractiveRunWritesRunAndLaneIdentityUnderTheBaseConfigKey(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions(
            $this->local(), 9201, 'profile\\interactive9201', '', '', '', 'run-uid', GeneratedBehatConfig::interactiveLaneId('run-uid', 3)
        );

        $this->assertArrayHasKey(DatabaseFormatterExtension::class, $extensions);
        $this->assertArrayNotHasKey('\\' . DatabaseFormatterExtension::class, $extensions);
        $this->assertSame('run-uid', $extensions[DatabaseFormatterExtension::class]['run_uid']);
        $this->assertSame('run-uid_interactive3', $extensions[DatabaseFormatterExtension::class]['lane_id']);
    }

    /**
     * Without a reserved slot the lane_id would not separate concurrent runs, so it is refused.
     */
    public function testInteractiveLaneIdRequiresASlot(): void
    {
        $this->expectException(\exface\Core\Exceptions\RuntimeException::class);
        GeneratedBehatConfig::interactiveLaneId('run-uid', 0);
    }

    /**
     * Keeps interactive headers, imports and extension values unchanged through the shared writer.
     */
    public function testInteractiveConfigRoundTripPreservesAllValues(): void
    {
        $extensions = GeneratedBehatConfig::buildExtensions($this->local(), 9201, 'profile\\interactive9201');
        $path = tempnam(sys_get_temp_dir(), 'bdt_config_');
        $this->assertNotFalse($path);
        try {
            $header = 'AUTO-GENERATED interactive run config - overwritten per run. Do not edit by hand.';
            GeneratedBehatConfig::write($path, __DIR__ . DIRECTORY_SEPARATOR . 'Behat.yml', $header, $extensions);
            $yaml = file_get_contents($path);
            $this->assertStringStartsWith('# ' . $header . "\n", $yaml);
            $this->assertSame([
                'imports' => ['Behat.yml'],
                'default' => ['extensions' => $extensions]
            ], Yaml::parse($yaml));
        } finally {
            unlink($path);
        }
    }
}
