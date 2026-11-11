<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers\ProcessHosts;

use axenox\BDT\Behat\BrowserManagers\ProcessHosts\WindowsProcessHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure path and image-name rules of the Windows host, which run on any operating system.
 *
 * WHY: profile ownership and orphan sweeps compare normalized paths and image names as strings, so a
 * drift in these rules would make sweeps silently match nothing.
 */
class WindowsProcessHostTest extends TestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function pathProvider(): array
    {
        return [
            'forward slashes and case' => ['C:/Data/Chrome_Profiles/Lane1', 'c:\\data\\chrome_profiles\\lane1'],
            'duplicate separators' => ['C:\\\\data\\\\x', 'c:\\data\\x'],
            'trailing separator removed' => ['C:\\data\\x\\', 'c:\\data\\x'],
            'surrounding whitespace' => ['  C:\\data\\x  ', 'c:\\data\\x'],
            'unc path keeps its leading pair' => ['\\\\Server\\Share\\x\\', '\\\\server\\share\\x'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testNormalizePath(string $input, string $expected): void
    {
        $this->assertSame($expected, (new WindowsProcessHost())->normalizePath($input));
    }

    /**
     * @return array<string,array{?string,string}>
     */
    public static function imageNameProvider(): array
    {
        return [
            'null falls back' => [null, 'chrome.exe'],
            'empty falls back' => ['', 'chrome.exe'],
            'windows path' => ['C:\\Apps\\Chrome-bin\\Chrome.exe', 'Chrome.exe'],
            'forward slashes' => ['data/chrome/chrome.exe', 'chrome.exe'],
            'bare file name' => ['chrome.exe', 'chrome.exe'],
        ];
    }

    #[DataProvider('imageNameProvider')]
    public function testGetImageNameForExecutable(?string $executable, string $expected): void
    {
        $this->assertSame($expected, (new WindowsProcessHost())->getImageNameForExecutable($executable));
    }

    public function testSeparatorAndInitialExitCode(): void
    {
        $host = new WindowsProcessHost();

        $this->assertSame('\\', $host->getDirectorySeparator());
        $this->assertNull($host->getLastCommandExitCode());
    }
}
