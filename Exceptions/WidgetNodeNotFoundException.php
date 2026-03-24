<?php
namespace axenox\BDT\Exceptions;

use Behat\Mink\Element\NodeElement;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

class WidgetNodeNotFoundException extends RuntimeException
{
    private string $nodeElement;

    public function __construct(NodeElement $node, $message, $alias = null, $previous = null)
    {
        parent::__construct($message, $alias, $previous);
        $this->nodeElement = $node;
    }

    public function getNodeElement() : NodeElement
    {
        return $this->nodeElement;
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
            'widget_type' => 'InputCode',
            'language' => 'html',
            'disabled' => true,
            'height' => '100%',
            'width' => '100%',
            'hide_caption' => true,
            'value' => $this->getFacadeNode()->getNodeElement()->getOuterHtml()
        ])));
        
        $debugMessage->addTab($tab);
        return $debugMessage;
    }
}