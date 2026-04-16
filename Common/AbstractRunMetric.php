<?php
namespace axenox\BDT\Common;

use axenox\BDT\Interfaces\MetricInterface;
use axenox\BDT\Interfaces\TestRunObserverInterface;
use exface\Core\CommonLogic\Traits\ICanBeConvertedToUxonTrait;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Events\Workbench\OnBeforeStopEvent;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchDependantInterface;
use exface\Core\Interfaces\WorkbenchInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Base class for BDT metrics to ease development of new metrics
 * 
 * @author Andrej Kabachnik
 */
abstract class AbstractRunMetric implements MetricInterface
{
    use ICanBeConvertedToUxonTrait;
    
    private WorkbenchInterface $workbench;
    private EventDispatcherInterface $eventDispatcher;
    private TestRunObserverInterface $formatter;
    
    private ?string $uid = null;
    private ?string $name = null;

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
        try {
            $this->saveMetrics();
        } catch (\Throwable $e) {
            $this->getTestRunObserver()->logException(
                new RuntimeException('Cannot save BDT metric ' . $this->getName() . '. ' . $e->getMessage(), null, $e)
            );
        }
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

    /**
     * @return string
     */
    public function getUid() : string
    {
        return $this->uid ?? '';
    }

    /**
     * @return string
     */
    public function getName() : string
    {
        return $this->name ?? '';
    }

    /**
     * @param string $val
     * @return MetricInterface
     */
    protected function setName(string $val) : MetricInterface
    {
        $this->name = $val;
        return $this;
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