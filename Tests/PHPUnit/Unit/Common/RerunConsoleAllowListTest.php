<?php
namespace axenox\BDT\Tests\PHPUnit\Unit\Common;

use axenox\BDT\Behat\Common\RerunCommandPolicy;
use axenox\BDT\Behat\Common\RunScope;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\RuntimeException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the command whitelist of the Test Runs rerun console (RerunCommandPolicy) and its use at run start.
 *
 * WHY: the console runs a command when ONE allowed_commands pattern matches it with preg_match() - a search,
 * not a full match - and the stored behat_command is prefilled into it. The patterns are the only barrier
 * between that writable column and the command line, so every character class and every anchor is pinned
 * here, including the characters that are only dangerous on POSIX shells (`$`, backtick) because the BDT
 * also runs on Linux. The reverse direction matters as much: a value the actions record but the console then
 * refuses would give a run that cannot be repeated, so the actions must refuse such a value at start.
 */
class RerunConsoleAllowListTest extends TestCase
{
    /**
     * A real, unstarted workbench: RunScope only needs the vendor folder to shorten a feature path, which
     * needs neither a database nor a persisted metamodel.
     */
    private function scope(): RunScope
    {
        return new RunScope(new Workbench([
            'SERVER.INSTALLATION_NAME' => 'bdt-rerun-policy-unit-test',
            'DEBUG.LIVE_CLASS_AUTOLOADER' => false
        ]));
    }

    #[DataProvider('allowedCommands')]
    public function testRerunCommandsAreAllowed(string $command): void
    {
        $this->assertTrue(RerunCommandPolicy::isAllowed($command), 'Must be allowed: ' . $command);
    }

    public static function allowedCommands(): array
    {
        return [
            'quoted tag expression' => ['vendor/bin/action axenox.BDT:RunTest --tags="@Foo&&@Bar"'],
            'quoted negation and grouping' => ['vendor/bin/action axenox.BDT:RunTest --tags="~@Foo&&(@Bar||@Baz)"'],
            'suite and feature' => ['vendor/bin/action axenox.BDT:RunParallel --suite=my.APP --feature=vendor/my/app/Tests/Behat/Features/Example.feature'],
            'no options' => ['vendor/bin/action axenox.BDT:RunTest'],
            'quoted value with whitespace and bare URL' => ['vendor/bin/action axenox.BDT:RunTest --tags="@Foo @Bar" --chrome_endpoint=https://host.example:9222/path'],
            'bare tag with namespace colons' => ['vendor/bin/action axenox.BDT:RunTest --tags=@Group::Name'],
            'URL with a query string' => ['vendor/bin/action axenox.BDT:RunTest --chrome_endpoint="https://host.example:9222/path?x=1&y=2"'],
            'bare URL with a query string' => ['vendor/bin/action axenox.BDT:RunTest --chrome_endpoint=https://host.example:9222/path?x=1'],
            'bare tag with a plus' => ['vendor/bin/action axenox.BDT:RunTest --tags=@Foo+Bar'],
            'quoted tag with a plus and a question mark' => ['vendor/bin/action axenox.BDT:RunTest --tags="@Foo+Bar && @Baz?"'],
            'bare tag with umlauts and sharp s' => ['vendor/bin/action axenox.BDT:RunTest --tags=@Flächen_Maßnahmen'],
            'quoted tag expression with umlauts' => ['vendor/bin/action axenox.BDT:RunTest --tags="@Übersicht && ~@Größe"'],
            'feature path with umlauts' => ['vendor/bin/action axenox.BDT:RunParallel --feature=vendor/my/app/Tests/Behat/Features/Flurstücke_Übersicht.feature'],
            'backslash separators' => ['vendor\\bin\\action axenox.BDT:RunTest --feature=vendor\\my\\app\\Example.feature'],
            'behat init, forward slashes' => ['vendor/bin/action axenox.BDT:Behat init'],
            'behat init, backslashes' => ['vendor\\bin\\action axenox.BDT:Behat init'],
        ];
    }

    #[DataProvider('refusedCommands')]
    public function testInjectedOrForeignCommandsAreRefused(string $command): void
    {
        $this->assertFalse(RerunCommandPolicy::isAllowed($command), 'Must be refused: ' . json_encode($command));
    }

