<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\Events\AfterSubstep;
use axenox\BDT\Behat\Events\BeforeSubstep;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Exceptions\AjaxException;
use axenox\BDT\Exceptions\FacadeNodeException;
use axenox\BDT\Exceptions\FacadeNodeScriptException;
use axenox\BDT\Exceptions\UIException;
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
use PHPUnit\Framework\Assert;

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

    /**
     * @return Session
     */
    public function getSession() : Session
    {
        return $this->session;
    }

    /**
     * Returns the Mink DOM node element representing the widget.
     * 
     * @return NodeElement
     */
    public function getNodeElement() : NodeElement
    {
        return $this->domNode;
    }

    /**
     * @return UI5Browser
     */
    public function getBrowser(): UI5Browser
    {
        if ($this->browser === null) {
            throw new \RuntimeException('BDT Browser not initialized on node! Did you forget to call setBrowser()?');
        }
        return $this->browser;
    }

    /**
     * {@inheritDoc}
     * @see FacadeNodeInterface::getCaption()
     */
    public function getCaption(): string
    {
        return strstr($this->getNodeElement()->getAttribute('aria-label'), "\n", true);
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
        if (!$isTranslated) {
            $caption = $this->getBrowser()
                ->getWorkbench()
                ->getCoreApp()
                ->getTranslator($this->getBrowser()->getLocale())
                ->translate($caption);
        }

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

        // If scope is given, search ONLY within scope — throw if not found there
        if ($scope !== null) {
            $scope = $this->getWidgetScope($scope);
            $candidates = $scope->findAll('xpath', $xpath);
            foreach (array_reverse($candidates) as $el) {
                if ($this->isElementVisibleInBrowser($el)) {
                    return $el;
                }
            }
            return null;
        }

        // No scope: fall back to full page search
        $candidates = $this->getSession()->getPage()->findAll('xpath', $xpath);
        foreach (array_reverse($candidates) as $el) {
            if ($this->isElementVisibleInBrowser($el)) {
                return $el;
            }
        }

        return null;
    }
    
    /**
     * Returns the nearest widget root ancestor that contains both
     * the toolbar and the content area of a widget.
     *
     * Use this to resolve the correct scope before passing it to
     * findVisibleButtonByCaption() when the button lives outside
     * the element you have at hand (e.g. toolbar above a sapUiTable).
     *
     * Priority:
     *  1. Open dialog  — never escape a dialog boundary
     *  2. article|section with data-sap-ui-render — SAP UI5 widget root
     *  3. Nearest exfw ancestor — ExFace widget container fallback
     */
    public function getWidgetScope(NodeElement $inner): NodeElement
    {
        // 1. If inside a dialog, the dialog itself is the boundary
        $dialog = $inner->find('xpath',
            'ancestor-or-self::*[@role="dialog"][1]'
        );
        if ($dialog !== null) {
            return $dialog;
        }

        // 2. Nearest SAP UI5 rendered widget root (article or section)
        $sapRoot = $inner->find('xpath',
            'ancestor-or-self::*[@data-sap-ui-render and (self::article or self::section)][1]'
        );
        if ($sapRoot !== null) {
            return $sapRoot;
        }

        // 3. Fallback: nearest ExFace widget container
        $exfRoot = $inner->find('xpath',
            'ancestor-or-self::*[contains(concat(" ",normalize-space(@class)," ")," exfw ")][1]'
        );

        return $exfRoot ?? $inner;
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
     *  Execution order on failure:
     *    1. Exception is caught.
     *    2. Screenshot is captured — the browser is still in the failed state,
     *       so the screenshot reflects exactly what went wrong (e.g. an error dialog is still visible).
     *    3. If the exception is a UI5DialogException, the error dialog is dismissed
     *       so the DOM is unblocked for subsequent interactions.
     *    4. The optional $onFailure callback is invoked — use this for cleanup that must
     *       happen after the screenshot but before the exception propagates (e.g. back-navigation
     *       after a tile click so the browser is not left on the wrong page).
     *    5. The exception is rethrown.
     *
     *  The $onFailure callback is intentionally separate from the main $fn closure so that
     *  cleanup logic does not interfere with the screenshot: anything inside $fn that runs
     *  after the failure point would change the browser state before the screenshot is taken.
     * 
     * @param callable $callable
     * @param string $title
     * @param string|null $category
     * @param LogBookInterface|null $logbook
     * @param callable|null $onFailure Optional cleanup callback invoked after screenshot
     *                                 and dialog dismiss, but before the exception is
     *                                 rethrown. Exceptions thrown inside this callback
     *                                 are silently swallowed to preserve the original error.
     * @return SubstepResult
     */
    public function runAsSubstep(
        callable $callable,
        string $title,
        ?string $category = null,
        ?LogBookInterface $logbook = null,
        callable $onFailure = null
    ) : SubstepResult
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
            $this->getBrowser()->captureScreenshot($logbook);
            $substepResult = SubstepResult::createFailed($e, $logbook);
            ErrorManager::getInstance()->logException($e, $this->getBrowser()->getWorkbench());
            if ($e instanceof UIException || $e instanceof AjaxException) {
                $this->getBrowser()->dismissErrorDialogIfPresent();
            }
            if ($onFailure !== null) {
                try {
                    ($onFailure)();
                } catch (\Throwable $ignored) {}
            }
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

    public function waitWhileBusy(int|float $timeoutSeconds = 30) : FacadeNodeInterface
    {
        usleep(100);
        $this->getSession()->wait(
            $timeoutSeconds * 1000,
            <<<JS
            (function() {
                var element = sap.ui.getCore().byId('{$this->getElementId()}');
                
                if (!element || typeof element.isBusy === "undefined") {
                    return true;
                }
                
                return element.isBusy() === false;
            })()
            JS
        );
        return $this;
    }
    
    protected function checkCaptionMatchesWidget() : FacadeNodeInterface
    {
        $widgetCaption = $this->getWidget()->getCaption();
        $nodeCaption = $this->getCaption();
        Assert::assertEquals(trim($widgetCaption), trim($nodeCaption), 'Widget caption "' . $widgetCaption . '" does not match rendered caption "' . $nodeCaption . '"');
        return $this;
    }
}