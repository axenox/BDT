<?php
namespace axenox\BDT\Behaviors;

use axenox\BDT\Behat\Common\FeatureFileValidator;
use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Events\Action\OnBeforeActionPerformedEvent;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
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

            // Step 1: run our own structural checks first — they are fast and produce
            // precise line-level error messages.  Only proceed to the dry-run if these
            // pass, because a dry-run on a structurally broken file would produce
            // confusing parser noise on top of errors we already report clearly.
            $validationResult = FeatureFileValidator::validate($content);
            if (! $validationResult->isValid()) {
                $rowLabel = $this->buildRowLabel($data, $rowNr);
                throw new RuntimeException(
                    'Feature file ' . $rowLabel . ' contains errors that would break '
                    . 'the test suite and cannot be saved:' . "\n\n"
                    . $validationResult->toText()
                );
            }

            // Step 2: run a Behat dry-run as a safety net for parser-level errors our
            // own checks cannot detect (e.g. malformed scenario outlines, illegal
            // Unicode in keywords, Gherkin dialect mismatches).
            $dryRunError = $this->runDryRun($content);
            if ($dryRunError !== null) {
                $rowLabel = $this->buildRowLabel($data, $rowNr);
                throw new RuntimeException(
                    'Feature file ' . $rowLabel . ' failed the Behat dry-run and cannot be saved:' . "\n\n"
                    . $dryRunError
                );
            }
        }
    }

    /**
     * Writes the given Gherkin content to a temporary file, runs a Behat dry-run
     * against it, and returns any parse/syntax error output.
     *
     * Returns null when the dry-run finds no fatal errors (undefined steps are
     * intentionally ignored — they produce a non-zero exit code but are not
     * considered blocking).  Returns a string with the error output when a fatal
     * parser error is detected.
     *
     * The temporary file is always deleted after the run, even when an error occurs.
     *
     * @param string $content Raw Gherkin content to validate.
     * @return string|null Error output from Behat, or null if the file is valid.
     */
    private function runDryRun(string $content): ?string
    {
        // Write content to a temporary feature file and a minimal behat.yml next to it.
        // The custom config has no suite path restrictions so Behat parses the file
        // regardless of the project-level behat.yml suite configuration.
        $tmpDir  = sys_get_temp_dir();
        $tmpId   = uniqid('bdt_dryrun_', true);
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . $tmpId . '.feature';
        $tmpConf = $tmpDir . DIRECTORY_SEPARATOR . $tmpId . '.yml';

        file_put_contents($tmpFile, $content);
        // Minimal Behat config: one default suite pointing directly at the temp file.
        // No contexts are loaded — dry-run only checks Gherkin syntax, not step definitions.
        file_put_contents($tmpConf, implode("\n", [
            'default:',
            '  suites:',
            '    default:',
            '      paths:',
            '        - ' . str_replace('\\', '/', $tmpFile),
            '      contexts: []',
        ]));

        try {
            $cwd = $this->getWorkbench()->getInstallationPath();
            // On Windows with IIS, CliCommandRunner falls back to exec() which does not
            // inherit cwd — so we must use an absolute path to the Behat binary.
            // The .bat wrapper is called via "cmd /c" because escapeshellarg() wraps the
            // path in quotes and quoted .bat files are not executable without cmd /c.
            if (DIRECTORY_SEPARATOR === '\\') {
                $behatBin = $cwd . '\\vendor\\bin\\behat.bat';
                $cmd = 'cmd /c ' . $behatBin . ' --config ' . escapeshellarg($tmpConf) . ' --dry-run --no-colors --format=pretty';
            } else {
                $cmd = $cwd . '/vendor/bin/behat --config ' . escapeshellarg($tmpConf) . ' --dry-run --no-colors --format=pretty';
            }

            $output = '';
            // Exit code 0 = all steps defined and dry-run passed.
            // Exit code 1 = some steps undefined — not a fatal error, ignore.
            // Any other exit code = real parser/bootstrap failure.
            foreach (CliCommandRunner::runCliCommand($cmd, [], 30, $cwd, true, [0, 1]) as $chunk) {
                $output .= $chunk;
            }

            // Even with exit codes 0/1, Behat may print a parse error in the output.
            // Detect the canonical Gherkin parse error marker.
            if (stripos($output, 'ParseException') !== false
                || stripos($output, 'Lexer Exception') !== false
                || stripos($output, 'SyntaxException') !== false
            ) {
                return $this->extractDryRunError($output);
            }

            return null;
        } catch (\Throwable $e) {
            // If the dry-run process itself fails (e.g. Behat binary not found or
            // timeout), re-throw so the save is blocked — we cannot confirm the file
            // is valid if the dry-run could not run at all.
            throw new RuntimeException(
                'Feature file dry-run could not be executed: ' . $e->getMessage(),
                null,
                $e
            );
        } finally {
            // Always clean up the temp file, regardless of success or failure.
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            if (file_exists($tmpConf)) {
                @unlink($tmpConf);
            }
        }
    }

    /**
     * Extracts a concise error message from raw Behat dry-run output.
     *
     * Behat prints verbose stack traces that are not useful to the end user.
     * This method strips everything after the first blank line following the
     * error headline so only the human-readable part is returned.
     *
     * @param string $output Raw output from the Behat dry-run process.
     * @return string Trimmed error message suitable for display.
     */
    private function extractDryRunError(string $output): string
    {
        $lines  = explode("\n", $output);
        $result = [];
        $found  = false;

        foreach ($lines as $line) {
            // Start collecting from the first line that mentions an exception.
            if (! $found && (stripos($line, 'Exception') !== false || stripos($line, 'Error') !== false)) {
                $found = true;
            }
            if (! $found) {
                continue;
            }
            // Stop at the first blank line after the error — everything after is stack trace.
            if ($found && trim($line) === '') {
                break;
            }
            $result[] = trim($line);
        }

        return $result !== [] ? implode("\n", $result) : trim($output);
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