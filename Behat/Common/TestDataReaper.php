<?php

namespace axenox\BDT\Behat\Common;

use axenox\BDT\Behat\Contexts\UI5Facade\UI5Browser;
use exface\Core\Behaviors\TimeStampingBehavior;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateTimeDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Factories\ConditionGroupFactory;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Factories\MetaObjectFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
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
 * WHY IT RUNS FROM THE SCHEDULED CLEANUP AND NOT FROM A TEST RUN: a reaper inside a run would
 * compete with the lanes over the same tables and only add lock contention. The retention window
 * makes the timing irrelevant anyway, so it runs where the other housekeeping runs - from
 * DatabaseFormatter::onCleanUp().
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
     * Upper bound for the foreign-key retry passes.
     *
     * WHY A LIMIT AT ALL: a mutual foreign-key dependency (A blocks B and B blocks A) can never be
     * resolved by retrying, so without a ceiling the reaper would keep the scheduled cleanup busy
     * repeating the same failing deletes.
     */
    private const DEFAULT_MAX_PASSES = 5;

    private WorkbenchInterface $workbench;

    /**
     * @var MetaObjectInterface[]|null Lazily built list of in-scope objects (mirrors the object cache
     *      of the DBML concepts, so the metamodel is read once per reaper instance).
     */
    private ?array $objectCache = null;

    /**
     * Resolved creation stamps per object alias - see getCreationStamp().
     *
     * @var array<string,array{created_on:string,created_by:string,user_value:?string}>
     */
    private array $stampCache = [];

    /**
     * Test-user values per USER attribute alias - see getTestUserValues().
     *
     * @var array<string,string[]>
     */
    private array $userValueCache = [];

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
     * WHY THE BATCH IS A BUDGET PER OBJECT ACROSS ALL PASSES: an object that is retried because some
     * of its rows were blocked must not delete another full batch in the retry - the cap exists to
     * keep one cleanup bounded, and a retry is still the same cleanup.
     *
     * @param int $maxAgeDays Rows created strictly before now minus this many days are eligible.
     * @param int $batchSize  Maximum rows deleted per object in this cleanup.
     * @return array{cutoff:?string,apps:string[],deleted:array<string,int>,capped:string[],failed:array<string,string>,skipped:array<string,string>,passes:int,users:int}
     */
    public function reap(int $maxAgeDays, int $batchSize): array
    {
        $report = [
            'cutoff'  => null,
            'apps'    => [],
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
            // recomputing it per object would move the boundary while the reaper works.
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
                        // CHANGED: the batch is a per-object budget for the whole cleanup, and the
                        // result distinguishes deleted from blocked rows instead of all-or-nothing.
                        $budget = $batchSize - ($report['deleted'][$alias] ?? 0);
                        $result = $this->deleteRowsOf($object, $userUids, $cutoffStr, $budget);
                        if ($result['deleted'] > 0) {
                            $report['deleted'][$alias] = ($report['deleted'][$alias] ?? 0) + $result['deleted'];
                            $progress = true;
                        }
                        // A spent budget means expired rows are left over for the next scheduled
                        // cleanup. Retrying the object in a later pass would defeat the cap.
                        $capped = ($report['deleted'][$alias] ?? 0) >= $batchSize;
                        if ($capped && ! in_array($alias, $report['capped'], true)) {
                            $report['capped'][] = $alias;
                        }
                        // CHANGED: blocked rows no longer fail the whole object. They are reported
                        // with one sample reason, and the object is retried unless its budget is
                        // spent - a blocker may be a child object that a later pass reaps first.
                        if (empty($result['blocked'])) {
                            unset($report['failed'][$alias]);
                        } else {
                            $report['failed'][$alias] = count($result['blocked']) . ' row(s) blocked, e.g.: ' . reset($result['blocked']);
                            if (! $capped) {
                                $blocked[$alias] = $object;
                            }
                        }
                    } catch (\Throwable $e) {
                        // The object as a whole is unusable in this pass (e.g. the creator values could
                        // not be resolved) - keep it for the next pass instead of giving up on it.
                        $blocked[$alias] = $object;
                        $report['failed'][$alias] = $e->getMessage();
                    }
                }

                // Nothing was deleted in a whole pass, so the remaining blockers are structural
                // (mutual FKs, permissions, rows referenced by data outside the cleanup scope) and
                // another pass would only repeat the same errors.
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
     * Reads one slice of expired rows of an object, oldest first.
     *
     * WHY IT IS SEPARATE FROM deleteRowsOf(): the delete loop reads repeatedly with a shrinking limit
     * and a growing exclusion list. Keeping the read in one place guarantees that every slice carries
     * both mandatory filters - a second hand-built read in the loop is where one would get lost.
     *
     * WHY THE EXCLUSION USES THE ATTRIBUTE'S LIST DELIMITER: a NOT IN condition is given as one
     * delimited string, and the delimiter is defined per attribute in the metamodel.
     *
     * @param array{created_on:string,created_by:string,user_value:?string} $stamp
     * @param string[] $excludeUids Rows already known to be blocked in this cleanup
     */
    private function readExpiredRows(MetaObjectInterface $object, array $stamp, array $creatorValues, string $cutoff, int $limit, array $excludeUids, ?string $parentAlias): DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObject($object);
        $ds->getColumns()->addFromUidAttribute();
        if ($parentAlias !== null) {
            $ds->getColumns()->addFromExpression($parentAlias);
        }
        $ds->getFilters()->addConditionFromValueArray($stamp['created_by'], $creatorValues);
        $ds->getFilters()->addConditionFromString($stamp['created_on'], $cutoff, ComparatorDataType::LESS_THAN);
        if (! empty($excludeUids)) {
            $uidAttr = $object->getUidAttribute();
            $ds->getFilters()->addConditionFromString(
                $uidAttr->getAliasWithRelationPath(),
                implode($uidAttr->getValueListDelimiter(), $excludeUids),
                ComparatorDataType::NOT_IN
            );
        }
        $ds->getSorters()->addFromString($stamp['created_on'], SortingDirectionsDataType::ASC);
        $ds->setRowsLimit($limit);
        // No total count is needed - a full slice is what signals a remaining backlog - and skipping
        // it saves a COUNT over tables this cleanup exists because they are large.
        $ds->setAutoCount(false);
        $ds->dataRead();
        return $ds;
    }

    /**
     * Deletes the rows of an already read sheet one at a time and collects the ones that cannot go.
     *
     * WHY ONE TRANSACTION PER ROW: it is the only way to keep a row whose cascade hits a foreign key
     * from rolling back its neighbours. The cost - one read and one delete per row - is only paid on
     * the failure path, after a whole batch was refused.
     *
     * WHY IT RE-READS EACH ROW: the refused batch may have been partially applied (leaf-first deletes
     * commit per level), so a row may already be gone. deleteByUids() re-reads and skips such rows
     * instead of issuing a delete against nothing.
     *
     * On a self-referencing object a parent may come before its child here and be refused; the child
     * still goes, and the parent is picked up by the next pass.
     *
     * @return array{deleted:int,blocked:array<string,string>} blocked: row UID => reason
     */
    private function deleteRowByRow(DataSheetInterface $sheet): array
    {
        $object = $sheet->getMetaObject();
        $deleted = 0;
        $blocked = [];
        foreach ($sheet->getUidColumn()->getValues(false) as $uid) {
            try {
                $deleted += self::deleteByUids($object, [$uid]);
            } catch (\Throwable $e) {
                $blocked[$uid] = $e->getMessage();
            }
        }
        return ['deleted' => $deleted, 'blocked' => $blocked];
    }

    /**
     * Deletes the rows of an object with the given UIDs, re-reading them first.
     *
     * WHY IT RE-READS: rows may already be gone - removed by the cascade of an earlier delete, or by
     * the level-by-level commits of a refused leaf-first delete - and a delete must only ever target
     * rows that provably still exist. The read also hands dataDelete() concrete UIDs to build its
     * cascading sub-deletes from.
     *
     * WHY AN EMPTY LIST RETURNS WITHOUT A QUERY: an IN filter over an empty list is dropped as an
     * empty value, and a filterless delete targets the whole table.
     *
     * WHY NO TRANSACTION PARAMETER: the only caller is the row-by-row fallback, whose whole point is
     * that every row runs in its own transaction.
     *
     * @param string[] $uids
     * @return int Number of deleted rows
     */
    private static function deleteByUids(MetaObjectInterface $object, array $uids) : int
    {
        if (empty($uids)) {
            return 0;
        }
        $sheet = DataSheetFactory::createFromObject($object);
        $sheet->getColumns()->addFromUidAttribute();
        $sheet->getFilters()->addConditionFromValueArray($object->getUidAttributeAlias(), $uids);
        $sheet->dataRead();
        if ($sheet->isEmpty()) {
            return 0;
        }
        return $sheet->dataDelete();
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
     * plus the two the reaper additionally needs: a UID to delete by and a creation stamp - creator
     * and creation time - to filter on.
     *
     * WHY THE CREATION STAMP IS MANDATORY: without a trustworthy creator the reaper cannot tell test
     * data from production data, and without a trustworthy timestamp the retention window cannot be
     * applied - the delete would also take rows created minutes ago. Deleting less than intended is a
     * nuisance; deleting fresh or foreign data is data loss, so any doubt resolves to "skip". Where the
     * stamp comes from is explained in getCreationStamp().
     *
     * WHY SQL CONNECTIONS ONLY: a delete against a non-SQL builder (the file source in particular)
     * behaves differently on empty or partial filter sets and can widen far beyond the intended rows.
     *
     * WHY THE CONNECTION CHECK COMES LAST: every check before it reads the metamodel only and is free;
     * resolving the connection may reach a remote server, so most out-of-scope objects never pay it.
     */
    private function getExclusionReason(MetaObjectInterface $object): ?string
    {
        if (! $object->isWritable()) {
            return 'not writable';
        }
        if (! $object->hasUidAttribute()) {
            return 'no UID attribute';
        }
        // CHANGED: was two hasAttribute() checks on the fixed aliases CREATED_BY_USER / CREATED_ON
        try {
            $this->getCreationStamp($object);
        } catch (\Throwable $e) {
            // The resolver states its reason in the message, so the skip list tells whether the
            // behavior is missing, incomplete or ambiguous.
            return $e->getMessage();
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
     * Resolves which attributes the platform stamps with the creation time and the creator of a row.
     *
     * WHY THE BEHAVIOR IS ASKED INSTEAD OF HARD-CODING CREATED_ON / CREATED_BY_USER: those aliases are
     * only a naming convention. The TimeStampingBehavior is what actually writes the stamp, so its
     * configuration is the single source of truth for (a) the attribute holding the creation time,
     * (b) the attribute holding the creator and (c) WHICH user value is stored there - the UID by
     * default, but e.g. the USERNAME if created_by_value_user_attribute_alias is set. A fixed alias
     * misses objects whose attributes are named differently, and on an object storing usernames it
     * filters by UIDs and silently matches nothing. An attribute that merely exists under the right
     * name but is not filled by the behavior is no evidence of who created a row at all.
     *
     * WHY A DISABLED BEHAVIOR STILL COUNTS: disabling only stops new stamps; the attribute mapping is
     * still correct for rows already written. Unstamped rows hold NULL, which matches neither the
     * creator nor the age filter, so they are never deleted.
     *
     * WHY DIFFERING BEHAVIORS DISQUALIFY THE OBJECT: if two behaviors stamp different attributes there
     * is no way to tell which one reflects the real creator. Guessing could put production rows in
     * scope, so the ambiguous case resolves to "skip".
     *
     * Memoized per object: getObjects() resolves it for the scope check and deleteRowsOf() needs it
     * again in every retry pass.
     *
     * @throws RuntimeException if the object has no usable or an ambiguous creation stamp - the
     *         message is the skip reason reported to the operator.
     * @return array{created_on:string,created_by:string,user_value:?string}
     */
    private function getCreationStamp(MetaObjectInterface $object): array
    {
        $key = $object->getAliasWithNamespace();
        if (isset($this->stampCache[$key])) {
            return $this->stampCache[$key];
        }

        $stamps = [];
        foreach ($object->getBehaviors()->getByPrototypeClass(TimeStampingBehavior::class)->getAll() as $behavior) {
            /** @var TimeStampingBehavior $behavior */
            if (! $behavior->hasCreatedOnAttribute() || ! $behavior->hasCreatedByAttribute()) {
                continue;
            }
            $stamp = [
                'created_on' => $behavior->getCreatedOnAttribute()->getAliasWithRelationPath(),
                'created_by' => $behavior->getCreatedByAttribute()->getAliasWithRelationPath(),
                'user_value' => $this->getCreatedByUserValueAlias($behavior),
            ];
            // Keyed by content, so the same mapping configured or inherited twice is not ambiguity.
            $stamps[implode('|', array_map('strval', $stamp))] = $stamp;
        }

        if (empty($stamps)) {
            throw new RuntimeException('no TimeStampingBehavior stamping both creation time and creator');
        }
        if (count($stamps) > 1) {
            throw new RuntimeException('ambiguous creation stamp - ' . count($stamps) . ' TimeStampingBehaviors stamp different attributes');
        }
        return $this->stampCache[$key] = reset($stamps);
    }

    /**
     * Returns which USER attribute the behavior stores as creator, or NULL when it stores the UID.
     *
     * WHY IT READS THE BEHAVIOR'S UXON: TimeStampingBehavior keeps this setting behind a protected
     * getter. The exported UXON is the public view of exactly the same configuration, and reading it
     * avoids reflection or subclassing a core class.
     *
     * WHY THE LOOKUP IS CASE-INSENSITIVE: UXON keys are mapped to setters case-insensitively (PHP
     * method names are), so an upper-case key configures the behavior just as well. The reader must
     * not be stricter than the writer, or it would fall back to UIDs and match nothing.
     */
    private function getCreatedByUserValueAlias(TimeStampingBehavior $behavior): ?string
    {
        $uxon = $behavior->exportUxonObject();
        if ($uxon === null) {
            return null;
        }
        $key = $uxon->findPropertyKey('created_by_value_user_attribute_alias');
        if ($key === false) {
            return null;
        }
        $value = trim((string) $uxon->getProperty($key));
        return ($value === '' || strcasecmp($value, 'UID') === 0) ? null : $value;
    }

    /**
     * Returns the values the test users carry in the given USER attribute, e.g. their usernames.
     *
     * WHY IT EXISTS: an object whose behavior stores the username (or any other user attribute) as
     * creator must be filtered by those values, not by UIDs - a UID filter matches nothing there and
     * the object would never be cleaned. The values are derived from the already resolved test-user
     * UIDs, so identifying the test users stays in findTestUserUids() only.
     *
     * WHY AN EMPTY UID LIST RETURNS EMPTY WITHOUT READING: an empty IN filter widens to all users,
     * which would turn every user's value into a delete criterion.
     *
     * Memoized per attribute: many objects share the same setting, and the set of test users does
     * not change during one cleanup (the reaper is instantiated per cleanup).
     *
     * @param string[] $userUids
     * @return string[]
     */
    private function getTestUserValues(array $userUids, string $userAttributeAlias): array
    {
        if (empty($userUids)) {
            return [];
        }
        if (array_key_exists($userAttributeAlias, $this->userValueCache)) {
            return $this->userValueCache[$userAttributeAlias];
        }

        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->workbench, 'exface.Core.USER');
        $col = $ds->getColumns()->addFromExpression($userAttributeAlias);
        $ds->getFilters()->addConditionFromValueArray($ds->getMetaObject()->getUidAttributeAlias(), $userUids);
        $ds->dataRead();

        $values = [];
        foreach ($col->getValues(false) as $value) {
            if ($value !== null && $value !== '' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }
        return $this->userValueCache[$userAttributeAlias] = $values;
    }

    /**
     * Deletes up to $batchSize expired rows of one object, oldest first, isolating rows that cannot go.
     *
     * WHY BOTH FILTERS ARE ALWAYS PRESENT: creator alone would delete data a running test just
     * produced, age alone would delete production data. Neither condition is ever optional - which is
     * also why an empty creator list aborts instead of being passed to the IN filter.
     *
     * WHY A FAILED BATCH FALLS BACK TO ROW-BY-ROW: dataDelete() runs the whole sheet, cascades
     * included, in one transaction and rolls all of it back on the first foreign key error. A single
     * row still referenced by data outside the scope (a delivery note pointing at an old requirement
     * list) would otherwise veto every deletable row in the batch.
     *
     * WHY BLOCKED ROWS ARE EXCLUDED FROM THE NEXT READ: reads are sorted oldest first, so a permanently
     * blocked row sits at the head of every batch. Without the exclusion it would be retried forever
     * and starve the object - the rows behind it would never be cleaned.
     *
     * WHY THE LOOP STOPS AT $batchSize BLOCKED ROWS: past that point the object is held by data outside
     * the cleanup scope, which configuration has to fix, not retries. It also keeps the NOT IN list
     * bounded.
     *
     * WHY THE LOOP ALSO STOPS WITHOUT PROGRESS: a delete that reports 0 affected rows (e.g. prevented
     * by a behavior) would otherwise re-read the same rows forever.
     *
     * KNOWN LIMITATION - on a self-referencing object the leaf-first delete commits level by level, so
     * a failure in an upper level leaves the deeper levels deleted but uncounted. The row-by-row
     * fallback only counts what it removes itself.
     *
     * @return array{deleted:int,blocked:array<string,string>} blocked: row UID => reason
     */
    private function deleteRowsOf(MetaObjectInterface $object, array $userUids, string $cutoff, int $batchSize): array
    {
        $stamp = $this->getCreationStamp($object);
        $creatorValues = $stamp['user_value'] === null
            ? $userUids
            : $this->getTestUserValues($userUids, $stamp['user_value']);
        if (empty($creatorValues)) {
            throw new RuntimeException('no creator value resolved for the test users (creator attribute "' . $stamp['created_by'] . '")');
        }

        $parentAlias = $this->findSelfReferenceAlias($object);
        $deleted = 0;
        $blocked = [];

        while ($deleted < $batchSize && count($blocked) < $batchSize) {
            $limit = $batchSize - $deleted;
            $ds = $this->readExpiredRows($object, $stamp, $creatorValues, $cutoff, $limit, array_keys($blocked), $parentAlias);
            if ($ds->isEmpty()) {
                break;
            }

            $before = $deleted + count($blocked);
            try {
                $deleted += $parentAlias !== null ? LeafFirstDeleter::delete($ds, $parentAlias) : $ds->dataDelete();
            } catch (\Throwable $e) {
                $isolated = $this->deleteRowByRow($ds);
                $deleted += $isolated['deleted'];
                $blocked += $isolated['blocked'];
            }

            // A short read means no expired rows are left behind this slice.
            if ($ds->countRows() < $limit || $deleted + count($blocked) === $before) {
                break;
            }
        }

        return ['deleted' => $deleted, 'blocked' => $blocked];
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