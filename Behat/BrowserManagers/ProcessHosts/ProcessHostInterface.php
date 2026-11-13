<?php
namespace axenox\BDT\Behat\BrowserManagers\ProcessHosts;

/**
 * Defines the operating-system operations needed to manage a detached browser process.
 */
interface ProcessHostInterface
{
    /**
     * Launches a browser detached so its lifetime is independent of the Behat worker.
     *
     * WHY RAW ARGUMENTS: quoting, redirection and detaching syntax differ per operating system, so
     * callers pass plain tokens (e.g. "--user-data-dir=<dir>" without quotes) and the host alone
     * decides how to render them. Otherwise every caller would carry one OS's shell syntax and the
     * abstraction would leak.
     *
     * @param string   $executable Browser executable path
     * @param string[] $arguments  Raw, unquoted arguments in the order expected by the browser
     * @return string The exact command line that was executed, for diagnostics
     */
    public function launchDetached(string $executable, array $arguments): string;

    /**
     * Returns the exit code of the last shell command this host ran, NULL if none ran yet.
     *
     * WHY ON THE INTERFACE: callers log the exit code next to a failed query. Probing for an
     * implementation-specific method with method_exists() silently reported a made-up exit code
     * for any host that lacked it.
     */
    public function getLastCommandExitCode(): ?int;

    /**
     * Derives the process image name under which this OS lists a browser launched from an executable.
     *
     * WHY ON THE HOST: the image name as the OS reports it (file name with .exe on Windows, truncated
     * kernel "comm" name on Linux) is an operating-system detail that must not be re-implemented by
     * every caller that enumerates browser processes.
     *
     * @param string|null $executable Configured browser executable path, NULL if unknown
     * @return string Image name to enumerate; a platform default when the executable is unknown
     */
    public function getImageNameForExecutable(?string $executable): string;

    /**
     * Finds the process currently listening on a port for startup identity checks.
     */
    public function findPidListeningOn(int $port): ?int;

    /**
     * Reads a process command line so callers can distinguish owned browsers from foreign ones.
     */
    public function getCommandLine(int $pid): ?string;

    /**
     * Enumerates matching browser command lines; NULL distinguishes failed enumeration from no matches.
     *
     * @return array<int,string>|null PID => command line
     */
    public function listCommandLines(string $imageName): ?array;

    /**
     * Kills a process tree without allowing cleanup failure to escape.
     */
    public function killTree(int $pid): void;

    /**
     * Removes a profile directory using the operating system's native recursive-delete behavior.
     */
    public function removeDirectoryTree(string $dir): bool;

    /**
     * Normalizes paths for reliable comparison with the paths reported by process enumeration.
     */
    public function normalizePath(string $path): string;

    /**
     * Returns the path separator used by normalized process command lines.
     */
    public function getDirectorySeparator(): string;
}
