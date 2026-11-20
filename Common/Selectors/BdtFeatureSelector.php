<?php
namespace axenox\BDT\Common\Selectors;

use exface\Core\CommonLogic\Selectors\AbstractSelector;
use exface\Core\Interfaces\WorkbenchInterface;

class BdtFeatureSelector extends AbstractSelector
{
    /**
     * Selects only vendor-relative feature paths, rejecting traversal and control characters.
     */
    public function __construct(WorkbenchInterface $workbench, string $selectorString)
    {
        if (strlen($selectorString) > 4096 || preg_match('/[\x00-\x1f]/', $selectorString) === 1) {
            throw new \InvalidArgumentException('Feature paths must be at most 4096 characters and contain no control characters.');
        }
        $path = str_replace('\\', '/', trim($selectorString));
        if (preg_match('~^[a-zA-Z0-9_-]+/[a-zA-Z0-9_.-]+/.+\.feature$~D', $path) !== 1
            || preg_match('~(^|/)\.\.?(/|$)|[:\x00-\x1f]~', $path) === 1) {
            throw new \InvalidArgumentException('Use a vendor-relative .feature file path.');
        }
        parent::__construct($workbench, $path);
    }

    /**
     * Identifies the app-owned registry component.
     */
    public function getComponentType(): string
    {
        return 'bdt_feature';
    }
}
