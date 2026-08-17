<?php

namespace axenox\BDT\Behat\Common\Traits;

use exface\Core\Interfaces\Log\LoggerInterface;

/**
 * Retry logic for database operations the server chose as a deadlock victim.
 *
 * WHY THIS EXISTS: being picked as a deadlock victim is NOT an error condition in the sense that
 * something is broken - it is the documented, expected outcome of a lock cycle, and MS SQL says so
 * literally in the message it returns ("Rerun the transaction."). The transaction was rolled back
 * completely, so re-running it is safe and is the ONLY correct response. Without a retry here a
 * single lock cycle anywhere in a 2+ hour parallel run silently loses the write - which is exactly
 * how a run row was left with no finished_on and no log at all.
 *
 * WHY IT IS NOT A BLANKET CATCH: it re-throws EVERYTHING except deadlock/lock-timeout. A constraint
 * violation, a connection loss or a schema error must still propagate untouched - suppressing those
 * would be masking a real defect, which is precisely what this framework must not do.
 */
trait DeadlockRetryTrait
{
    /**
     * Runs $operation and re-runs it if the database rolled it back as a deadlock victim.
     *
     * WHY EXPONENTIAL BACKOFF WITH JITTER: two victims retrying after the same fixed delay collide
     * again on the same resources. A randomized, growing delay separates them so the second attempt
     * meets an already-released lock.
     *
     * @param callable $operation The DB call to run. Must be idempotent on rollback (a rolled-back
     *                            transaction leaves nothing behind, so any single dataUpdate/dataCreate is).
     * @param string $what Human-readable description used in the warning log line.
     * @param LoggerInterface|null $logger Optional - retries are logged as warnings when available.
     * @return mixed Whatever $operation returns.
     * @throws \Throwable The original exception if it is not a deadlock, or if all attempts failed.
     */
    protected static function runWithDeadlockRetry(callable $operation, string $what, ?LoggerInterface $logger = null)
    {
        $maxAttempts = 4;
        $baseBackoffUs = 50000;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $operation();
            } catch (\Throwable $e) {
                // Out of attempts, or not a deadlock at all - the caller must see the real exception.
                if ($attempt >= $maxAttempts || ! self::isDeadlockVictimException($e)) {
                    throw $e;
                }
                $window = $baseBackoffUs * (2 ** ($attempt - 1));
                $sleepUs = random_int($window, $window * 2);
                if ($logger !== null) {
                    $logger->warning(sprintf(
                        'BDT: %s was chosen as a deadlock victim (attempt %d of %d) - retrying in %d ms',
                        $what,
                        $attempt,
                        $maxAttempts,
                        (int) ($sleepUs / 1000)
                    ));
                }
                usleep($sleepUs);
            }
        }
    }

    /**
     * Tells whether an exception chain reports a deadlock victim or a lock timeout.
     *
     * WHY THE WHOLE CHAIN IS WALKED: the driver exception is wrapped by the SQL connector and again
     * by the DataSheet layer, so the deadlock evidence is never on the outermost exception.
     *
     * WHY MESSAGE MATCHING AND NOT ONLY ERROR CODES: the numeric codes are ambiguous across engines
     * (MS SQL 1205 is a deadlock, MySQL 1205 is a lock wait timeout) and the wrapping layers do not
     * preserve them reliably, while the engine's own wording survives verbatim through the wrapping.
     * SQLSTATE 40001 is checked as well wherever the driver exception is still reachable.
     */
    protected static function isDeadlockVictimException(\Throwable $e): bool
    {
        $needles = [
            'was deadlocked on lock resources',        // MS SQL 1205
            'deadlock victim',                         // MS SQL 1205
            'sqlstate[40001]',                         // ANSI serialization failure
            'lock request time out period exceeded',   // MS SQL 1222
            'deadlock found when trying to get lock',  // MySQL 1213
            'lock wait timeout exceeded'               // MySQL 1205
        ];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $haystack = mb_strtolower($current->getMessage());
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
            if ($current instanceof \PDOException) {
                $sqlState = (string) ($current->errorInfo[0] ?? $current->getCode());
                if ($sqlState === '40001') {
                    return true;
                }
            }
        }

        return false;
    }
}