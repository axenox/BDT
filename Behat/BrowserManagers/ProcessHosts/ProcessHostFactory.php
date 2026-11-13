<?php
namespace axenox\BDT\Behat\BrowserManagers\ProcessHosts;

use exface\Core\DataTypes\ServerSoftwareDataType;

/**
 * Keeps the process-host operating-system choice in one place.
 */
class ProcessHostFactory
{
    /**
     * Selects the process implementation used by the current server.
     *
     * WHY A FACTORY: callers need process behavior, not duplicated operating-system branches that
     * can drift as browser lifecycle support is extended.
     */
    public static function createForCurrentOs(): ProcessHostInterface
    {
        if (ServerSoftwareDataType::isOsWindows()) {
            return new WindowsProcessHost();
        }
        return new LinuxProcessHost();
    }
}
