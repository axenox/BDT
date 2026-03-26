<?php
namespace axenox\BDT\Common\Selectors;

use axenox\BDT\Interfaces\Selectors\BdtMetricSelectorInterface;
use exface\Core\CommonLogic\Selectors\AbstractSelector;
use axenox\GenAI\Interfaces\Selectors\AiAgentSelectorInterface;
use exface\Core\CommonLogic\Selectors\Traits\PrototypeSelectorTrait;
use exface\Core\CommonLogic\Selectors\Traits\ResolvableNameSelectorTrait;

/**
 * Generic implementation of the BdtMetricSelectorInterface.
 *
 * @see AiAgentSelectorInterface
 *
 * @author Andrej Kabachnik
 *
 */
class BdtMetricSelector extends AbstractSelector implements BdtMetricSelectorInterface
{
    use ResolvableNameSelectorTrait;

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Selectors\SelectorInterface::getComponentType()
     */
    public function getComponentType() : string
    {
        return 'BDT metric';
    }
}