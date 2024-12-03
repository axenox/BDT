<?php
namespace axenox\BDT\Behat\Contexts\UI5;

use Behat\Behat\Context\Context;
use Behat\Behat\Tester\Exception\PendingException;
use Behat\MinkExtension\Context\MinkContext;
use PHPUnit\Framework\Assert;


/**
 * Defines application features from the specific context.
 */
class FeatureContext extends MinkContext implements Context
{
    /**
     * Initializes context.
     *
     * Every scenario gets its own context instance.
     * You can also pass arbitrary arguments to the
     * context constructor through behat.yml.
     */
    private $browser;

    public function __construct()
    {
    }
    //******************************************** */

    

    //************************************** */



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

        $username = $this->browser->findInputByLabel('User Name');
        $username->setValue('admin');
        $password = $this->browser->findInputByLabel('Password');
        $password->setValue('admin');

        $loginButton = $this->browser->findButtonByLabel('Login');
        $loginButton->click();
        // FIXME Need to wait one second here because otherwise the next steps do not find their
        // widgets. But why? Why isn't waitWhileAppBusy() helping???
        sleep(1);
        $this->browser->waitWhileAppBusy();
    }

    /**
     * @Then /^I see (\d+) widgets? of type ([a-zA-z]+) with "([a-zA-z]+)"$/i
     * @Then /^I see (\d+) widgets? of type ([a-zA-z]+)$/i
     */
    public function iSeeWidgets(int $number, string $widgetType, string $objectAlias): void
    {
        $found = $this->browser->countWigets($widgetType);
        Assert::assertEquals($number, $found);
    }
}