    public static function refusedCommands(): array
    {
        $run = 'vendor/bin/action axenox.BDT:RunTest ';
        return [
            'foreign command mentioning action' => ['whoami & echo action'],
            'unquoted & after a bare value' => [$run . '--tags=x & whoami'],
            'unquoted &&' => [$run . '--tags=x && whoami'],
            'unquoted pipe' => [$run . '--tags=x | whoami'],
            'unquoted redirect' => [$run . '--tags=x > out.txt'],
            'unquoted caret' => [$run . '--tags=a^b'],
            'unquoted & inside a bare value' => [$run . '--tags=a&b'],
            'unquoted parentheses' => [$run . '--tags=(a)'],
            'unquoted semicolon' => [$run . '--tags=a;whoami'],
            'unquoted dollar' => [$run . '--tags=$HOME'],
            'unquoted command substitution' => [$run . '--tags=$(whoami)'],
            'unquoted backtick' => [$run . '--tags=`whoami`'],
            '& after a closed quote' => [$run . '--tags="a" & whoami'],
            '& glued to a closed quote' => [$run . '--tags="a"& whoami'],
            '& after the second quoted option' => [$run . '--tags="a" --x="b" & whoami'],
            'dollar inside quotes' => [$run . '--tags="$HOME"'],
            'command substitution inside quotes' => [$run . '--tags="$(whoami)"'],
            'backtick inside quotes' => [$run . '--tags="`whoami`"'],
            'semicolon inside quotes' => [$run . '--tags="a;whoami"'],
            'redirect inside quotes' => [$run . '--tags="a>out.txt"'],
            'caret inside quotes' => [$run . '--tags="a^b"'],
            'percent expansion, bare' => [$run . '--tags=%PATH%'],
            'percent expansion, quoted' => [$run . '--tags="a%b"'],
            'delayed expansion' => [$run . '--tags=!x'],
            'delayed expansion, quoted' => [$run . '--tags="a!b"'],
            'quote inside a bare value' => [$run . '--tags=a"b'],
            'backslash before the closing quote' => [$run . '--tags="a\\"'],
            'newline after the command' => [$run . "--tags=x\nwhoami"],
            'newline inside a quoted value' => [$run . "--tags=\"x\n\""],
            'tab inside a quoted value' => [$run . "--tags=\"x\ty\""],
            'invalid UTF-8 byte' => [$run . "--tags=@a\xC3"],
            'another action' => ['vendor/bin/action exface.Core:ShowObjectDialog'],
            'action name with a suffix' => ['vendor/bin/action axenox.BDT:RunTestX'],
            'behat init with options' => ['vendor/bin/action axenox.BDT:Behat init --x=1'],
            'another Behat action' => ['vendor/bin/action axenox.BDT:Behat run'],
            'prefix before the command' => ['whoami vendor/bin/action axenox.BDT:RunTest'],
            'option without a value' => [$run . '--tags'],
            'double space' => ['vendor/bin/action axenox.BDT:RunTest  --tags=x'],
            'trailing space' => [$run . '--tags=x '],
            'plain behat call' => ['behat --tags=x'],
        ];
    }

    /**
     * What the actions record must be accepted by the console that replays it - otherwise a legitimate
     * rerun would be refused. Typical tag expressions exercise every quoting case of RunScope::cliOption():
     * `&&`, `||`, whitespace, parentheses, `~` negation, `,` and `+`.
     */
    #[DataProvider('recordedTagExpressions')]
    public function testCommandsRecordedByTheActionsPassTheAllowList(string $tags): void
    {
        $command = $this->scope()->describeInvocation('axenox.BDT:RunTest', $tags, null, null);

        $this->assertTrue(RerunCommandPolicy::isAllowed($command), 'Recorded command refused: ' . $command);
    }

    public static function recordedTagExpressions(): array
    {
        return [
            'single tag' => ['@Foo'],
            'namespaced tag' => ['@Group::Name'],
            'AND' => ['@Foo&&@Bar'],
            'AND with whitespace' => ['@Foo && @Bar'],
            'OR' => ['@Foo||@Bar'],
            'parentheses' => ['@Foo && (@Bar || @Baz)'],
            'negation' => ['~@Foo'],
            'negation with AND' => ['@Foo&&~@Bar'],
            'comma list' => ['@Foo,@Bar'],
            'plus' => ['@Foo+Bar'],
            'plus inside a grouped expression' => ['(@Foo+Bar || ~@Baz), @Qux'],
            'umlauts and sharp s' => ['@Flächen_Maßnahmen'],
            'umlauts in a grouped expression' => ['@Übersicht && (~@Größe || @Öffnen)'],
        ];
    }

    /**
     * Every recorded option, with representative values, passes: suite, feature, and the options RunTest adds
     * (chrome_endpoint, base_url). A query string is not among the endpoint values: normalizeEndpoint() already
     * refuses one before RunTest records anything.
     */
    #[DataProvider('recordedOptionSets')]
    public function testEveryRecordedOptionPassesTheAllowList(string $action, ?string $tags, ?string $feature, ?string $suite, array $extra): void
    {
        $scope = $this->scope();
        $command = $scope->describeInvocation($action, $tags, $feature, $suite, $extra);

        $this->assertTrue(RerunCommandPolicy::isAllowed($command), 'Recorded command refused: ' . $command);
        // The start-time check is the same decision, so it must not object either.
        $scope->assertRerunnable($action, $tags, $feature, $suite, $extra);
    }

