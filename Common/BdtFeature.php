<?php
namespace axenox\BDT\Common;

use axenox\BDT\Common\Selectors\BdtFeatureSelector;

class BdtFeature
{
    private BdtFeatureSelector $selector;

    /**
     * Retains the validated vendor-relative feature identity.
     */
    public function __construct(BdtFeatureSelector $selector)
    {
        $this->selector = $selector;
    }

    /**
     * Creates a feature component independently of recorded test runs.
     */
    public static function createFromSelector(BdtFeatureSelector $selector): self
    {
        return new self($selector);
    }

    /**
     * Returns the feature identity and its workbench context.
     */
    public function getSelector(): BdtFeatureSelector
    {
        return $this->selector;
    }

    /**
     * Reads only regular feature files canonically contained in vendor, including after symlink resolution.
     */
    public function getContents(): string
    {
        $path = $this->selector->toString();
        $root = realpath($this->selector->getWorkbench()->filemanager()->getPathToVendorFolder());
        $file = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path));
        if ($file === false || !is_file($file)) {
            throw new \OutOfBoundsException('Feature file not found: ' . $path);
        }
        $prefix = $root . DIRECTORY_SEPARATOR;
        if (strncasecmp($file, $prefix, strlen($prefix)) !== 0
            || strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'feature') {
            throw new \InvalidArgumentException('The feature file must be inside the vendor directory.');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException('Cannot read feature file: ' . $path);
        }
        return $content;
    }
}
