<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\BrowserManagers\ProcessHosts;

use axenox\BDT\Behat\BrowserManagers\ProcessHosts\LinuxProcessHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure helpers of the Linux host, which run on any operating system.
 *
 * WHY: the Linux host cannot be exercised on the Windows development machines, so everything that does
 * not need /proc or a shell (path rules, image names, parsing of ss output and /proc/<pid>/stat) is
 * pinned here as pure functions.
 */
class LinuxProcessHostTest extends TestCase
{
    /**
     * @return array<string,array{string,string}>
     */
    public static function pathProvider(): array
    {
        return [
            'case is kept' => ['/Opt/Chrome/Profile', '/Opt/Chrome/Profile'],
            'duplicate separators' => ['/opt//chrome///x', '/opt/chrome/x'],
            'trailing separator removed' => ['/opt/chrome/', '/opt/chrome'],
            'backslashes converted' => ['C:\\foo\\bar', 'C:/foo/bar'],
            'surrounding whitespace' => ['  /opt/x  ', '/opt/x'],
        ];
    }

    #[DataProvider('pathProvider')]
    public function testNormalizePath(string $input, string $expected): void
    {
        $this->assertSame($expected, (new LinuxProcessHost())->normalizePath($input));
    }

    /**
     * @return array<string,array{?string,string}>
     */
    public static function imageNameProvider(): array
    {
        return [
            'null falls back' => [null, 'chrome'],
            'empty falls back' => ['', 'chrome'],
            'absolute path' => ['/opt/google/chrome/chrome', 'chrome'],
            'wrapper script name is returned as is' => ['/usr/bin/google-chrome', 'google-chrome'],
            'backslash path' => ['\\opt\\chromium\\chromium', 'chromium'],
            'bare file name' => ['chromium', 'chromium'],
        ];
    }

    #[DataProvider('imageNameProvider')]
    public function testGetImageNameForExecutable(?string $executable, string $expected): void
    {
        $this->assertSame($expected, (new LinuxProcessHost())->getImageNameForExecutable($executable));
    }

    public function testSeparatorAndInitialExitCode(): void
    {
        $host = new LinuxProcessHost();

        $this->assertSame('/', $host->getDirectorySeparator());
        $this->assertNull($host->getLastCommandExitCode());
    }

    public function testParseListeningPidReadsPidFromSsOutput(): void
    {
        $lines = ['LISTEN 0 10 127.0.0.1:9222 0.0.0.0:* users:(("chrome",pid=4242,fd=33))'];

        $this->assertSame(4242, LinuxProcessHost::parseListeningPid($lines));
    }

    public function testParseListeningPidUsesTheFirstMatchingLine(): void
    {
        $lines = [
            'LISTEN 0 10 127.0.0.1:9222 0.0.0.0:*',
            'LISTEN 0 10 [::1]:9222 [::]:* users:(("chrome",pid=77,fd=5))',
            'LISTEN 0 10 127.0.0.1:9222 0.0.0.0:* users:(("other",pid=88,fd=6))',
        ];

        $this->assertSame(77, LinuxProcessHost::parseListeningPid($lines));
    }

    public function testParseListeningPidReturnsNullWithoutOwnerInformation(): void
    {
        $this->assertNull(LinuxProcessHost::parseListeningPid([]));
        $this->assertNull(LinuxProcessHost::parseListeningPid(['LISTEN 0 10 127.0.0.1:9222 0.0.0.0:*']));
    }

    /**
     * @return array<string,array{string,?int}>
     */
    public static function statProvider(): array
    {
        return [
            'regular stat' => ['4242 (chrome) S 1 777 4242 0 -1 4194560', 777],
            'comm with spaces and parentheses' => ['4242 (my (weird) name) S 1 888 4242 0', 888],
            'group id zero is rejected' => ['4242 (chrome) S 1 0 4242 0', null],
            'non numeric group id' => ['4242 (chrome) S 1 abc 4242 0', null],
            'too few fields' => ['4242 (chrome) S 1', null],
            'no comm terminator' => ['4242 chrome S 1 777', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('statProvider')]
    public function testParseProcessGroupId(string $stat, ?int $expected): void
    {
        $this->assertSame($expected, LinuxProcessHost::parseProcessGroupId($stat));
    }
}
