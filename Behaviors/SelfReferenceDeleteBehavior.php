<?php
namespace axenox\BDT\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\CommonLogic\Debugger\LogBooks\BehaviorLogBook;
use exface\Core\Events\Behavior\OnBeforeBehaviorAppliedEvent;
use exface\Core\Events\Behavior\OnBehaviorAppliedEvent;
use exface\Core\Events\DataSheet\OnBeforeDeleteDataEvent;
use exface\Core\Exceptions\Behaviors\BehaviorConfigurationError;
use exface\Core\Exceptions\Behaviors\BehaviorRuntimeError;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\DataTypes\RelationCardinalityDataType;

/**
 * Deletes a self-referencing hierarchy leaf-to-root, so ON DELETE RESTRICT foreign keys are never violated.
 * 
 * Attach this behavior to a meta object that references itself via a relation (e.g. a `parent`
 * relation pointing back to the same object). When any row of such an object is deleted, a single
 * batch `DELETE` would try to remove parents before their children, which fails on databases with
 * an `ON DELETE RESTRICT` (or `NO ACTION`) foreign key - typically MySQL error 1451 or the
 * equivalent MS SQL Server constraint error. Switching the database to `ON DELETE CASCADE` is not
 * always possible: MS SQL Server forbids cascading deletes on self-referencing tables.
 *
 * This behavior solves the problem entirely inside the workbench and independently of the database
 * engine. On `OnBeforeDeleteData` it expands the rows being deleted into the full sub-tree of
 * descendants, groups them by their depth and deletes them one generation at a time, starting with
 * the deepest (leaf) generation and ending with the original rows. Related rows on the SAME data
 * source are removed by the normal cascade of each generation's delete; related rows on a DIFFERENT
 * data source (e.g. file objects on a file connector) are deleted explicitly per generation, because
 * the built-in cascade cannot reliably resolve them (see deleteCrossSourceChildren()).* 
 * Since the behavior takes over the complete delete of the addressed rows, it works no matter how
 * the delete was triggered - the PowerUI delete action, an `ActionChain`, or a manual
 * `DataSheet::dataDelete()` in custom cleanup code all benefit from it.
 * 
 * ## Configuration
 * 
 * The only required property is `parent_relation` - the alias of the relation that points back to
 * the object itself.
 * 
 * ```
 * {
 *  "parent_relation": "parent_step"
 * }
 * 
 * ```
 *
 * ## Notes
 * 
 * - Do NOT enable `DELETE_WITH_RELATED_OBJECT` on the self-relation (the `parent_relation`). This
 * behavior already deletes the whole hierarchy in the correct order, so the model cascade on the
 * self-relation would only duplicate the work.
 * - DO keep `DELETE_WITH_RELATED_OBJECT` enabled on relations pointing to related objects on a
 * DIFFERENT data source (e.g. the screenshot file object's relation back to this object). This
 * behavior relies on that flag to discover which cross-source children to delete explicitly - without
 * it, those files would be left behind silently.
 * - The behavior guards against infinite recursion via `max_depth`. Increase this value only if you
 * really expect hierarchies deeper than the default.
 * 
 * @author Gizem Bicer
 */
class SelfReferenceDeleteBehavior extends AbstractBehavior
{
    private ?string $parentRelationAlias = null;
    
    private int $maxDepth = 100;
    
