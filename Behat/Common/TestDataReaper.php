<?php

namespace axenox\BDT\Behat\Common;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSources\SqlDataConnectorInterface;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Removes business data that the BDT test users created, once it is older than the retention window.
 *
 * WHY THIS EXISTS: scenarios create real rows (orders, articles, tickets, ...) through the UI and
 * nothing ever removed them. Over months of nightly runs that garbage grows without bound, slows the
 * tested apps down and starts to influence the tests themselves - list assertions on a table polluted
 * by hundreds of leftovers from previous nights.
 *
 * WHY A RETENTION WINDOW INSTEAD OF DELETING WHAT THIS RUN CREATED: the data a run produced is the
 * primary evidence when a scenario failed. Deleting it at the end of the run would destroy that
 * evidence at exactly the moment somebody needs it, and would race with anybody still looking at the
 * system. Keeping the last N days means the reaper only ever touches rows nobody is investigating
 * any more, and it also reclaims the garbage of earlier crashed runs, which a run-scoped cleanup
 * never could.
 *
 * WHY IT IS DRIVEN BY THE METAMODEL: scenarios touch whatever object the tested app exposes, so a
 * hand-maintained table list is outdated the moment a new feature file lands. The object enumeration
 * follows the same pattern the DBML concepts use - read ALIAS_WITH_NS from exface.Core.OBJECT,
 * instantiate each object, then decide per object whether it is in scope.
 *
 * WHY IT MUST NOT RUN INSIDE A WORKER: lanes run concurrently. Even with a retention window, a
 * reaper competing with N other lanes over the same tables only adds lock contention to a run.
 * Only the coordinator, after every lane has exited, may call it.
 */
final class TestDataReaper
{
    /**
     * Apps that are NEVER cleaned, regardless of configuration.
     *
     * WHY HARD-CODED AND NOT A CONFIG DEFAULT: the worker authenticates as the test user before it
     * writes anything, so the BDT result rows (run / run_feature / run_scenario / run_step) and the
     * core rows of the test user itself (USER, USER_ROLE_USERS, USER_AUTHENTICATOR, queued tasks,
     * monitor entries) all carry a test user as their creator. Cleaning those apps would delete test
     * results and de-provision the test users. A config option could be mis-set once and destroy
     * weeks of history, so this exclusion is not configurable - configuration may only ADD to it.
     */
    private const PROTECTED_APPS = ['exface.Core', 'axenox.BDT'];

    private const CFG_ENABLED         = 'TEST_DATA_CLEANUP.ENABLED';
    private const CFG_RETENTION_DAYS  = 'TEST_DATA_CLEANUP.RETENTION_DAYS';
    private const CFG_EXCLUDE_APPS    = 'TEST_DATA_CLEANUP.EXCLUDE_APPS';
    
    /**
     * App-config key listing apps to clean IN ADDITION to those that have BDT features.
     *
     * WHY IT IS NEEDED: the app that owns a feature file is not necessarily the app that owns the
     * rows the feature creates - a scenario in my.SalesApp routinely writes into a shared master-data
     * app. Those apps have no features of their own, so nothing would ever put them in scope.
     */
    private const CFG_INCLUDE_APPS = 'TEST_DATA_CLEANUP.INCLUDE_APPS';
    private const CFG_EXCLUDE_OBJECTS = 'TEST_DATA_CLEANUP.EXCLUDE_OBJECTS';
    private const CFG_OBJECT_FILTERS  = 'TEST_DATA_CLEANUP.OBJECT_FILTERS';
    private const CFG_MAX_PASSES      = 'TEST_DATA_CLEANUP.MAX_PASSES';

    /**
     * Days of test data kept before it becomes garbage. Two weeks plus a day, so that data produced
     * on any day of a sprint is still there for the whole of the following sprint week.
     */
    private const DEFAULT_RETENTION_DAYS = 15;

