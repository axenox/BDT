<?php
namespace axenox\BDT\Behat\BrowserManagers;

/**
 * Exposes allocation settings separately from the runtime PID and port.
 *
 * Coordinators need these values before a browser has started, while diagnostics only need runtime
 * process state. Keeping the two concerns separate avoids coupling diagnostics to allocation details.
 */
interface BrowserLaneConfigurationInterface
{
    /**
     * Supplies the interactive port window so concurrent testers reserve distinct ports.
     */
    public function getPortBandInteractive(): string;

    /**
     * Supplies the scheduled port window so parallel lanes reserve distinct ports.
     */
    public function getPortBandScheduled(): string;

    /**
     * Supplies the executable so coordinators validate and sweep the same browser image as the lanes.
     */
    public function getExecutable(): ?string;

    /**
     * Describes browser mode without a port, because a coordinator does not own any lane port.
     */
    public function describeForParallelLanes(): string;
}
