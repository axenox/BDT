<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\Interfaces\Tasks\TaskInterface;

/**
 * CLI option names of RunTest and RunParallel and the one way to read them from a task.
 *
 * WHY ONE DEFINITION: the names are declared in both actions' getCliOptions(), read back from the task, and
 * written into the recorded rerun command by RunScope::describeInvocation(). Each class used to carry its own
 * copy, so renaming an option in one place would have produced a command the other side no longer
 * understands - and a recorded rerun command that names an option the action does not declare is refused by
 * Symfony Console long after the run ended.
 */
final class RunOptions
{
    public const TAGS = 'tags';
    public const FEATURE = 'feature';
    public const SUITE = 'suite';
    public const BEHAT_CONFIG = 'behat_config';
    public const CHROME_PATH = 'chrome_path';
    public const CHROME_ENDPOINT = 'chrome_endpoint';
    public const BASE_URL = 'base_url';

    /**
     * Reads an optional option value, TRIMMED, returning NULL when it is absent or blank.
     *
     * WHY IT TRIMS: the values arrive from free-text console-preset inputs a tester fills in by hand, and
     * every consumer decides on them with an exact empty check - the "at least one of --tags/--feature/--suite"
     * guard, the Behat command assembly, the recorded rerun command. RunParallel used to read them untrimmed:
     * --tags=" " passed the scope guard as "given" and was then dropped from the worker command as "empty",
     * so the run went ahead unscoped (or scoped by --feature/--suite alone) although the caller believed it
     * was filtered. Trimming here makes "whitespace only" and "not provided" the same thing for every
     * consumer of both actions at once.
     *
     * WHY NULL AND NOT A DEFAULT: none of these options has a default at this level - each is either a pure
     * pass-through or is resolved from its authoritative source (app config, installation root) by the
     * caller, which fails loudly there. Absence must therefore never throw here.
     *
     * @param TaskInterface $task The action's task
     * @param string        $name One of this class's constants
     * @return string|null Trimmed non-empty value, or NULL
     */
    public static function read(TaskInterface $task, string $name): ?string
    {
        if (! $task->hasParameter($name)) {
            return null;
        }
        $value = $task->getParameter($name);
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
