<?php

namespace axenox\BDT\Behat\Common;

use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;

/**
 * Deletes rows of a self-referencing object in leaf-to-root order.
 *
 * WHY THIS EXISTS AS ITS OWN CLASS: a self-referencing foreign key with ON DELETE RESTRICT cannot be
 * taught the order by the database - MS SQL Server forbids ON DELETE CASCADE on a self-referencing
 * table - so the application has to impose it. Two callers now need exactly that: the run retention
 * (bdt_run_step.parent_step_oid) and the test data reaper (any hierarchical business object). A
 * second copy of the depth walk would be one more place where the cycle guard can be forgotten.
 *
 * WHY DEPTH IS COMPUTED IN PHP: expressing "has no remaining children" as a data sheet filter would
 * mean one query per hierarchy level. One flat read plus an in-memory depth calculation costs a
 * single round trip regardless of how deep the tree gets.
 */
final class LeafFirstDeleter
{
    /**
     * Deletes every row of the given sheet, deepest level first.
     *
     * @param DataSheetInterface $sheet Already read, carrying the UID column and $parentColumnName
     * @param string $parentColumnName Column holding the parent UID (empty/NULL marks a root row)
     * @param DataTransactionInterface|null $transaction Transaction the deletes must join, if any
     * @return int Number of deleted rows
     */
    public static function delete(DataSheetInterface $sheet, string $parentColumnName, DataTransactionInterface $transaction = null) : int
    {
        if ($sheet->isEmpty()) {
            return 0;
        }

        $object = $sheet->getMetaObject();
        $uidAlias = $object->getUidAttributeAlias();
        $parentOf = [];
        foreach ($sheet->getRows() as $row) {
            $parentOf[$row[$uidAlias]] = ($row[$parentColumnName] ?? null) ?: null;
        }

        // Walk each row up to its root to get its depth. The visited set is not an optimisation but a
        // safety net: corrupt data with a parent cycle would otherwise loop forever inside a
        // transaction and hang the scheduled cleanup.
        $byDepth = [];
        foreach (array_keys($parentOf) as $uid) {
            $depth = 0;
            $cursor = $uid;
            $visited = [];
            while (($parent = $parentOf[$cursor] ?? null) !== null) {
                if (isset($visited[$parent])) {
                    throw new RuntimeException('Cannot delete "' . $object->getAliasWithNamespace() . '" leaf-first: the hierarchy contains a cycle at "' . $parent . '".');
                }
                $visited[$parent] = true;
                $cursor = $parent;
                $depth++;
            }
            $byDepth[$depth][] = $uid;
        }
        krsort($byDepth);

        $deleted = 0;
        foreach ($byDepth as $uids) {
            $batch = DataSheetFactory::createFromObject($object);
            $batch->getColumns()->addFromUidAttribute();
            $batch->getFilters()->addConditionFromValueArray($uidAlias, $uids);
            $batch->dataRead();
            if (! $batch->isEmpty()) {
                $deleted += $transaction !== null ? $batch->dataDelete($transaction) : $batch->dataDelete();
            }
        }
        return $deleted;
    }
}