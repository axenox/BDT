<?php

namespace Src\Context;

use Behat\Behat\Context\Context;
use Page\LoginPage;
use Behat\MinkExtension\Context\MinkContext;
use PHPUnit\Framework\Assert;


class FeatureContext extends MinkContext implements Context
{
    private $loginPage;

}
