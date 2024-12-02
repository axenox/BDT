<?php
namespace axenox\BDT\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Filemanager;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Interfaces\Tasks\CliTaskInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Allows to manage and run Behat tests
 * 
 * Use from the command line:
 * 
 * - `vendor\bin\action axenox.BDT:Behat` - run all tests
 * - `vendor\bin\action axenox.BDT:Behat init` - prepare everything to run Behat tests on this installation
 * - `vendor\bin\action axenox.BDT:Behat includeApp=my.APP` - include app `my.APP` as a test suite
 *        
 * @author Andrej Kabachnik
 *        
 */
class Behat extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    const PARAM_COMMAND = 'operation';

    const COMMAND_INIT = 'init';

    const OPTION_INCLUDE_APP = 'includeApp';
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$task];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(CliTaskInterface $task = null) : \Generator
    {
        $cmd = $task->getParameter(self::PARAM_COMMAND);

        switch ($cmd) {
            case self::COMMAND_INIT:
                yield from $this->doInit();
                break;
            default:
                
        }

        switch (true) {
            case $task->hasParameter(self::OPTION_INCLUDE_APP):
                yield from $this->doIncludeApp($task->getParameter(self::OPTION_INCLUDE_APP));
        }
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliArguments()
     */
    public function getCliArguments() : array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::PARAM_COMMAND)
                ->setDescription('Command to be performed')
        ];
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [
            (new ServiceParameter($this))
                ->setName(self::OPTION_INCLUDE_APP)
                ->setDescription('Include an app (alias) when running tests on the current installation')
        ];
    }

    /**
     * 
     * @return string
     */
    protected function getGlobalYmlPath() : string
    {
        $wbPath = $this->getWorkbench()->getInstallationPath();
        return $wbPath . DIRECTORY_SEPARATOR . 'behat.yml';
    }

    protected function doIncludeApp(string $alias) : \Generator
    {
        $ymlPath = $this->getGlobalYmlPath();
        $loader = $this->getYmlReader($ymlPath);
        yield from $loader;
        $yml = $loader->getReturn();

        $app = $this->getWorkbench()->getApp($alias);
        $appDir = $app->getDirectoryAbsolutePath();
        $pathToBehat = $appDir . DIRECTORY_SEPARATOR . 'Tests' . DIRECTORY_SEPARATOR . 'Behat' . DIRECTORY_SEPARATOR;
        $pathToFeatures = $pathToBehat . 'Features';
        $pathToYml = $pathToBehat . 'Behat.yml';
        if (! file_exists($pathToYml)) {
            // Make sure Tests/Behat/Features exists in app
            Filemanager::pathConstruct($pathToFeatures);
            $appYml = [
                'default' => [
                    'suites' => [
                        $app->getAliasWithNamespace() => [
                            'paths' => ['%paths.base%/vendor/' . StringDataType::substringAfter(FilePathDataType::normalize($pathToFeatures), '/vendor/')],
                            'contexts' => ['axenox\BDT\Behat\Contexts\UI5\FeatureContext']
                        ]
                    ]
                ]
            ];
            file_put_contents($pathToYml, Yaml::dump($appYml,  10));
            yield 'Created app config "' . StringDataType::substringAfter(FilePathDataType::normalize($pathToYml), '/vendor/') . '"' . PHP_EOL;
        } else {
            yield 'Found existing Behat.yml in app' . PHP_EOL;
        }

        $imports = $yml['imports'];
        $pathToYmlRelative = 'vendor/' . StringDataType::substringAfter(FilePathDataType::normalize($pathToYml), '/vendor/');
        if (! array_search($pathToYmlRelative, $imports)) {
            $yml['imports'][] = $pathToYmlRelative;
            yield 'Added import to global behat.yml' . PHP_EOL;
        } else {
            yield 'App already included.' . PHP_EOL;
        }

        $writer = $this->getYmlWriter($yml, $ymlPath);
        yield from $writer;
    }

    /**
     * 
     * @return \Generator
     */
    protected function doInit() : \Generator
    {
        $ymlPath = $this->getGlobalYmlPath();
        $loader = $this->getYmlReader($ymlPath);
        yield from $loader;
        $yml = $loader->getReturn();

        $writer = $this->getYmlWriter($yml, $ymlPath);
        yield from $writer;

        // TODO Check if browsers are running

        yield 'Ready to test now!' . PHP_EOL;
    }

    /**
     * 
     * @param string $ymlPath
     * @return \Generator
     */
    protected function getYmlReader(string $ymlPath) : \Generator
    {
        
        if (file_exists($ymlPath)) {
            yield 'Found existing global behat.yml file' . PHP_EOL;
        } else {
            $tplPath = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Behat' . DIRECTORY_SEPARATOR . 'Template.yml';
            file_put_contents($ymlPath, file_get_contents($tplPath));
            yield 'Created new global behat.yml file in installation folder';
        }

        $yml = Yaml::parseFile($ymlPath);
        return $yml;
    }

    /**
     * 
     * @param array $yml
     * @param string $ymlPath
     * @return \Generator
     */
    protected function getYmlWriter(array $yml, string $ymlPath) : \Generator
    {
        $currentUrl = $yml['default']['extensions']['Behat\MinkExtension']['base_url'];
        $wbUrl = $this->getWorkbench()->getUrl();
        if ($currentUrl !== $wbUrl) {
            yield 'Updating base_url to "' . $this->getWorkbench()->getUrl() . '"' . PHP_EOL;
            $yml['default']['extensions']['Behat\MinkExtension']['base_url'] = $wbUrl;
        }
        $str = Yaml::dump($yml,  10);
        file_put_contents($ymlPath, $str);
        return $yml;
    }
}