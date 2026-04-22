<?php
namespace axenox\BDT\Exceptions;

use axenox\BDT\Behat\Contexts\UI5Facade\ChromeManager;
use Behat\Mink\Session;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\WidgetFactory;
use exface\Core\Widgets\DebugMessage;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;

class BrowserDriverException extends RuntimeException
{
    private $session;
    private $browser;
    private $occurredAt;
    
    public function __construct(Session $minkSession, $message, $alias = null, $previous = null, UI5Browser $browser = null)
    {
        $this->session = $minkSession;
        $this->browser = $browser;
        $this->occurredAt = date('Y-m-d H:i:s', (int) microtime(true))
            . '.' . substr((string) fmod(microtime(true), 1), 2, 3);
        parent::__construct($message, $alias, $previous);
    }

    /**
     * {@inheritDoc}
     * @see RuntimeException::createDebugWidget()
     */
    public function createDebugWidget(DebugMessage $debugMessage)
    {
        $debugMessage = parent::createDebugWidget($debugMessage);
        $tab = $debugMessage->createTab();
        $tab->setCaption($this->getBrowserName());
        $tab->addWidget(WidgetFactory::createFromUxonInParent($tab, new UxonObject([
            'widget_type' => 'Markdown',
            'height' => '100%',
            'width' => '100%',
            'hide_caption' => true,
            'value' => $this->toMarkdown()
        ])));
        $debugMessage->addTab($tab);
        return $debugMessage;
    }
    
    public function getMinkSession() : Session
    {
        return $this->session;
    }
    
    protected function getBrowserName() : string
    {
        // TODO get browser name from the inner exception once we use other browser than chrome
        return 'Chrome';
    }

    protected function getBrowserProcessId() : ?int
    {
        // TODO get browser name from the inner exception once we use other browser than chrome
        return ChromeManager::getPid();
    }
    
    //to check if the tab is still open
    protected function getDriverTabList() : array
    {
        return ChromeManager::getTabList();
    }

    protected function toMarkdown() : string
    {
        // TODO do we need more information about the browser process? Memory consumed?
        $driverClass = get_class($this->getMinkSession()->getDriver());
        
        return <<<MD

- OS process id: `{$this->getBrowserProcessId()}`
- Driver class: `{$driverClass}`
- Driver tab List: `{$this->getDriverTabList()}`
- Current Url: `{$this->browser->getPageCurrent()}`
- Occurred at: `{$this->occurredAt}`
MD;
    }
}