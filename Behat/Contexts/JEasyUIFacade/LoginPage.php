<?php
namespace axenox\BDT\Behat\Contexts\JEasyUIFacade;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Behat\Mink\Driver\Driverinterface\findElement;
use Behat\MinkExtension\Context\RawMinkContext;
use Behat\Mink\Selector\SelectorsHandler;

class LoginPage
{
    private $session;

    // Constructor
    public function __construct(Session $session)
    {
        $this->session = $session;
    }

    // Sayfadaki elementlerin seçicileri
    private $usernameField = 'USERNAME';
    //private $usernameField = '//*[@id="_easyui_textbox_input1"]';

    private $passwordField = '#_easyui_textbox_input2';
    //private $passwordField = '//*[@id="_easyui_textbox_input2"]';
    private $loginButton = '[title=\"Login\"]';
    private $errorMessage ='.error-summary';

    // Elementlerle etkileşime geçecek metodlar
    public function enterUsername($username)
    {
        /*
        $page = $this->session->getPage();
        $page->find('css', $this->usernameField)->setValue($username);
        */
        $timeout = 10; // Maksimum bekleme süresi (saniye)
        $page = $this->session->getPage();
        //$sess = $this->session;
        // Elementin yüklenmesini bekle
        //$sess->wait($timeout * 1000, "document.getElementById('_easyui_textbox_input1') !== null");

        // sleep(3); // 3 saniye bekler

		$page->findById('_easyui_textbox_input1')->setValue($username);
        
        //$page->fillField($this->usernameField, $username);

        /*echo ''. $username .'';

        // Sayfanın tamamen yüklenmesini bekle
        //$this->session->wait(10000, "return document.readyState === 'complete';");

        $page = $this->session->getPage();

        echo ''. $this->usernameField .'';
        
        // Check if the element is found
        $usernameFieldd = $page->find('css', $this->usernameField);
        echo ''. $usernameFieldd .'';

        if ($usernameFieldd === null) {
            throw new \Exception('Username field not found');
        }
        
        $usernameFieldd->setValue($username);*/
    }

    public function enterPassword($password)
    {
        /*
        $page = $this->session->getPage();
        $page->find('xpath', $this->passwordField)->setValue($password);
        */
        $page = $this->session->getPage();

        
       
        // Check if the element is found
        $passwordField = $page->find('css', $this->passwordField);
        if ($passwordField === null) {
            throw new \Exception('Password field not found');
        }

        $passwordField->setValue($password);

    }

    public function clickLoginButton()
    {
        $page = $this->session->getPage();
        $page->find('css', '#LoginPrompt_Form_FormToolbar_ButtonGroup_Button')->click();
    }

    public function getErrorMessage()
    {
        // CSS Selector kullanarak öğeyi bulma
        $errorElement = $this->session->getDriver()->find( $this->errorMessage);

        if( !empty($errorElement) ) {
            // Öğenin metnini almak
            return $errorElement[0]->getText();
        }

        return null;

    }
}