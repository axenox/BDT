<?php

namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatter;
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

    /**
     * Validates the page root as one screen-level operation.
     *
     * WHY THERE IS NO SKIP CHECK HERE ANY MORE: the question "was this already validated in this
     * run" is asked once, inside runAsSubstep(), for every kind of covered work. The in-memory cache
     * this method used to consult answered the same question from process memory, which meant it was
     * rebuilt at every feature boundary and invisible to the other lanes - a page reached by three
     * lanes was swept three times, and two scenarios of one feature running under different roles
     * shared its entries.
     *
     * WHY THE SUBSTEP IS CONDITIONAL: runAsSubstep() and the identity builder are declared on
     * UI5AbstractNode, and the factory may return a node that does not extend it. Requiring that type
     * would make coverage recording a precondition of testing the page at all - a screen the registry
     * cannot describe would fail instead of simply going unrecorded and being repeated.
     */
    public function checkWorksAsExpected(LogBookInterface $logbook): TestResultInterface
    {
        $alias = $this->pageSelector;
        $logbook ??= new MarkdownLogBook($this->getCaption());
        DatabaseFormatter::addTestLogbook($logbook);

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

        if (! $facadeNode instanceof UI5AbstractNode) {
            return $facadeNode->checkWorksAsExpected($logbook);
        }

        $result = $facadeNode->runAsSubstep(
            function () use ($facadeNode, $logbook) {
                return $facadeNode->checkWorksAsExpected($logbook);
            },
            'Checking page "' . $alias . '"',
            UI5AbstractNode::CATEGORY_SCREENS,
            $logbook,
            null,
            $facadeNode->buildWholeScreenSubstepCoverageIdentity($rootWidget)
        );

        // runAsSubstep() converts a failure into a result instead of letting it escape, but callers of
        // this method rely on the exception to abort the surrounding work.
        if ($result->isFailed() && $result->getException() !== null) {
            throw $result->getException();
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