<?php
namespace axenox\BDT\Behat\Events;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;

class AfterSubstep extends BeforeSubstep
{
    private SubstepResult $result;
    private bool $servedFromRegistry;

    /**
     * Couples the completed result with the same eager identity dispatched before execution.
     *
     * WHY THE IDENTITY IS FORWARDED: nested work may close or replace its source widget before this
     * event is handled, so the listener must never resolve identity from live UI state.
     *
     * WHY THE REGISTRY FLAG IS SEPARATE FROM THE RESULT CODE: runAsSubstep() adopts whatever its
     * callback returns, so a status meaning "seen before" travels up through every enclosing substep.
     * An ancestor that really did its work then carries that status without having earned it, and a
     * listener deciding by the code alone would refuse to record it - leaving that ancestor's own
     * identity missing from the registry and its work repeated on every later encounter. Only the
     * frame that actually returned early can answer this, so it states it explicitly.
     */
    public function __construct(
        SubstepResult $result,
        string $stepName,
        ?string $category = null,
        ?SubstepCoverageIdentity $coverageIdentity = null,
        bool $servedFromRegistry = false
    )
    {
        parent::__construct($stepName, $category, $coverageIdentity);
        $this->result = $result;
        $this->servedFromRegistry = $servedFromRegistry;
    }

    /**
     * Tells whether this substep did no work because the registry already held its outcome.
     *
     * WHY LISTENERS NEED THIS: the record it would write already exists, so writing it again can only
     * violate the uniqueness constraint. Unlike the result code, this answer belongs to this substep
     * alone and never propagates from a nested one.
     */
    public function isServedFromRegistry(): bool
    {
        return $this->servedFromRegistry;
    }
    
    public function getResult(): SubstepResult
    {
        return $this->result;
    }
    
    public function getResultCode() : int
    {
        return $this->getResult()->getCode();
    }
    
    public function getException() : ?\Throwable
    {
        return $this->getResult()->getException();
    }
    
    public function isPassed() : bool
    {
        return $this->getResult()->isPassed();
    }

    public function isFailed() : bool
    {
        return $this->getResult()->isFailed();
    }
}