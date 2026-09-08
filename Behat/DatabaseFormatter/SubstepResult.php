<?php
namespace axenox\bdt\Behat\DatabaseFormatter;

use axenox\BDT\DataTypes\StepStatusDataType;
use axenox\BDT\Interfaces\TestResultInterface;
use exface\Core\Interfaces\Debug\LogBookInterface;

class SubstepResult implements TestResultInterface
{
    private int $code;
    private ?string $title = null;
    private ?string $reason = null;
    private ?\Throwable $exception = null;
    private ?LogBookInterface $logbook = null;
    
    public function __construct(int $code, ?LogBookInterface $logbook = null, \Throwable $exception = null)
    {
        $this->code = $code;
        $this->exception = $exception;
        $this->logbook = $logbook;
    }
    
    public static function createPassed(?LogBookInterface $logbook = null) : self
    {
        return new self(StepStatusDataType::PASSED, $logbook);
    }

    public static function createSkipped(string $reason, ?LogBookInterface $logbook = null) : self
    {
        $result = new self(StepStatusDataType::SKIPPED, $logbook);
        $result->setReason($reason);
        return $result;
    }

    public static function createFailed(?\Throwable $exception = null, ?LogBookInterface $logbook = null) : self
    {
        return new self(StepStatusDataType::FAILED, $logbook, $exception);
    }

    public static function createPassedPreviously(?LogBookInterface $logbook = null) : self
    {
        return new self(StepStatusDataType::PASSED_PREVIOUSLY, $logbook);
    }

    public static function createFailedPreviously(?LogBookInterface $logbook = null) : self
    {
        return new self(StepStatusDataType::FAILED_PREVIOUSLY, $logbook);
    }
    
    public static function createFromPrevious(SubstepResult $result) : self
    {
        switch (true) {
            case $result->isPassed(): $code = StepStatusDataType::PASSED_PREVIOUSLY; break;
            case $result->isFailed(): $code = StepStatusDataType::FAILED_PREVIOUSLY; break;
            default: $code = $result->getCode(); break;
        }
        return new self($code, $result->getLogbook());
    }

    /**
     * Rebuilds a result from an outcome the coverage registry recorded earlier in this run.
     *
     * WHY THIS EXISTS NEXT TO createFromPrevious(): that one converts a result object still held in
     * memory. A registry hit has no object to convert - the work was done by another scenario, or by
     * another lane in another process, and all that survived is the status code.
     *
     * WHY THE CODE IS TRANSLATED RATHER THAN COPIED: isReplayed() is derived from the status itself,
     * so a verbatim PASSED would be indistinguishable from work actually performed just now. The
     * writer would then insert the record a second time and hit the uniqueness constraint on every
     * skip, turning an expected outcome into log noise that buries real write failures.
     *
     * @param int $status Conclusive status as stored in the registry.
     */
    public static function createFromRecordedStatus(int $status, ?LogBookInterface $logbook = null) : self
    {
        switch ($status) {
            case StepStatusDataType::PASSED:
            case StepStatusDataType::PASSED_PREVIOUSLY:
                $code = StepStatusDataType::PASSED_PREVIOUSLY;
                break;
            case StepStatusDataType::FAILED:
            case StepStatusDataType::FAILED_PREVIOUSLY:
                $code = StepStatusDataType::FAILED_PREVIOUSLY;
                break;
            default:
                // Only conclusive outcomes reach this factory, so anything else means the caller
                // skipped the suppressesRetest() gate. Keeping the code unchanged makes that visible in
                // the report instead of dressing an inconclusive record up as a verdict.
                $code = $status;
        }
        return new self($code, $logbook);
    }

    /**
     * Restates a nested verdict as one this frame reached by actually doing the work.
     *
     * WHY A NEW OBJECT RATHER THAN A MUTATION: the inner result is still held by the frame that
     * produced it and by its own event, so changing its code in place would rewrite history that has
     * already been reported. Only the outer frame's copy is restated.
     *
     * @param SubstepResult $inner Verdict returned by the nested work.
     */
    public static function createAsPerformed(SubstepResult $inner) : self
    {
        switch ($inner->getCode()) {
            case StepStatusDataType::PASSED_PREVIOUSLY: $code = StepStatusDataType::PASSED; break;
            case StepStatusDataType::FAILED_PREVIOUSLY: $code = StepStatusDataType::FAILED; break;
            default: $code = $inner->getCode();
        }
        $result = new self($code, $inner->getLogbook(), $inner->getException());
        if ($inner->getReason() !== null) {
            $result->setReason($inner->getReason());
        }
        return $result;
    }
    
    public function getCode() : int
    {
        return $this->code;
    }

    /**
     * Tells whether this operation failed, whether it failed just now or on an earlier encounter.
     *
     * WHY THE PREVIOUSLY-CODE COUNTS: callers use this to decide whether the widget or table around
     * them is broken, and a defect does not stop being a defect because another scenario met it
     * first. Excluding the replayed code let a container report success while one of its children
     * carried a recorded failure - the more coverage the registry suppressed, the more failures went
     * unreported.
     */
    public function isFailed() : bool
    {
        return in_array($this->getCode(), [StepStatusDataType::FAILED, StepStatusDataType::FAILED_PREVIOUSLY]);
    }

    /**
     * Tells whether this operation passed, whether it passed just now or on an earlier encounter.
     *
     * Kept symmetrical with isFailed() on purpose: a caller asking one of the two must never get a
     * picture the other contradicts.
     */
    public function isPassed() : bool
    {
        return in_array($this->getCode(), [StepStatusDataType::PASSED, StepStatusDataType::PASSED_PREVIOUSLY]);
    }
    
    public function getException() : ?\Throwable
    {
        return $this->exception;
    }
    
    public function getLogbook() : ?LogBookInterface
    {
        return $this->logbook;
    }

    public function getTitle() : ?string
    {
        return $this->title;
    }

    public function continueTitle(string $title) : SubstepResult
    {
        $this->title .= $title;
        return $this;
    }

    public function setTitle(string $title) : SubstepResult
    {
        $this->title = $title;
        return $this;
    }
    
    public function getReason() : ?string
    {
        if ($this->reason === null) {
            if ($this->exception !== null) {
                return $this->exception->getMessage();
            }
        }
        return $this->reason;
    }
    
    public function setReason(string $reason) : SubstepResult
    {
        $this->reason = $reason;
        return $this;
    }
    
    public function isReplayed() : bool
    {
        return in_array($this->getCode(), [StepStatusDataType::PASSED_PREVIOUSLY, StepStatusDataType::FAILED_PREVIOUSLY]);
    }
}