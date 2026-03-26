<?php

namespace axenox\BDT\Interfaces;

use Symfony\Component\EventDispatcher\EventDispatcherInterface;

interface TestRunObserverInterface
{
    /**
     * @return string|null
     */
    public function getCurrentRunUid() : ?string;
    
    public static function getEventDispatcher() : EventDispatcherInterface;
}