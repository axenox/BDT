<?php
namespace axenox\BDT\Behat\Events;

use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;

class AfterSubstep extends BeforeSubstep
{
    private SubstepResult $result;
    
    public function __construct(SubstepResult $result, string $stepName, ?string $category = null)
    {
        parent::__construct($stepName, $category);
        $this->result = $result;
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