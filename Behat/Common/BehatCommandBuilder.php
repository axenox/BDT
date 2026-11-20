<?php
namespace axenox\BDT\Behat\Common;

use exface\Core\Exceptions\RuntimeException;

/**
 * Builds the Behat command line of a RunTest run and of every RunParallel worker, with one quoting and
 * validation rule set.
 *
 * WHY ONE BUILDER: the two actions used to assemble the command independently, with two different safety
 * rules. RunTest quoted with escapeshellarg() and refused what that function cannot carry; RunParallel wrapped
 * values in hand-written double quotes, refused a different character list and hard-coded the Windows path
 * `vendor\bin\behat`, which a Linux shell reads as `vendorbinbehat`. The same tag expression could therefore
 * be accepted by one action and refused by the other, and the fleet could not run off Windows at all.
 *
 * WHY THE STRING IS A SHELL COMMAND: it is handed to Process::fromShellCommandline(), so a shell parses it -
 * and suite, tags and feature reach the actions from free-text console presets that users far less
 * privileged than the account running BDT can fill in. Every interpolated value therefore goes through
 * quote(), which supplies its own quotes; none may be concatenated raw.
 *
 * WHY POSITIONAL FEATURE OUTSIDE --config: Behat only honours a feature path given as a positional argument;
 * concatenated into the config option it silently runs everything.
 *
 * WHY --tags AND --suite ARE CONDITIONAL: an empty tag expression is not "no filter" to Behat and would
 * diverge from the expected count. Omitting the option lets Behat apply the suite's own filters.tags, which
 * the expected count applies as well. --suite, when given, makes Behat run a feature in that suite only,
 * which is exactly the scope the expected counts were computed for.
 *
 * WHY NO --colors: the output is forwarded to a captured stream or a log file; forcing ANSI colours on a
 * non-TTY would inject escape codes. Behat's own TTY detection is right.
 */
final class BehatCommandBuilder
{
    /**
     * Builds the complete Behat command line.
     *
     * WHY PLATFORM SEPARATORS FOR THE EXECUTABLE: an unquoted Windows backslash is consumed by a POSIX shell,
     * so the path to the Composer Behat executable must use the separator of the platform the command runs on.
     *
     * @param string      $configPath Generated config (interactive or lane) the process must use
     * @param string|null $suite      --suite value, NULL or '' to omit
     * @param string|null $tags       --tags value, NULL or '' to omit
     * @param string|null $feature    Feature file or directory as positional argument, NULL or '' to omit
     * @throws RuntimeException if a value cannot reach the command line unchanged
     */
    public static function build(string $configPath, ?string $suite, ?string $tags, ?string $feature): string
    {
        $cmd = 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'behat'
            . ' --config ' . self::quote($configPath, 'behat config path');
        if ($suite !== null && $suite !== '') {
            $cmd .= ' --suite=' . self::quote($suite, 'suite name');
        }
        if ($tags !== null && $tags !== '') {
            $cmd .= ' --tags=' . self::quote($tags, 'tag expression');
        }
        if ($feature !== null && $feature !== '') {
            $cmd .= ' ' . self::quote($feature, 'feature path');
        }
        return $cmd;
    }

    /**
     * Validates the caller-supplied scope selectors before anything is persisted or rendered.
     *
     * WHY BEFORE THE COMMAND EXISTS: the selectors do not reach only the Behat command. The run row's
     * behat_command column records them, and the Test Runs console renders that column into its
     * start_commands JSON. On a run that ends before Behat starts (parse error, empty scope) the command is
     * never built, so a check that lived only in build() would never run and the value would be stored
     * regardless. Both actions call this first.
     *
     * @throws RuntimeException naming the first offending selector
     */
    public static function assertSelectorsSafe(?string $tags, ?string $feature, ?string $suite): void
    {
        foreach (['tag expression' => $tags, 'feature path' => $feature, 'suite name' => $suite] as $what => $value) {
            if ($value !== null && $value !== '') {
                self::quote($value, $what);
            }
        }
    }

    /**
     * Returns the value quoted and escaped for the current platform's shell, refusing what cannot be carried.
     *
     * WHY escapeshellarg() RATHER THAN HAND-WRITTEN QUOTES: hand-written "..." broke on any value that ended
     * in a backslash - a directory path typed with a trailing separator produced "...\Features\", where the
     * backslash escapes the closing quote and corrupts the rest of the command line. escapeshellarg() counts
     * trailing backslashes correctly and quotes for the platform it runs on (double quotes on Windows, single
     * quotes on POSIX, where `$` and the backtick are then inert as well).
     *
     * WHY REFUSE AND NOT ESCAPE FURTHER: on Windows escapeshellarg() silently REPLACES `"`, `%` and `!` with a
     * space. Behat would then run with a value nobody typed - a run that quietly tests something else is the
     * exact failure this framework exists to catch. None of the three belongs in a suite name, a tag
     * expression or a path, so refusing loudly loses nothing. Line breaks and NUL are refused on every
     * platform: a line break survives quoting and corrupts the value, and NUL cannot be passed at all.
     *
     * WHY THE CALLER MUST NOT ADD QUOTES: the returned string already carries its own; wrapping it again
     * would put literal quotes inside the value.
     *
     * @param string $value The value about to be placed on the command line
     * @param string $what  Human-readable name of the value, used in the error message
     * @throws RuntimeException if the value cannot reach the command line unchanged
     */
    public static function quote(string $value, string $what): string
    {
        if (preg_match('/[\r\n\x00]/', $value) === 1) {
            throw new RuntimeException(
                'Refusing to build the Behat command: the ' . $what . ' contains a line break or NUL byte. Got: '
                . var_export($value, true)
            );
        }
        if (PHP_OS_FAMILY === 'Windows' && strpbrk($value, '"%!') !== false) {
            throw new RuntimeException(
                'Refusing to build the Behat command: the ' . $what . ' contains one of the characters " % ! , '
                . 'which the Windows command line cannot carry without silently altering the value. Got: '
                . var_export($value, true)
            );
        }
        return escapeshellarg($value);
    }
}
