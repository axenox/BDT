<?php
namespace axenox\BDT\Behat\BrowserManagers\ProcessHosts;

/**
 * Implements browser process handling with the Windows commands used by BDT.
 */
class WindowsProcessHost implements ProcessHostInterface
{
    private ?int $lastCommandExitCode = null;

    /**
     * Keeps the browser in the current interactive Windows session while detaching it from Behat.
     *
     * WHY THE HOST QUOTES: the caller passes raw tokens; cmd.exe quoting, "start /B" detaching and the
     * "2>nul" redirection are Windows details. Only the --user-data-dir value is quoted (always, because
     * profile paths may contain spaces) and any other "--key=value" token only if its value contains
     * whitespace; this reproduces the historic command line token by token.
     *
     * @inheritDoc
     * @see ProcessHostInterface::launchDetached()
     */
    public function launchDetached(string $executable, array $arguments): string
    {
        $tokens = [];
        foreach ($arguments as $argument) {
            $tokens[] = $this->quoteArgument($argument);
        }
        $cmd = 'start /B "" '
            . '"' . $executable . '" '
            . implode(' ', $tokens)
            . ' 2>nul';
        pclose(popen($cmd, 'r'));
        return $cmd;
    }

    /**
     * Quotes the value of a "--key=value" argument where cmd.exe needs it.
     *
     * WHY A SEPARATE METHOD: keeps the single quoting rule in one place so the logged command line and
     * the executed one cannot differ.
     */
    private function quoteArgument(string $argument): string
    {
        $eq = strpos($argument, '=');
        if ($eq === false) {
            return $argument;
        }
        $key = substr($argument, 0, $eq);
        $value = substr($argument, $eq + 1);
        if ($key === '--user-data-dir' || preg_match('/\s/', $value) === 1) {
            return $key . '="' . $value . '"';
        }
        return $argument;
    }

