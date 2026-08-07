<?php
namespace axenox\BDT\Exceptions;

/**
 * Thrown when the browser did not reach a settled state within the allotted time.
 *
 * WHY this is a separate type: a wait that runs out of time is not the same failure
 * as an application error. The page may be perfectly healthy and merely slow - a heavy
 * DataTable load, a backend under load, or a lane competing for CPU with its siblings.
 * Reporting both through FacadeBrowserException made them indistinguishable in the
 * daily error report, so every slow step inflated the framework-error count and buried
 * the failures that actually needed a code fix.
 *
 * Carrying its own type lets the report classify timeouts separately and lets callers
 * decide to retry a timeout while still failing hard on a real error.
 */
class BrowserTimeoutException extends FacadeBrowserException
{
}