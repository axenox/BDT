<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade;

class ChromeStartResult
{
    /**
     * Represents both local launches and remote attachments, which have no Windows process ID.
     */
    public function __construct(
        public int   $port,
        public ?int  $pid,
        public float $startupMs
    ) {}
}