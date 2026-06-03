<?php
namespace axenox\BDT\Behaviors;

use axenox\BDT\Behat\Common\FeatureFileValidator;
use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Events\Action\OnBeforeActionPerformedEvent;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\Model\BehaviorInterface;

/**
 * Validates the Gherkin content of a feature file before it is saved.
 *
 * Attach this behavior to the meta object that stores feature file records
 * (e.g. axenox.BDT.feature_file).  Whenever a SaveData or UpdateData action
 * is performed on that object, this behavior reads the content column from
 * the input data, runs FeatureFileValidator::validate() against it, and throws
 * a RuntimeException if any fatal structural errors are found — which causes
 * PowerUI to abort the save before it reaches the database.
 *
 * Because the listener fires on OnBeforeActionPerformedEvent, the data is
 * never written when validation fails, so no timestamp conflict can occur
 * when the user corrects the error and tries to save again.
 *
 * Only errors that would cause the entire Behat suite to abort are treated
 * as blocking (missing Feature: keyword, tags with spaces, steps without
 * Gherkin keywords, inconsistent Examples table columns, etc.).  Non-fatal
 * issues such as undefined step definitions are intentionally ignored so
 * that users are not prevented from saving work-in-progress scenarios.
 *
 * Configuration in the model:
 *
 *   {
 *     "content_attribute_alias": "content"
 *   }
 *
 * `content_attribute_alias` — alias of the attribute that holds the raw
 * Gherkin text.  Defaults to "content".
 */
class FeatureFileValidatingBehavior extends AbstractBehavior
{
    /** Default alias of the attribute that stores raw Gherkin content */
    private const DEFAULT_CONTENT_ALIAS = 'CONTENTS';

    /** @var string Attribute alias read from behavior configuration */
    private string $contentAttributeAlias = self::DEFAULT_CONTENT_ALIAS;

    /**
     * Registers the pre-save validation listener.
     *
     * Uses OnBeforeActionPerformedEvent so that validation runs before the
     * action writes anything to the database.  If validation fails, the
     * exception prevents the save entirely — no timestamp conflict can arise.
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::registerEventListeners()
     */
    protected function registerEventListeners(): BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(
            OnBeforeActionPerformedEvent::getEventName(),
            [$this, 'onBeforeFeatureFileSave'],
            $this->getPriority()
        );
        return $this;
    }

    /**
     * Removes the pre-save validation listener when the behavior is disabled.
     *
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior::unregisterEventListeners()
     */
    protected function unregisterEventListeners(): BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->removeListener(
            OnBeforeActionPerformedEvent::getEventName(),
            [$this, 'onBeforeFeatureFileSave']
        );
        return $this;
    }

    /**
     * Validates feature file content before a SaveData or UpdateData action is executed.
     *
     * Reads the input data from the task (not the result, which does not exist
     * yet at this point), extracts the content column, and runs the static
     * validator against each row.  If any row contains fatal structural errors,
     * a RuntimeException is thrown immediately, aborting the action before any
     * data is written to the database.
     *
     * Rows that do not include the content column are skipped silently —
     * this handles partial updates (e.g. changing only the status field)
     * where the content was not transmitted to the server.
     *
     * @param OnBeforeActionPerformedEvent $event
     * @throws RuntimeException when the feature file content contains fatal Gherkin errors.
     */
    public function onBeforeFeatureFileSave(OnBeforeActionPerformedEvent $event): void
    {
        $action = $event->getAction();

        // Only act on explicit save/update actions — read-only actions are ignored.
        if (! $action->is('exface.Core.SaveData') && ! $action->is('exface.Core.UpdateData')) {
            return;
        }

        $task = $event->getTask();
        if (! $task->hasInputData()) {
            return;
        }

        $data = $task->getInputData();

        // Only act on data that belongs to the object this behavior is attached to.
        if (! $data->getMetaObject()->is($this->getObject())) {
            return;
        }

        // If the content column was not part of this save payload, nothing to validate.
        $contentCol = $data->getColumns()->get($this->contentAttributeAlias);
        if ($contentCol === false || $contentCol === null) {
            return;
        }

        foreach ($data->getRows() as $rowNr => $row) {
            $content = $contentCol->getCellValue($rowNr);

            // Skip rows where content is null or empty (not transmitted).
            if ($content === null || $content === '') {
                continue;
            }

            $validationResult = FeatureFileValidator::validate($content);

            if (! $validationResult->isValid()) {
                $rowLabel = $this->buildRowLabel($data, $rowNr);
                throw new RuntimeException(
                    'Feature file ' . $rowLabel . ' contains errors that would break '
                    . 'the test suite and cannot be saved:' . "\n\n"
                    . $validationResult->toText()
                );
            }
        }
    }

    /**
     * Builds a human-readable label for a data row to include in error messages.
     *
     * Tries common name/title columns first so the message refers to the feature
     * file by name rather than a raw UID.  Falls back to the row index when no
     * recognisable label column is present.
     *
     * @param \exface\Core\Interfaces\DataSheets\DataSheetInterface $data
     * @param int $rowNr Zero-based row index.
     * @return string A short label such as '"My Feature"' or 'row 3'.
     */
    private function buildRowLabel(\exface\Core\Interfaces\DataSheets\DataSheetInterface $data, int $rowNr): string
    {
        foreach (['name', 'title', 'filename', 'alias'] as $candidate) {
            $col = $data->getColumns()->get($candidate);
            if ($col !== false && $col !== null) {
                $value = $col->getCellValue($rowNr);
                if ($value !== null && $value !== '') {
                    return '"' . $value . '"';
                }
            }
        }
        if ($data->hasUidColumn(true)) {
            $uid = $data->getUidColumn()->getCellValue($rowNr);
            if ($uid !== null && $uid !== '') {
                return '[' . $uid . ']';
            }
        }
        return 'row ' . ($rowNr + 1);
    }

    /**
     * Returns the alias of the attribute that holds the raw Gherkin content.
     *
     * @return string
     */
    public function getContentAttributeAlias(): string
    {
        return $this->contentAttributeAlias;
    }

    /**
     * Sets the alias of the attribute that holds the raw Gherkin content.
     *
     * Called automatically by AbstractBehavior when the behavior is
     * instantiated from its UXON configuration.
     *
     * @param string $alias
     * @return FeatureFileValidatingBehavior
     */
    public function setContentAttributeAlias(string $alias): FeatureFileValidatingBehavior
    {
        $this->contentAttributeAlias = $alias;
        return $this;
    }
}