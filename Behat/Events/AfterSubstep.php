<?php
namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Tester\Result\TestResult;

class AfterSubstep extends BeforeSubstep
{
    private int $resultCode;
    private ?\Throwable $exception;
    
    public function __construct(string $stepName, ?string $category = null, ?\Throwable $exception = null, ?int $resultCode = null)
    {
        parent::__construct($stepName, $category);
        $this->resultCode = $resultCode ?? ($exception ? TestResult::FAILED : TestResult::PASSED);
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
        return $this->getResultCode() === TestResult::PASSED;
    }

    public function isFailed() : bool
    {
        return $this->getResultCode() === TestResult::FAILED;
    }
}