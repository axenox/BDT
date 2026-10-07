<?php

namespace axenox\BDT\Behat\Common\Traits;

use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Keeps credentials out of stack traces for the few calls that cannot avoid taking them as an argument.
 *
 * WHY THIS TRAIT EXISTS: PHP records the arguments of every frame in an exception trace, and the
 * workbench's exception renderer (ExceptionMarkdownRenderer, used for the stack trace tab of every
 * logged error) prints string and array arguments verbatim. The DB log, the daily error report built
 * from it and the lane logs therefore received the test user's password in plaintext whenever an
 * exception was raised below a call that received it. Our own methods no longer take the password as
 * an argument, but Mink, the Chrome driver and the core security/data APIs do, and those cannot be
 * changed. Their calls are wrapped in this guard instead.
 *
 * WHY zend.exception_ignore_args AND NOT #[\SensitiveParameter]: the attribute needs PHP 8.2 and would
 * have to sit on third-party signatures; the ini setting works from PHP 7.4 on, is changeable at
 * runtime and applies to every frame, including vendor frames.
 */
trait ExceptionArgumentsSuppressionTrait
{
    /**
     * Runs $fn with argument capture in exception traces switched off and returns whatever $fn returns.
     *
     * WHY THE SCOPE MATTERS: PHP evaluates the setting when an exception object is CREATED, so every
     * exception created inside $fn carries no arguments for ANY of its frames - including the frames of
     * the callers above the guard. Argument capture is still needed for debugging everywhere else, so
     * callers must keep $fn as small as the password-bearing call itself.
     *
     * WHY IT NEVER FAILS: if ini_set() refuses the change, a warning is logged and $fn still runs. A
     * login must not break because of this guard.
     *
     * WHY IT IS STATIC: setupUser() is static, so an instance method could not be reached from there.
     *
     * @param WorkbenchInterface $workbench
     * @param callable $fn
     * @return mixed
     */
    protected static function withoutExceptionArguments(WorkbenchInterface $workbench, callable $fn)
    {
        static $warned = false;

        // ini_set() returns the previous value as a string ("0" is valid) or false on failure.
        $previous = ini_set('zend.exception_ignore_args', '1');
        if ($previous === false && $warned === false) {
            $warned = true;
            $workbench->getLogger()->warning(
                'BDT: could not switch on zend.exception_ignore_args at runtime - test user credentials may appear in stack traces.'
            );
        }

        try {
            return $fn();
        } finally {
            if ($previous !== false) {
                ini_set('zend.exception_ignore_args', $previous);
            }
        }
    }
}
