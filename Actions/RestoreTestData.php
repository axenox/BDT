<?php
namespace axenox\BDT\Actions;

use axenox\BDT\Common\Installer\TestDataInstaller;
use exface\Core\CommonLogic\AbstractActionDeferred;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\CommonLogic\Selectors\AppSelector;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Actions\ActionInputInvalidObjectError;
use exface\Core\Interfaces\Actions\iCanBeCalledFromCLI;
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
class RestoreTestData extends AbstractActionDeferred implements iCanBeCalledFromCLI
{
    
    protected function init()
    {
        parent::init();
        $this->setInputRowsMin(1);
        $this->setInputRowsMax(1);
        $this->setIcon(Icons::WRENCH);
        $this->setInputObjectAlias('axenox.BDT.TEST_DATA_SET');
    }

    /**
     * {@inheritDoc}
     * @see AbstractActionDeferred::performImmediately()
     */
    protected function performImmediately(
        TaskInterface $task,
        DataTransactionInterface $transaction,
        ResultMessageStreamInterface $result
    ) : array 
    {
        $inputSheet     = $this->getInputDataSheet($task);
        
        if ($inputSheet->getMetaObject()->isExactly('axenox.BDT.TEST_DATA_SET')) {
            $appAlias = $inputSheet->getColumns()->get('APP__ALIAS')->getValue(0);
            $path = $inputSheet->getColumns()->get('PATHNAME_RELATIVE')->getValue(0);
            $subFolder = StringDataType::substringAfter($path, 'Tests/Data/');
        } else {
            throw new ActionInputInvalidObjectError($this, 'Action RestoreTestData only supports axenox.BDT.TEST_DATA_SET as input object');
        }

        return [$appAlias, $subFolder];
    }

    /**
     * @param DataSheetInterface|null $inputSheet
     * @param AppInterface|null $targetApp
     * @param string $subfolder
     * @return \Generator
     */
    protected function performDeferred(
        string $appAlias = null,
        string $subfolder = null
    ) : \Generator 
    {
        $workbench = $this->getWorkbench();
        $appSelector = new AppSelector($workbench, $appAlias);
        $installer = new TestDataInstaller($appSelector, '');
        yield from $installer->installTestData($subfolder);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliArguments() :array 
    {
        return [];
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() :array 
    {
        return [];
    }
}