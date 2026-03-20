<?php
namespace axenox\BDT\Interfaces;

interface TestResultInterface
{
    public function getCode() : int;
    
    public function isFailed() : bool;

    public function isPassed() : bool;
}