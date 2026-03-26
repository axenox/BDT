<?php
namespace axenox\BDT\Common;

use axenox\BDT\Interfaces\MetricInterface;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Events\Workbench\OnBeforeStopEvent;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * 
 */
abstract class AbstractRunMetric implements MetricInterface
{
    use ICanBeConvertedToUxonTrait;
    
    private WorkbenchInterface $workbench;
    private EventDispatcherInterface $eventDispatcher;
    private TestRunObserverInterface $formatter;
    
    private ?string $uid = null;

    public function __construct(WorkbenchInterface $workbench, TestRunObserverInterface $formatter, ?UxonObject $uxon = null)
    {
        $this->workbench = $workbench;
        $this->formatter = $formatter;
        $this->eventDispatcher = $formatter::getEventDispatcher();
        
        if ($uxon !== null) {
            $this->importUxonObject($uxon);
        }
        $this->registerEventHandlers();
        $this->registerEventHandlersToSaveMetrics();
    }

    abstract protected function registerEventHandlers();

    abstract protected function saveMetrics();
    
    protected function registerEventHandlersToSaveMetrics()
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeStopEvent::getEventName(), [$this, 'onBeforeStopWorkbenchSaveMetrics']);
    }
    
    public function onBeforeStopWorkbenchSaveMetrics(OnBeforeStopEvent $event)
    {
        $this->saveMetrics();
    }

    /**
     * {@inheritDoc}
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->workbench;
    }
    
    protected function setUid(string $uid) : MetricInterface
    {
        $this->uid = $uid;
        return $this;
    }
    
    public function getUid() : string
    {
        return $this->uid;
    }
    
    protected function getEventDispatcher() : EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }
    
    public function getTestRunObserver() : TestRunObserverInterface
    {
        return $this->formatter;
    }
}