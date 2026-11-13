<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\DatabaseFormatter;

use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension;
use PHPUnit\Framework\TestCase;

/**
 * Covers the mapping of the deprecated `chrome` node onto the `browser` node.
 *
 * WHY: existing hand-written behat.yml files keep the old node for one more release. The mapping is the
 * only place that still knows it, so its rules (browser wins, endpoint selects the remote manager, empty
 * values are dropped) must not drift while the rest of the code moves on.
 */
class DatabaseFormatterExtensionBrowserSettingsTest extends TestCase
{
    public function testWithoutAnyNodeNothingIsSelectedAndNoMessageIsReturned(): void
    {
        $this->assertSame([null, [], []], DatabaseFormatterExtension::resolveBrowserSettings([]));
    }

    public function testBrowserNodeIsPassedThroughUnchanged(): void
    {
        [$manager, $config, $messages] = DatabaseFormatterExtension::resolveBrowserSettings([
            'browser' => ['manager' => RemoteChromeManager::class, 'config' => ['endpoint' => 'http://a:9222']]
        ]);

        $this->assertSame(RemoteChromeManager::class, $manager);
        $this->assertSame(['endpoint' => 'http://a:9222'], $config);
        $this->assertSame([], $messages);
    }

    public function testEmptyManagerIsTreatedAsNotSet(): void
    {
        [$manager] = DatabaseFormatterExtension::resolveBrowserSettings(['browser' => ['manager' => '', 'config' => []]]);

        $this->assertNull($manager);
    }

    public function testChromeNodeMapsToBrowserConfigWithEmptyValuesDropped(): void
    {
        [$manager, $config, $messages] = DatabaseFormatterExtension::resolveBrowserSettings([
            'chrome' => [
                'port' => '9301',
                'executable' => 'chrome.exe',
                'user_data_dir' => 'profile',
                'endpoint' => null,
                'base_url' => ''
            ]
        ]);

        $this->assertNull($manager);
        $this->assertSame(['port' => '9301', 'executable' => 'chrome.exe', 'user_data_dir' => 'profile'], $config);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('DEPRECATED', $messages[0]);
    }

    public function testNonEmptyChromeEndpointSelectsTheRemoteManager(): void
    {
        [$manager, $config] = DatabaseFormatterExtension::resolveBrowserSettings([
            'chrome' => ['endpoint' => 'http://a:9222', 'base_url' => 'http://app/', 'port' => null]
        ]);

        $this->assertSame(RemoteChromeManager::class, $manager);
        $this->assertSame(['endpoint' => 'http://a:9222', 'base_url' => 'http://app/'], $config);
    }

    public function testBrowserNodeWinsOverChromeNodeAndAWarningSaysSo(): void
    {
        [$manager, $config, $messages] = DatabaseFormatterExtension::resolveBrowserSettings([
            'browser' => ['manager' => null, 'config' => ['port' => 9301]],
            'chrome' => ['endpoint' => 'http://a:9222', 'port' => 9222]
        ]);

        $this->assertNull($manager);
        $this->assertSame(['port' => 9301], $config);
        $this->assertCount(1, $messages);
        $this->assertStringContainsString('ignored', $messages[0]);
    }

    public function testChromeNodeWithOnlyEmptyValuesIsNotTreatedAsPresent(): void
    {
        $this->assertSame(
            [null, ['port' => 9301], []],
            DatabaseFormatterExtension::resolveBrowserSettings([
                'browser' => ['manager' => null, 'config' => ['port' => 9301]],
                'chrome' => ['port' => null, 'endpoint' => null]
            ])
        );
    }
}
