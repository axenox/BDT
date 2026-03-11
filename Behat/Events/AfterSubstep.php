<?php
namespace axenox\BDT\Behat\Events;

use axenox\BDT\DataTypes\StepStatusDataType;

class AfterSubstep extends BeforeSubstep
{
    private int $resultCode;
    private ?\Throwable $exception;
    
    public function __construct(string $stepName, ?string $category = null, ?\Throwable $exception = null, ?int $resultCode = null)
    {
        parent::__construct($stepName, $category);
        $this->resultCode = $resultCode ?? ($exception ? StepStatusDataType::FAILED : StepStatusDataType::PASSED);
        $this->exception = $exception;
    }
        
    public function getResultCode() : int
    {
        return $this->resultCode;
    }
    
    public function getException() : ?\Throwable
    {
        return $this->exception;
    }
    
    public function isPassed() : bool
    {
        return StepStatusDataType::convertFromBehatResultCode($this->getResultCode()) === StepStatusDataType::PASSED;
    }

    public function isFailed() : bool
    {
        return StepStatusDataType::convertFromBehatResultCode($this->getResultCode()) === StepStatusDataType::FAILED;
    }
}