<?php

namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Event\Event;

class BeforeSubstep extends Event
{
    private string $stepName;
    private string $category;
    
    public function __construct(string $stepName, string $category)
    {
        $this->stepName = $stepName;
        $this->category = $category;
    }
    
    public function getSubstepName() : string
    {
        return $this->stepName;
    }
    
    public function getCategory() : string
    {
        return $this->category;
    }
}