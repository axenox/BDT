<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\CommonLogic\Traits\ImportUxonObjectTrait;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Shared lifecycle policy for browser managers: start history, restart sequence and the health check.
 *
 * WHY IT EXISTS: the confirm-before-declaring-dead policy in isAlive() decides whether recovery kills a
 * browser, so it must be identical for a local process and a remote endpoint. Putting it (and the
 * history and logbook plumbing) in one base class keeps the concrete managers down to what really
 * differs: how the browser is started, stopped and probed.
 *
 * LIFECYCLE: instances are created by {@see BrowserManagerFactory}, which imports the UXON
 * configuration and calls validateConfig() before the manager is handed out. start() is called lazily
 * before the first browser use, restart() during recovery and stop() at the end of the run.
 *
 * Subclasses are configured through UXON setters; their property annotations are the configuration
 * surface and a concrete config example is given in each subclass docblock.
 */
abstract class AbstractBrowserManager implements BrowserManagerInterface, WorkbenchDependantInterface
{
    use ImportUxonObjectTrait;

    private const HEALTH_PROBE_TIMEOUT_SECONDS = 2.0;
    private const HEALTH_CONFIRM_TIMEOUT_SECONDS = 4.0;
    private const HEALTH_CONFIRM_ATTEMPTS = 2;
    private const HEALTH_CONFIRM_PAUSE_MICROSECONDS = 500_000;

    private LogBookInterface $logbook;
    private array $startHistory = [];
    private bool $startAttempted = false;

    /**
     * Retains the workbench because concrete managers need runtime services without depending on formatters.
     */
    public function __construct(private WorkbenchInterface $workbench)
    {
        $this->logbook = new MarkdownLogBook('Chrome');
    }

    /**
     * Returns the workbench required by configuration import and local browser logging.
     *
     * {@inheritDoc}
     *
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * Rejects incomplete manager-specific configuration before any process or network operation begins.
     *
     * WHY ONE EXCEPTION NAMING ALL KEYS: the factory calls this right after the UXON import, so a
     * misconfigured run fails at startup with a complete list instead of one missing key at a time.
     *
     * @throws ConfigException
     */
    abstract public function validateConfig(): void;

    /**
     * Lets each mode record an attempt only when its legacy runtime-state milestone is reached.
     */
    abstract protected function doStart(): BrowserStartResult;

    /**
     * Probes the manager's endpoint while keeping the confirmation policy identical for every browser.
     */
    abstract protected function probe(float $timeoutSeconds): bool;

    /**
     * Checks runtime state before an endpoint probe can establish liveness.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::hasActiveSession()
     */
    abstract public function hasActiveSession(): bool;

    /**
     * Starts the browser and records its result in the common history format.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::start()
     */
    public function start(): BrowserStartResult
    {
        $result = $this->doStart();
        $this->startHistory[] = $result->toHistoryEntry();
        return $result;
    }

    /**
     * Restarts the browser through the shared stop/start sequence and mode-specific pause hook.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::restart()
     */
    public function restart(): void
    {
        $this->stop();
        $this->waitBeforeRestart();
        $this->start();
    }

    /**
     * Allows local process managers to wait for port release while remote attach managers remain immediate.
     */
    protected function waitBeforeRestart(): void
    {
    }

    /**
     * Confirms a negative fast probe before recovery can terminate or replace a healthy-but-slow browser.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::isAlive()
     */
    public function isAlive(): bool
    {
        if (!$this->hasActiveSession()) {
            return false;
        }

        $parts = parse_url($this->getApiUrl());
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        if ($this->probe(self::HEALTH_PROBE_TIMEOUT_SECONDS)) {
            return true;
        }

        for ($attempt = 1; $attempt <= self::HEALTH_CONFIRM_ATTEMPTS; $attempt++) {
            usleep(self::HEALTH_CONFIRM_PAUSE_MICROSECONDS);
            if ($this->probe(self::HEALTH_CONFIRM_TIMEOUT_SECONDS)) {
                $this->getLogbook()->addLine(
                    "isAlive({$port}): no answer within " . self::HEALTH_PROBE_TIMEOUT_SECONDS
                    . ' s but answered on confirmation attempt ' . $attempt
                    . ' - Chrome is SLOW, not dead. Not restarting it. Check server memory/CPU load.'
                );
                return true;
            }
        }

        $this->getLogbook()->addLine(
            "isAlive({$port}): declared dead after 1 fast probe and " . self::HEALTH_CONFIRM_ATTEMPTS
            . ' confirmation probe(s)'
        );
        return false;
    }

    /**
     * Returns no application URL unless a manager explicitly supplies a remote browser-facing address.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getBaseUrl()
     */
    public function getBaseUrl(): ?string
    {
        return null;
    }

    /**
     * Adds no banner hint by default; only managers with something actionable to say override it.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getDebugHint()
     */
    public function getDebugHint(): ?string
    {
        return null;
    }

    /**
     * Reports lifecycle attempts independently of local ports so failed remote attaches can be retried.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::hasStartBeenAttempted()
     */
    public function hasStartBeenAttempted(): bool
    {
        return $this->startAttempted;
    }

    /**
     * Retains recovery eligibility after stop or a failed restart without treating configuration as a start.
     */
    protected function markStartBeenAttempted(): void
    {
        $this->startAttempted = true;
    }

    /**
     * Returns all recorded browser starts since the formatter last persisted this feature's metadata.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getStartHistory()
     */
    public function getStartHistory(): array
    {
        return $this->startHistory;
    }

    /**
     * Clears feature-scoped start metadata after it has been written to the run record.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::clearStartHistory()
     */
    public function clearStartHistory(): void
    {
        $this->startHistory = [];
    }

    /**
     * Supplies a shared diagnostics book so lifecycle failures retain their existing structured log.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getLogbook()
     */
    public function getLogbook(): LogBookInterface
    {
        return $this->logbook;
    }
}
