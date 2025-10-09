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
    //<editor-fold desc="Variables">
    // CLI args/options
    public const CLI_ARG_OBJECT_ALIAS = 'objectAlias';
    public const CLI_ARG_OBJECT_UID   = 'objectUid';
    public const CLI_OPT_APP_ALIAS    = 'appAlias';
    public const CLI_OPT_SUBFOLDER    = 'subfolder';

    // Internals
    private const SAVE_INPUT_ALIAS   = 'axenox.BDT.SAVE_TEST_DATA_INPUT';
    private const DEFAULT_SUBFOLDER  = 'Global';
    private const DUMP_MAX_DEPTH     = 10;

    /** @var mixed|null Cached meta-object of SAVE_TEST_DATA_INPUT */
    private $saveInputMetaObject = null;
    
    //</editor-fold>
    
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
    protected function performImmediately(
        TaskInterface $task,
        DataTransactionInterface $transaction,
        ResultMessageStreamInterface $result
    ) : array {
        $app       = $this->getTargetApp($task);
        $subfolder = $this->getSubfolder($task);
        $input     = $this->getInputData($task);

        return [$input, $app, $subfolder];
    }

    /**
     * @param DataSheetInterface|null $inputSheet
     * @param AppInterface|null $targetApp
     * @param string $subfolder
     * @return \Generator
     */
    protected function performDeferred(
        DataSheetInterface $inputSheet = null,
        AppInterface $targetApp = null,
        string $subfolder = self::DEFAULT_SUBFOLDER
    ) : \Generator {
        $installer = new TestDataInstaller($targetApp->getSelector(), '');
        yield from $installer->dumpTestData($inputSheet, $targetApp, $subfolder, self::DUMP_MAX_DEPTH);
    }
    
    //<editor-fold desc="CLI wiring">

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliArguments() :array 
    {
        return [
            (new ServiceParameter($this))->setName(self::CLI_ARG_OBJECT_ALIAS),
            (new ServiceParameter($this))->setName(self::CLI_ARG_OBJECT_UID),
        ];
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCanBeCalledFromCLI::getCliOptions()
     */
    public function getCliOptions() :array 
    {
        return [
            (new ServiceParameter($this))->setName(self::CLI_OPT_APP_ALIAS),
            (new ServiceParameter($this))->setName(self::CLI_OPT_SUBFOLDER),
        ];
    }
    //</editor-fold>

    //<editor-fold desc="Context resolution">
    public function getTargetApp(TaskInterface $task) :AppInterface
    {
        if ($task->hasInputData()) {
            $sheet = $task->getInputData();

            if ($this->isSaveInputSheet($sheet)) {
                $row = $sheet->getRows()[0] ?? [];
                $appAlias = $row['TARGET_APP'] ?? null;
                if (!$appAlias) {
                    throw new \InvalidArgumentException('TARGET_APP is required in SAVE_TEST_DATA_INPUT.');
                }
                return AppFactory::createFromAlias($appAlias, $this->getWorkbench());
            }

            // Any normal sheet coming from UI
            return $sheet->getMetaObject()->getApp();
        }
        $appAlias = $task->getParameter(self::CLI_OPT_APP_ALIAS);
        if (!$appAlias) {
            throw new \InvalidArgumentException('CLI option "appAlias" is required when no input sheet is provided.');
        }
        return AppFactory::createFromAlias($appAlias, $this->getWorkbench());
        
    }

    public function getSubfolder(TaskInterface $task) :string
    {
        if ($task->hasInputData()) {
            $sheet = $task->getInputData();
            if ($this->isSaveInputSheet($sheet)) {
                $row = $sheet->getRows()[0] ?? [];
                return $row['TARGET_SUBFOLDER'] ?? self::DEFAULT_SUBFOLDER;
            }
            // Any normal sheet: default to Global
            return self::DEFAULT_SUBFOLDER;
        }

        // CLI path
        return $task->getParameter(self::CLI_OPT_SUBFOLDER) ?: self::DEFAULT_SUBFOLDER;
    }
    
    public function getInputData(TaskInterface $task) :DataSheetInterface
    {
        if ($task->hasInputData()) {
            $sheet = $task->getInputData();

            if ($this->isSaveInputSheet($sheet)) {
                $row = $sheet->getRows()[0] ?? [];
                $objectAlias = $row['EXPORTED_OBJECT'] ?? null;
                $uid         = $row['EXPORTED_UID'] ?? null;

                if (!$objectAlias || !$uid) {
                    throw new \InvalidArgumentException('EXPORTED_OBJECT and EXPORTED_UID are required in SAVE_TEST_DATA_INPUT.');
                }

                return $this->buildSheetByUid($objectAlias, $uid);
            }

            // Any normal sheet: use as-is
            return $sheet;
        }

        // CLI path
        $objectAlias = $task->getParameter(self::CLI_ARG_OBJECT_ALIAS);
        $uid         = $task->getParameter(self::CLI_ARG_OBJECT_UID);

        if (!$objectAlias || !$uid) {
            throw new \InvalidArgumentException('CLI args "objectAlias" and "objectUid" are required.');
        }

        return $this->buildSheetByUid($objectAlias, $uid);
    }
    //</editor-fold>

    //<editor-fold desc="Helpers">
    private function isSaveInputSheet(DataSheetInterface $sheet) : bool
    {
        return $sheet->getMetaObject()->isExactly($this->getSaveInputMetaObject());
    }

    private function getSaveInputMetaObject()
    {
        if ($this->saveInputMetaObject === null) {
            $this->saveInputMetaObject = DataSheetFactory::createFromObjectIdOrAlias(
                $this->getWorkbench(),
                self::SAVE_INPUT_ALIAS
            )->getMetaObject();
        }
        return $this->saveInputMetaObject;
    }

    private function buildSheetByUid(string $objectAliasOrId, string $uid) : DataSheetInterface
    {
        $sheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $objectAliasOrId);
        $sheet->getColumns()->addFromUidAttribute();
        $sheet->getFilters()->addConditionFromString(
            $sheet->getUidColumnName(),
            $uid,
            ComparatorDataType::EQUALS
        );
        $sheet->dataRead();

        return $sheet;
    }
    //</editor-fold >
}