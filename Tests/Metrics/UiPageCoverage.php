<?php
namespace axenox\BDT\Tests\Metrics;

use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Common\AbstractRunMetric;
use Behat\Behat\EventDispatcher\Event\AfterScenarioTested;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;

/**
 * Creates metric scores for every page in every app visited by the tests and tracks page visits
 * 
 * TODO how to track the number of steps, that happened on a page???
 * 
 * @author Andrej Kabachnik
 */
class UiPageCoverage extends AbstractRunMetric
{
    
    private array $pageAliasStats = [];
    /** @var \exface\Core\Interfaces\DataSheets\DataSheetInterface[] */
    private array $appScoreSheets = [];
    
    protected function registerEventHandlers()
    {
        $this->getEventDispatcher()->addListener(AfterPageVisited::class, [$this, 'onAfterPageVisitedCount']);
    }
    
    public function onAfterPageVisitedCount(AfterPageVisited $event)
    {
        $this->logVisit($event->getPageAlias());
    }
    
    protected function logVisit(string $pageAlias) 
    {
        $this->setDirty(true);
        if (null === ($this->pageAliasStats[$pageAlias] ?? null)) {
            $appAlias = StringDataType::substringBefore($pageAlias, AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '', false, true);
            if ($appAlias !== '' && ! array_key_exists($appAlias, $this->appScoreSheets)) {
                $this->appScoreSheets[$appAlias] = $this->loadMetricsForApp($appAlias);
            }
            $this->pageAliasStats[$pageAlias] = [
                'count' => 1
                // How to add step UIDs here?
            ];
        } else {
            $this->pageAliasStats[$pageAlias]['count']++;
        }
    }
    
    protected function saveMetrics() : void
    {
        if ($this->isDirty() === false) {
            return;
        }
        foreach ($this->appScoreSheets as $appAlias => $scoreSheet) {
            foreach ($scoreSheet->getRows() as $i => $row) {
                $pageAlias = $row['subject_name'];
                if (null !== $stats = ($this->pageAliasStats[$pageAlias] ?? null)) {
                    $scoreSheet->setCellValue('score_absolute', $i, $stats['count']);
                    $scoreSheet->setCellValue('score_percentual', $i, $stats['count'] > 0 ? 100 : 0);
                    $scoreSheet->setCellValue('steps_count', $i, $stats['count']);
                }
            }
            if ($scoreSheet->hasUidColumn(true)) {
                $scoreSheet->dataUpdate(false);
            } else {
                $scoreSheet->dataCreate(false);
            }
        }
        $this->setDirty(false);
    }
    
    protected function loadMetricsForApp(string $appAlias) : DataSheetInterface
    {
        $pageSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.PAGE');
        $pageSheet->getFilters()->addConditionFromString('APP__ALIAS', $appAlias, ComparatorDataType::EQUALS);
        $pageSheet->getColumns()->addMultiple([
            'UID',
            'ALIAS',
            'APP'
        ]);
        $pageSheet->dataRead();
        
        $scoreSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.run_metric_score');
        $runUid = $this->getTestRunObserver()->getCurrentRunUid();
        $metricUid = $this->getUid();
        foreach ($pageSheet->getRows() as $pageRow) {
            $row = [
                'run' => $runUid,
                'metric' => $metricUid,
                'app' => $pageRow['APP'],
                'score_expected' => 1,
                'score_absolute' => 0,
                'score_percentual' => 0,
                'steps_count' => 0,
                'subject_name' => $pageRow['ALIAS'],
                'subject_uid' => $pageRow['UID']
            ];
            $scoreSheet->addRow($row);
        }
        return $scoreSheet;
    }    
}