    /**
     * Upper bound for the foreign-key retry passes.
     *
     * WHY A LIMIT AT ALL: a mutual foreign-key dependency (A blocks B and B blocks A) can never be
     * resolved by retrying, so without a ceiling the reaper would spin until the close-out budget is
     * gone and the run row is finalized by a hard kill instead of by us.
     */
    private const DEFAULT_MAX_PASSES = 5;

    private WorkbenchInterface $workbench;

    /**
     * @var MetaObjectInterface[]|null Lazily built list of in-scope objects (mirrors the object cache
     *      of the DBML concepts, so the metamodel is read once per reaper instance).
     */
    private ?array $objectCache = null;

    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * Deletes every expired test-user row and reports what happened.
     *
     * WHY AGE AND BATCH ARE PARAMETERS AND NOT CONFIG READS: the caller already resolves the CLEANUP.*
     * options for the run retention. Reading them a second time here would let the two halves of the
     * same cleanup disagree about the cutoff after a config change, and would spread the CLEANUP
     * schema across two classes.
     *
     * WHY IT RETURNS A REPORT INSTEAD OF WRITING MESSAGES: the OnCleanUpEvent is the caller's channel
     * to the operator. Keeping this class free of it means the reaper can also be driven from a test
     * or a console command without an event to talk to.
     *
     * WHY IT NEVER THROWS: it runs inside the scheduled cleanup, where an unhandled failure would
     * replace the run cleanup's own error. Per-object failures are expected (foreign keys) and belong
     * in the report, not in an exception.
     *
     * @param int $maxAgeDays Rows created strictly before now minus this many days are eligible.
     * @param int $batchSize  Maximum rows deleted per object in this pass.
     * @return array{apps:string[],cutoff:?string,deleted:array<string,int>,capped:string[],failed:array<string,string>,skipped:array<string,string>,passes:int,users:int}
     */
    public function reap(int $maxAgeDays, int $batchSize): array
    {
        $report = [
            'apps' => [],
            'cutoff'  => null,
            'deleted' => [],
            'capped'  => [],
            'failed'  => [],
            'skipped' => [],
            'passes'  => 0,
            'users'   => 0
        ];

        try {
            if ($maxAgeDays < 1 || $batchSize < 1) {
                throw new RuntimeException('Invalid test data cleanup bounds: age "' . $maxAgeDays . '" days, batch "' . $batchSize . '".');
            }

            // Cutoff = now - maxAgeDays, computed ONCE. The retry passes below may span minutes, so
            // recomputing it per object would move the boundary while the reaper works and let two
            // objects of the same pass be cleaned to different cut-offs - impossible to reason about
            // when a foreign key later complains about a row that "should" have been deleted.
            $cutoff = (new \DateTimeImmutable('now'))->sub(new \DateInterval('P' . $maxAgeDays . 'D'));
            $cutoffStr = DateTimeDataType::formatDateNormalized($cutoff);
            $report['cutoff'] = $cutoffStr;

            $userUids = $this->findTestUserUids();
            $report['users'] = count($userUids);
            // No test user means nothing was ever created under one. The early return is not an
            // optimization: an empty UID list in the filter below would widen to "all rows".
            if (empty($userUids)) {
                return $report;
            }

            // Scope is resolved before anything is read: with no app under test there is nothing to
            // clean, and the reaper must stop rather than widen.
            $report['apps'] = $this->findAppsUnderTest();
            if (empty($report['apps'])) {
                return $report;
            }

            $pending = $this->getObjects($report['apps'], $report['skipped']);
            $maxPasses = $this->getMaxPasses();

            for ($pass = 1; $pass <= $maxPasses && ! empty($pending); $pass++) {
                $report['passes'] = $pass;
                $blocked = [];
                $progress = false;

                foreach ($pending as $alias => $object) {
                    try {
                        $count = $this->deleteRowsOf($object, $userUids, $cutoffStr, $batchSize);
                        if ($count > 0) {
                            $report['deleted'][$alias] = ($report['deleted'][$alias] ?? 0) + $count;
                            $progress = true;
                        }
                        // A full batch means expired rows are left over for the next scheduled pass.
                        // Re-queuing the object inside THIS pass would defeat the cap it just hit.
                        if ($count >= $batchSize && ! in_array($alias, $report['capped'], true)) {
                            $report['capped'][] = $alias;
                        }
                        unset($report['failed'][$alias]);
                    } catch (\Throwable $e) {
                        // Most likely a foreign key still held by a child object that has not been
                        // reaped yet - keep it for the next pass instead of giving up on it.
                        $blocked[$alias] = $object;
                        $report['failed'][$alias] = $e->getMessage();
                    }
                }

                // Nothing was deleted in a whole pass, so the remaining blockers are structural
                // (mutual FKs, permissions, rows referenced by production data) and another pass would
                // only repeat the same errors.
                if (! $progress) {
                    break;
                }
                $pending = $blocked;
            }
        } catch (\Throwable $e) {
            $report['failed']['*'] = get_class($e) . ': ' . $e->getMessage();
            $this->workbench->getLogger()->logException($e);
        }

        return $report;
    }

