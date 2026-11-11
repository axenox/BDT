<?php
namespace axenox\BDT\Behat\BrowserManagers;

/**
 * Optional capability: the manager owns a local browser process that parallel lanes must track.
 *
 * WHY A SEPARATE INTERFACE: PID and debugging port only exist for a browser BDT launched itself. A
 * remote browser has neither, and exposing them there would invite callers to kill or probe a process
 * that belongs to someone else. Diagnostics and lane code therefore check for this interface
 * explicitly (e.g. BrowserDriverException when reporting the PID of a hung browser).
 *
 * LIFECYCLE: values are NULL until start() has launched the process and are reset by stop().
 */
interface SupportsParallelLanesInterface
{
    /**
     * Supplies a process ID only for managers that own a local browser process.
     */
    public function getPid(): ?int;

    /**
     * Supplies a debugging port only for managers that own a local browser process.
     */
    public function getPort(): ?int;
}
