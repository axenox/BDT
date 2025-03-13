<?php
namespace axenox\BDT\Behat\Extension;

use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use axenox\BDT\Behat\Listeners\GlobalExceptionListener;

class BehatExceptionExtension implements Extension
{
    public function getConfigKey()
    {
        return 'axenox_bdt_exception';
    }

    public function configure(ArrayNodeDefinition $builder)
    {
        // No configuration needed
    }

    public function load(ContainerBuilder $container, array $config)
    {
        $definition = new Definition(GlobalExceptionListener::class, [
            new Reference('exception.presenter')
        ]);
        $definition->addTag('event_dispatcher.subscriber');
        $container->setDefinition('axenox.bdt.exception_listener', $definition);
    }

    public function process(ContainerBuilder $container)
    {
        // No processing needed
    }

    public function initialize(ExtensionManager $extensionManager)
    {
        // No initialization needed
    }
}