<?php
namespace axenox\BDT\Behat\Common;

/**
 * The command patterns the Test Runs rerun console accepts - the single, tested definition of them.
 *
 * WHY IT EXISTS: the console runs whatever command the browser sends as long as ONE allowed_commands pattern
 * gives a non-zero preg_match() on it (WebConsoleFacade::performCommand()). That is a search, not a full
 * match, so the first version of the list (`/action.*\/i`) let any command containing the word "action"
 * through. The prefilled command comes from the writable column behat_command, so the patterns are the only
 * barrier between that column and the command line. They are therefore written as WHITELISTS of characters,
 * not as blacklists of dangerous ones: a character nobody thought of is refused, not accepted.
 *
 * ONE DEFINITION FOR BOTH SIDES: the same constants decide what the console ACCEPTS on a rerun and what the
 * actions ACCEPT at start (RunScope::assertRerunnable() asks isAllowed() about the very command that
 * describeInvocation() records). A value the console would refuse on a rerun therefore never gets a run
 * started - otherwise the rerun would fail with a bare "Command not allowed!" long after the run ended.
 * Do not write a second character list anywhere else.
 *
 * KEEP THE MODEL IN SYNC: the console widget in Model/axenox.BDT.run/08_OBJECT_ACTION.json carries a COPY of
 * these patterns (JSON-escaped), because a model file cannot reference a PHP constant. Whenever a constant
 * here changes, update that copy by hand and re-export the model. RerunConsoleAllowListTest compares the copy
 * with patterns() and fails when they differ.
 *
 * HOW THE CONSOLE EXECUTES AN ALLOWED COMMAND: a command containing "vendor/bin/action" is handled in-process
 * (ConsoleFacade::getOutputGenerator() parses it with Symfony's StringInput and calls the action) - no shell
 * is involved. Everything else would go to CliCommandRunner::runCliCommand(), which does use a shell. That is
 * why `?` and `+` (glob and regex characters in a shell, plain characters for StringInput) are allowed, while
 * the characters a shell or cmd acts on (`; $ backtick % !` and, outside quotes, `& | ^ < > ( )`) are not: the
 * routing is a property of another package and may change, and the BDT also runs on Linux, where `$` and the
 * backtick are live inside double quotes.
 */
final class RerunCommandPolicy
{
    /**
     * Characters of a BARE (unquoted) option value, as the inside of a regex character class.
     *
     * Letters of ANY script (`\p{L}`, which needs the pattern's `u` flag) and ASCII digits; `@` (tags),
     * `_ . - ,` (names, lists), `:` (Status::Ready, URLs), `/` and `\` (paths, either separator), `~` (tag
     * negation), `=` (a value that contains one), `?` (URL query strings) and `+` (a legal character of a tag
     * and of a URL). Deliberately absent: whitespace and every character a shell acts on -
     * `& | ^ < > ( ) ; $ ` plus quotes, `%` and `!`.
     *
     * WHY ANY LETTER AND NOT A-Z: this installation's tags and feature names are German (`@Flächensicherung`,
     * `@Alle_Maßnahmen_filter`); an ASCII-only class refused every such run at start and every such rerun.
     * No letter of any script is a shell or cmd metacharacter, so widening the letters adds no risk.
     *
     * Escapes inside the class: `\/` is a slash, `\\` a backslash and `\-` a literal hyphen (escaped so that
     * the characters after it cannot turn it into a range).
     */
    public const BARE_VALUE_CHARS = '\p{L}0-9@_.:\/\\\\,~=\-?+';

    /**
     * What a QUOTED value may contain on top of a bare one: space and the boolean/grouping characters of a
     * tag expression - `&` `|` `(` `)`.
     *
     * RunScope::cliOption() puts a value in quotes exactly when it contains whitespace, `&`, `|`, `<`, `>`,
     * `^`, `(` or `)`. Of those, `<`, `>` and `^` are not valid in a Behat tag expression and are refused
     * here on purpose. Still forbidden inside quotes: `"` (would end the value), `%` and `!` (cmd expands
     * them even inside quotes), `;`, `$` and the backtick (live inside double quotes on POSIX shells), tab
     * and line breaks.
     */
    public const QUOTED_EXTRA_CHARS = ' &|()';

