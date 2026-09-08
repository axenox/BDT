<?php
namespace axenox\BDT\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\DataTypes\GherkinDataType;
use exface\Core\Events\Action\OnBeforeActionPerformedEvent;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Facades\ConsoleFacade\CliCommandRunner;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
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
 *     "content_attribute_alias": "contents",
 *     "strict": true,
 *     "dry_run": true
 *   }
 *
 * `content_attribute_alias` — alias of the attribute that holds the raw Gherkin text.
 * Defaults to "CONTENTS".
 * `strict` — set to FALSE to ignore non-fatal convention issues. Default TRUE.
 * `dry_run` — set to FALSE to skip the Behat dry-run. Default TRUE.
 */
class FeatureFileValidatingBehavior extends AbstractBehavior
{
    /** Default alias of the attribute that stores raw Gherkin content */
    private const DEFAULT_CONTENT_ALIAS = 'CONTENTS';

    /** @var string Attribute alias read from behavior configuration */
    private string $contentAttributeAlias = self::DEFAULT_CONTENT_ALIAS;

    /** @var bool Whether convention checks of GherkinDataType are applied too */
    private bool $strict = true;

    /** @var bool Whether the (slow) Behat dry-run is performed after the static checks */
    private bool $dryRun = true;

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
     * Reads the input data from the task (not the result, which does not exist yet at this
     * point), extracts the content column and validates every row. If any row contains fatal
     * structural errors, a RuntimeException aborts the action before anything is written to the
     * database - so no timestamp conflict can occur when the user corrects the error and saves
     * again.
     *
     * Rows without the content column are skipped silently - this handles partial updates (e.g.
     * changing only the status field) where the content was not transmitted to the server.
     *
     * @param OnBeforeActionPerformedEvent $event
     * @throws RuntimeException when the feature file content contains fatal Gherkin errors.
     */
    public function onBeforeFeatureFileSave(OnBeforeActionPerformedEvent $event): void
    {
        $action = $event->getAction();

        // Only act on explicit save/update actions - read-only actions are ignored.
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
            if ($content === null || trim($content) === '') {
                continue;
            }

            // Step 1: the structural checks of the Gherkin data type. They are fast, produce
            // precise line-level messages and are shared with any other app that needs them.
            // Only continue to the dry-run if they pass - a dry-run on a structurally broken
            // file just adds parser noise on top of errors we already report clearly.
            $errors = GherkinDataType::findErrors($content, $this->isStrict());
            if ($errors !== []) {
                throw new RuntimeException(
                    'Feature file ' . $this->buildRowLabel($data, $rowNr) . ' contains errors that would break '
                    . 'the test suite and cannot be saved:' . "\n\n"
                    . GherkinDataType::formatErrors($errors)
                );
            }

            // Step 2: the Behat dry-run as a safety net for parser-level errors the static
            // checks cannot detect (malformed outlines, illegal Unicode in keywords, Gherkin
            // dialect mismatches). Optional, because it starts an external process on every save.
            if ($this->isDryRunEnabled()) {
                $dryRunError = $this->runDryRun($content);
                if ($dryRunError !== null) {
                    throw new RuntimeException(
                        'Feature file ' . $this->buildRowLabel($data, $rowNr) . ' failed the Behat dry-run '
                        . 'and cannot be saved:' . "\n\n" . $dryRunError
                    );
                }
            }
        }
    }

    /**
     * Writes the given Gherkin content to a temporary file, runs a Behat dry-run against it and
     * returns any parse/syntax error output.
     *
     * Returns NULL when the dry-run finds no fatal errors - undefined steps are ignored on
     * purpose: they produce a non-zero exit code, but they do not stop the suite. Returns the
     * error text when a real parser error is detected.
     *
     * Everything is written into a private temporary directory created with 0700, because the
     * content is user data and a predictable name in the shared temp folder is open to symlink
     * attacks on multi-user servers. The directory is always removed afterwards.
     *
     * @param string $content Raw Gherkin content to validate.
     * @throws RuntimeException if the dry-run itself cannot be executed.
     * @return string|null Error output from Behat, or NULL if the file is valid.
     */
    private function runDryRun(string $content): ?string
    {
        $tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bdt_dryrun_' . bin2hex(random_bytes(8));
        if (! @mkdir($tmpDir, 0700, true) && ! is_dir($tmpDir)) {
            throw new RuntimeException('Cannot create temporary folder for the feature file dry-run!');
        }

        // A .feature extension is required - the Gherkin loader ignores other files.
        $tmpFile = $tmpDir . DIRECTORY_SEPARATOR . 'dryrun.feature';
        $tmpConf = $tmpDir . DIRECTORY_SEPARATOR . 'behat.yml';

        try {
            file_put_contents($tmpFile, $content);
            // Minimal Behat config: one suite pointing directly at the temp file, so the
            // project-level behat.yml and its suite filters cannot exclude it. No contexts are
            // loaded - a dry-run only checks Gherkin syntax, not step definitions.
            file_put_contents($tmpConf, implode("\n", [
                'default:',
                '  suites:',
                '    default:',
                '      paths:',
                '        - ' . str_replace('\\', '/', $tmpFile),
                '      contexts: []',
            ]));
            @chmod($tmpFile, 0600);
            @chmod($tmpConf, 0600);

            $cwd = $this->getWorkbench()->getInstallationPath();
            // On Windows with IIS, CliCommandRunner falls back to exec(), which does not inherit
            // the cwd - so the Behat binary needs an absolute path. The .bat wrapper is called
            // via "cmd /c" because a quoted .bat is not executable on its own. The path must be
            // escaped: installation folders like "C:\Program Files\..." contain spaces.
            if (DIRECTORY_SEPARATOR === '\\') {
                $behatBin = escapeshellarg($cwd . '\\vendor\\bin\\behat.bat');
                $cmd = 'cmd /c ' . $behatBin;
            } else {
                $cmd = escapeshellarg($cwd . '/vendor/bin/behat');
            }
            $cmd .= ' --config ' . escapeshellarg($tmpConf) . ' --dry-run --no-colors --format=pretty';

            $output = '';
            // Exit code 0 = dry-run passed. 1 = undefined steps, not fatal. Anything else is a
            // real parser or bootstrap failure.
            foreach (CliCommandRunner::runCliCommand($cmd, [], 30, $cwd, true, [0, 1]) as $chunk) {
                $output .= $chunk;
            }

            // Even with exit code 0/1 Behat may print a parse error into the output.
            if (stripos($output, 'ParseException') !== false
                || stripos($output, 'Lexer Exception') !== false
                || stripos($output, 'SyntaxException') !== false
            ) {
                return $this->extractDryRunError($output);
            }

            return null;
        } catch (RuntimeException $e) {
            // Our own errors are already meaningful - do not wrap them again.
            throw $e;
        } catch (\Throwable $e) {
            // If the process itself fails (binary missing, timeout), block the save: we cannot
            // confirm the file is valid if the dry-run never ran.
            throw new RuntimeException(
                'Feature file dry-run could not be executed: ' . $e->getMessage(),
                null,
                $e
            );
        } finally {
            // Always clean up, regardless of success or failure.
            if (file_exists($tmpFile)) {
                @unlink($tmpFile);
            }
            if (file_exists($tmpConf)) {
                @unlink($tmpConf);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
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
     * Tries common name/title columns first, so the message refers to the feature file by name
     * instead of a raw UID. Both upper and lower case aliases are tried because attribute
     * aliases are case sensitive and most models use upper case.
     *
     * @param DataSheetInterface $data
     * @param int $rowNr Zero-based row index.
     * @return string A short label such as '"My Feature"' or 'row 3'.
     */
    private function buildRowLabel(DataSheetInterface $data, int $rowNr): string
    {
        foreach (['NAME', 'TITLE', 'FILENAME', 'ALIAS', 'name', 'title', 'filename', 'alias'] as $candidate) {
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
     * Returns TRUE if the convention checks of the Gherkin data type are applied too.
     *
     * @return bool
     */
    public function isStrict(): bool
    {
        return $this->strict;
    }

    /**
     * Set to FALSE to only block saves on errors that break the Gherkin parser.
     *
     * Needed for objects that hold imported feature files the user does not control - ragged
     * tables or duplicate tags in foreign files should not make them unsavable.
     *
     * @uxon-property strict
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return FeatureFileValidatingBehavior
     */
    public function setStrict(bool $value): FeatureFileValidatingBehavior
    {
        $this->strict = $value;
        return $this;
    }

    /**
     * Returns TRUE if the Behat dry-run is executed after the static checks.
     *
     * @return bool
     */
    public function isDryRunEnabled(): bool
    {
        return $this->dryRun;
    }

    /**
     * Set to FALSE to skip the Behat dry-run and rely on the static checks only.
     *
     * The dry-run starts an external process with a 30 second timeout on every single save. On
     * installations with many concurrent editors this is the dominant cost of saving a feature
     * file, while the static checks already catch nearly everything.
     *
     * @uxon-property dry_run
     * @uxon-type boolean
     * @uxon-default true
     *
     * @param bool $value
     * @return FeatureFileValidatingBehavior
     */
    public function setDryRun(bool $value): FeatureFileValidatingBehavior
    {
        $this->dryRun = $value;
        return $this;
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