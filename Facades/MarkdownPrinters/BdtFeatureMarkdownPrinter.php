<?php
namespace axenox\BDT\Facades\MarkdownPrinters;

use axenox\BDT\Common\BdtFeature;
use axenox\BDT\Common\Selectors\BdtFeatureSelector;
use exface\Core\Facades\DocsFacade\MarkdownPrinters\AbstractMarkdownPrinter;
use exface\Core\Interfaces\Facades\MarkdownInstancePrinterInterface;
use exface\Core\Interfaces\Facades\MarkdownPrinterInterface;
use exface\Core\Interfaces\WorkbenchInterface;

class BdtFeatureMarkdownPrinter extends AbstractMarkdownPrinter implements MarkdownInstancePrinterInterface
{
    private BdtFeature $feature;

    /**
     * Selects a vendor-relative feature file; absolute paths and traversal are forbidden.
     */
    public function __construct(WorkbenchInterface $workbench, string $path)
    {
        $this->feature = BdtFeature::createFromSelector(new BdtFeatureSelector($workbench, $path));
    }

    /**
     * Builds the feature printer from the registry-created BDT component.
     */
    public static function constructForInstance(object $instance): MarkdownPrinterInterface
    {
        if (!$instance instanceof BdtFeature) {
            throw new \InvalidArgumentException('The BDT feature printer requires a BdtFeature component.');
        }
        $printer = new self($instance->getSelector()->getWorkbench(), $instance->getSelector()->toString());
        $printer->feature = $instance;
        return $printer;
    }

    /**
     * Prints the selected feature definition, independently of any recorded test run.
     */
    public function getMarkdown(): string
    {
        $path = $this->feature->getSelector()->toString();
        $content = $this->feature->getContents();
        preg_match_all('/`+/', $content, $matches);
        $length = 3;
        foreach ($matches[0] as $match) {
            $length = max($length, strlen($match) + 1);
        }
        $fence = str_repeat('`', $length);

        return '# ' . $this->escapeMarkdownText(basename($path)) . "\n\n"
            . $this->escapeMarkdownText($path) . "\n\n"
            . $fence . "gherkin\n" . $content . "\n" . $fence . "\n";
    }
}
