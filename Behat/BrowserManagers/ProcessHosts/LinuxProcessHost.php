<?php
namespace axenox\BDT\Behat\BrowserManagers\ProcessHosts;

/**
 * Implements browser process handling with Linux process groups and /proc.
 */
class LinuxProcessHost implements ProcessHostInterface
{
    /** Linux truncates the kernel process name (/proc/<pid>/comm) to this many characters. */
    private const COMM_MAX_LENGTH = 15;

    private ?int $lastCommandExitCode = null;

    /**
     * Starts Chrome in a new session so cleanup can terminate its complete process group.
     *
     * WHY THE HOST ESCAPES: the caller passes raw tokens, so every token - including
     * "--user-data-dir=<dir>" - is escaped with escapeshellarg() here and no quote handling is needed.
     *
     * @inheritDoc
     * @see ProcessHostInterface::launchDetached()
     */
    public function launchDetached(string $executable, array $arguments): string
    {
        $tokens = array_merge([$executable], $arguments);
        $escapedTokens = [];
        foreach ($tokens as $token) {
            $escapedTokens[] = escapeshellarg($token);
        }
        $cmd = escapeshellarg('setsid') . ' ' . implode(' ', $escapedTokens) . ' > /dev/null 2>&1 &';
        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        return $cmd;
    }

    /**
     * Reports the exit code of the last exec() call, which is the only shell command source on Linux.
     *
     * WHY /proc READS DO NOT COUNT: they are file reads, not shell commands, so they have no exit code.
     *
     * @inheritDoc
     * @see ProcessHostInterface::getLastCommandExitCode()
     */
    public function getLastCommandExitCode(): ?int
    {
        return $this->lastCommandExitCode;
    }

    /**
     * Derives the image name from the executable's file name, falling back to "chrome".
     *
     * KNOWN LIMITATION: wrapper scripts such as "google-chrome" or "chromium-browser" start a process
     * whose kernel name differs from the file name (typically "chrome"), so enumeration by this name
     * finds nothing for them. This must be verified on the first Linux run; point the executable at the
     * real binary if the profile sweep reports no processes.
     *
     * @inheritDoc
     * @see ProcessHostInterface::getImageNameForExecutable()
     */
    public function getImageNameForExecutable(?string $executable): string
    {
        if ($executable === null || $executable === '') {
            return 'chrome';
        }
        $name = basename($this->normalizePath($executable));
        return $name === '' ? 'chrome' : $name;
    }

    /**
     * Uses ss to discover the browser PID after a detached launch.
     *
     * @inheritDoc
     * @see ProcessHostInterface::findPidListeningOn()
     */
    public function findPidListeningOn(int $port): ?int
    {
        $output = [];
        $exitCode = 1;
        exec('ss -ltnpH "sport = :' . $port . '"', $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        if ($exitCode !== 0) {
            return null;
        }
        return self::parseListeningPid($output);
    }

    /**
     * Extracts the first owning PID from "ss -ltnpH" output lines.
     *
     * WHY A PURE STATIC METHOD: the output format is the only fragile part of the lookup; keeping the
     * parsing free of exec() lets it be unit-tested without a Linux host.
     *
     * @param string[] $ssOutputLines Output lines of ss
     */
    public static function parseListeningPid(array $ssOutputLines): ?int
    {
        foreach ($ssOutputLines as $line) {
            if (preg_match('/pid=(\d+)/', $line, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    /**
     * Reads /proc cmdline because Linux exposes no portable process-command API in PHP.
     *
     * @inheritDoc
     * @see ProcessHostInterface::getCommandLine()
     */
    public function getCommandLine(int $pid): ?string
    {
        $contents = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($contents === false) {
            return null;
        }
        return trim(str_replace("\0", ' ', $contents));
    }

    /**
     * Enumerates /proc by kernel process name while reserving NULL for an unreadable process directory.
     *
     * WHY ONLY THE FIRST 15 CHARACTERS ARE COMPARED: /proc/<pid>/comm is truncated to 15 characters
     * by the kernel, so a longer image name would never match its own process.
     *
     * @inheritDoc
     * @see ProcessHostInterface::listCommandLines()
     */
    public function listCommandLines(string $imageName): ?array
    {
        if (! is_readable('/proc')) {
            return null;
        }
        $entries = @scandir('/proc');
        if ($entries === false) {
            return null;
        }

        $expectedComm = substr($imageName, 0, self::COMM_MAX_LENGTH);
        $result = [];
        foreach ($entries as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }
            $pid = (int) $entry;
            $comm = @file_get_contents('/proc/' . $entry . '/comm');
            if ($comm === false || trim($comm) !== $expectedComm) {
                continue;
            }
            $commandLine = $this->getCommandLine($pid);
            $result[$pid] = $commandLine ?? '';
        }
        return $result;
    }

    /**
     * Terminates the launched session group, falling back to the process if its group is unavailable.
     *
     * @inheritDoc
     * @see ProcessHostInterface::killTree()
     */
    public function killTree(int $pid): void
    {
        try {
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            $pgid = $stat === false ? null : self::parseProcessGroupId($stat);
            $output = [];
            $exitCode = 1;
            if ($pgid !== null) {
                exec('kill -9 -- -' . $pgid . ' 2>/dev/null', $output, $exitCode);
            } else {
                exec('kill -9 ' . $pid . ' 2>/dev/null', $output, $exitCode);
            }
            $this->lastCommandExitCode = $exitCode;
        } catch (\Throwable $e) {
            // Cleanup is best-effort; failure to inspect or signal a vanished process must not escape.
        }
    }

    /**
     * Extracts the process group ID from the content of /proc/<pid>/stat.
     *
     * WHY SPLIT AT THE LAST ") ": the second field (comm) is parenthesized and may itself contain
     * spaces and parentheses, so only the text after its closing parenthesis can be split safely.
     * Pure and static so the parsing is unit-testable without /proc.
     */
    public static function parseProcessGroupId(string $stat): ?int
    {
        $commEnd = strrpos($stat, ') ');
        if ($commEnd === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $commEnd + 2)));
        if (isset($fields[2]) && ctype_digit($fields[2]) && (int) $fields[2] > 0) {
            return (int) $fields[2];
        }
        return null;
    }

    /**
     * Uses rm's native recursive removal and verifies the directory is actually gone.
     *
     * @inheritDoc
     */
    public function removeDirectoryTree(string $dir): bool
    {
        $output = [];
        $exitCode = 1;
        exec('rm -rf -- ' . escapeshellarg($dir) . ' 2>/dev/null', $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        clearstatcache(true, $dir);
        return ! is_dir($dir);
    }

    /**
     * Normalizes case-sensitive Linux paths without changing their case.
     *
     * @inheritDoc
     */
    public function normalizePath(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;
        return rtrim($normalized, '/');
    }

    /**
     * Supplies the separator so profile-root markers use the same syntax as Linux command lines.
     */
    public function getDirectorySeparator(): string
    {
        return '/';
    }
}
