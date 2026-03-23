<?php
namespace axenox\BDT\Exceptions;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\HtmlDataType;
use exface\Core\DataTypes\MarkdownDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

class FacadeNodeException extends RuntimeException
{
    private FacadeNodeInterface $node;

    public function __construct(FacadeNodeInterface $node, $message, $alias = null, $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->node = $node;
    }
    
    public function getFacadeNode() : FacadeNodeInterface
    {
        return $this->node;
    }

    /**
     * {@inheritDoc}
     * @see RuntimeException::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        $debugMessage = parent::createDebugWidget($debugMessage);
        $tab = $debugMessage->createTab();
        $tab->setCaption('DOM node');
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
    
    protected function toMarkdown() : string
    {
        $html = $this->getFacadeNode()->getNodeElement()->getOuterHtml();
        try {
            $html = HtmlDataType::prettify($html);
        } catch (\Exception $e) {
            $this->getFacadeNode()->getWorkbench()->getLogger()->logException($e->getMessage());
        }
        $html = MarkdownDataType::escapeCodeBlock($html, 'html');
        return <<<MD

- Widget type: {$this->getFacadeNode()->getWidgetType()}
- Caption: {$this->getFacadeNode()->getCaption()}

{$html}

MD;

    }
}