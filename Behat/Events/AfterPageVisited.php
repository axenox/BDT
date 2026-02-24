<?php

namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Event\Event;

class AfterPageVisited extends Event
{
    private $pageAlias;
    
    public function __construct(string $pageSelector)
    {
        $this->pageAlias = $pageSelector;
    }
    
    public function getPageAlias() : string
    {
        return $this->pageAlias;
    }
}