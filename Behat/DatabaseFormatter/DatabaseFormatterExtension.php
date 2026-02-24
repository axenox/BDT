<?php
namespace axenox\BDT\Behat\DatabaseFormatter;

use Behat\Testwork\ServiceContainer\Extension;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use exface\Core\CommonLogic\Workbench;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

class DatabaseFormatterExtension implements Extension
{    
    public function getConfigKey(): string
    {
        return 'database_formatter';
    }

    public function initialize(ExtensionManager $extensionManager) {}

    public function configure(ArrayNodeDefinition $builder) {}

    public function load(ContainerBuilder $container, array $config)
    {
        // Register the formatter as a service
        $definition = new Definition(DatabaseFormatter::class, [
            new Reference('database_formatter.workbench'),
            new Reference('screenshot.provider'),
            new Reference('event_dispatcher'),
        ]);

        $definition->addTag('output.formatter');

        $container->setDefinition('database_formatter.formatter', $definition);


        $workbench = Workbench::startNewInstance();
        $container->set('database_formatter.workbench', $workbench);
    }

    public function process(ContainerBuilder $container) {}
}