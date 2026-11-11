<?php
namespace axenox\BDT\Behat\BrowserManagers;

use axenox\BDT\Exceptions\ConfigException;
use exface\Core\Exceptions\RuntimeException;

/**
 * Attaches to a Chrome that is started and owned by someone else, e.g. a container, and drives it remotely.
 * 
 * Use this manager when BDT must not launch a browser itself, for example when Chrome runs in a
 * separate container. BDT only attaches to the DevTools endpoint and closes the tabs it opened - it
 * never stops the browser process.
 * 
 * ## Example
 * 
 * ```json
 * {
 *     "endpoint": "http://bdt-chrome.internal:9222",
 *     "base_url": "http://app-under-test.internal/"
 * }
 *  
 * ```
 *  
 * `base_url` is the address of the workbench as seen from the remote browser - it usually differs from
 * the address used by the server running the tests.
 * 
 * @see BrowserManagerFactory
 *
 * WHY IT EXISTS: a containerized browser has no local PID, port or profile, so none of the local
 * process logic applies; only attach, health check and tab cleanup remain.
 *
 * LIFECYCLE: created by BrowserManagerFactory when an endpoint is configured; start() verifies the
 * endpoint and snapshots the pre-existing pages, stop() closes only pages created since and detaches.
 */
class RemoteChromeManager extends AbstractBrowserManager implements BrowserTabControlInterface
{
    private ?string $endpoint = null;
    private ?string $baseUrl = null;
    private ?int $port = null;
    private ?array $initialPageIds = null;

    /**
     * Requires both browser and application addresses before any remote connection is attempted.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::validateConfig()
     */
    public function validateConfig(): void
    {
        $missing = [];
        if ($this->endpoint === null || $this->endpoint === '') {
            $missing[] = 'endpoint';
        }
        if ($this->baseUrl === null || $this->baseUrl === '') {
            $missing[] = 'base_url';
        }
        if ($missing !== []) {
            throw new ConfigException(
                'Remote Chrome requires the following configuration properties: ' . implode(', ', $missing) . '.'
            );
        }
    }

    /**
     * HTTP(S) address of the remote Chrome DevTools API, e.g. `http://bdt-chrome.internal:9222`.
     * 
     * Credentials, query strings and fragments are not allowed.
     * 
     * @uxon-property endpoint
     * @uxon-type string
     * @uxon-required true
     * 
     * WHY NORMALIZED: transport and lock keys must never be built from two different spellings of
     * the same URL.
     */
    public function setEndpoint(string $endpoint): void
    {
        $this->endpoint = $endpoint === ''
            ? null
            : ChromeDevToolsHttpClient::normalizeEndpoint($endpoint);
    }

    /**
     * Address of the workbench as reachable from the remote browser, e.g. `http://app-under-test.internal/`.
     * 
     * The remote browser opens the application under test through this URL. A trailing slash is added
     * automatically.
     * 
     * @uxon-property base_url
     * @uxon-type string
     * @uxon-required true
     * 
     * WHY A TRAILING SLASH IS GUARANTEED: callers append relative paths to it.
     */
    public function setBaseUrl(string $baseUrl): void
    {
        $this->baseUrl = $baseUrl === ''
            ? null
            : ChromeDevToolsHttpClient::normalizeEndpoint($baseUrl) . '/';
    }

    /**
     * Attaches to externally managed Chrome and snapshots pre-existing pages for safe cleanup.
     *
     * The snapshot is retained until stop(): replacing it after a failed health check would
     * classify this run's existing tabs as pre-existing and exclude them from cleanup.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::doStart()
     */
    protected function doStart(): BrowserStartResult
    {
        if ($this->hasActiveSession() && $this->isAlive()) {
            $this->getLogbook()->addLine('start(): a healthy Chrome is already running on port ' . $this->port . ' - reusing it');
            $result = new BrowserStartResult($this->port, null, 0.0);
            $result->mode = 'remote';
            $result->endpoint = $this->getApiUrl();
            return $result;
        }

        $this->markStartBeenAttempted();
        $startedAt = microtime(true);
        $endpoint = $this->getApiUrl();
        $parts = parse_url($endpoint);
        $port = $parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);
        $version = $this->createDevToolsClient()->getVersion(4.0, true);
        if (!is_string($version['webSocketDebuggerUrl'] ?? null) || $version['webSocketDebuggerUrl'] === '') {
            throw new RuntimeException('Remote endpoint at ' . $endpoint . ' did not advertise a CDP webSocketDebuggerUrl.');
        }

