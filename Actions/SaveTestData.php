<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Common\Installer\TestDataInstaller;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Adds selected data rows to BDT test data set
 * 
 * @author Andrej Kabachnik      
 */
class SaveTestData extends AbstractActionDeferred
{
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setIcon(Icons::BULLSEYE);
    }

    /**
     * {@inheritDoc}
     * @see AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(TaskInterface $task, DataTransactionInterface $transaction, ResultMessageStreamInterface $result) : array
    {
        $inputSheet = $task->getInputData();
        // TODO get target app and subfolder from task parameters (from CLI) or input data
        $obj = $inputSheet->getMetaObject();
        $app = $obj->getApp();
        $subfolder = 'Global';
        return [$inputSheet, $app, $subfolder];
    }

    /**
     * @param TaskInterface|null $task
     * @return \Generator
     */
    protected function performDeferred(DataSheetInterface $inputSheet = null, AppInterface $targetApp = null, string $subfolder = 'Global'): \Generator
    {
        $installer = new TestDataInstaller($targetApp->getSelector());
        yield from $installer->dumpTestData($inputSheet, $targetApp, $subfolder, 10);
    }
}