<?php

namespace axenox\BDT\Behat\Common\Traits;

use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Lets a worker write to the shared exface.Core.USER_AUTHENTICATOR row without dying on the
 * TimeStampingBehavior optimistic-lock check.
 *
 * WHY THIS TRAIT EXISTS: the authenticator row that carries last_authenticated_on is written from
 * several unrelated places inside one Behat worker - the CLI identity resolution at boot, the
 * per-scenario authenticate() call in setupUser(), and the security work done while the workbench
 * shuts down - and the very same row is written by the IIS process whenever the browser submits the
 * login form. Every one of those writers needs the same guard, so the guard belongs in one shared
 * place instead of being re-implemented (and forgotten) at each call site. A trait also keeps the
 * single-source-of-truth property: if the object alias or the behavior class ever changes, it changes
 * once.
 */
trait AuthenticatorTimeStampingTrait
{
    /**
     * Runs $fn with the TimeStampingBehavior of exface.Core.USER_AUTHENTICATOR disabled IN THIS PROCESS
     * ONLY, and returns whatever $fn returns.
     *
     * WHY AN IN-PROCESS DISABLE IS ENOUGH: the optimistic-lock version check is performed by the
     * behavior inside the process that issues the write. Turning the behavior off in memory therefore
     * stops THIS worker from raising "changed in the meantime" no matter who else touched the row in
     * the meantime, and it has no effect on the web server or on the other lanes, which hold their own
     * behavior instances.
     *
     * WHY IT IS SAFE: the only contended field is a last-login timestamp. Losing its version check
     * costs nothing - a lost update simply means a slightly older timestamp survives - whereas the
     * conflict it produces kills a whole lane mid-run.
     *
     * WHY IT IS STATIC: setupUser() is static, so an instance method could not be reached from there.
     *
     * @param WorkbenchInterface $workbench
     * @param callable $fn
     * @return mixed
     */
    protected static function withoutAuthenticatorTimeStamping(WorkbenchInterface $workbench, callable $fn)
    {
        $object = MetaObjectFactory::createFromString($workbench, 'exface.Core.USER_AUTHENTICATOR');
        $disabled = [];
        foreach ($object->getBehaviors() as $behavior) {
            if (! ($behavior instanceof TimeStampingBehavior) || $behavior->isDisabled()) {
                continue;
            }
            $behavior->disable();
            $disabled[] = $behavior;
        }

        try {
            return $fn();
        } finally {
            // Re-enable only what this call actually turned off, so a behavior that was already
            // disabled for some other reason is never silently switched back on.
            foreach ($disabled as $behavior) {
                $behavior->enable();
            }
        }
    }
}