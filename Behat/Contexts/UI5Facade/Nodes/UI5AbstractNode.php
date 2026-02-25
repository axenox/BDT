<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use exface\Core\DataTypes\StringDataType;
use Behat\Mink\Session;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Debug\LogBookInterface;
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

    public function itWorksAsExpected(LogBookInterface $logbook): void
    {        
        $widgetType = $this->getWidgetType();
        /*$widgetDomeNode = self::findWidgetNode($this->getNodeElement());
        $elementId = $widgetDomeNode->getAttribute('id');
        $widget = $this->getWidgetFromElementId($elementId);
        $mainObject = $widget->getMetaObject();
        $tableCaption = !empty($this->getCaption()) ?
            '`' .$this->getCaption() . '`' :
           '[' . MarkdownDataType::escapeString($mainObject->__toString()) . '](' . DocsFacade::buildUrlToDocsForMetaObject($mainObject) . ')' ;*/
        $logbook->addLine( 'Looking at `' . $widgetType . '` ' . $this->getCaption());
        
        //is this function override
        $declaring = (new \ReflectionMethod($this, 'itWorksAsExpected'))->getDeclaringClass()->getName();
        $hasCustom = ($declaring !== self::class);
        if (!$hasCustom) {
            $visible = $this->isVisible();
            if ($visible) {
                $logbook->addIndent(1);
                $logbook->addLine('Seeing a ' . $widgetType);
                $logbook->addIndent(-1);
            }
            
        }
    }

    /**
     * @param string $ui5ElementId
     * @param UiPageInterface|null $page
     * @return WidgetInterface
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
        return substr($widget->getPage()->getUid(),1) . '__' . $widget->getId();
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
        return $innerDomNode;
    }
    
    public function findVisibleButtonByCaption(string $translated, ?NodeElement $scope = null): ?NodeElement
    {
        // 1) Search scoped first (important in UI5: previous pages stay in DOM but are hidden)
        $contexts = [];
        if ($scope) {
            $contexts[] = $scope;
        }
        $contexts[] = $this->getSession()->getPage();

        // Prefer robust selectors that match UI5 button structure:
        // - <button ...>
        // - inside: <bdi>Caption</bdi>
        // - OR title/aria-label equals caption (depending on UI5 control)
        $xpath = sprintf(
            ".//button[
            .//bdi[normalize-space(.)=%s]
            or normalize-space(@title)=%s
            or normalize-space(@aria-label)=%s
        ]",
            $this->xpathLiteral($translated),
            $this->xpathLiteral($translated),
            $this->xpathLiteral($translated)
        );

        foreach ($contexts as $ctx) {
            $candidates = $ctx->findAll('xpath', $xpath);
            if (!$candidates) {
                continue;
            }

            // 2) Filter only *actually visible* elements (UI5 keeps hidden duplicates in DOM)
            foreach (array_reverse($candidates) as $el) {
                if ($this->isElementVisibleInBrowser($el)) {
                    return $el;
                }
            }
        }

        return null;
    }

    public function isElementVisibleInBrowser(NodeElement $el): bool
    {
        $id = $el->getAttribute('id');
        if (!$id) {
            return false;
        }

        $idJs = json_encode($id, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $script = <<<JS
(function(){
  var el = document.getElementById($idJs);
  if (!el) return false;

  // Check aria-hidden on ancestors
  for (var p = el; p; p = p.parentElement) {
    if (p.getAttribute && p.getAttribute('aria-hidden') === 'true') return false;
  }

  var cs = window.getComputedStyle(el);
  if (!cs) return false;
  if (cs.display === 'none' || cs.visibility === 'hidden') return false;

  var opacity = parseFloat(cs.opacity || '1');
  if (opacity <= 0) return false;

  var rect = el.getBoundingClientRect();
  if (!rect || (rect.width <= 0 && rect.height <= 0)) return false;

  return true;
})();
JS;

        return (bool) $this->getSession()->evaluateScript($script);
    }


    /**
     * Safely quote arbitrary strings for XPath literal usage.
     */
    public function xpathLiteral(string $value): string
    {
        // If the string contains no single quotes, we can wrap it in single quotes.
        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }
        // Otherwise build concat('a', "'", 'b', ...)
        $parts = explode("'", $value);
        $out = "concat(";
        foreach ($parts as $i => $p) {
            if ($i > 0) {
                $out .= ", \"'\", ";
            }
            $out .= "'" . $p . "'";
        }
        $out .= ")";
        return $out;
    }

    public function isVisible(): bool
    {
        return $this->getNodeElement()->isVisible();
    }
    
    public function runAsSubstep(callable $callable, string $title, ?string $category = null, ?LogBookInterface $logbook) : AfterSubstep
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($title, $category));
        try {
            $title = $callable() ?? $title;
            $resultEvent = new AfterSubstep($title, $category);
        } catch (\Throwable $e) {
            $logbook?->addLine('**ERROR:** ' . $e->getMessage());
            $this->getBrowser()->captureScreenshot();
            $resultEvent = new AfterSubstep($title, $category, $e);
        }
        $dispatcher->dispatch($resultEvent);
        return $resultEvent;
    }
}