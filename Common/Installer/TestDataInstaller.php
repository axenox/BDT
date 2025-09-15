<?php
namespace axenox\BDT\Common\Installer;

use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\CommonLogic\AppInstallers\DataInstaller;
use exface\Core\CommonLogic\AppInstallers\MetaModelInstaller;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\AppInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Model\ConditionInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\Selectors\SelectorInterface;

class TestDataInstaller extends DataInstaller
{
    const INDENT_STEP = '  ';
    
    const STATUS_COL_NAME = '_STATUS';
    const STATUS_REQUIRED_FOR_RELATION = 'related';
    const STATUS_SAVED_PREVIOUSLY = 'saved';
    const STATUS_REQUIRED_BY_USER = 'required';
    
    public function install(string $source_absolute_path) : \Iterator
    {
        $indent = $this->getOutputIndentation();
        yield $indent . "Test data will NOT be installed now, but for every test run separately" . PHP_EOL;
    }

    /**
     * This will install test data from the given subfolder in `Tests/Data` of the app of this installer
     * 
     * @param string $folder
     * @return \Iterator
     */
    public function installTestData(string $folder) : \Iterator
    {
        return parent::install($this->getTestDataPath($this->getApp(), $folder));
    }

    /**
     * 
     * @param DataSheetInterface $inputSheet
     * @return $this
     */
    public function addTestData(DataSheetInterface $inputSheet) : TestDataInstaller
    {
        $obj = $inputSheet->getMetaObject();
        if (null === $this->getModelFileIndex($obj->getAliasWithNamespace())) {
            $this->addDataToMerge($obj->getAliasWithNamespace(), $this->findSortAttributeAlias($obj));
        }
        
        return $this;
    }

    /**
     * @param DataSheetInterface $inputSheet
     * @param AppInterface $app
     * @param string $folder
     * @param int|null $maxDepth
     * @param string $indent
     * @return \Iterator
     */
    public function dumpTestData(DataSheetInterface $inputSheet, AppInterface $app, string $folder, int $maxDepth = null, string $indent = self::INDENT_STEP) : \Iterator
    {
        $obj = $inputSheet->getMetaObject();
        $dir = $this->getTestDataPath($app, $folder);
        $app->getWorkbench()->filemanager()->pathConstruct($dir);

        $existingSheets = [];
        foreach ($this->readModelSheetsFromFolders($dir) as $existingSheet) {
            $existingSheets[] = $existingSheet;
        }
        if ($this->hasInstallableObjects() === false) {
            foreach ($existingSheets as $existingSheet) {
                $this->addDataOfObject($existingSheet->getMetaObject()->getAliasWithNamespace(), $existingSheet->getSorters()->getFirst()->getAttributeAlias());
            }
        }
        
        $this->addTestData($inputSheet);

        $newUids = [];
        $exportSheet = null;
        // If an exported data sheet already exists for this object, add the new UIDs to it
        foreach ($existingSheets as $existingSheet) {
            if ($existingSheet->getMetaObject()->isExactly($inputSheet->getMetaObject())) {
                $uidAttr = $obj->getUidAttribute();
                $exportSheet = $existingSheet;
                $statusCol = $exportSheet->getColumns()->addFromExpression(self::STATUS_COL_NAME, self::STATUS_COL_NAME, true);
                $statusCol->setValueOnAllRows(self::STATUS_SAVED_PREVIOUSLY);
                $uidCondition = $exportSheet->getFilters()->getConditions(function(ConditionInterface $condition) use ($uidAttr){
                    $expr = $condition->getExpression();
                    return $expr->isMetaAttribute() && $expr->getAttribute()->isExactly($uidAttr);
                })[0];
                $existingUids = explode($uidAttr->getValueListDelimiter(), $uidCondition->getValue());
                $inputUids = $inputSheet->getUidColumn()->getValues();
                foreach ($exportSheet->getUidColumn()->getValues() as $i => $existingUid) {
                    if (in_array($existingUid, $inputUids)) {
                        $statusCol->setValue($i, self::STATUS_REQUIRED_BY_USER);
                    }
                }
                $allUids = array_unique(array_merge($existingUids, $inputUids));
                $newUids = array_diff($allUids, $existingUids);
                $uidCondition->setValue(implode($uidAttr->getValueListDelimiter(), $allUids));
                yield 'Added ' . (count($newUids)) . ' rows to existing test data for ' . $obj->__toString() . PHP_EOL;
                break;
            }
        }
        
        // If no existing sheet found 
        if ($exportSheet === null) {
            $exportSheet = $this->createModelSheet($obj->getAliasWithNamespace(), $this->findSortAttributeAlias($obj));
            $allUids = $inputSheet->getUidColumn()->getValues();
            $newUids = $allUids;
            $exportSheet->getFilters()->addConditionFromValueArray($obj->getUidAttribute()->getAliasWithRelationPath(), $allUids);
            $exportSheet->dataRead();
            yield $indent . 'Created new test data sheet ' . count($allUids) . ' rows for ' . $obj->__toString() . PHP_EOL;
        }
        
        // If there were new UIDs found, save the data sheet.
        // BEFORE saving look for foreign keys and export required related data too!
        if (count($newUids) > 0) {
            if ($maxDepth === 0) {
                yield $indent. self::INDENT_STEP . 'Not searching for related test data - depth limit reached!' . PHP_EOL;
            } else {
                yield from $this->dumpRelatedData($exportSheet, $app, $folder, ($maxDepth === null ? null : $maxDepth - 1), $indent . self::INDENT_STEP);
            }
        }
        
        $exportSheet->getColumns()->removeByKey(self::STATUS_COL_NAME);
        $this->exportModelFile($dir, $exportSheet);
        return $this;
    }

