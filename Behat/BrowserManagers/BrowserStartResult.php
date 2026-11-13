<?php
namespace axenox\BDT\Behat\BrowserManagers;

/**
 * Record of one browser start or attach.
 *
 * WHY IT EXISTS: local launches and remote attachments must land in the same run-record history, so
 * the formatter persists one stable shape instead of branching on the browser type. It replaces the
 * former ChromeStartResult, which could not describe a remote attach (no PID, an endpoint instead).
 *
 * LIFECYCLE: a manager creates one instance per start() call; AbstractBrowserManager::start() converts
 * it with toHistoryEntry() and keeps the entry until the formatter persists and clears the history.
 */
class BrowserStartResult
{
    public string $mode = 'local';
    public ?string $endpoint = null;
    
    /**
     * TRUE when start() found a healthy browser already running and launched or attached nothing.
     *
     * WHY: AbstractBrowserManager::start() keeps such results out of the start history. The history
     * feeds the Chrome section of the run record, and a reuse is not a start.
     */
    public bool $reused = false;

    /**
     * Carries the launch result so callers can record local starts and remote attachments uniformly.
     */
    public function __construct(
        public int $port,
        public ?int $pid,
        public float $startupMs
    ) {}

    /**
     * Produces one stable history shape so database formatting does not branch by browser mode.
     */
    public function toHistoryEntry(): array
    {
        return [
            'pid' => $this->pid,
            'port' => $this->port,
            'startup_duration' => $this->startupMs,
            'started_at' => date('Y-m-d H:i:s'),
            'mode' => $this->mode,
            'endpoint' => $this->endpoint
        ];
    }
}