    public static function recordedOptionSets(): array
    {
        $feature = 'vendor/my/app/Tests/Behat/Features/Example.feature';
        return [
            'RunParallel: suite' => ['axenox.BDT:RunParallel', null, null, 'my.APP', []],
            'RunParallel: feature and suite' => ['axenox.BDT:RunParallel', null, $feature, 'my.APP', []],
            'RunParallel: tags and feature' => ['axenox.BDT:RunParallel', '@Foo && ~@Bar', $feature, null, []],
            'RunTest: all selectors' => ['axenox.BDT:RunTest', '@Foo+Bar,@Baz', $feature, 'my.APP', []],
            'RunTest: chrome_endpoint with a path' => ['axenox.BDT:RunTest', '@Foo', null, null, ['chrome_endpoint' => 'https://host.example:9222/chrome/api']],
            'RunTest: base_url' => ['axenox.BDT:RunTest', '@Foo', null, null, ['base_url' => 'http://app.example/exface/']],
            'RunTest: feature path with spaces and parentheses' => ['axenox.BDT:RunTest', null, 'C:\\Program Files (x86)\\my app\\Example.feature', null, []],
            'RunTest: tag and feature path with umlauts' => ['axenox.BDT:RunTest', '@Flächen_Maßnahmen', 'vendor/my/app/Tests/Behat/Features/Flurstücke_Übersicht.feature', null, []],
        ];
    }

    /**
     * Any value the console would refuse on a rerun is refused when the run is STARTED, with a message that
     * names the option - so a run that could not be repeated is never started.
     */
    #[DataProvider('unrerunnableValues')]
    public function testAValueTheConsoleWouldRefuseIsRefusedAtStart(string $option, string $value): void
    {
        $scope = $this->scope();
        $tags = $option === 'tags' ? $value : null;
        $feature = $option === 'feature' ? $value : null;
        $suite = $option === 'suite' ? $value : null;
        $extra = in_array($option, ['tags', 'feature', 'suite'], true) ? [] : [$option => $value];

        // Sanity: the console really would refuse the recorded command.
        $this->assertFalse(RerunCommandPolicy::isAllowed($scope->describeInvocation('axenox.BDT:RunTest', $tags, $feature, $suite, $extra)));

        try {
            $scope->assertRerunnable('axenox.BDT:RunTest', $tags, $feature, $suite, $extra);
            $this->fail('A value the console refuses must be refused at start: --' . $option . '=' . json_encode($value));
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--' . $option . ' value', $e->getMessage());
            $this->assertStringContainsString('cannot be re-run from the Test Runs console', $e->getMessage());
            $this->assertStringContainsString('letters (any script', $e->getMessage());
        }
    }

    public static function unrerunnableValues(): array
    {
        $templates = [
            'tags' => '@a%s@b',
            'suite' => 'my%sAPP',
            'feature' => 'vendor/my/app/Ex%s.feature',
            'chrome_endpoint' => 'https://host.example%s9222',
            'base_url' => 'http://app.example%sx/'
        ];
        $characters = [
            'semicolon' => ';', 'dollar' => '$', 'backtick' => '`', 'double quote' => '"', 'percent' => '%',
            'exclamation mark' => '!', 'less-than' => '<', 'greater-than' => '>', 'caret' => '^',
            'newline' => "\n", 'tab' => "\t"
        ];
        $cases = [];
        foreach ($templates as $option => $template) {
            foreach ($characters as $label => $char) {
                $cases[$option . ' with ' . $label] = [$option, sprintf($template, $char)];
            }
        }
        return $cases;
    }

    /**
     * The message lists the allowed characters, and it is derived from the policy constants - so it can only
     * mention characters the console really accepts.
     */
    public function testTheDescriptionListsTheCharactersTheConsoleAccepts(): void
    {
        $description = RerunCommandPolicy::describeAllowedCharacters();

        foreach (['?', '+', '@', '~', ':', '&', '|', '(', ')', 'ä'] as $char) {
            $this->assertStringContainsString($char, $description);
        }
        foreach (['"', '%', '!', ';', '$', '`'] as $char) {
            $this->assertStringNotContainsString($char, $description);
        }
    }

    /**
     * The copy of the patterns in the exported model must equal the PHP definition.
     *
     * The model file cannot reference a PHP constant, so it carries a copy; a hand-edited constant that was
     * not copied would leave the console on the OLD patterns - refusing legitimate reruns, or accepting what
     * the PHP side forbids. This compares the copy with the definition; it does not freeze the model.
     */
    public function testTheModelCopyOfTheAllowListEqualsThePolicy(): void
    {
        $file = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'Model' . DIRECTORY_SEPARATOR
            . 'axenox.BDT.run' . DIRECTORY_SEPARATOR . '08_OBJECT_ACTION.json';
        $model = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($model, 'The object action model must be valid JSON');

        $console = $this->findConsole($model);

        $this->assertNotNull($console, 'The rerun dialog must contain the console widget "test_cli"');
        $this->assertSame(
            RerunCommandPolicy::patterns(),
            $console['allowed_commands'] ?? null,
            'Update allowed_commands in Model/axenox.BDT.run/08_OBJECT_ACTION.json from RerunCommandPolicy::patterns() and re-export the model.'
        );
    }

    private function findConsole(mixed $node): ?array
    {
        if (! is_array($node)) {
            return null;
        }
        if (($node['id'] ?? null) === 'test_cli' && ($node['widget_type'] ?? null) === 'Console') {
            return $node;
        }
        foreach ($node as $child) {
            $found = $this->findConsole($child);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
