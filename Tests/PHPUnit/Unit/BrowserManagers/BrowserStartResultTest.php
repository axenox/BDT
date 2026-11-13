<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers;

use axenox\BDT\Behat\BrowserManagers\BrowserStartResult;
use PHPUnit\Framework\TestCase;

/**
 * Covers the one stable history shape the formatter persists for local starts and remote attachments.
 */
class BrowserStartResultTest extends TestCase
{
    public function testHistoryEntryHasTheDocumentedKeys(): void
    {
        $entry = (new BrowserStartResult(9222, 4711, 123.4))->toHistoryEntry();

        $this->assertSame(
            ['pid', 'port', 'startup_duration', 'started_at', 'mode', 'endpoint'],
            array_keys($entry)
        );
        $this->assertSame(4711, $entry['pid']);
        $this->assertSame(9222, $entry['port']);
        $this->assertSame(123.4, $entry['startup_duration']);
        $this->assertSame('local', $entry['mode']);
        $this->assertNull($entry['endpoint']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $entry['started_at']);
    }

    public function testRemoteAttachmentCarriesEndpointAndNoPid(): void
    {
        $result = new BrowserStartResult(9222, null, 5.0);
        $result->mode = 'remote';
        $result->endpoint = 'http://bdt-chrome.internal:9222';

        $entry = $result->toHistoryEntry();

        $this->assertNull($entry['pid']);
        $this->assertSame('remote', $entry['mode']);
        $this->assertSame('http://bdt-chrome.internal:9222', $entry['endpoint']);
    }
}
