<?php
namespace axenox\BDT\Behat\Listeners;

use Behat\Behat\EventDispatcher\Event\AfterStepTested;
use Behat\Behat\EventDispatcher\Event\ScenarioTested;
use Behat\Testwork\EventDispatcher\Event\SuiteTested;
use Behat\Testwork\Exception\ExceptionPresenter;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use axenox\BDT\Tests\Behat\Contexts\UI5Facade\ErrorManager;

class GlobalExceptionListener implements EventSubscriberInterface
{
    private $exceptionPresenter;

    public function __construct(ExceptionPresenter $exceptionPresenter)
    {
        $this->exceptionPresenter = $exceptionPresenter;
    }

    public static function getSubscribedEvents()
    {
        return [
            'afterSuite' => ['captureSuiteExceptions', -10],
            'afterScenario' => ['captureScenarioExceptions', -10],
            'afterStep' => ['captureStepExceptions', -10],
        ];
    }

    public function captureSuiteExceptions(SuiteTested $event)
    {
        if (method_exists($event, 'getException')) {
            $this->processExceptions($event->getException(), 'Suite');
        }
    }

    public function captureScenarioExceptions(ScenarioTested $event)
    {
        if (method_exists($event, 'getException')) {
            $this->processExceptions($event->getException(), 'Scenario');
        }
    }

    public function captureStepExceptions(AfterStepTested $event)
    {
        if (method_exists($event, 'getTestResult') && 
            method_exists($event->getTestResult(), 'getException')) {
            $this->processExceptions($event->getTestResult()->getException(), 'Step');
        }
    }

    private function processExceptions($exception, $source)
    {
        if (null === $exception) {
            return;
        }

        $errorManager = ErrorManager::getInstance();
        
        $errorData = [
            'type' => 'BehatException',
            'message' => $exception->getMessage(),
            'status' => $exception->getCode(),
            'stack' => $this->exceptionPresenter->presentException($exception),
        ];
        
        $errorManager->addError($errorData, 'Behat:' . $source);
    }
}
