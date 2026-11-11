<?php
namespace axenox\BDT\Behat\BrowserManagers;

/**
 * Optional capability: listing and closing browser tabs through the DevTools HTTP API.
 *
 * WHY A SEPARATE INTERFACE: not every future browser exposes Chrome's HTTP tab endpoints, so tab
 * cleanup must not be forced onto the base lifecycle contract. Callers check for this interface
 * before attempting tab recovery.
 *
 * LIFECYCLE: used during recovery and diagnostics (UI5BrowserContext cleanup, BrowserDriverException
 * tab dumps) once the manager has been started; it never starts or stops the browser itself.
 */
interface BrowserTabControlInterface
{
    /**
     * Lists browser tabs so recovery can identify and clean up abandoned sessions.
     */
    public function getTabList(): array;

    /**
     * Closes an abandoned tab without requiring the driver's possibly broken WebSocket.
     */
    public function closeTab(string $targetId): void;

    /**
     * Keeps cleanup scoped to tabs created by this test run.
     */
    public function isTestOwnedTab(string $targetId): bool;
}
