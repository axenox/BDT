<?php
namespace axenox\BDT\Behat\BrowserManagers;

use exface\Core\Interfaces\Debug\LogBookInterface;

/**
 * Contract for every browser BDT can drive: start or attach, health-check, recover and stop.
 *
 * WHY IT EXISTS: BDT used to be hard-wired to one locally launched Chrome (the former ChromeManager).
 * Callers such as the DatabaseFormatter, the browser context and the run actions only need a
 * lifecycle, an API URL and diagnostics. Hiding the concrete browser behind this contract lets a local
 * process and a remote DevTools endpoint share the same recovery code, and lets further browsers be
 * added without touching those callers.
 *
 * LIFECYCLE: {@see BrowserManagerFactory} creates exactly one validated manager per process (in the
 * DatabaseFormatter constructor) or a short-lived one (RunTest attaching to a remote browser). The
 * browser context calls start() lazily before the first browser use, isAlive()/restart() during
 * recovery, and the formatter calls stop() once at the end of the run.
 */
interface BrowserManagerInterface
{
    /**
     * Starts or attaches to a browser so callers can use one lifecycle contract for every browser type.
     */
    public function start(): BrowserStartResult;

    /**
     * Releases browser resources owned by this manager without affecting externally owned processes.
     */
    public function stop(): void;

    /**
     * Re-establishes the browser after recovery while allowing each manager to choose its own pause.
     */
    public function restart(): void;

    /**
     * Confirms browser health before callers trigger destructive recovery.
     */
    public function isAlive(): bool;

    /**
     * Distinguishes an unstopped browser session from configuration or a previous failed start.
     */
    public function hasActiveSession(): bool;

    /**
     * Provides the DevTools API address needed by browser drivers and diagnostics.
     */
    public function getApiUrl(): string;

    /**
     * Exposes a browser-reachable application URL when local and remote deployments differ.
     */
    public function getBaseUrl(): ?string;

    /**
     * Distinguishes a first start from a failed start so recovery can retry correctly.
     */
    public function hasStartBeenAttempted(): bool;

    /**
     * Returns lifecycle metadata so the formatter can persist every start consistently.
     */
    public function getStartHistory(): array;

    /**
     * Clears feature-scoped history after the formatter has persisted it.
     */
    public function clearStartHistory(): void;

    /**
     * Exposes structured lifecycle diagnostics without coupling managers to the formatter.
     */
    public function getLogbook(): LogBookInterface;

    /**
     * Gives logs and recovery messages a manager-specific description.
     */
    public function describe(): string;

    /**
     * Returns an optional extra line for the startup banner, or NULL when the manager has nothing to add.
     *
     * WHY ON THE INTERFACE: the formatter used to parse describe() text (prefix and substring
     * matching) to decide whether to print a visibility hint, so any rewording of describe() silently
     * dropped or changed the hint. The manager that knows its own state now supplies the hint directly.
     */
    public function getDebugHint(): ?string;
}
