<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Exceptions\FacadeNodeScriptException;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Exception\DriverException;
use Behat\Mink\Session;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use exface\Core\Interfaces\WorkbenchDependantInterface;

abstract class UI5AbstractNode implements FacadeNodeInterface
{
    private $domNode = null;
    private $session = null;

    /** @var UI5Browser|null */
    protected $browser;
    
    private ?WidgetInterface $widget = null;

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
        if (null !== $thisElementClass = UI5FacadeNodeFactory::findWidgetType($this->getNodeElement())) {
            return $thisElementClass;
        }
        $firstWidgetChild = $this->getNodeElement()->find('css', '.exfw');
        if (! $firstWidgetChild) {
            throw new FacadeNodeException($this, 'Cannot find widget inside of DOM node "' . $this->getNodeElement()->getXpath() . '"');
        }
        $widgetType = UI5FacadeNodeFactory::findWidgetType($firstWidgetChild);
        return $widgetType;
    }

    public function capturesFocus() : bool
    {
        return true;
    }

    public function checkWorksAsExpected(LogBookInterface $logbook) : TestResultInterface
    {        
        $widgetType = $this->getWidgetType();
        $logbook->addLine( 'No checks defined at `' . $widgetType . '` ' . $this->getCaption());
        return SubstepResult::createPassed($logbook);
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
    
    public function findVisibleButtonByCaption(string $caption, bool $isTranslated, ?NodeElement $scope = null): ?NodeElement
    {
        if(! $isTranslated) {
            $caption = $this->getBrowser()
                ->getWorkbench()
                ->getCoreApp()
                ->getTranslator($this->getBrowser()->getLocale())
                ->translate($caption);
        }
        
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
            $this->xpathLiteral($caption),
            $this->xpathLiteral($caption),
            $this->xpathLiteral($caption)
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

    /**
     * Runs test substep defined by the given callable and returns the corresponding result object
     * 
     * The $callable will receive the default result object as argument and may modify it or return
     * a new one. If the callable does not return anything, it will not fail - the default result
     * will be used. If the callable throws an exception, a failed result will be created automatically
     * 
     * @param callable $callable
     * @param string $title
     * @param string|null $category
     * @param LogBookInterface|null $logbook
     * @return SubstepResult
     */
    public function runAsSubstep(callable $callable, string $title, ?string $category = null, ?LogBookInterface $logbook = null) : SubstepResult
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($title, $category));
        try {
            $substepResult = SubstepResult::createPassed($logbook);
            $substepResult->setTitle($title);
            $returnValue = $callable($substepResult);
            if ($returnValue instanceof SubstepResult) {
                $substepResult = $returnValue;
            }
        } catch (\Throwable $e) {
            $logbook?->addLine('**ERROR:** ' . $e->getMessage());
            $this->getBrowser()->captureScreenshot();
            $substepResult = SubstepResult::createFailed($e, $logbook);
            ErrorManager::getInstance()->logException($e, $this->getBrowser()->getWorkbench());
            // IMPORTANT: reset the node to make sure subsequent tests find it in the same state as it
            // would be if no error happened!
            $logbook->continueLine(' - resetting ' . $this->getWidgetType());
            $this->reset();
        }
        $resultEvent = new AfterSubstep($substepResult, $substepResult->getTitle() ?? $title, $category);
        $dispatcher->dispatch($resultEvent);
        return $substepResult;
    }

    public function logSubstep(string $title, int $resultCode, ?string $reason, ?string $category = null) : AfterSubstep
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($title, $category));
        $result = new SubstepResult($resultCode);
        if ($reason !== null) {
            $result->setReason($reason);
        }
        $resultEvent = new AfterSubstep($result, $title, $category);
        $dispatcher->dispatch($resultEvent);
        return $resultEvent;
    }
    
    protected function logSubstepResult(SubstepResult $result, ?string $category = null) : AfterSubstep
    {
        $dispatcher = $this->getBrowser()->getEventDispatcher();
        $dispatcher->dispatch(new BeforeSubstep($result->getTitle(), $category));
        $resultEvent = new AfterSubstep($result, $result->getTitle(), $category);
        $dispatcher->dispatch($resultEvent);
        return $resultEvent;
    }

    /**
     * @return string
     */
    public function getElementId() : string
    {
        return $this->getNodeElement()->getAttribute('id');
    }

    /**
     * @return WidgetInterface#
     */
    public function getWidget() : WidgetInterface
    {
        if ($this->widget === null) {
            $elementId = $this->getElementId();
            $this->widget = $this->getWidgetFromElementId($elementId);
        }
        return $this->widget;
    }

    /**
     * {@inheritDoc}
     * @see FacadeNodeInterface::reset()
     */
    public function reset() : FacadeNodeInterface
    {
        return $this;
    }

    /**
     * Returns the result of the given JavaScript snippet
     * 
     * The script must evaluate to a scalar value. It is a good idea to wrap the script in an iife:
     * 
     * ```
     *  (function(oInput, sDelim){
     *      var aTokens = oInput.getTokens();
     *      var sVal = '';
     *      aTokens.forEach(function(oToken) {
     *          sVal += (sVal === '' ? '' : sDelim) + oToken.getText();
     *      });
     *      return sVal;
     *  })(sap.ui.getCore().byId('{$this->getElementId()}'), '{$this->getWidget()->getMultiSelectTextDelimiter()}')
     * 
     * ```
     * 
     * @param string $script
     * @return mixed
     */
    protected function getFromJavascript(string $script)
    {
        try {
            return $this->getSession()->evaluateScript($script);
        } catch (\Throwable $e) {
            throw new FacadeNodeScriptException($this, $script, $e->getCode(), null, $e);
        }
    }

    /**
     * {@inheritDoc}
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->getBrowser()->getWorkbench();
    }
    
    public function checkDisabled(): bool
    {
        return false;
    }
}