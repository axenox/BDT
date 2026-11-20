<?php
namespace axenox\BDT\Common\Selectors;

use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\Interfaces\WorkbenchInterface;

class BdtRunSelector extends AbstractSelector
{
    /**
     * Selects a stored run by UID or one scenario tag.
     */
    public function __construct(WorkbenchInterface $workbench, string $selectorString)
    {
        $selectorString = trim($selectorString);
        if (strlen($selectorString) > 4096
            || preg_match('/^(?:0x[0-9a-f]{32}|@?[a-z0-9_][a-z0-9_.-]*)$/iD', $selectorString) !== 1) {
            throw new \InvalidArgumentException('Use a BDT run UID or a single Gherkin tag such as "@smoke".');
        }
        parent::__construct($workbench, $selectorString);
    }

    /**
     * Identifies the app-owned registry component.
     */
    public function getComponentType(): string
    {
        return 'bdt_run';
    }
}
