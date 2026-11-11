<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\ChromeDevToolsHttpClient;
use axenox\BDT\Exceptions\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the endpoint rules every remote configuration passes through, without any network access.
 *
 * WHY: the normalized endpoint doubles as transport and lock key, so two spellings of one URL must
 * collapse to one string and unsafe URLs must be refused before any request is sent.
 */
class ChromeDevToolsHttpClientTest extends TestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function validEndpointProvider(): array
    {
        return [
            'plain http with port' => ['http://bdt-chrome.internal:9222', 'http://bdt-chrome.internal:9222'],
            'trailing slash removed' => ['http://bdt-chrome.internal:9222/', 'http://bdt-chrome.internal:9222'],
            'host lower-cased' => ['HTTP://Bdt-Chrome.Internal:9222', 'http://bdt-chrome.internal:9222'],
            'default http port dropped' => ['http://host:80/', 'http://host'],
            'default https port dropped' => ['https://host:443', 'https://host'],
            'no port' => ['http://host', 'http://host'],
            'path kept without trailing slash' => ['https://host/app/base/', 'https://host/app/base'],
        ];
    }

    #[DataProvider('validEndpointProvider')]
    public function testNormalizeEndpointAcceptsValidUrls(string $input, string $expected): void
    {
        $this->assertSame($expected, ChromeDevToolsHttpClient::normalizeEndpoint($input));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function rejectedEndpointProvider(): array
    {
        return [
            'credentials' => ['http://user:secret@host:9222'],
            'user only' => ['http://user@host:9222'],
            'query string' => ['http://host:9222?token=1'],
            'fragment' => ['http://host:9222/#frag'],
            'non-http scheme' => ['ftp://host:9222'],
            'websocket scheme' => ['ws://host:9222'],
            'not a url' => ['not a url'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedEndpointProvider')]
    public function testNormalizeEndpointRejectsUnsafeUrls(string $input): void
    {
        $this->expectException(ConfigException::class);
        ChromeDevToolsHttpClient::normalizeEndpoint($input);
    }
}
