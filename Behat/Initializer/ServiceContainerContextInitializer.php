<?php
namespace axenox\BDT\Behat\Initializer;

use axenox\BDT\Behat\Common\ScreenshotAwareInterface;
use axenox\BDT\Behat\Common\ScreenshotProviderInterface;
use Behat\Behat\Context\Initializer\ContextInitializer;
use Behat\Behat\Context\Context;

class ServiceContainerContextInitializer implements ContextInitializer
{
    private ScreenshotProviderInterface $provider;

    public function __construct(ScreenshotProviderInterface $provider)
    {
        $this->provider = $provider;
    }

    public function initializeContext(Context $context): void
    {
        if ($context instanceof ScreenshotAwareInterface) {
            $context->setScreenshotProvider($this->provider);
        }
    }
}