        if ($this->initialPageIds === null) {
            $tabs = $this->createDevToolsClient()->getTabList(true);
            $this->initialPageIds = [];
            foreach ($tabs as $tab) {
                if (($tab['type'] ?? null) === 'page' && isset($tab['id'])) {
                    $this->initialPageIds[] = $tab['id'];
                }
            }
        }

        $this->port = $port;
        $elapsedMs = round((microtime(true) - $startedAt) * 1000, 1);
        $result = new BrowserStartResult($port, null, $elapsedMs);
        $result->mode = 'remote';
        $result->endpoint = $endpoint;
        $this->getLogbook()->addLine('Attached to externally managed Chrome at ' . $endpoint);
        return $result;
    }

    /**
     * Closes only pages created after attachment and leaves the externally managed browser process alive.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::stop()
     */
    public function stop(): void
    {
        try {
            if ($this->initialPageIds !== null) {
                foreach ($this->getTabList() as $tab) {
                    if (($tab['type'] ?? null) === 'page'
                        && isset($tab['id'])
                        && $this->isTestOwnedTab($tab['id'])) {
                        $this->closeTab($tab['id']);
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->getLogbook()->addLine('Remote tab cleanup failed: ' . $e->getMessage());
        }

        $this->port = null;
        $this->initialPageIds = null;
        $this->getLogbook()->addLine('Detached from remote Chrome; the externally managed browser remains running.');
    }

    /**
     * Omits the local process-release pause because remote Chrome lifecycle belongs to its operator.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::waitBeforeRestart()
     */
    protected function waitBeforeRestart(): void
    {
    }

    /**
     * Exposes the configured remote DevTools endpoint to drivers and diagnostics.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getApiUrl()
     */
    public function getApiUrl(): string
    {
        if ($this->endpoint === null) {
            throw new ConfigException('Remote Chrome endpoint has not been configured.');
        }
        return $this->endpoint;
    }

    /**
     * Exposes the URL the remote browser must use to reach the workbench.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::getBaseUrl()
     */
    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    /**
     * Labels the browser endpoint in logs without implying local process ownership.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::describe()
     */
    public function describe(): string
    {
        return 'remote Chrome at ' . $this->getApiUrl();
    }

    /**
     * Preserves tabs that existed before attach and marks only later tabs as owned by this test run.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::isTestOwnedTab()
     */
    public function isTestOwnedTab(string $targetId): bool
    {
        return $this->initialPageIds !== null
            && !in_array($targetId, $this->initialPageIds, true);
    }

    /**
     * Lists remote browser tabs for diagnostics and cleanup of pages created by this run.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::getTabList()
     */
    public function getTabList(): array
    {
        return $this->createDevToolsClient()->getTabList();
    }

    /**
     * Closes one remote tab without taking ownership of its browser process.
     *
     * {@inheritDoc}
     *
     * @see BrowserTabControlInterface::closeTab()
     */
    public function closeTab(string $targetId): void
    {
        $this->createDevToolsClient()->closeTab($targetId);
    }

    /**
     * Binds DevTools requests to this manager's validated remote endpoint.
     */
    private function createDevToolsClient(): ChromeDevToolsHttpClient
    {
        return new ChromeDevToolsHttpClient($this->getApiUrl(), $this->getLogbook());
    }

    /**
     * Probes the remote endpoint through the same bounded CDP transport used by attachment.
     *
     * {@inheritDoc}
     *
     * @see AbstractBrowserManager::probe()
     */
    protected function probe(float $timeoutSeconds): bool
    {
        return $this->createDevToolsClient()->probe($timeoutSeconds);
    }

    /**
     * Avoids treating an unconfigured endpoint as a live attached session.
     *
     * {@inheritDoc}
     *
     * @see BrowserManagerInterface::hasActiveSession()
     */
    public function hasActiveSession(): bool
    {
        return $this->port !== null;
    }
}
