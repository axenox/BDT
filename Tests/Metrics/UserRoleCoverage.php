<?php
namespace axenox\BDT\Tests\Metrics;

use axenox\BDT\Behat\Events\AfterPageVisited;
use axenox\BDT\Behat\Events\BeforeUserLoggedIn;
use axenox\BDT\Common\AbstractRunMetric;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Selectors\AliasSelectorInterface;

/**
 * Creates metric scores for every user role in every app visited by the tests and tracks page visits
 * 
 * TODO how to track the number of steps, that happened on a page???
 * 
 * @author Andrej Kabachnik
 */
class UserRoleCoverage extends AbstractRunMetric
{
    private array $userRoleStats = [];
    /** @var \exface\Core\Interfaces\DataSheets\DataSheetInterface[] */
    private array $appScoreSheets = [];

    /**
     * {@inheritDoc}
     * @see AbstractRunMetric::registerEventHandlers()
     */
    protected function registerEventHandlers()
    {
        $this->getEventDispatcher()->addListener(BeforeUserLoggedIn::class, [$this, 'onBeforeLoginCount']);
    }

    /**
     * @param BeforeUserLoggedIn $event
     * @return void
     */
    public function onBeforeLoginCount(BeforeUserLoggedIn $event)
    {
        $this->logVisit($event->getRoleAliases());
    }

    /**
     * @param array $roleAliases
     * @return void
     */
    protected function logVisit(array $roleAliases) 
    {
        foreach ($roleAliases as $roleAlias) {
            if (null === ($this->userRoleStats[$roleAlias] ?? null)) {
                $appAlias = StringDataType::substringBefore($roleAlias, AliasSelectorInterface::ALIAS_NAMESPACE_DELIMITER, '', false, true);
                if ($appAlias !== '' && !array_key_exists($appAlias, $this->appScoreSheets)) {
                    $this->appScoreSheets[$appAlias] = $this->loadMetricsForApp($appAlias);
                }
                $this->userRoleStats[$roleAlias] = [
                    'count' => 1
                    // How to add step UIDs here?
                ];
            } else {
                $this->userRoleStats[$roleAlias]['count']++;
            }
        }
    }

    /**
     * {@inheritDoc}
     * @see AbstractRunMetric::saveMetrics()
     */
    protected function saveMetrics()
    {
        foreach ($this->appScoreSheets as $appAlias => $scoreSheet) {
            foreach ($scoreSheet->getRows() as $i => $row) {
                $alias = $row['subject_name'];
                if (null !== $stats = ($this->userRoleStats[$alias] ?? null)) {
                    $scoreSheet->setCellValue('score_absolute', $i, $stats['count']);
                    $scoreSheet->setCellValue('score_percentual', $i, $stats['count'] > 0 ? 100 : 0);
                    $scoreSheet->setCellValue('steps_count', $i, $stats['count']);
                }
            }
            $scoreSheet->dataCreate(false);
        }
    }

    /**
     * @param string $appAlias
     * @return DataSheetInterface
     */
    protected function loadMetricsForApp(string $appAlias) : DataSheetInterface
    {
        $roleSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.USER_ROLE');
        $roleSheet->getFilters()->addConditionFromString('APP__ALIAS', $appAlias, ComparatorDataType::EQUALS);
        $roleSheet->getColumns()->addMultiple([
            'UID',
            'ALIAS_WITH_NS',
            'APP'
        ]);
        $roleSheet->dataRead();
        
        $scoreSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.BDT.run_metric_score');
        $runUid = $this->getTestRunObserver()->getCurrentRunUid();
        $metricUid = $this->getUid();
        foreach ($roleSheet->getRows() as $roleRow) {
            $row = [
                'run' => $runUid,
                'metric' => $metricUid,
                'app' => $roleRow['APP'],
                'score_expected' => 1,
                'score_absolute' => 0,
                'score_percentual' => 0,
                'steps_count' => 0,
                'subject_name' => $roleRow['ALIAS_WITH_NS'],
                'subject_uid' => $roleRow['UID']
            ];
            $scoreSheet->addRow($row);
        }
        return $scoreSheet;
    }    
}