<?php
namespace axenox\BDT\Behat\Contexts\UI5Facade\Nodes;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5FacadeNodeFactory;
use axenox\BDT\Interfaces\FacadeNodeInterface;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use exface\Core\CommonLogic\Debugger\LogBooks\MarkdownLogBook;
use exface\Core\Factories\UiPageFactory;
use exface\Core\Interfaces\Debug\LogBookInterface;
use exface\Core\Interfaces\Model\UiPageInterface;
use PHPUnit\Framework\Assert;

class UI5PageNode implements FacadeNodeInterface
{
    private string $pageSelector;
    private ?UiPageInterface $page = null;
    
    private $session = null;
    /** @var UI5Browser|null */
    protected $browser;


    /** @var array<string,bool> */
    protected static array $validatedAliases = [];

    public function __construct(string $pageSelector, Session $session, UI5Browser $browser)
    {
        $this->pageSelector = $pageSelector;
        $this->session = $session;
        $this->browser = $browser;
    }
    
    public function getUiPage() : UiPageInterface
    {
        if ($this->page === null) {
            $this->page = UiPageFactory::createFromModel($this->getBrowser()->getWorkbench(), $this->pageSelector);
        }
        return $this->page;
    }

    public function getSession() : Session
    {
        return $this->session;
    }

    public function getNodeElement() : NodeElement
    {
        return $this->getSession()->getPage()->findById($this->getUiPage()->getWidgetRoot()->getId()) ?? $this->getSession()->getPage()->find('css', 'body');
    }

    public function getBrowser(): UI5Browser
    {
        return $this->browser;
    }

    public function getWidgetType() : ?string
    {
        return $this->getUiPage()->getWidgetRoot()->getWidgetType();
    }

    public function capturesFocus() : bool
    {
        return false;
    }

    public function checkWorksAsExpected(LogBookInterface $logbook) : int
    {
        $alias = $this->pageSelector;
        if (null !== $prevState = (static::$validatedAliases[$alias] ?? null)) {
            return $prevState;
        }

        $rootWidget = $this->getUiPage()->getWidgetRoot();
        $rootElementId = $this->getBrowser()->getElementIdFromWidget($rootWidget);
        $rootNode = $this->getSession()->getPage()->findById($rootElementId);
        // Decide which widget type is the best "root" for the page validation.
        // $rootNodeElement = $this->findMainWidgetNodeElementForCurrentPage($alias);
        Assert::assertNotNull($rootNode, 'Cannot determine the main widget for the current page.(' . $alias . '.html)');

        $widgetType = $rootWidget->getWidgetType();

        $facadeNode = UI5FacadeNodeFactory::createFromNodeElement(
            $widgetType,
            $rootNode,
            $this->getSession(),
            $this->browser
        );


        $logbook = new MarkdownLogBook($this->getCaption());
        $resultCode = $facadeNode->checkWorksAsExpected($logbook);
        self::$validatedAliases[$alias] = $resultCode;
        return $resultCode;
    }

    public static function findWidgetNode(NodeElement $innerDomNode) : NodeElement
    {
        return $innerDomNode;
    }

    public function getCaption(): string
    {
        return $this->getUiPage()->getName();
    }
}