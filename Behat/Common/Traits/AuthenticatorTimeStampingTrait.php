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
 *
 * NOTE: the guard suppresses ONLY the conflict check, it does NOT switch the behavior off. The
 * timestamps themselves must keep being written, otherwise the very first INSERT of an authenticator
 * row on a fresh environment violates the NOT NULL constraint on `modified_on`.
 */
trait AuthenticatorTimeStampingTrait
{
    /**
     * Runs $fn with the optimistic-lock check of the TimeStampingBehavior of
     * exface.Core.USER_AUTHENTICATOR turned off IN THIS PROCESS ONLY, and returns whatever $fn returns.
     *
     * WHY ONLY THE CONFLICT CHECK AND NOT THE WHOLE BEHAVIOR: disabling the behavior unregisters ALL
     * of its listeners, including the one that fills CREATED_ON/MODIFIED_ON on OnBeforeCreateData. On
     * an environment where the test user has never logged in yet, the authenticator row must be
     * INSERTed for the first time - and `exf_user_authenticator.modified_on` is NOT NULL in the
     * database. With the behavior off, that INSERT carried no timestamps at all and every lane died
     * with `"User authentication" could not be saved. The field "Modified on" is required`. Switching
     * off only `check_for_conflicts_on_update` removes the "changed in the meantime" error this guard
     * exists for while the timestamps keep being written.
     *
     * WHY AN IN-PROCESS SWITCH IS ENOUGH: the optimistic-lock version check is performed by the
     * behavior inside the process that issues the write. Turning it off in memory therefore stops THIS
     * worker from raising "changed in the meantime" no matter who else touched the row in the
     * meantime, and it has no effect on the web server or on the other lanes, which hold their own
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
        $switchedOff = [];
        foreach ($object->getBehaviors() as $behavior) {
            if (! ($behavior instanceof TimeStampingBehavior) || $behavior->isDisabled()) {
                continue;
            }
            if ($behavior->getCheckForConflictsOnUpdate() === false) {
                continue;
            }
            $behavior->setCheckForConflictsOnUpdate(false);
            $switchedOff[] = $behavior;
        }

        try {
            return $fn();
        } finally {
            // Restore only what this call actually turned off, so a behavior that already had the
            // conflict check off for some other reason is never silently switched back on.
            foreach ($switchedOff as $behavior) {
                $behavior->setCheckForConflictsOnUpdate(true);
            }
        }
    }
}