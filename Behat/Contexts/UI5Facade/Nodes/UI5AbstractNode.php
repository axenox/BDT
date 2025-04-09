<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\DataTypes\StringDataType;
use Behat\Mink\Session;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    private $domNode = null;
    private $session = null;

    public function __construct(NodeElement $nodeElement, Session $session)
    {
        $this->domNode = $nodeElement;
        $this->session = $session;
    }
    
    public function getSession() : Session
    {
        return $this->session;
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