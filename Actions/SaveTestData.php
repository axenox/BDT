<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Common\Installer\TestDataInstaller;
use exface\Core\CommonLogic\AbstractAction;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * 
 *        
 */
class SaveTestData extends AbstractAction
{
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setIcon(Icons::BULLSEYE);
    }
    
    /**
     * @inheritDoc
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction): ResultInterface
    {
        $inputSheet = $task->getInputData();
        $obj = $inputSheet->getMetaObject();
        $app = $obj->getApp();
        $installer = new TestDataInstaller($app->getSelector());
        $generator = $installer->dumpTestData($inputSheet, $app, 'Global', 3);
        $msg = '';
        foreach ($generator as $output) {
            $msg .= $output;
        }
        $result = ResultFactory::createMessageResult($task, $msg);
        return $result;
    }
}