    /**
     * Characters of a QUOTED option value: a bare value's characters plus QUOTED_EXTRA_CHARS.
     *
     * Built from the bare definition so the two can never drift apart.
     */
    public const QUOTED_VALUE_CHARS = self::BARE_VALUE_CHARS . self::QUOTED_EXTRA_CHARS;

    /**
     * A recorded run command: `vendor/bin/action axenox.BDT:RunTest|RunParallel` followed only by
     * ` --name=value` options.
     *
     * Parts, in order:
     * - `#...#iDu`: delimiter `#` (it appears nowhere in the pattern); `i` because the console matches
     *   case-insensitively on Windows paths; `D` so that `$` does not accept a trailing line break; `u` so the
     *   subject is read as UTF-8 and `\p{L}` matches whole letters such as `ä` - without it the class would
     *   test single bytes. A subject that is not valid UTF-8 makes preg_match() fail, which both this class
     *   and the console treat as "not allowed".
     * - `^vendor[/\\]bin[/\\]action `: the executable, with either separator (the facade converts `/` to the
     *   platform separator before execution).
     * - `axenox\.BDT:(?:RunTest|RunParallel)`: exactly these two actions - nothing else the console could
     *   reach, no suffix (`RunTestX` fails because the next character must be a space or the end).
     * - `(?: --NAME=VALUE)*`: zero or more options, each introduced by exactly ONE space. `NAME` is
     *   `[a-z][a-z0-9_-]*`. `VALUE` is either a double-quoted string or one or more BARE_VALUE_CHARS.
     * - `"[QUOTED_VALUE_CHARS]*(?<!\\)"`: the quoted form, possibly empty. The lookbehind `(?<!\\)` refuses a
     *   backslash right before the closing quote: Symfony's StringInput (which executes the command) reads
     *   `\"` as an escaped quote while a regex sees the end of the value, so the two would disagree about
     *   where the value stops.
     * - `$`: nothing may follow, so an unquoted `& whoami` after a value has nowhere to match.
     */
    public const RUN_ACTION_PATTERN = '#^vendor[/\\\\]bin[/\\\\]action axenox\.BDT:(?:RunTest|RunParallel)'
        . '(?: --[a-z][a-z0-9_-]*=(?:"[' . self::QUOTED_VALUE_CHARS . ']*(?<!\\\\)"'
        . '|[' . self::BARE_VALUE_CHARS . ']+))*$#iDu';

    /**
     * The console's start command: `Behat init`, as an exact command without options.
     *
     * WHY ALLOWED AT ALL: the rerun dialog runs it first, so the root behat.yml exists before the rerun
     * (the actions no longer run init themselves). WHY EXACT: init rewrites the root configuration; no
     * option or trailing text is needed or accepted.
     */
    public const BEHAT_INIT_PATTERN = '#^vendor[/\\\\]bin[/\\\\]action axenox\.BDT:Behat init$#iD';

    /**
     * Returns the patterns in the order they belong in the console's allowed_commands.
     *
     * WHY A LIST: it is what the facade iterates, and the model copy has the same shape.
     *
     * @return string[]
     */
    public static function patterns(): array
    {
        return [self::RUN_ACTION_PATTERN, self::BEHAT_INIT_PATTERN];
    }

    /**
     * Decides a command exactly the way the facade does: allowed when any pattern matches.
     *
     * WHY IT MIRRORS THE FACADE INSTEAD OF BEING A SECOND CHECK: the point is that the tests and the actions
     * ask the decision the console really makes - a stricter private rule here would test something nobody
     * runs.
     *
     * @param string $command The command as the browser sends it
     */
    public static function isAllowed(string $command): bool
    {
        foreach (self::patterns() as $pattern) {
            if (preg_match($pattern, $command) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Describes the accepted value characters in words, for error messages.
     *
     * WHY DERIVED FROM THE CONSTANTS: a message that spelled the list out by hand would be a second copy of
     * it, and the first change to the pattern would leave the message promising characters the console then
     * refuses. The ranges are dropped and the regex escapes removed, which leaves the literal characters.
     */
    public static function describeAllowedCharacters(): string
    {
        $bare = stripslashes(str_replace('\p{L}0-9', '', self::BARE_VALUE_CHARS));
        return 'letters (any script, e.g. ä ö ü ß), digits and the characters [' . $bare . '] - in a value that gets double quotes '
            . '(it contains whitespace, & | ( ) ...) additionally space and [' . trim(self::QUOTED_EXTRA_CHARS) . ']';
    }
}