    /**
     * Finds the PID of the process listening on the given TCP port using netstat.
     *
     * Used to retrieve the Chrome PID after launching it detached,
     * which does not return a PID directly.
     *
     * @param int $port The remote-debugging port Chrome is listening on
     * @return int|null The PID, or null if no matching LISTENING process was found
     */
    public function findPidListeningOn(int $port): ?int
    {
        $output = [];
        $exitCode = 1;
        exec('netstat -ano -p TCP', $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        foreach ($output as $line) {
            if (preg_match('/(?:0\.0\.0\.0|127\.0\.0\.1):' . $port . '\s+.*LISTENING\s+(\d+)/', $line, $matches)) {
                return (int) $matches[1];
            }
        }
        return null;
    }

    /**
     * Returns the full command line of a running process, or null if it cannot be determined.
     *
     * WHY THIS EXISTS: the command line is the evidence for the own-vs-foreign decision in startup.
     * Chrome carries its --user-data-dir argument there, the reliable marker distinguishing this run's
     * leftover from another project or tester on the shared server.
     *
     * WHY POWERSHELL Get-CimInstance INSTEAD OF WMIC: wmic is deprecated/removed on current Windows
     * versions; Get-CimInstance queries the same Win32_Process data everywhere. The filter is built
     * from an int PID, so no injection surface exists. Single quotes inside the double-quoted
     * PowerShell command avoid cmd.exe quote-escaping pitfalls.
     *
     * @param int $pid PID of the process to inspect
     * @return string|null The command line, or null on query failure or if the process is gone
     */
    public function getCommandLine(int $pid): ?string
    {
        $cmd = 'powershell -NoProfile -Command "(Get-CimInstance Win32_Process -Filter \'ProcessId=' . $pid . '\').CommandLine"';
        $output = [];
        $exitCode = 1;
        exec($cmd, $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        $commandLine = trim(implode(' ', $output));

        if ($exitCode !== 0 || $commandLine === '') {
            return null;
        }
        return $commandLine;
    }

    /**
     * Preserves the local manager's diagnostic exit-code line after the command-line query has moved here.
     *
     * WHY EXPOSE THIS RESULT: the process-host interface returns only the command line, but the
     * existing Windows log includes PowerShell's exact exit code when that line is unavailable.
     * WHY NULLABLE: before any command ran there is no exit code, and 0 or 1 would be a made-up one.
     *
     * @inheritDoc
     * @see ProcessHostInterface::getLastCommandExitCode()
     */
    public function getLastCommandExitCode(): ?int
    {
        return $this->lastCommandExitCode;
    }

    /**
     * Derives the image name from the executable's file name, as Win32_Process reports it.
     *
     * WHY THE FALLBACK: enumeration can be reached before a local executable is known, and a remote
     * run has none at all; "chrome.exe" is the image BDT launches by default.
     *
     * @inheritDoc
     * @see ProcessHostInterface::getImageNameForExecutable()
     */
    public function getImageNameForExecutable(?string $executable): string
    {
        if ($executable === null || $executable === '') {
            return 'chrome.exe';
        }
        $name = basename(str_replace('\\', '/', $executable));
        return $name === '' ? 'chrome.exe' : $name;
    }

    /**
     * Returns the command line of every matching image keyed by PID, or NULL if enumeration failed.
     *
     * WHY THE COMMAND LINE (not just the PID): the profile dir - the only marker tying a Chrome to a
     * specific lane/port - lives in the --user-data-dir launch argument, not in any CIM property. A
     * single snapshot is taken so a caller matching several dirs scans the image only once.
     *
     * WHY NULL AND NOT AN EMPTY ARRAY ON FAILURE: an empty array means "no browser is running", which
     * callers treat as "nothing left to clean up - done". Returning it for a FAILED enumeration made a
     * sweep that never looked indistinguishable from a sweep that found nothing, so leftover browsers
     * were silently reported as cleaned.
     *
     * WHY A COMPLETION MARKER AND NOT ONLY THE EXIT CODE: a shell that is killed or truncated mid-write
     * can still exit 0 with a partial list. The marker is written LAST, so its presence proves the
     * enumeration ran to the end.
     *
     * WHY IT RETRIES: a transient resource shortage is the usual failure, and retrying costs
     * milliseconds only after a failed attempt while sparing the caller a skipped cleanup.
     *
     * WHY Get-CimInstance: wmic is removed on current Windows; Get-CimInstance reads the same
     * Win32_Process data. Output is "<pid>|<commandline>" per line and no caller input reaches this
     * command.
     *
     * @param string $imageName Executable image name to enumerate
     * @return array<int,string>|null PID => full command line, or NULL if the list could not be read
     */
    public function listCommandLines(string $imageName): ?array
    {
        $marker = 'BDT_CHROME_ENUM_OK';
        $safeImageName = str_replace("'", "''", $imageName);
        $cmd = 'powershell -NoProfile -Command "Get-CimInstance Win32_Process '
            . '| Where-Object { $_.Name -eq \'' . $safeImageName . '\' } '
            . '| ForEach-Object { $_.ProcessId.ToString() + \'|\' + $_.CommandLine }; '
            . 'Write-Output \'' . $marker . '\'"';

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $output = [];
            $exitCode = 1;
            exec($cmd, $output, $exitCode);
            $this->lastCommandExitCode = $exitCode;

            $completed = false;
            $result = [];
            if ($exitCode === 0) {
                foreach ($output as $line) {
                    if (trim($line) === $marker) {
                        $completed = true;
                        continue;
                    }
                    $parts = explode('|', $line, 2);
                    if (count($parts) === 2 && is_numeric(trim($parts[0]))) {
                        $result[(int) trim($parts[0])] = $parts[1];
                    }
                }
            }
            if ($completed) {
                return $result;
            }
            if ($attempt < $maxAttempts) {
                usleep(300_000);
            }
        }
        return null;
    }

    /**
     * Uses taskkill's tree mode so an intact Chrome process tree is removed in one call.
     *
     * @inheritDoc
     */
    public function killTree(int $pid): void
    {
        try {
            exec('taskkill /F /T /PID ' . $pid . ' 2>nul', $output, $exitCode);
            $this->lastCommandExitCode = $exitCode;
        } catch (\Throwable $e) {
            // Cleanup is best-effort; a vanished process or unavailable shell must not abort teardown.
        }
    }

    /**
     * Recursively removes a directory and its contents, returning whether it is gone afterwards.
     *
     * WHY "rd /s /q" over a PHP RecursiveIterator: Chrome profile trees are deep and full of files
     * with Windows-locked/long paths; the native command handles those and is faster. The result is
     * verified with is_dir() rather than trusted from the exit code, because a still-open Chrome
     * handle can make the removal partial - the caller logs that case rather than assuming success.
     *
     * @param string $dir Absolute path of the directory to remove
     * @return bool TRUE if the directory no longer exists after the attempt
     */
    public function removeDirectoryTree(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }
        $output = [];
        $exitCode = 1;
        exec('rd /s /q "' . $dir . '" 2>nul', $output, $exitCode);
        $this->lastCommandExitCode = $exitCode;
        clearstatcache(true, $dir);
        return ! is_dir($dir);
    }

    /**
     * Makes CIM paths comparable with BDT paths on Windows, including UNC paths.
     *
     * @inheritDoc
     */
    public function normalizePath(string $path): string
    {
        $p = strtolower(str_replace('/', '\\', trim($path)));
        $isUnc = str_starts_with($p, '\\\\');
        $p = preg_replace('#\\\\{2,}#', '\\\\', $p) ?? $p;
        if ($isUnc) {
            $p = '\\' . $p;
        }
        return rtrim($p, '\\');
    }

    /**
     * Supplies the separator so profile-root markers use the same syntax as Windows command lines.
     */
    public function getDirectorySeparator(): string
    {
        return '\\';
    }
}
