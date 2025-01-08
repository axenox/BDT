<?php
namespace axenox\BDT\Tests\Behat\Contexts\UI5Facade;

use Behat\Behat\Context\Context;
use Behat\MinkExtension\Context\MinkContext;
use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use PHPUnit\Framework\Assert;


/**
 * Test steps available for the OpenUI5 facade
 * 
 * Every scenario gets its own context instance.
 * You can also pass arbitrary arguments to the
 * context constructor through behat.yml.
 * 
 */
class UI5BrowserContext extends MinkContext implements Context
{
    private $browser;

    /**
     * Summary of focusStack
     * @var \Behat\Mink\Element\NodeElement[]
     */
    private $focusStack = [];

    /**
     * @Given I log in to the page ":url"
     * @Given I log in to the page ":url" as ":userRole"
     */
    public function iLogInToPage(string $url, string $userRole = null)
    {
        $this->visitPath('/' . $url);

        // Geçerli URL'yi al ve ekrana yazdır
        $currentUrl = $this->getSession()->getCurrentUrl();
        echo 'Current URL: ' . $currentUrl;

        $this->browser = new UI5Browser($this->getSession(), $url);

        $username = $this->browser->findInputByCaption('User Name');
        $username->setValue('admin');
        $password = $this->browser->findInputByCaption('Password');
        $password->setValue('admin');

        $loginButton = $this->browser->findButtonByCaption('Login');
        $loginButton->click();
        // FIXME Need to wait one second here because otherwise the next steps do not find their
        // widgets. But why? Why isn't waitWhileAppBusy() helping???
        sleep(1);
        $this->browser->waitWhileAppBusy(30);
    }

    /**
     * @Then I see :number widget of type ":widgetType"
     * @Then I see :number widgets of type ":widgetType"
     * @Then I see :number widget of type ":widgetType" with ":objectAlias"
     * @Then I see :number widgets of type ":widgetType" with ":objectAlias"
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias): void
    {
        $found = $this->browser->countWidgets($widgetType);
        Assert::assertEquals($number, $found);
    }

    /**
     * 
     * @When I click button ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function iClickButton(string $caption) : void
    {
        $btn = $this->browser->findButtonByCaption($caption);
        if ($btn === null) {
            // TODO throw error
        }
        $btn->click();
        $this->browser->waitWhileAppBusy(30);
    }

    /**
     * 
     * @When I type ":value" into ":caption"
     *
     * @param string $value
     * @param string $caption
     * @return void
     */
    public function iTypeIntoWidgetWithCaption(string $value, string $caption) : void
    {
        $widget = $this->browser->findInputByCaption($caption);
        if ($widget === null) {
            // TODO throw error
        }
        $widget->setValue($value);
    }

    /**
     * @When I look at first ":widgetType"
     * 
     * @param string $widgetType
     * @return void
     */
    public function iLookAtWidget(string $widgetType) : void
    {
        $widgetNodes = $this->browser->findWidgets($widgetType);
        if (count($widgetNodes) === 0) {
            // TODO throw error
        }
        $this->focusStack[] = $widgetNodes[0];
    }

    /**
     * @Then it has a column ":caption"
     * 
     * @param string $caption
     * @return void
     */
    public function itHasColumn(string $caption) : void
    {
        /**
         * @var \Behat\Mink\Element\NodeElement $tableNode
         */
        $tableNode = end($this->focusStack);
        $colNode = $tableNode->find('css', 'td');
        Assert::assertNotNull($colNode);
    }
}
