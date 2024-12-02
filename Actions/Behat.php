<?php
namespace axenox\BDT\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
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
    const COMMAND_INIT = 'init';
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        return [$this->getCommands($task)];
    }

    protected function getCommands(TaskInterface $task) : array
    {
        $commands = [];
        switch (true) {
            case $task->hasParameter(self::COMMAND_INIT):
                $commands[] = [self::COMMAND_INIT, []];
                break;
        }
        return $commands;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $commands = []) : \Generator
    {
        foreach ($commands as $cmdArray) {
            list($cmd, $args) = $cmdArray;
            switch ($cmd) {
                case self::COMMAND_INIT:
                    yield from $this->doInit();
                    break;
                default:
                    yield 'Not implemented yet! But a Behat test will run here some day';
            }
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
                ->setName(self::COMMAND_INIT)
                ->setDescription('Initialize this installation for Behat tests'),
            (new ServiceParameter($this))
                ->setName('includeApp')
                ->setDescription('Include an app (alias) when running tests on the current installation'),
            (new ServiceParameter($this))
                ->setName('excludeApp')
                ->setDescription('Exclude an app (alias) when running tests on the current installation')
        ];
    }
    
    /**
     * 
     * {@inheritdoc}
     * @see iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() : array
    {
        return [];
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
            yield 'Found existing behat.yml file' . PHP_EOL;
        } else {
            $tplPath = $this->getApp()->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR . 'Behat' . DIRECTORY_SEPARATOR . 'Template.yml';
            file_put_contents($ymlPath, file_get_contents($tplPath));
            yield 'Created new behat.yml file in installation folder';
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