<?php

namespace axenox\BDT\Behat\Common;

use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\Interfaces\WorkbenchInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;

/**
 * Single source of truth for creating the axenox.BDT.run record.
 *
 * Why extracted: both DatabaseFormatter (when it owns the run, i.e. non-attach mode) and the
 * parallel coordinator's RunLifecycle need to create exactly the same run row with the same
 * columns. Keeping this in one place prevents the two writers from drifting apart and keeps
 * the run schema controlled from a single location.
 *
 * Why it does NOT register shutdown handlers, metrics or timing: those are concerns of the
 * specific caller (the formatter manages its own lifecycle and shutdown finalization), not of
 * run-row creation. This class only writes the row and returns the populated sheet so the
 * caller can read the new UID.
 */
final class RunRecordWriter
{
    /**
     * Creates a new run row and returns the datasheet carrying the generated UID.
     *
     * Why $behatCommand is a parameter rather than read from argv here: the command string is
     * context-dependent. Inside the Behat process it is the behat invocation; inside the
     * coordinator action it must be the command the coordinator chooses to record. Reading argv
     * in this shared class would capture the wrong value for one of the two callers.
     *
     * @param WorkbenchInterface $workbench
     * @param string|null $behatCommand Value to store in the behat_command column.
     * @return DataSheetInterface The created run sheet (UID column populated).
     */
    public function create(WorkbenchInterface $workbench, ?string $behatCommand): DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($workbench, 'axenox.BDT.run');
        $ds->getColumns()->addFromSystemAttributes();
        $ds->addRow([
            'started_on'    =>  DateTimeDataType::now(),
            'behat_command' => $behatCommand
        ]);
        $ds->dataCreate(false);
        return $ds;
    }

    /**
     * Writes the expected feature/scenario counts onto an existing run row.
     *
     * Why a shared method: the coordinator knows these counts when it opens the run (it
     * discovered the features up front), and the single-process formatter computes them in its
     * BeforeSuite handler. Routing both through one method keeps the run schema and write
     * semantics controlled from one place, so the two paths cannot drift apart.
     *
     * Why no fresh read by UID: the caller holds the sheet returned by create(), which carries
     * the system attributes (and thus the current optimistic-locking version), and only the run
     * owner writes this row, so the sheet cannot be stale.
     *
     * @param DataSheetInterface $runSheet      The sheet returned by create().
     * @param int $featureCount   Expected number of features (post tag-filter).
     * @param int $scenarioCount  Expected number of scenarios/outlines (post tag-filter).
     * @return void
     */
    public function setExpectedCounts(DataSheetInterface $runSheet, int $featureCount, int $scenarioCount): void
    {
        $runSheet->setCellValue('expected_feature_count', 0, $featureCount);
        $runSheet->setCellValue('expected_scenario_count', 0, $scenarioCount);
        $runSheet->dataUpdate(false);
    }

    /**
     * Finalizes a run row: writes finished_on (and the run status) onto the run created by
     * create().
     *
     * @param DataSheetInterface $runSheet The sheet returned by create().
     * @return void
     */
    public function finalize(DataSheetInterface $runSheet): void
    {
        $finished_on = DateTimeDataType::now();
        $runSheet->setCellValue('finished_on', 0, $finished_on);
        $durationMs = $this->computeDurationSeconds($runSheet->getRow()['started_on'], $finished_on);
        $runSheet->setCellValue('duration_ms', 0, $durationMs);
        $runSheet->dataUpdate(false);
    }

    /**
     * Computes elapsed seconds between two datetime values.
     *
     * Centralized so both finalize() and any future caller measure duration identically rather
     * than each reimplementing the date math.
     */
    private function computeDurationSeconds($startedOn, $finishedOn): float
    {
        $start = new \DateTimeImmutable((string) $startedOn);
        $end   = new \DateTimeImmutable((string) $finishedOn);
        return (float) ($end->getTimestamp() - $start->getTimestamp());
    }

    /**
     * Stages the run's log digest (summary + failure blocks) onto the run sheet WITHOUT
     * issuing its own update.
     *
     * Why no dataUpdate here: the coordinator calls this immediately before finalize(), so the log
     * value rides along in finalize()'s single dataUpdate. That keeps the run row's whole close-out
     * (finished_on, duration, log) in ONE write, avoiding a second optimistic-locking round-trip on
     * a row whose only writer is the coordinator anyway.
     *
     * Why it lives here rather than in the coordinator: this class is the single source of truth for
     * the run-row schema, so the log column name stays owned in one place and the two run writers
     * cannot drift on it.
     *
     * @param DataSheetInterface $runSheet The sheet returned by create().
     * @param string $log The log digest to write into the run row.
     * @return void
     */
    public function setRunLog(DataSheetInterface $runSheet, string $log): void
    {
        $runSheet->setCellValue('log', 0, $log);
    }
}