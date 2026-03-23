<?php
namespace axenox\BDT\Exceptions;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;

class FacadeNodeScriptException extends FacadeNodeException
{
    private string $script;

    public function __construct(FacadeNodeInterface $node, string $script, $message, $alias = null, $previous = null)
    {
        parent::__construct($node, $message, $alias, $previous);
        $this->script = $script;
    }

    /**
     * @return string
     */
    public function getScript() : string
    {
        return $this->script;
    }

    /**
     * {@inheritDoc}
     * @see FacadeNodeException::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        $debugMessage = parent::createDebugWidget($debugMessage);
        $tab = $debugMessage->createTab();
        $tab->setCaption('Script');
        $tab->addWidget(WidgetFactory::createFromUxonInParent($tab, new UxonObject([
            'widget_type' => 'InputCode',
            'disabled' => true,
            'height' => '100%',
            'width' => '100%',
            'hide_caption' => true,
            'value' => $this->getScript()
        ])));
        $debugMessage->addTab($tab);
        return $debugMessage;
    }
}