    /**
     * Tells whether data cleanup is switched on for this installation.
     *
     * WHY OPT-OUT RATHER THAN OPT-IN: on a test system the garbage is the problem cleanup exists to
     * solve, so the useful default is ON. A system whose database is shared with something that must
     * not be touched can turn it off in one place.
     */
    private function isEnabled(): bool
    {
        $cfg = $this->workbench->getApp('axenox.BDT')->getConfig();
        return $cfg->hasOption(self::CFG_ENABLED) ? (bool) $cfg->getOption(self::CFG_ENABLED) : true;
    }

    /**
     * Computes the timestamp before which test data is considered garbage.
     *
     * WHY IT IS COMPUTED ONCE PER RUN AND PASSED DOWN: the retry passes may span minutes. Recomputing
     * "now minus N days" per object would move the boundary while the reaper works, so two objects in
     * the same run could be cleaned to different cut-offs - which is impossible to reason about when
     * a foreign key later complains about a row that "should" have been deleted.
     *
     * WHY A ZERO OR NEGATIVE RETENTION IS REFUSED: it would mean "delete everything the test users
     * ever created, including what the run that is finishing right now just produced". That is a data
     * loss policy, not a retention policy, and must not be reachable through a mistyped config value.
     */
    private function getCutoff(): string
    {
        $cfg = $this->workbench->getApp('axenox.BDT')->getConfig();
        $days = $cfg->hasOption(self::CFG_RETENTION_DAYS)
            ? (int) $cfg->getOption(self::CFG_RETENTION_DAYS)
            : self::DEFAULT_RETENTION_DAYS;
        if ($days < 1) {
            throw new RuntimeException('Invalid test data retention: ' . self::CFG_RETENTION_DAYS . ' must be at least 1 day, "' . $days . '" given.');
        }
        return (new \DateTimeImmutable())->modify('-' . $days . ' days')->format('Y-m-d H:i:s');
    }

    /**
     * Resolves the UIDs of the base test user and all of its per-lane siblings.
     *
     * WHY A PATTERN MATCH AND NOT A SINGLE USERNAME: a parallel run provisions one user per lane by
     * appending "_laneN" to the configured base username. Cleaning only the base user would leave
     * everything the lanes created - which in a parallel run is all of it.
     *
     * WHY UI5Browser DECIDES WHAT A TEST USERNAME IS: that class owns the naming convention because
     * it is the one that creates the users. Re-implementing the pattern here would let the two drift
     * apart, and a reaper working from a stale pattern either misses garbage or, far worse, matches a
     * real person's username.
     *
     * @return string[]
     */
    private function findTestUserUids(): array
    {
        $base = (string) $this->workbench->getApp('axenox.BDT')->getConfig()->getOption('TEST_USER.USERNAME');
        if ($base === '') {
            throw new RuntimeException('Cannot clean up test data: TEST_USER.USERNAME is empty.');
        }

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.USER');
        $ds->getColumns()->addFromSystemAttributes();
        $ds->getColumns()->addFromExpression('USERNAME');
        // A "contains" read followed by a strict check: the metamodel comparator alone would also
        // match a real person whose username merely starts with the same string.
        $ds->getFilters()->addConditionFromString('USERNAME', $base, ComparatorDataType::IS);
        $ds->dataRead();

        $uids = [];
        foreach ($ds->getRows() as $row) {
            if (UI5Browser::isTestUsername($this->workbench, (string) $row['USERNAME'])) {
                $uids[] = $row[$ds->getUidColumn()->getName()];
            }
        }
        return $uids;
    }

