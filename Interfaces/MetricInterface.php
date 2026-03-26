<?php
namespace axenox\BDT\Interfaces;

use exface\Core\Interfaces\iCanBeConvertedToUxon;
use exface\Core\Interfaces\WorkbenchDependantInterface;

interface MetricInterface extends WorkbenchDependantInterface, iCanBeConvertedToUxon
{
    public function getTestRunObserver() : TestRunObserverInterface;
}