<?php

namespace axenox\BDT\Behat\TwigFormatter;

use axenox\BDT\Behat\Initializer\ServiceContainerContextInitializer;
use Behat\Testwork\ServiceContainer\Extension as ExtensionInterface;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Class BehatFormatterExtension
 * @package Features\Formatter
 */
class BehatFormatterExtension implements ExtensionInterface {
  /**
   * You can modify the container here before it is dumped to PHP code.
   *
   * @param ContainerBuilder $container
   *
   * @api
   */
  public function process(ContainerBuilder $container) {
  }

  /**
   * Returns the extension config key.
   *
   * @return string
   */
  public function getConfigKey() {
    return "elkanhtml";
  }

  /**
   * Initializes other extensions.
   *
   * This method is called immediately after all extensions are activated but
   * before any extension `configure()` method is called. This allows extensions
   * to hook into the configuration of other extensions providing such an
   * extension point.
   *
   * @param ExtensionManager $extensionManager
   */
  public function initialize(ExtensionManager $extensionManager) {
  }

  /**
   * Setups configuration for the extension.
   *
   * @param ArrayNodeDefinition $builder
   */
  public function configure(ArrayNodeDefinition $builder) {
    $builder
        ->children()
        ->scalarNode("name")->defaultValue("power-ui.html")->end()
        ->scalarNode("projectName")->defaultValue("Power-UI BehatFormatter")->end()
        ->scalarNode("projectImage")->defaultNull()->end()
        ->scalarNode("projectDescription")->defaultNull()->end()
        ->scalarNode("renderer")->defaultValue("Twig")->end()
        ->scalarNode("fileName")->defaultValue("generated")->end()
        ->scalarNode("printArgs")->defaultValue(false)->end()
        ->scalarNode("printOutp")->defaultValue(false)->end()
        ->scalarNode("loopBreak")->defaultValue(false)->end()
        ->scalarNode("showTags")->defaultValue(false)->end()
        ->scalarNode("output")->defaultValue(false)->end()
        ->end()
    ;
  }

  /**
   * Loads extension services into temporary container.
   *
   * @param ContainerBuilder $container
   * @param array $config
   */
    public function load(ContainerBuilder $container, array $config) {
        $init = new Definition(ServiceContainerContextInitializer::class);
        $init
            ->addArgument(new Reference('event_dispatcher'))
            ->addTag('context.initializer');
        $container->setDefinition('container.context_initializer', $init);
        
        $definition = new Definition("axenox\\BDT\\Behat\\TwigFormatter\\Formatter\\BehatFormatter");
        $definition
            ->addArgument($config['name'])
            ->addArgument($config['projectName'])
            ->addArgument($config['projectImage'])
            ->addArgument($config['projectDescription'])
            ->addArgument($config['renderer'])
            ->addArgument($config['fileName'])
            ->addArgument($config['printArgs'])
            ->addArgument($config['printOutp'])
            ->addArgument($config['loopBreak'])
            ->addArgument($config['showTags'])
            ->addArgument('%paths.base%')
            ->addTag('event_dispatcher.subscriber')
        ;
        
        $container->setParameter('timestamp', time());
        
        $container
            ->setDefinition('html.formatter', $definition)
            ->addTag('output.formatter')
        ;
    }
}