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

    /**
     * Returns the OS process id of the browser, or NULL if it is unknown.
     *
     * @return int|null The Chrome process id, or NULL if no Chrome is being managed.
     */
    protected function getBrowserProcessId() : ?int
    {
        // TODO get browser name from the inner exception once we use other browser than chrome
        // getPid() is an instance method - calling it statically is a fatal error.
        return ChromeManager::getInstance()->getPid();
    }
    
    /**
     * Lists the tabs the browser currently has open, so it is visible whether the tab under
     * test is still there.
     *
     * Returns an empty array if the browser cannot be reached: this exception is raised when
     * the connection is broken, so a failing probe is the normal case and must not escape.
     *
     * @return array The tab descriptors reported by Chrome, or an empty array if unavailable.
     */
    protected function getDriverTabList() : array
    {
        try {
            // getTabList() is an instance method - calling it statically is a fatal error.
            return ChromeManager::getInstance()->getTabList();
        } catch (\Throwable $ignored) {
            return [];
        }
    }

    /**
     * Renders the browser diagnostics shown in the debug widget of this exception.
     *
     * Every value is resolved defensively: this exception is thrown precisely when the
     * browser connection is broken, so any probe here can fail or return NULL. A crash
     * while BUILDING the error report would replace the real cause with a meaningless
     * secondary error, so unavailable values are rendered as "unknown" instead.
     *
     * @return string Markdown describing the browser process, driver and current URL.
     */
    protected function toMarkdown() : string
    {
        // TODO do we need more information about the browser process? Memory consumed?
        $driverClass = get_class($this->getMinkSession()->getDriver());
        $tabList = json_encode($this->getDriverTabList());
        // The URL the browser is actually showing. It is the key piece of evidence for a
        // navigation error: if it already shows the requested page, the page did load and
        // only the connection to the browser broke.
        $url = $this->getCurrentUrlSafely() ?? 'unknown';
        // The browser is optional: it is not initialised yet when the very first navigation
        // of a scenario fails.
        $page = 'unknown';
        if ($this->browser !== null) {
            try {
                $page = $this->browser->getPageCurrent();
                $page = $page === null ? 'unknown' : $page->getAliasWithNamespace();
            } catch (\Throwable $ignored) {
                $page = 'unknown';
            }
        }
        
        return <<<MD

- OS process id: `{$this->getBrowserProcessId()}`
- Driver class: `{$driverClass}`
- Driver tab List: `{$tabList}`
- Current Url: `{$url}`
- Current page: `{$page}`
- Occurred at: `{$this->occurredAt}`
MD;
    }

    /**
     * Reads the URL currently loaded in the browser, or NULL if it cannot be determined.
     *
     * Asking the driver for the URL requires the very connection that is usually broken when
     * this exception is raised, so the failure is expected and reported as NULL rather than
     * thrown.
     *
     * @return string|null The current URL, or NULL if the driver could not be reached.
     */
    protected function getCurrentUrlSafely() : ?string
    {
        try {
            return $this->getMinkSession()->getCurrentUrl();
        } catch (\Throwable $ignored) {
            return null;
        }
    }
}