<?php
namespace axenox\BDT\Exceptions;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

class TracerException extends RuntimeException
{
    private $details;
    
    public function __construct($message, $alias = null, $previous = null, ?array $details = null)
    {
        $this->details = $details;
        parent::__construct($message, $alias, $previous);
    }

    /**
     * {@inheritDoc}
     * @see RuntimeException::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        $debugMessage = parent::createDebugWidget($debugMessage);
        $tab = $debugMessage->createTab();
        $tab->setCaption($this->getBrowserName());
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
    
    public function getDetails() : array
    {
        return $this->details;
    }
    
    
    protected function toMarkdown() : string
    {
        $infoTable = MarkdownDataType::buildMarkdownTableFromArray($this->getDetails());
        return <<<MD
** {$this->getMessage()} **
{$infoTable}
MD;
    }
}