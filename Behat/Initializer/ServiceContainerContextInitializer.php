<?php
namespace axenox\BDT\Behat\Initializer;

use Behat\Behat\Context\Initializer\ContextInitializer;
use Behat\Behat\Context\Context;
use Behat\Testwork\EventDispatcher\TestworkEventDispatcher;

class ServiceContainerContextInitializer implements ContextInitializer
{
    private TestworkEventDispatcher $dispatcher;

    // inject the dispatcher directly, not the whole container
    public function __construct(TestworkEventDispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function initializeContext(Context $context): void
    {
        if (! method_exists($context, 'setEventDispatcher')) {
            return;
        }
        $context->setEventDispatcher($this->dispatcher);
    }
}