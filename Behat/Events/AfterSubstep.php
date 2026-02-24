<?php
namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Tester\Result\TestResult;

class AfterSubstep extends BeforeSubstep
{
    public function getResultCode() : int
    {
        return $this->getException() ? TestResult::FAILED : TestResult::PASSED;
    }
    
    public function getException() : ?\Throwable
    {
        return null;
    }
}