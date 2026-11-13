<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers the single source of truth for the local DevTools URL and the port-band defaults.
 *
 * WHY: RunTest and RunParallel write the Mink api_url into generated configs, while the manager derives
 * the same URL at run time. If the two ever differ (localhost vs 127.0.0.1), Windows pays an IPv6 lookup
 * delay on every connection - so both must come from LocalChromeManager::buildApiUrl().
 */
class LocalChromeManagerApiUrlTest extends TestCase
{
    private function local(array $config): LocalChromeManager
    {
        $manager = new LocalChromeManager($this->createStub(WorkbenchInterface::class));
        $manager->importUxonObject(new UxonObject($config));
        return $manager;
    }

    public function testBuildApiUrlUsesTheLoopbackAddressNotLocalhost(): void
    {
        $this->assertSame('http://127.0.0.1:9301', LocalChromeManager::buildApiUrl(9301));
    }

    public function testManagerApiUrlMatchesBuildApiUrlForTheConfiguredPort(): void
    {
        $manager = $this->local(['executable' => 'chrome.exe', 'port' => 9302]);

        $this->assertSame(LocalChromeManager::buildApiUrl(9302), $manager->getApiUrl());
    }

    public function testManagerApiUrlFallsBackToTheDefaultPortBeforeAnyPortIsConfigured(): void
    {
        $manager = $this->local(['executable' => 'chrome.exe']);

        $this->assertSame(LocalChromeManager::buildApiUrl(9222), $manager->getApiUrl());
    }

    public function testPortBandsFallBackToTheDocumentedDefaults(): void
    {
        $manager = $this->local(['executable' => 'chrome.exe']);

        $this->assertSame('9201-9251', $manager->getPortBandInteractive());
        $this->assertSame('9251-9300', $manager->getPortBandScheduled());
    }

    public function testConfiguredPortBandsAreReturned(): void
    {
        $manager = $this->local([
            'executable' => 'chrome.exe',
            'port_band_interactive' => '9401-9410',
            'port_band_scheduled' => '9411-9420'
        ]);

        $this->assertSame('9401-9410', $manager->getPortBandInteractive());
        $this->assertSame('9411-9420', $manager->getPortBandScheduled());
    }
}
