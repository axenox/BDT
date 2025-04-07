<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\DataTypes\StringDataType;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    private $domNode = null;
    public function __construct(NodeElement $nodeElement)
    {
        $this->domNode = $nodeElement;
    }

    public function getNodeElement() : NodeElement
    {
        return $this->domNode;
    }

    public function getWidgetType() : ?string
    {
        $firstWidgetChild = $this->getNodeElement()->find('css', '.exfw');
        $cssClasses = explode(' ', $firstWidgetChild->getAttribute('class'));
        foreach ($cssClasses as $class) {
            if ($class === '.exfw') {
                continue;
            }
            if (StringDataType::startsWith($class, 'exfw-')) {
                $widgetType = StringDataType::substringAfter($class, 'exfw-');
                break;
            }
        }
        return $widgetType;
    }

    public function capturesFocus() : bool
    {
        return true;
    }
}