    private bool $inProgress = false;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::registerEventListeners()
     */
    protected function registerEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeDeleteDataEvent::getEventName(), [$this, 'onBeforeDeleteOrderLeafFirst'], $this->getPriority());
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::unregisterEventListeners()
     */
    protected function unregisterEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->removeListener(OnBeforeDeleteDataEvent::getEventName(), [$this, 'onBeforeDeleteOrderLeafFirst']);
        return $this;
    }
    
    /**
     * Expands the rows being deleted into their full sub-tree and deletes them leaf-first.
     * 
     * @param OnBeforeDeleteDataEvent $event
     * @throws BehaviorRuntimeError
     * @return void
     */
    public function onBeforeDeleteOrderLeafFirst(OnBeforeDeleteDataEvent $event) : void
    {
        if ($this->isDisabled()) {
            return;
        }
        
        // Ignore the deletes this behavior performs itself (re-entrancy). The nested per-generation
        // deletes must run through the normal delete path without being expanded again.
        if ($this->inProgress === true) {
            return;
        }
        
        $eventSheet = $event->getDataSheet();
        
        // Only react to data of exactly this object (or objects extended from it).
        if (! $eventSheet->getMetaObject()->isExactly($this->getObject())) {
            return;
        }
        
        // Nothing to do for empty sheets.
        if ($eventSheet->isEmpty()) {
            return;
        }
        
        $logbook = new BehaviorLogBook($this->getAlias(), $this, $event);
        $logbook->addLine('Deleting self-referencing hierarchy of ' . $this->getObject()->__toString() . ' leaf-first via relation "' . $this->getParentRelationAlias() . '".');
        $this->getWorkbench()->eventManager()->dispatch(new OnBeforeBehaviorAppliedEvent($this, $event, $logbook));
        
        $transaction = $event->getTransaction();
        
        // Determine the UIDs of the rows the caller wants to delete.
        $rootUids = $this->getUidsToDelete($eventSheet, $logbook);
        if (empty($rootUids)) {
            $logbook->addLine('No UIDs found in the data being deleted - nothing to reorder.');
            $this->getWorkbench()->eventManager()->dispatch(new OnBehaviorAppliedEvent($this, $event, $logbook));
            return;
        }
        
        // Build a map UID => depth for the whole sub-tree. A node always gets its greatest depth so
        // that it is guaranteed to be deleted before any of its ancestors.
        $depthByUid = $this->mapDepths($rootUids, $logbook);
        
        // Group the UIDs by depth and delete from the deepest generation up to the roots.
        $generations = $this->groupByDepthDescending($depthByUid);
        $logbook->addLine('Deleting ' . count($depthByUid) . ' row(s) in ' . count($generations) . ' generation(s), deepest first.');
        
        $this->inProgress = true;
        try {
            foreach ($generations as $depth => $uids) {
                // Explicitly remove related rows living on a DIFFERENT data source (e.g. files on a
                // file connector) BEFORE deleting this generation. The built-in cascade would filter
                // the related object by the relation key using bare UID values, which a file query
                // builder cannot always resolve to the correct folder - so it may silently delete
                // nothing. Reading the related rows first (with their full context) and deleting them
                // through the normal path guarantees the physical records are removed.
                $this->deleteCrossSourceChildren($uids, $transaction, $logbook);
                
                $deleteSheet = DataSheetFactory::createFromObject($this->getObject());
                $uidCol = $deleteSheet->getColumns()->addFromUidAttribute();
                foreach ($uids as $uid) {
                    $deleteSheet->addRow([$uidCol->getName() => $uid]);
                }
                $count = $deleteSheet->dataDelete($transaction);
                $logbook->addLine('Deleted ' . $count . ' row(s) at depth ' . $depth . '.');
            }
        } finally {
            $this->inProgress = false;
        }
        
        // We have deleted the whole hierarchy (including the original rows) ourselves through the
        // normal delete path, so cancel the default delete query and its cascade for the original
        // rows to avoid deleting them a second time.
        $event->preventDelete(true);
        
        $this->getWorkbench()->eventManager()->dispatch(new OnBehaviorAppliedEvent($this, $event, $logbook));
    }
    
    /**
     * Returns the UID values of the rows being deleted, reading them from the data source if needed.
     * 
     * @param \exface\Core\Interfaces\DataSheets\DataSheetInterface $eventSheet
     * @param BehaviorLogBook $logbook
     * @throws BehaviorRuntimeError
     * @return string[]
     */
    protected function getUidsToDelete($eventSheet, BehaviorLogBook $logbook) : array
    {
        if (! $this->getObject()->hasUidAttribute()) {
            throw new BehaviorRuntimeError($this, 'Cannot order deletes leaf-first for ' . $this->getObject()->__toString() . ': the object has no UID attribute.');
        }
        
        $sheet = $eventSheet;
        $uidCol = $sheet->getUidColumn();
        
        // If the sheet does not carry UID values yet, load them using its current filters.
        if ($uidCol === null || $uidCol->isEmpty(true)) {
            if (! $sheet->getMetaObject()->isReadable()) {
                throw new BehaviorRuntimeError($this, 'Cannot determine the rows to delete for ' . $this->getObject()->__toString() . ': no UID values and object is not readable.');
            }
            $logbook->addLine('Input data has no UID values - reading them from the data source.');
            $sheet = $eventSheet->copy();
            if ($sheet->getUidColumn() === null) {
                $sheet->getColumns()->addFromUidAttribute();
            }
            $sheet->dataRead();
            $uidCol = $sheet->getUidColumn();
        }
        
        return array_values(array_unique(array_filter($uidCol->getValues(), function($v) {
            return $v !== null && $v !== '';
        })));
    }
    
    /**
     * Walks down the hierarchy generation by generation and maps every UID to its greatest depth.
     * 
     * The root rows have depth `0`, their children depth `1` and so on. Mapping a node to its
     * greatest depth guarantees it is deleted before any of its ancestors even if the hierarchy is
     * not a strict tree.
     * 
     * @param string[] $rootUids
     * @param BehaviorLogBook $logbook
     * @throws BehaviorRuntimeError
     * @return int[] UID => depth
     */
    protected function mapDepths(array $rootUids, BehaviorLogBook $logbook) : array
    {
        $depthByUid = [];
        $frontier = $rootUids;
        $depth = 0;
        
        while (! empty($frontier)) {
            if ($depth > $this->getMaxDepth()) {
                throw new BehaviorRuntimeError($this, 'Cannot delete self-referencing hierarchy of ' . $this->getObject()->__toString() . ': maximum depth of ' . $this->getMaxDepth() . ' exceeded - the data may contain a cycle.');
            }
            
            foreach ($frontier as $uid) {
                if (! array_key_exists($uid, $depthByUid) || $depthByUid[$uid] < $depth) {
                    $depthByUid[$uid] = $depth;
                }
            }
            
            // Read the children of the current generation via the self-relation.
            $childSheet = DataSheetFactory::createFromObject($this->getObject());
            $childUidCol = $childSheet->getColumns()->addFromUidAttribute();
            $childSheet->getFilters()->addConditionFromValueArray($this->getParentRelationAlias(), $frontier);
            $childSheet->dataRead();
            
            $frontier = array_values(array_unique(array_filter($childUidCol->getValues(), function($v) {
                return $v !== null && $v !== '';
            })));
            $depth++;
        }
        
        return $depthByUid;
    }
    
    /**
     * Explicitly deletes rows of related objects that live on a different data source than this
     * object and are marked with `DELETE_WITH_RELATED_OBJECT` on their relation.
     * 
     * This targets exactly the case the built-in cascade struggles with: a related object whose
     * data source cannot join to this (SQL) object - most notably file objects on a file connector.
     * For such objects the generic cascade filters the related object by the relation key using bare
     * UID values, which a file query builder cannot always resolve to the correct folder, so it may
     * silently delete nothing. To avoid that, the related rows are read first (which resolves their
     * real records - e.g. concrete file paths) and only then deleted through the normal delete path,
     * reusing the given transaction.
     * 
     * Related objects on the SAME data source are intentionally skipped here - the standard cascade
     * of the per-generation delete handles them reliably (and re-deleting them would be redundant).
     * 
     * @param string[] $parentUids
     * @param \exface\Core\Interfaces\DataSources\DataTransactionInterface $transaction
     * @param BehaviorLogBook $logbook
     * @return void
     */
    protected function deleteCrossSourceChildren(array $parentUids, $transaction, BehaviorLogBook $logbook) : void
    {
        if (empty($parentUids)) {
            return;
        }
        
        $thisObj = $this->getObject();
        foreach ($thisObj->getRelations() as $rel) {
            // Only reverse relations (1-to-n) can request deletion of the related (right) object.
            if ($rel->getCardinality() == RelationCardinalityDataType::N_TO_ONE) {
                continue;
            }
            // Only relations explicitly marked to delete the related object together with this one.
            if (! $rel->isRightObjectToBeDeletedWithLeftObject()) {
                continue;
            }
            
            $rightObj = $rel->getRightObject();
            if (! $rightObj->isWritable() || ! $rightObj->isReadable()) {
                continue;
            }
            // Same-data-source children are handled reliably by the normal cascade - skip them here.
            if ($rightObj->hasDataSource() && $thisObj->hasDataSource() && $rightObj->getDataSource()->getId() === $thisObj->getDataSource()->getId()) {
                continue;
            }
            
            try {
                // Read the related rows in their own context so the query builder can resolve the
                // actual records (e.g. full file paths), then delete whatever was found.
                $childSheet = DataSheetFactory::createFromObject($rightObj);
                $childSheet->getColumns()->addFromSystemAttributes();
                $childSheet->getFilters()->addConditionFromValueArray($rel->getRightKeyAttribute()->getAlias(), $parentUids);
                if ($childSheet->dataRead() > 0) {
                    $deleted = $childSheet->dataDelete($transaction);
                    $logbook->addLine('Deleted ' . $deleted . ' related ' . $rightObj->__toString() . ' row(s) via relation "' . $rel->getAliasWithModifier() . '".');
                }
            } catch (\Throwable $e) {
                // A failed file delete (e.g. a locked screenshot) must stop the whole cleanup: silently
                // continuing would leak files and hide a real problem. Log the failure explicitly, then
                // abort by rethrowing so the caller sees why nothing was deleted.
                $logbook->addLine('FAILED to delete related ' . $rightObj->__toString() . ' via relation "' . $rel->getAliasWithModifier() . '": ' . $e->getMessage());
                $this->getWorkbench()->getLogger()->logException($e);
                throw new BehaviorRuntimeError($this, 'Cannot delete related ' . $rightObj->__toString() . ' for ' . $thisObj->__toString() . ': ' . rtrim($e->getMessage(), '.!') . '.', null, $e);
            }
        }
    }
    
    /**
     * Groups the UID => depth map into generations ordered by descending depth (deepest first).
     * 
     * @param int[] $depthByUid
     * @return array<int, string[]> depth => UIDs
     */
    protected function groupByDepthDescending(array $depthByUid) : array
    {
        $byDepth = [];
        foreach ($depthByUid as $uid => $depth) {
            $byDepth[$depth][] = $uid;
        }
        krsort($byDepth, SORT_NUMERIC);
        return $byDepth;
    }
    
    /**
     * The relation of this object that points back to itself - e.g. `parent_step`.
     * 
     * This is the self-reference along which the hierarchy is deleted. The behavior reads all rows
     * whose parent relation matches the rows being deleted, then their children and so on, and
     * removes them starting from the deepest level.
     * 
     * @uxon-property parent_relation
     * @uxon-type metamodel:relation
     * @uxon-required true
     * 
     * @param string $alias
     * @return SelfReferenceDeleteBehavior
     */
    public function setParentRelation(string $alias) : SelfReferenceDeleteBehavior
    {
        $this->parentRelationAlias = $alias;
        return $this;
    }
    
    /**
     * 
     * @throws BehaviorConfigurationError
     * @return string
     */
    protected function getParentRelationAlias() : string
    {
        if ($this->parentRelationAlias === null) {
            throw new BehaviorConfigurationError($this, 'Missing required property "parent_relation" for ' . get_class($this) . ' on object ' . $this->getObject()->__toString() . '.');
        }
        
        // Validate that the relation actually points back to this object.
        if (! $this->getObject()->hasRelation($this->parentRelationAlias)) {
            throw new BehaviorConfigurationError($this, 'Relation "' . $this->parentRelationAlias . '" not found on object ' . $this->getObject()->__toString() . '.');
        }
        $rightObject = $this->getObject()->getRelation($this->parentRelationAlias)->getRightObject();
        if (! $rightObject->isExactly($this->getObject())) {
            throw new BehaviorConfigurationError($this, 'Relation "' . $this->parentRelationAlias . '" of ' . $this->getObject()->__toString() . ' is not self-referencing - it points to ' . $rightObject->__toString() . '.');
        }
        
        return $this->parentRelationAlias;
    }
    
    /**
     * Maximum number of hierarchy levels the behavior will descend before aborting.
     * 
     * This is a safety limit against cyclic data. Increase it only if you really expect deeper
     * hierarchies.
     * 
     * @uxon-property max_depth
     * @uxon-type integer
     * @uxon-default 100
     * 
     * @param int $value
     * @return SelfReferenceDeleteBehavior
     */
    public function setMaxDepth(int $value) : SelfReferenceDeleteBehavior
    {
        $this->maxDepth = $value;
        return $this;
    }
    
    /**
     * 
     * @return int
     */
    protected function getMaxDepth() : int
    {
        return $this->maxDepth;
    }
}