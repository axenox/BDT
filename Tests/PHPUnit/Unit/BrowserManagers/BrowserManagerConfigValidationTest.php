<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\LocalChromeManager;
use axenox\BDT\Behat\BrowserManagers\RemoteChromeManager;
use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\UxonMapError;
use exface\Core\Interfaces\WorkbenchInterface;
use PHPUnit\Framework\TestCase;

/**
 * Covers configuration validation of the managers, which runs before any process or network access.
 *
 * WHY: a misconfigured run must fail at startup with one message naming every missing key, instead of
 * surfacing one key at a time or as an obscure error in the middle of a run.
 */
class BrowserManagerConfigValidationTest extends TestCase
{
    private function remote(array $config): RemoteChromeManager
    {
        $manager = new RemoteChromeManager($this->createStub(WorkbenchInterface::class));
        $manager->importUxonObject(new UxonObject($config));
        return $manager;
    }

    private function local(array $config): LocalChromeManager
    {
        $manager = new LocalChromeManager($this->createStub(WorkbenchInterface::class));
        $manager->importUxonObject(new UxonObject($config));
        return $manager;
    }

    public function testRemoteWithoutAnyKeyNamesBothMissingKeysInOneException(): void
    {
        $manager = $this->remote([]);

        try {
            $manager->validateConfig();
            $this->fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            $this->assertStringContainsString('endpoint', $e->getMessage());
            $this->assertStringContainsString('base_url', $e->getMessage());
        }
    }

    public function testRemoteWithoutBaseUrlNamesOnlyBaseUrl(): void
    {
        $manager = $this->remote(['endpoint' => 'http://bdt-chrome.internal:9222']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/base_url/');
        $manager->validateConfig();
    }

    public function testRemoteWithEmptyValuesCountsThemAsMissing(): void
    {
        $manager = $this->remote(['endpoint' => '', 'base_url' => '']);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/endpoint, base_url/');
        $manager->validateConfig();
    }

    public function testCompleteRemoteConfigPassesAndNormalizesUrls(): void
    {
        $manager = $this->remote([
            'endpoint' => 'http://Bdt-Chrome.internal:9222/',
            'base_url' => 'http://app-under-test.internal'
        ]);

        $manager->validateConfig();
        $this->assertSame('http://bdt-chrome.internal:9222', $manager->getApiUrl());
        $this->assertSame('http://app-under-test.internal/', $manager->getBaseUrl());
    }

    public function testLocalWithoutExecutableIsRejected(): void
    {
        $manager = $this->local(['headless' => true]);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/executable/');
        $manager->validateConfig();
    }

    public function testLocalWithExecutablePasses(): void
    {
        $manager = $this->local(['executable' => 'chrome.exe']);

        $manager->validateConfig();
        $this->assertSame('http://127.0.0.1:9222', $manager->getApiUrl());
    }

    public function testUnknownPropertyOnRemoteManagerFails(): void
    {
        $this->expectException(UxonMapError::class);
        $this->remote(['endpoint' => 'http://host:9222', 'no_such_property' => 1]);
    }

    public function testUnknownPropertyOnLocalManagerFails(): void
    {
        $this->expectException(UxonMapError::class);
        $this->local(['executable' => 'chrome.exe', 'endpoint' => 'http://host:9222']);
    }
}
