<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Common\Installer\TestDataInstaller;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Actions\ServiceParameter;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\Exceptions\AppNotFoundError;
use exface\Core\Exceptions\Facades\FacadeUnsupportedWidgetPropertyWarning;
use exface\Core\Factories\AppFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataSourceInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultMessageStreamInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * Adds selected data rows to BDT test data set
 * 
 * @author Andrej Kabachnik      
 */
class SaveTestData extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    const CLI_ARG_OBJECT_ALIAS = 'objectAlias';

    const CLI_ARG_OBJECT_UID = 'objectUid';

    const CLI_OPT_APP_ALIAS = 'appAlias';

    const CLI_OPT_SUBFOLDER = 'subfolder';
    
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
        $app = $this->getTargetApp($task);
        $subfolder = $this->getSubfolder($task);
        $inputSheet = $this->getInputData($task);
        
        return [$inputSheet, $app, $subfolder];
    }

    /**
     * @param TaskInterface|null $task
     * @return \Generator
     */
    protected function performDeferred(DataSheetInterface $inputSheet = null, AppInterface $targetApp = null, string $subfolder = 'Global'): \Generator
    {
        $installer = new TestDataInstaller($targetApp->getSelector(), $subfolder);
        yield from $installer->dumpTestData($inputSheet, $targetApp, $subfolder, 10);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliArguments() :array 
    {
        return [(new ServiceParameter($this))->setName(self::CLI_ARG_OBJECT_ALIAS),
                (new ServiceParameter($this))->setName(self::CLI_ARG_OBJECT_UID),]; 
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() :array 
    {
        return [(new ServiceParameter($this))->setName(self::CLI_OPT_APP_ALIAS),
                (new ServiceParameter($this))->setName(self::CLI_OPT_SUBFOLDER)];
    }

    public function getTargetApp(TaskInterface $task) :AppInterface
    {
        if ($task->hasInputData()) {
            $inputSheet = $task->getInputData();
            $obj = $inputSheet->getMetaObject();
            $app = $obj->getApp();
        } else {
            $appAlias = $task->getParameter(self::CLI_OPT_APP_ALIAS);
            $app = AppFactory::createFromAlias($appAlias, $this->getWorkbench());
        }
        return $app;
    }

    public function getSubfolder(TaskInterface $task) :string
    {
        if ($task->hasInputData()) {
            $subfolder = 'GLobal';
        } else {
            $subfolder = $task->getParameter(self::CLI_OPT_SUBFOLDER);
        }
        return $subfolder;
    }
    
    public function getInputData(TaskInterface $task) :DataSheetInterface
    {
        if ($task->hasInputData()) {
            $inputSheet = $task->getInputData();
        } else {
            $inputSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $task->getParameter(self::CLI_ARG_OBJECT_ALIAS));
            $inputSheet->getColumns()->addFromUidAttribute();
            $inputSheet->getFilters()->addConditionFromString(
                $inputSheet->getUidColumnName(),
                $task->getParameter(self::CLI_ARG_OBJECT_UID),
                ComparatorDataType::EQUALS
            );
            $inputSheet->dataRead();
        }
        return $inputSheet;
    }
}