<?php
namespace axenox\BDT\Exceptions;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use exface\Core\CommonLogic\UxonObject;
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
    
    protected function toMarkdown() : string
    {
        return <<<MD

- Widget type: {$this->getFacadeNode()->getWidgetType()}
- Caption: {$this->getFacadeNode()->getCaption()}

```
{$this->getFacadeNode()->getNodeElement()->getOuterHtml()}
```
MD;

    }
}