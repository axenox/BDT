<?php

namespace axenox\BDT\Behat\TwigFormatter;

use Behat\Testwork\ServiceContainer\Extension as ExtensionInterface;
use Behat\Testwork\ServiceContainer\ExtensionManager;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

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
        ->scalarNode("name")->defaultValue("elkanhtml")->end()
        ->scalarNode("projectName")->defaultValue("Elkan's BehatFormatter")->end()
        ->scalarNode("projectImage")->defaultNull()->end()
        ->scalarNode("projectDescription")->defaultNull()->end()
        ->scalarNode("renderer")->defaultValue("Twig")->end()
        ->scalarNode("file_name")->defaultValue("generated")->end()
        ->scalarNode("print_args")->defaultValue(false)->end()
        ->scalarNode("print_outp")->defaultValue(false)->end()
        ->scalarNode("loop_break")->defaultValue(false)->end()
        ->scalarNode("show_tags")->defaultValue(false)->end()
        ->scalarNode("output")->defaultValue(false)->end()
        ->scalarNode('screenshots_folder')->defaultValue('Screenshots')->end()
        // define root_path as an array of strings with a default
        ->arrayNode('root_path')
            ->scalarPrototype()->end()
            ->defaultValue([ '%paths.base%', ])  // or ['.']
            ->end()
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
    $definition = new Definition("axenox\\BDT\\Behat\\TwigFormatter\\Formatter\\BehatFormatter");
      $definition
          ->addArgument($config['name'])
          ->addArgument($config['projectName'])
          ->addArgument($config['projectImage'])
          ->addArgument($config['projectDescription'])
          ->addArgument($config['renderer'])
          ->addArgument($config['file_name'])
          ->addArgument($config['print_args'])
          ->addArgument($config['print_outp'])
          ->addArgument($config['loop_break'])
          ->addArgument($config['show_tags'])
          ->addArgument('%paths.base%')
          ->addArgument($config['screenshots_folder'])
          ->addArgument($config['root_path'])
      ;

      $container->setParameter('timestamp', time());

      $container
          ->setDefinition('html.formatter', $definition)
          ->addTag('output.formatter')
      ;
  }
}