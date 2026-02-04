<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use exface\Core\DataTypes\StringDataType;
use Behat\Mink\Session;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;

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

    public function itWorksAsExpected(): void
    {
        
    }

    /**
     * @param string $ui5ElementId
     * @return string
     */
    protected function getWidgetFromElementId(string $ui5ElementId, ?UiPageInterface $page = null) : WidgetInterface
    {
        list($pageUid, $widgetId) = explode('__', $ui5ElementId);
        // Make sure the page UID has the 0x-format
        $pageUid = '0' . ltrim($pageUid, '0');
        if ($page === null) {
            $page = UiPageFactory::createFromModel($this->browser->getWorkbench(), $pageUid);
        }
        return $page->getWidget($widgetId);
    }

    /**
     * 
     * $this->getElementIdFromWidget($page->getWidgetRoot())
     * 
     * @param WidgetInterface $widget
     * @return string
     */
    protected function getElementIdFromWidget(WidgetInterface $widget) : string
    {
        return $widget->getPage()->getUid() . '__' . $widget->getId();
    }
    
    public static function findWidgetNode(NodeElement $innerDomNode) : NodeElement
    {
        if ($innerDomNode->hasClass('exfw')) {
            return $innerDomNode;
        }
        
        try {
            $currentDomNode = $innerDomNode;
            while ($parentDomNode = $currentDomNode->getParent()) {
                if ($parentDomNode->hasClass('exfw')) {
                    return $parentDomNode;
                }
                $currentDomNode = $parentDomNode;
            }
        } catch (DriverException $e) {
            return $innerDomNode;
        }
    }
}