    /**
     * @param DataSheetInterface $exportSheet
     * @param AppInterface $app
     * @param string $folder
     * @param int|null $maxDepth
     * @param string $indent
     * @return \Generator
     */
    protected function dumpRelatedData(DataSheetInterface $exportSheet, AppInterface $app, string $folder, int $maxDepth = null, string $indent = self::INDENT_STEP) : \Generator
    {
        $obj = $exportSheet->getMetaObject();
        foreach ($exportSheet->getColumns() as $column) {
            if (!$column->isAttribute()) {
                continue;
            }
            $attr = $column->getAttribute();
            if (!$attr->isRelation()) {
                continue;
            }
            $leftFileIdx = $this->getModelFileIndex($obj->getAliasWithNamespace());
            $rel = $attr->getRelation();
            if ($rel->isForwardRelation() && $rel->isDefinedInLeftObject() && $rel->getRightObject()->isWritable()) {
                $leftKeys = $column->getValues();
                if ($rel->getRightKeyAttribute()->isUidForObject()) {
                    $rightUids = array_filter(array_unique($leftKeys));
                } else {
                    // TODO find the UIDs of the right object
                    yield $indent . 'Cannot save related test data for relation ' . $rel->toString() . ' - relations to non-UID attributes not supported yet' . PHP_EOL;
                    continue;
                }
                if (empty($rightUids)) {
                    continue;
                }
                yield $indent . 'Saving related test data for relation ' . $rel->toString() . PHP_EOL;
                $rightObj = $rel->getRightObject();
                $rightDumpData = DataSheetFactory::createFromObject($rightObj);
                $rightUidCol = $rightDumpData->getColumns()->addFromUidAttribute();
                $rightUidCol->setValues($rightUids);
                $rightStatusCol = $rightDumpData->getColumns()->addFromExpression(self::STATUS_COL_NAME);
                $rightStatusCol->setValueOnAllRows(self::STATUS_REQUIRED_FOR_RELATION);
                $rightDumpData->getFilters()->addConditionFromColumnValues($rightUidCol);
                $this->addTestData($rightDumpData);
                $rightFileIdx = $this->getModelFileIndex($rightObj->getAliasWithNamespace());
                // If the data of the right object is going to be installed AFTER the data of the left object,
                // we might get foreign key errors, so we need them to switch places. Place the right data
                // where the left data was - this will move the left data to a later position.
                if ($rightFileIdx > $leftFileIdx) {
                    $this->setModelFileIndex($rightObj->getAliasWithNamespace(), $leftFileIdx);
                }
                yield from $this->dumpTestData($rightDumpData, $app, $folder, $maxDepth, $indent . self::INDENT_STEP);
            }
        }
    }
    
    protected function findSortAttributeAlias(MetaObjectInterface $obj) : string
    {
        switch (true) {
            /* @var $tsBehavior \exface\Core\Behaviors\TimeStampingBehavior */
            case (null !== $tsBehavior = $obj->getBehaviors()->findBehavior(TimeStampingBehavior::class)) && $tsBehavior->hasCreatedByAttribute():
                $sortByAttributeAlias = $tsBehavior->getCreatedByAttribute()->getAliasWithRelationPath();
                break;
            case $obj->hasUidAttribute():
                $sortByAttributeAlias = $obj->getUidAttribute()->getAliasWithRelationPath();
                break;
        }
        return $sortByAttributeAlias;
    }
    
    protected function getTestDataPath(AppInterface $app, string $folder) : string
    {
        return $app->getDirectoryAbsolutePath() . DIRECTORY_SEPARATOR 
            . 'Tests' . DIRECTORY_SEPARATOR 
            . 'Data' . DIRECTORY_SEPARATOR 
            . $folder;
    }
}