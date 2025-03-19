<?php
namespace axenox\BDT\Exceptions;

use Behat\Behat\Hook\Scope\AfterStepScope;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

class FacadeBrowserException extends RuntimeException
{
    private $scope = null;
    private $info = null;

    public function __construct($message, $alias = null, $previous = null, AfterStepScope $behatScope = null, array $info)
    {
        parent::__construct($message, $alias, $previous);
        $this->scope = $behatScope;
        $this->info = $info;
    }

    public function getBehatScope() : AfterStepScope
    {
        return $this->scope;
    }

    public function getType() : string
    {
        return '';
    }

    public function getUrl() : string
    {
        return '';
    }

    public function toCliOutput() : string
    {
        // Original formatting for other error types
        $message = "ERROR DETAILS 1|\n";
        $message .= "===============\n"; 

        $message .= "LogID: " . $this->getId() . "\n";
        $message .= "===================\n";
        $message .= "Type: " . PhpClassDataType::findClassNameWithoutNamespace($this::class) . "\n";
        $message .= "Status: " . ($this->getCode() ?? 'N/A') . "\n";
        $message .= "Message: " . $this->getMessage() . "\n";
        $message .= "Source line: " . $this->scope->getStep()->getLine() . "\n";
        
        return $message;
        /*
        return <<<CLI

Type: {$this->getType()}
URL: {$this->getUrl()}
CLI;*/
    }

    public function toMarkdown() : string
    {
        $infoTable = MarkdownDataType::buildMarkdownTableFromArray($this->info);

        return <<<CLI

Type: {$this->getType()}
URL: {$this->getUrl()}

{$infoTable}
CLI;
    }

    //Creates a debug widget with the given DebugMessage and exception
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        $tab = $debugMessage->createTab();
        $tab->setCaption('Behat');
        $tab->addWidget(WidgetFactory::createFromUxonInParent($tab, new UxonObject([
            'widget_type' => 'Markdown',
            'height' => '100%',
            'width' => '100%',
            'hide_caption' => true,
            'value' => $this->toMarkdown()
        ])));
        $debugMessage->addTab($tab);
        return $debugMessage;
    }
}