<?php

namespace axenox\BDT\Interfaces;

use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

interface TestRunObserverInterface
{
    /**
     * @return string|null
     */
    public function getCurrentRunUid() : ?string;

    /**
     * @return EventDispatcherInterface
     */
    public static function getEventDispatcher() : EventDispatcherInterface;

    /**
     * @param string $title
     * @param \Throwable|null $e
     * @return DataSheetInterface
     */
    public function logError(string $title, ?\Throwable $e = null) : DataSheetInterface;

    /**
     * @param \Throwable $e
     * @return DataSheetInterface
     */
    public function logException(\Throwable $e) : DataSheetInterface;
}