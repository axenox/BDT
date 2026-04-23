<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade;

class ChromeStartResult
{
    public function __construct(
        public int   $port,
        public int   $pid,
        public float $startupMs
    ) {}
}