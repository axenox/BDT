<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
use axenox\bdt\Behat\DatabaseFormatter\SubstepResult;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use axenox\BDT\Interfaces\TestResultInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use exface\Core\Interfaces\WidgetInterface;
use PHPUnit\Framework\Assert;
use Throwable;

class UI5PageNode implements FacadeNodeInterface
{
    /** @var UI5Browser|null */
    protected $browser;
    private string $pageSelector;
    private ?UiPageInterface $page = null;
    private $session = null;

    public function __construct(string $pageSelector, Session $session, UI5Browser $browser)
    {
        $this->pageSelector = $pageSelector;
        $this->session = $session;
        $this->browser = $browser;
    }

    public static function findWidgetNode(NodeElement $innerDomNode): NodeElement
    {
        return $innerDomNode;
    }

    /**
     * A page is always addressed through the element of its root widget.
     *
     * {@inheritDoc}
     * @see FacadeNodeInterface::usesOwnDomElement()
     */
    public function usesOwnDomElement(): bool
    {
        return true;
    }

    /**
     * Returns the DOM element of this page's root widget.
     *
     * WHY the id comes from getElementIdFromWidget() and not from the widget's own getId(): the id a
     * widget carries in the model is only the second half of what the UI5 facade renders - the rendered
     * id is "<pageUid>__<widgetId>". Looking the root up by its bare model id never matched, so this
     * method silently degraded to the <body> fallback and every caller ended up scoped to the whole
     * document instead of to the page root. checkWorksAsExpected() already resolves the very same
     * widget through the browser, so both paths now agree on one id.
     */
    public function getNodeElement(): NodeElement
    {
        $rootElementId = $this->getBrowser()->getElementIdFromWidget($this->getUiPage()->getWidgetRoot());
        return $this->getSession()->getPage()->findById($rootElementId) ?? $this->getSession()->getPage()->find('css', 'body');
    }

    public function getBrowser(): UI5Browser
    {
        return $this->browser;
    }

    public function getUiPage(): UiPageInterface
    {
        if ($this->page === null) {
            $this->page = UiPageFactory::createFromModel($this->getBrowser()->getWorkbench(), $this->pageSelector);
        }
        return $this->page;
    }

    /**
     * {@inheritDoc}
     * @see WorkbenchDependantInterface::getWorkbench()
     */
    public function getWorkbench()
    {
        return $this->getBrowser()->getWorkbench();
    }

    public function getSession(): Session
    {
        return $this->session;
    }

    public function capturesFocus(): bool
    {
        return false;
    }

    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $alias = $this->pageSelector;
        $roles = $this->getBrowser()->getCurrentRoles();
        $logbook ??= new MarkdownLogBook($this->getCaption());
        DatabaseFormatter::addTestLogbook($logbook);

        // Skip re-testing if the same page was already verified for this exact role set.
        // This covers pages that appear in multiple menu locations: a works-as-expected
        // check on any one of them is sufficient for the same user environment.
        if (null !== $prevResult = DatabaseFormatter::hasTestedPage($roles, $alias)) {
            $logbook->addLine('Page already validated for this role set — reusing previous result.');
            return SubstepResult::createFromPrevious($prevResult);
        }

        $rootWidget = $this->getUiPage()->getWidgetRoot();
        $rootElementId = $this->getBrowser()->getElementIdFromWidget($rootWidget);
        $rootNode = $this->getSession()->getPage()->findById($rootElementId);
        Assert::assertNotNull($rootNode, 'Cannot determine the main widget for the current page.(' . $alias . '.html)');

        $widgetType = $rootWidget->getWidgetType();

        $facadeNode = UI5FacadeNodeFactory::createFromWidgetType(
            $widgetType,
            $rootNode,
            $this->getSession(),
            $this->browser,
            $rootWidget
        );

        try {
            $result = $facadeNode->checkWorksAsExpected($logbook);
            DatabaseFormatter::markPageAsTested($roles, $alias, $result);
        } catch (Throwable $e) {
            $failed = SubstepResult::createFailed($e, $logbook);
            DatabaseFormatter::markPageAsTested($roles, $alias, $failed);
            throw $e;
        }
        return $result;
    }

    public function getCaption(): string
    {
        return $this->getUiPage()->getName();
    }

    public function getWidgetType(): ?string
    {
        return $this->getUiPage()->getWidgetRoot()->getWidgetType();
    }

    /**
     * @inheritDoc
     */
    public function reset(): FacadeNodeInterface
    {
        $this->getWidget()->reset();
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getWidget(): WidgetInterface
    {
        return $this->getUiPage()->getWidgetRoot();
    }

    public function checkDisabled(): bool
    {
        return false;
    }
}