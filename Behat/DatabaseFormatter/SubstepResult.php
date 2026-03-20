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

    public static function createSkipped(?LogBookInterface $logbook = null) : self
    {
        return new self(StepStatusDataType::SKIPPED, $logbook);
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
    
    public function getCode() : int
    {
        return $this->code;
    }
    
    public function isFailed() : bool
    {
        return $this->getCode() === StepStatusDataType::FAILED;
    }
    
    public function isPassed() : bool
    {
        return $this->getCode() === StepStatusDataType::PASSED;
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
    
    public function setReason(string $reason) : SubstepResult
    {
        $this->reason = $reason;
        return $this;
    }
}