<?php
namespace axenox\BDT\Exceptions;

use Behat\Behat\Hook\Scope\AfterStepScope;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

/**
 * Custom exception class for Facade Browser testing
 * Extends RuntimeException to provide detailed information about Behat test failures
 */
class FacadeBrowserException extends RuntimeException
{
    // Store the Behat scope and additional information about the exception
    private $scope = null;
    private $info = null;

    /**
     * Constructor for the FacadeBrowserException
     * 
     * @param string $message Error message
     * @param string|null $alias Error alias
     * @param \Exception|null $previous Previous exception
     * @param AfterStepScope|null $behatScope Behat test step scope
     * @param array $info Additional information about the exception
     */
    public function __construct($message, $alias = null, $previous = null, AfterStepScope $behatScope = null, array $info)
    {
        // Call parent constructor
        parent::__construct($message, $alias, $previous);
        // Store Behat scope and additional info

        $this->scope = $behatScope;
        $this->info = $info;
    }

    /**
     * Get the Behat test step scope
     * 
     * @return AfterStepScope Behat test step scope
     */
    public function getBehatScope(): AfterStepScope
    {
        return $this->scope;
    }

    /**
     * Get the error type from the info array
     * 
     * @return string Error type or 'Unknown' if not set
     */
    public function getType(): string
    {
        // Info array içinden error type'ı alın
        return $this->info['errorType'] ?? 'Unknown';
    }

    /**
     * Get the URL associated with the exception
     * 
     * @return string URL or 'No URL' if not set
     */
    public function getUrl(): string
    {
        return $this->info['url'] ?? 'No URL';
    }

    /**
     * Get the full additional information array
     * 
     * @return array Additional exception information
     */
    public function getInfo(): array
    {
        return $this->info;
    }

    /**
     * Generate a markdown-formatted table with Behat scope details
     * 
     * @return string Markdown table with scope information
     */
    public function getScopeDetails(): string
    {
        // Return default message if no scope is available

        if ($this->scope === null) {
            return 'No Behat scope available';
        }

        // Extract step and result information
        $step = $this->scope->getStep();
        $result = $this->scope->getTestResult();

        // Create an array of scope details
        $details = [
            'Step Text' => $step->getText(),
            'Step Keyword' => $step->getKeyword(),
            'Step Line' => $step->getLine(),
            'Result Status' => get_class($result)
        ];

        // Convert details to a markdown table
        return MarkdownDataType::buildMarkdownTableFromArray($details);
    }

    /**
     * Generate a CLI-friendly output of the exception details
     * 
     * @return string Formatted CLI output
     */
    public function toCliOutput(): string
    {
        // Convert simple info values to a string
        $infoStr = '';
        foreach ($this->info as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $infoStr .= "\n{$key}: {$value}";
            }
        }

        // Add Behat scope details if available
        $scopeDetails = '';
        if ($this->scope) {
            $scopeDetails = "\n\nBehat Scope:\n" . str_replace('|', "\t", $this->getScopeDetails());
        }

        // Return formatted CLI output
        return <<<CLI
    
    Message: {$this->getMessage()}
    Type: {$this->getType()}
    URL: {$this->getUrl()}
    Info:{$infoStr}{$scopeDetails}
    CLI;
    }


    /**
     * Generate a markdown-formatted description of the exception
     * 
     * @return string Markdown-formatted exception details
     */
    public function toMarkdown(): string
    {
        // Check if info array has content before trying to build a table
        $infoSection = '';
        if (!empty($this->info)) {
            $infoTable = MarkdownDataType::buildMarkdownTableFromArray($this->info);
            $infoSection = "### Additional Information\n{$infoTable}";
        } else {
            $infoSection = "### Additional Information\n*No additional information available*";
        }

        // Get Behat scope details
        $scopeDetails = $this->getScopeDetails();
        $scopeSection = !empty($scopeDetails) ? "### Behat Scope\n{$scopeDetails}" : "";

        // Return markdown-formatted exception details
        return <<<MARKDOWN

## Exception Details
**Message:** {$this->getMessage()}
**Type:** {$this->getType()}
**URL:** {$this->getUrl()}

{$infoSection}

{$scopeSection}
MARKDOWN;
    }

    /**
     * Create a debug widget with exception details
     * 
     * @param DebugMessage $debugMessage Debug message widget to modify
     * @return DebugMessage Modified debug message widget
     */
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        // Create a new tab in the debug message
        $tab = $debugMessage->createTab();
        $tab->setCaption('Behat');
        // Add a markdown widget with exception details
        $tab->addWidget(WidgetFactory::createFromUxonInParent($tab, new UxonObject([
            'widget_type' => 'Markdown',
            'height' => '100%',
            'width' => '100%',
            'hide_caption' => true,
            'value' => $this->toMarkdown()
        ])));

        // Add the tab to the debug message
        $debugMessage->addTab($tab);
        return $debugMessage;
    }
}
