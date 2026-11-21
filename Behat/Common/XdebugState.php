<?php
namespace axenox\BDT\Behat\Common;

/**
 * Tells whether an Xdebug debugger session is active in the current process.
 *
 * WHY ONE HELPER: the local browser manager decides "headless or visible" from this answer, while the
 * RunParallel banner and the DatabaseFormatter banner tell the user which of the two to expect. Three copies of
 * the check could disagree (e.g. one forgetting the guard for older Xdebug builds), and the banners would then
 * announce a visible browser that never appears.
 */
final class XdebugState
{
    /**
     * Returns TRUE when an Xdebug debugger session is currently active.
     *
     * WHY THE function_exists() GUARD: older Xdebug builds do not expose xdebug_is_debugger_active(); without
     * the guard the check would fatal exactly on the machines where a developer runs an old Xdebug.
     */
    public static function isDebuggerActive(): bool
    {
        return extension_loaded('xdebug')
            && function_exists('xdebug_is_debugger_active')
            && xdebug_is_debugger_active();
    }
}
