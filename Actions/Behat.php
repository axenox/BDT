<?php
namespace axenox\BDT\Actions;

use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;

/**
 * Allows to manage and run Behat tests
 * 
 * Use from the command line:
 * 
 * - `vendor\bin\action axenox.BDT:Behat` - run all tests
 * - `vendor\bin\action axenox.BDT:Behat includeApp=my.APP` - include app `my.APP` as a test suite
 *        
 * @author Andrej Kabachnik
 *        
 */
class Behat extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    
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
        return [];
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractActionDeferred::performDeferred()
     */
    protected function performDeferred(array $commands = []) : \Generator
    {
        yield 'Not implemented yet! But a Behat test will run here some day';
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
}