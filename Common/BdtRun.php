<?php
namespace axenox\BDT\Common;

use axenox\BDT\Common\Selectors\BdtRunSelector;

class BdtRun
{
    private BdtRunSelector $selector;

    /**
     * Retains a lazy run selection; data is read when the report is requested.
     */
    public function __construct(BdtRunSelector $selector)
    {
        $this->selector = $selector;
    }

    /**
     * Creates the run component through the component registry's selector factory.
     */
    public static function createFromSelector(BdtRunSelector $selector): self
    {
        return new self($selector);
    }

    /**
     * Returns the run identity and its workbench context.
     */
    public function getSelector(): BdtRunSelector
    {
        return $this->selector;
    }
}
