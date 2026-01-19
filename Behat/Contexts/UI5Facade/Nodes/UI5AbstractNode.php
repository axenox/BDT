<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use exface\Core\DataTypes\StringDataType;
use Behat\Mink\Session;
use exface\Core\Interfaces\Model\UiPageInterface;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    private $domNode = null;
    private $session = null;

    /** @var UI5Browser|null */
    protected $browser;

    public function __construct(NodeElement $nodeElement, Session $session, UI5Browser $browser)
    {
        $this->domNode = $nodeElement;
        $this->session = $session;
        $this->browser = $browser;
    }
    
    public function getSession() : Session
    {
        return $this->session;
    }  

    public function getNodeElement() : NodeElement
    {
        return $this->domNode;
    }

    public function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            throw new \RuntimeException('BDT Browser not initialized on node! Did you forget to call setBrowser()?');
        }
        return $this->browser;
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

    public function itWorksAsExpected(UiPageInterface $page): void
    {
        
    }
}