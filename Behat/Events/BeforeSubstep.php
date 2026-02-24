<?php

namespace axenox\BDT\Behat\Events;

use Behat\Testwork\Event\Event;

class BeforeSubstep extends Event
{
    private string $stepName;
    private ?string $category = null;
    
    public function __construct(string $stepName, ?string $category = null)
    {
        $this->stepName = $stepName;
        $this->category = $category;
    }

    /**
     * @return string
     */
    public function getSubstepName() : string
    {
        return $this->stepName;
    }

    /**
     * @return string|null
     */
    public function getCategory() : ?string
    {
        return $this->category;
    }
}