    /**
     * Resolves the apps whose data the reaper is allowed to touch.
     *
     * WHY THE SCOPE IS A WHITELIST AND NOT "EVERYTHING MINUS EXCLUSIONS": an installation carries
     * dozens of apps the tests never touch. Scanning them costs a metamodel read and a SELECT per
     * object for nothing, and - far more important - it puts objects in delete range that no test
     * ever wrote to, where the only possible outcome of a mistake is destroying production data.
     *
     * WHY BEHAT_FEATURE AND NOT behat.yml: the global behat.yml is GENERATED from exactly this data
     * sheet (see the Behat action's init). Parsing the file would mean re-resolving the imports
     * chain, profiles and path placeholders that only a running Behat process has resolved - the
     * cleanup runs in the workbench, with no suite registry - and would drift the moment somebody
     * edits the file by hand. The metamodel is the upstream source of both.
     *
     * WHY AN EMPTY RESULT MEANS "CLEAN NOTHING": no registered features means no app was ever tested
     * here, so there is no test data by definition. Falling back to "all apps" in that case would
     * turn a misconfigured installation into a full-database delete scope.
     *
     * @return string[]
     */
    private function findAppsUnderTest(): array
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'axenox.BDT.BEHAT_FEATURE');
        $appCol = $ds->getColumns()->addFromExpression('APP__ALIAS');
        $ds->dataRead();

        $apps = [];
        foreach ($appCol->getValues(false) as $alias) {
            if ($alias !== null && $alias !== '' && ! in_array($alias, $apps, true)) {
                $apps[] = $alias;
            }
        }
        foreach ($this->getConfigList(self::CFG_INCLUDE_APPS) as $alias) {
            if (! in_array($alias, $apps, true)) {
                $apps[] = $alias;
            }
        }

        // The protected apps are removed AFTER the whitelist is built: axenox.BDT ships its own
        // feature files, so it would otherwise put its own result tables in delete scope.
        $excluded = array_merge(self::PROTECTED_APPS, $this->getConfigList(self::CFG_EXCLUDE_APPS));
        return array_values(array_diff($apps, $excluded));
    }

    /**
     * Reads the aliases of all candidate objects from the metamodel, limited to the apps under test.
     *
     * WHY THE CONFIGURED FILTER IS ADDED AS A NESTED GROUP: the config group may use OR internally.
     * Merging its conditions into the top-level group would turn the app scope into an alternative -
     * a config reading "app is X OR app is Y" would then also disable the whitelist. Nesting keeps
     * the scope ANDed no matter what the config says.
     *
     * @param string[] $apps Apps in scope, as resolved by findAppsUnderTest()
     * @return string[]
     */
    private function getObjectAliases(array $apps): array
    {
        if (empty($apps)) {
            return [];
        }

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.OBJECT');
        $aliasCol = $ds->getColumns()->addFromExpression('ALIAS_WITH_NS');
        $ds->getFilters()->addConditionFromValueArray('APP__ALIAS', $apps);

        $cfg = $this->workbench->getApp('axenox.BDT')->getConfig();
        if ($cfg->hasOption(self::CFG_OBJECT_FILTERS)) {
            $uxon = $cfg->getOption(self::CFG_OBJECT_FILTERS);
            if ($uxon instanceof UxonObject && ! $uxon->isEmpty()) {
                $ds->getFilters()->addNestedGroup(
                    ConditionGroupFactory::createFromUxon($this->workbench, $uxon, $ds->getMetaObject())
                );
            }
        }

        $ds->dataRead();
        return $aliasCol->getValues();
    }

    /**
     * Instantiates every candidate object and keeps the ones that may be cleaned.
     *
     * WHY OBJECTS THAT FAIL TO INSTANTIATE ARE REPORTED AND NOT ONLY SWALLOWED: a broken model entry
     * is not the reaper's problem to solve, but if the object it hides is the one filling the
     * database, silence turns a model bug into an unexplained storage problem months later.
     * 
     * WHY THE CONNECTION CHECK COMES LAST: the four checks before it are answered from the metamodel
     * alone, while this one resolves the object's data connection - which for a remote source means
     * touching another server. Ordering the cheap checks first keeps a scan over dozens of objects
     * from opening connections it will not use.
     *
     * @param array<string,string> $skipped Filled with alias => reason for everything left out
     * @return array<string,MetaObjectInterface> alias with namespace => object
     */
    private function getObjects(array $apps, array &$skipped): array
    {
        if ($this->objectCache !== null) {
            return $this->objectCache;
        }

        $excludedObjects = $this->getConfigList(self::CFG_EXCLUDE_OBJECTS);
        $result = [];
        foreach ($this->getObjectAliases($apps) as $alias) {
            if (in_array($alias, $excludedObjects, true)) {
                $skipped[$alias] = 'excluded by config';
                continue;
            }
            try {
                $object = MetaObjectFactory::createFromString($this->workbench, $alias);
                // Scope checks share the guard on purpose: getExclusionReason() resolves the object's
                // data connection, which throws when that connection is misconfigured or its server is
                // unreachable. One remote source being down must cost that source only - not the whole
                // cleanup, which is what an escaping exception here would do.
                $reason = $this->getExclusionReason($object);
            } catch (\Throwable $e) {
                $skipped[$alias] = 'not usable: ' . $e->getMessage();
                continue;
            }
            if ($reason !== null) {
                $skipped[$alias] = $reason;
                continue;
            }
            $result[$alias] = $object;
        }

        $this->objectCache = $result;
        return $result;
    }

    /**
     * Returns why an object is out of scope, or NULL if it may be cleaned.
     *
     * The physical-table checks mirror the ones the SQL DBML concept uses to recognise a real table,
     * plus the three the reaper additionally needs: something to delete by, a creator to filter on
     * and a timestamp to apply the retention window to.
     *
     * WHY A MISSING CREATED_ON DISQUALIFIES THE OBJECT: without a timestamp the retention window
     * cannot be applied, so the delete would also take rows created minutes ago - including those of
     * the run that just finished. Deleting less than intended is a nuisance; deleting fresh data is
     * data loss, so the ambiguous case must always resolve to "skip".
     *
     * WHY SQL CONNECTIONS ONLY: a delete against a non-SQL builder (the file source in particular)
     * behaves differently on empty or partial filter sets and can widen far beyond the intended rows.
     * Restricting the reaper to real SQL tables removes that class of accident entirely.
     */
    private function getExclusionReason(MetaObjectInterface $object): ?string
    {
        if (! $object->isWritable()) {
            return 'not writable';
        }
        if (! $object->hasUidAttribute()) {
            return 'no UID attribute';
        }
        if (! $object->hasAttribute('UserNeu')) {
            return 'no UserNeu attribute';
        }
        if (! $object->hasAttribute('ZeitNeu')) {
            return 'no ZeitNeu attribute - retention window not applicable';
        }
        if (! ($object->getDataConnection() instanceof SqlDataConnectorInterface)) {
            return 'not an SQL data source';
        }
        $address = (string) $object->getDataAddress();
        // A data address containing a bracket is a SQL statement like (SELECT ...), not a table.
        if ($address === '' || stripos($address, '(') !== false) {
            return 'data address is not a physical table';
        }
        return null;
    }

    /**
     * Deletes up to $batchSize expired rows of one object.
     *
     * WHY IT READS BEFORE IT DELETES: the row count is what the cleanup reports, the read is what the
     * batch cap is applied to, and an empty result skips the delete entirely - so a clean system costs
     * one SELECT per object and no writes. An empty sheet must never reach dataDelete(): with no rows
     * and no narrowing filter the delete scope widens to the whole object, which is how a cleanup
     * wipes a table.
     *
     * WHY BOTH FILTERS ARE ALWAYS PRESENT: creator alone would delete data a running test just
     * produced, age alone would delete production data. Neither condition is ever optional.
     *
     * WHY OLDEST FIRST: an unsorted limited read would take an arbitrary slice and could leave the
     * oldest rows - the ones the retention window is actually about - alive indefinitely.
     *
     * WHY SELF-REFERENCING OBJECTS TAKE THE LEAF-FIRST PATH: a tree object (category, folder,
     * position hierarchy) deletes a mid-level row before its own children and trips the RESTRICT
     * foreign key. That order has to come from the application, exactly as it does for run_step.
     *
     * @return int Number of rows deleted.
     */
    private function deleteRowsOf(MetaObjectInterface $object, array $userUids, string $cutoff, int $batchSize): int
    {
        $ds = DataSheetFactory::createFromObject($object);
        $ds->getColumns()->addFromUidAttribute();
        $ds->getFilters()->addConditionFromValueArray('UserNeu', $userUids);
        // The attribute alias is ZeitNeu in uppercase - the metamodel resolves aliases
        // case-sensitively and a lowercase spelling would silently fail to resolve.
        $ds->getFilters()->addConditionFromString('ZeitNeu', $cutoff, ComparatorDataType::LESS_THAN);
        $ds->getSorters()->addFromString('ZeitNeu', SortingDirectionsDataType::ASC);
        $ds->setRowsLimit($batchSize);
        // No total count is needed - a full batch is what signals a remaining backlog - and skipping
        // it saves a COUNT over tables this cleanup exists because they are large.
        $ds->setAutoCount(false);

        $parentAlias = $this->findSelfReferenceAlias($object);
        if ($parentAlias !== null) {
            $ds->getColumns()->addFromExpression($parentAlias);
        }

        $ds->dataRead();
        if ($ds->isEmpty()) {
            return 0;
        }

        if ($parentAlias !== null) {
            return LeafFirstDeleter::delete($ds, $parentAlias);
        }
        return $ds->dataDelete();
    }

    /**
     * Returns the alias of the object's own parent relation, or NULL if it is not hierarchical.
     *
     * Exists so the caller can decide between a plain delete and the leaf-first one without knowing
     * anything about the object's model. Only the FIRST self-reference is used: an object with two
     * independent parent chains cannot be linearised by depth anyway, and guessing which chain the
     * foreign key means would be worse than letting the delete fail and be reported.
     */
    private function findSelfReferenceAlias(MetaObjectInterface $object): ?string
    {
        foreach ($object->getRelations() as $relation) {
            if ($relation->isForwardRelation() && $relation->getRightObject()->isExactly($object)) {
                return $relation->getAliasWithModifier();
            }
        }
        return null;
    }

    /**
     * Reads an optional list-valued config option as a plain string array.
     *
     * Exists so a missing option, a single string and a real list all behave the same at the call
     * sites, instead of each one re-implementing the same three-way check.
     *
     * @return string[]
     */
    private function getConfigList(string $key): array
    {
        $cfg = $this->workbench->getApp('axenox.BDT')->getConfig();
        if (! $cfg->hasOption($key)) {
            return [];
        }
        $value = $cfg->getOption($key);
        if ($value instanceof UxonObject) {
            $value = $value->toArray();
        }
        return is_array($value) ? array_values($value) : [(string) $value];
    }

    /**
     * Resolves how many retry passes the foreign-key ordering may cost.
     */
    private function getMaxPasses(): int
    {
        $cfg = $this->workbench->getApp('axenox.BDT')->getConfig();
        $passes = $cfg->hasOption(self::CFG_MAX_PASSES) ? (int) $cfg->getOption(self::CFG_MAX_PASSES) : self::DEFAULT_MAX_PASSES;
        return max(1, $passes);
    }
}