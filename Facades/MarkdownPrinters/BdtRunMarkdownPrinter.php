<?php
namespace axenox\BDT\Facades\MarkdownPrinters;

use axenox\BDT\Common\BdtRun;
use exface\Core\Interfaces\Facades\MarkdownInstancePrinterInterface;
use exface\Core\Interfaces\Facades\MarkdownPrinterInterface;

class BdtRunMarkdownPrinter extends AbstractBdtMarkdownPrinter implements MarkdownInstancePrinterInterface
{
    /**
     * Builds the run printer from the registry-created BDT component.
     */
    public static function constructForInstance(object $instance): MarkdownPrinterInterface
    {
        if (!$instance instanceof BdtRun) {
            throw new \InvalidArgumentException('The BDT run printer requires a BdtRun component.');
        }
        $selector = $instance->getSelector();
        return new self($selector->getWorkbench(), $selector->toString());
    }

    /**
     * Prints a complete test run selected by UID or scenario tag.
     */
    public function getMarkdown(): string
    {
        return $this->getRunMarkdown();
    }
}
