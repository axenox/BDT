<?php
namespace axenox\BDT\Behat\Common;

use axenox\BDT\BDTApp;
use axenox\BDT\Behat\Common\Traits\FeaturePathResolverTrait;
use exface\Core\DataTypes\FilePathDataType;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\Interfaces\WorkbenchInterface;

/**
 * Everything RunTest and RunParallel must agree on BEFORE a Behat process starts: the base config, the
 * scope (feature / suite / tags), the expected counts and the recorded invocation.
 *
 * WHY ONE CLASS: both actions used to carry their own copy of each of these, and the copies had
 * already drifted - RunTest quoted its recorded rerun command only on a space, so a tag expression
 * such as "@A&&@B" was recorded in a form that broke when re-run, and RunParallel refused --feature
 * together with --suite while Behat itself supports the combination. Routing both actions through
 * this class means a rule is changed once and applies to both.
 *
 * WHAT IT DELIBERATELY DOES NOT DO: run `Behat init`. Init is a setup step of its own that the caller
 * runs before the tests (scheduler step, console start command, agent setup). It rewrites the root
 * behat.yml, which other Behat processes may be reading at that moment, so doing it again on every
 * action call would duplicate the step and race with them. This class only READS the existing files.
 */
final class RunScope
{
    use FeaturePathResolverTrait;

    // App-config fallback for the base behat.yml: the option ALWAYS wins, the config key is the
    // fallback, the installation-root behat.yml is the default.
    public const CFG_BEHAT_CONFIG = 'PARALLEL.BEHAT_CONFIG';

    // Error recorded and thrown by both actions when the scope matches nothing. One text, so the run's
    // error_message and the exception the caller sees can never say different things.
    public const EMPTY_SCOPE_MESSAGE = 'No feature/scenario matched the requested scope/tags - nothing was run.';

    // How many broken feature files a parse-error message spells out, and how long each reason may be (bytes).
    private const PARSE_ERRORS_LISTED = 5;
    private const PARSE_ERROR_MAX_BYTES = 300;

    private WorkbenchInterface $workbench;

    /**
     * Binds the scope rules to one workbench.
     *
     * WHY THE WORKBENCH IS INJECTED: every rule here reads installation state (vendor folder, app config) and
     * the two actions each hold their own workbench; taking it as a constructor argument keeps the class free
     * of globals and lets the unit tests hand it a stub with a temporary installation root.
     *
     * @param WorkbenchInterface $workbench The workbench of the action using this scope
     */
    public function __construct(WorkbenchInterface $workbench)
    {
        $this->workbench = $workbench;
    }

    /**
     * Required by FeaturePathResolverTrait, which resolves paths against the workbench's folders.
     */
    public function getWorkbench(): WorkbenchInterface
    {
        return $this->workbench;
    }

    /**
     * Resolves the base behat.yml the generated configs import: the explicit --behat_config when given,
     * else the PARALLEL.BEHAT_CONFIG app-config key, else the installation-root behat.yml.
     *
     * WHY IT RUNS FIRST AND FAILS LOUDLY: everything after it - port, run row, Chrome - depends on this
     * file, so a missing one must stop the run before any of that exists. The file is written by
     * `Behat init`, which is the caller's job, so the error tells the caller exactly which command to run
     * instead of leaving them to guess why the action cannot find its config. Defaulting to the root file
     * is not guessing: it is the exact file the rest of the run (config imports, port-band override
     * lookup) already uses.
     *
     * WHY AN EXPLICIT VALUE IS RESOLVED RATHER THAN USED AS TYPED: a relative value used to be checked
     * against the CWD of this process, which differs between a shell launch and a Test Runs console
     * launch - the same defect --feature had.
     *
     * WHY THE LOCATION IS ENFORCED: every generated config is written into the installation root and
     * imports the base by FILE NAME only ("imports: - behat.yml"), because that relative import is what
     * keeps %paths.base% identical to a normal run. A base config living anywhere else would therefore be
     * silently replaced by the root behat.yml - the run would use a different base_url and a different
     * port band than the operator asked for, and nothing would report it. Refusing is the only outcome
     * that cannot be mistaken for success.
     *
     * @param string|null $explicit --behat_config value, if given
     * @param string      $cwd      Installation root
     * @return string Absolute path of an existing behat.yml in the installation root
     * @throws RuntimeException if the file does not exist, is not a file, or lives outside the root
     */
    public function resolveBehatConfig(?string $explicit, string $cwd): string
    {
        $rootDir = realpath($cwd);
        $rootDir = $rootDir === false ? rtrim($cwd, '\\/') : rtrim($rootDir, '\\/');

        if ($explicit === null || $explicit === '') {
            $cfg = $this->workbench->getApp(BDTApp::APP_ALIAS)->getConfig();
            if ($cfg->hasOption(self::CFG_BEHAT_CONFIG)) {
                $fromCfg = $cfg->getOption(self::CFG_BEHAT_CONFIG);
                $explicit = $fromCfg === null ? null : (string) $fromCfg;
            }
        }

        if ($explicit === null || $explicit === '') {
            $path = $rootDir . DIRECTORY_SEPARATOR . BdtPaths::BEHAT_CONFIG_FILE_NAME;
            if (! is_file($path)) {
                throw new RuntimeException(
                    'behat_config is not a file: ' . $path . '. Run `vendor/bin/action axenox.BDT:Behat init` '
                    . 'first - it creates the installation-root behat.yml, and this action does not run it for you.'
                );
            }
            return $path;
        }

        $path = $this->resolveExistingPath($explicit, [$rootDir], 'behat_config');
        if (! is_file($path)) {
            throw new RuntimeException('behat_config is not a file: ' . $path);
        }

        $configDir = FilePathDataType::normalize(rtrim(dirname($path), '\\/'), '/');
        if (strcasecmp($configDir, FilePathDataType::normalize($rootDir, '/')) !== 0) {
            throw new RuntimeException(
                'behat_config must live in the installation root (' . $rootDir . '), got: ' . $path
                . '. Generated configs are written to the installation root and import the base config by file '
                . 'name only, so a config outside that folder would be silently replaced by the root '
                . BdtPaths::BEHAT_CONFIG_FILE_NAME . '.'
            );
        }
        return $path;
    }

    /**
     * Resolves the scan roots the run operates on and refuses a scope that would run nothing.
     *
     * WHY --feature IS NOT USED AS GIVEN: the value is typically copied out of the run log or out of
     * run_feature.filename, both of which store the VENDOR-RELATIVE form produced by
     * featureRelativePath() (e.g. "example/app/Tests/Behat/Features/X.feature"). Checking that string
     * with a bare file_exists() resolves it against the PHP process CWD instead - which is the web root
     * when the action is launched from the Test Runs console - so a perfectly valid path was rejected
     * with "feature does not exist". Resolution now happens in resolveFeatureScanRoot(), which accepts
     * exactly the forms this system itself prints.
     *
     * WHY --feature AND --suite MAY BE COMBINED: Behat supports it - it runs that feature within that
     * suite. The feature must then lie inside the suite's configured paths. A feature outside them would
     * be filtered out by Behat, the run would execute nothing and report green, so it is refused here,
     * loudly, BEFORE a run row exists. It reads the existing behat.yml and its imports; the suites
     * registered there are whatever the caller's last `Behat init` left.
     *
     * WHY THE RESULT IS ABSOLUTE: the returned roots are consumed by the calculator and end up as the
     * positional feature argument of the Behat command. A relative root would make both depend on the CWD
     * of whichever process happens to expand it, so an identical run would match different files
     * depending on how it was started.
     *
     * @param string      $behatConfig Base behat.yml used for suite resolution
     * @param string|null $feature     --feature value, if given
     * @param string|null $suite       --suite value, if given
     * @return string[] Absolute scan roots: the single feature path, or the paths of the selected/all suites
     * @throws RuntimeException if the feature is outside the suite, or nothing can be resolved
     */
    public function resolveScanRoots(string $behatConfig, ?string $feature, ?string $suite): array
    {
        $hasFeature = $feature !== null && $feature !== '';
        $hasSuite   = $suite  !== null && $suite  !== '';

        if ($hasFeature) {
            $featurePath = $this->resolveFeatureScanRoot($feature);
            if ($hasSuite) {
                $suites = (new BehatSuiteResolver())->resolveSuites($behatConfig, $suite);
                if (BehatSuiteResolver::findSuiteOwningPath($suites, $featurePath) === null) {
                    throw new RuntimeException(
                        'The feature "' . $this->featureRelativePath($featurePath) . '" is not inside suite "' . $suite
                        . '" (suite paths: ' . implode(', ', $suites[$suite]['paths']) . '). Behat would run nothing '
                        . 'and the run would look green. Pick a feature inside the suite, or drop --suite.'
                    );
                }
            }
            return [$featurePath];
        }

        $suites = (new BehatSuiteResolver())->resolveSuites($behatConfig, $hasSuite ? $suite : null);
        $paths = [];
        foreach ($suites as $suiteDefinition) {
            $paths = array_merge($paths, $suiteDefinition['paths']);
        }
        $paths = array_values(array_unique($paths));
        if ($paths === []) {
            throw new RuntimeException(
                'No feature paths resolved from ' . $behatConfig
                . ($hasSuite ? ' for suite "' . $suite . '"' : '') . '.'
            );
        }
        return $paths;
    }

    /**
     * Counts the features and scenarios Behat is going to run for this scope, and lists the feature
     * files that survive the tag filter.
     *
     * WHY ITS OWN METHOD NEXT TO resolveScanRoots(): the roots say WHERE to look, the counts must also
     * reproduce WHAT Behat filters out. Behat applies the CLI --tags when given and the owning suite's own
     * filters.tags otherwise; counting with the CLI --tags alone over-counted every --suite run without
     * --tags, so expected exceeded actual and the run looked like a silent stop. The rule itself lives in
     * ExpectedTestCountCalculator::calculateForScope(), which the formatter uses as well.
     *
     * WHY A PARSE ERROR THROWS: a broken feature file aborts the whole Behat run at parse time, so the
     * offenders are surfaced now instead of letting the child crash opaquely with exit code 255.
     *
     * @param string      $behatConfig Base behat.yml used for suite resolution
     * @param string|null $feature     --feature value, if given
     * @param string|null $suite       --suite value, if given
     * @param string|null $tags        --tags value, if given
     * @throws RuntimeException if a feature file fails to parse
     */
    public function calculateExpectedScope(string $behatConfig, ?string $feature, ?string $suite, ?string $tags): ExpectedTestCountResult
    {
        $hasSuite   = $suite !== null && $suite !== '';
        // An empty --tags means "not given" for the actions, but for the calculator '' would mean "count
        // everything" and override the suite's own filters.tags - the exact over-count this method prevents.
        $tags = $tags === '' ? null : $tags;
        $featurePath = $feature !== null && $feature !== '' ? $this->resolveFeatureScanRoot($feature) : null;
        $suites = (new BehatSuiteResolver())->resolveSuites($behatConfig, $hasSuite ? $suite : null);

        $expected = (new ExpectedTestCountCalculator())->calculateForScope($suites, $hasSuite ? $suite : null, $tags, $featurePath);
        if ($expected->hasErrors()) {
            throw new RuntimeException($this->describeParseErrors($expected->errors));
        }
        return $expected;
    }

    /**
     * Tells whether the scope will execute nothing: no matching scenario, or no feature file that survives
     * the tag filter.
     *
     * WHY BOTH CONDITIONS: the counts and the matched files come from one calculation and should agree, but a
     * run that has either of them empty has nothing for Behat to execute - and the child would still exit with
     * 0 and print "all scenarios passed". Judging from both keeps a disagreement between them from slipping
     * through as a green run.
     *
     * @param ExpectedTestCountResult $expected Result of calculateExpectedScope()
     */
    public static function isEmptyScope(ExpectedTestCountResult $expected): bool
    {
        return $expected->scenarioCount === 0 || $expected->matchedFiles === [];
    }

    /**
     * Builds the exception message for feature files that failed to parse: each file with ITS OWN parse error.
     *
     * WHY THE ERROR TEXT AND NOT ONLY THE FILE NAME: the message used to list file names alone, so the reader
     * knew WHICH file was broken but had to open it and re-run a parser to learn WHY. The reason is what lets
     * a tester (or an agent) fix the file without a second round trip, and it ends up in the run log and the
     * run's error_message as well.
     *
     * WHY IT IS CAPPED: one broken line can produce a multi-line parser message, and a bad merge can break
     * hundreds of files. Each reason is cut to a single bounded line, and only the first few files are
     * spelled out, followed by how many more there are.
     *
     * @param array<string,string> $errors Map of absolute file path => parser message
     */
    private function describeParseErrors(array $errors): string
    {
        $lines = [];
        foreach (array_slice($errors, 0, self::PARSE_ERRORS_LISTED, true) as $file => $message) {
            // One line per file: collapse whitespace and line breaks of the parser's message, and drop the
            // trailing " in file: <absolute path>" the parser appends - the file is named in front already.
            $reason = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
            $reason = preg_replace('/\s+in file:? .*$/', '', $reason) ?? $reason;
            if (strlen($reason) > self::PARSE_ERROR_MAX_BYTES) {
                $reason = mb_strcut($reason, 0, self::PARSE_ERROR_MAX_BYTES) . '...';
            }
            $lines[] = $this->featureRelativePath($file) . ': ' . $reason;
        }
        $more = count($errors) - count($lines);
        return 'Feature files failed to parse (' . count($errors) . '): ' . implode('; ', $lines)
            . ($more > 0 ? '; ... and ' . $more . ' more' : '');
    }

    /**
     * Refuses a scope selector that looks HTML-encoded.
     *
     * WHY IT EXISTS: a selector that went through an HTML escape on its way here (a UI that rendered a stored
     * command for display and sent that text back, as the Test Runs rerun button once did) arrives as e.g.
     * `A&amp;&amp;B`. Behat then matches no tag and the run ends as "nothing to run", which an AI agent cannot
     * tell apart from a genuinely empty scope. A loud refusal that names the cause is the only outcome that
     * cannot be mistaken for a legitimate empty run.
     *
     * WHY THE CHECK IS THE ENTITY PATTERN: a valid Behat tag expression never contains ";" (tags are
     * `@name`, combined with `&&`, `||`, `,`, `~`, parentheses), and neither do suite names or feature paths
     * in practice. `&name;` / `&#NN;` / `&#xHH;` is what HTML escaping produces. A bare `&&` stays valid.
     *
     * WHY STATIC: it needs no workbench, so both actions can call it first thing, before any other input
     * is resolved and before anything is persisted.
     *
     * @param string|null $tags    --tags value
     * @param string|null $suite   --suite value
     * @param string|null $feature --feature value
     * @throws RuntimeException naming the first offending value
     */
    public static function assertNotHtmlEncoded(?string $tags, ?string $suite, ?string $feature): void
    {
        foreach (['tag expression' => $tags, 'suite name' => $suite, 'feature path' => $feature] as $what => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (preg_match('/&(?:#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $value, $entity) === 1) {
                throw new RuntimeException(
                    'The ' . $what . ' looks HTML-encoded (it contains the entity "' . $entity[0] . '"): '
                    . var_export($value, true) . '. A valid ' . $what . ' never contains such an entity, and '
                    . 'running it as given would match nothing and end as "nothing to run". Pass the raw '
                    . 'text, e.g. "&&" instead of "&amp;&amp;".'
                );
            }
        }
    }

    /**
     * Reconstructs a reproducible description of what an action ran, for the run row's behat_command
     * column.
     *
     * Why the action invocation rather than a behat command: a parallel run spawns one behat command
     * per feature, so there is no single behat command to record; the action invocation with its
     * resolved scope selectors is the value that reproduces the whole run - and RunTest records the same
     * kind of value so both actions can be re-run from the Test Runs page the same way.
     *
     * Why FORWARD slashes: they resolve identically on Windows and Linux and match the console's own static
     * "vendor/bin/action ... Behat init" start command and the vendor-relative form stored in
     * run_feature.filename. The resolver accepts them on the way back in.
     *
     * WHY THE FEATURE VALUE IS NORMALISED AND SHORTENED TOO: the operator's own path may be absolute and
     * backslashed. The vendor-relative, forward-slashed form is the one this system prints everywhere else, so
     * the recorded command reads the same as the run log and the DB.
     *
     * WHY THE VALUES CAN CONTAIN QUOTES: the Test Runs console receives this string unchanged (core's Console
     * widget used to JSON-encode prefill values before the facade encoded them again; that was fixed there),
     * so a quoted value such as --tags="@A&&@B" reaches the shell exactly as recorded.
     *
     * @param string               $actionAlias  Action to re-run, e.g. "axenox.BDT:RunTest"
     * @param string|null          $tags         --tags value
     * @param string|null          $feature      --feature value (absolute or vendor-relative)
     * @param string|null          $suite        --suite value
     * @param array<string,string> $extraOptions Further options of the action (name => value), recorded when non-empty
     */
    public function describeInvocation(string $actionAlias, ?string $tags, ?string $feature, ?string $suite, array $extraOptions = []): string
    {
        $cmd = 'vendor/bin/action ' . $actionAlias;
        if ($tags !== null && $tags !== '') {
            $cmd .= $this->cliOption(RunOptions::TAGS, $tags);
        }
        if ($feature !== null && $feature !== '') {
            $cmd .= $this->cliOption(RunOptions::FEATURE, $this->featureRelativePath($feature));
        }
        if ($suite !== null && $suite !== '') {
            $cmd .= $this->cliOption(RunOptions::SUITE, $suite);
        }
        foreach ($extraOptions as $name => $value) {
            if ($value !== '') {
                $cmd .= $this->cliOption($name, $value);
            }
        }
        return $cmd;
    }

    /**
     * Refuses, at the start of a run, any option value that the Test Runs console would refuse to re-run.
     *
     * WHY IT EXISTS: the rerun button replays the recorded behat_command through the console's allow-list
     * (RerunCommandPolicy). A value that RunTest / RunParallel accept and record - a chrome_endpoint with a
     * query string, a tag containing "+" - but the policy refuses, produced a run that could never be
     * repeated from the page, and the only symptom was a bare "Command not allowed!" long after the run had
     * ended. Failing here, before any port, run row or browser exists, moves that failure to the moment the
     * operator can still fix the input.
     *
     * WHY IT ASKS THE POLICY ABOUT THE REAL RECORDED COMMAND AND HAS NO CHARACTER LIST OF ITS OWN: the
     * command is built by describeInvocation() - the exact string that will be stored - so quoting, path
     * shortening and the policy are all judged together, and a change to the policy changes this check with
     * it. A second list here would drift from the console's.
     *
     * Each option is judged on its own afterwards only to NAME the culprit in the message.
     *
     * @param string               $actionAlias  Action to re-run, e.g. "axenox.BDT:RunTest"
     * @param string|null          $tags         --tags value
     * @param string|null          $feature      --feature value, as it will be recorded (describeInvocation() shortens it)
     * @param string|null          $suite        --suite value
     * @param array<string,string> $extraOptions Further options that will be recorded (name => value)
     * @throws RuntimeException if the recorded command would be refused by the rerun console
     */
    public function assertRerunnable(string $actionAlias, ?string $tags, ?string $feature, ?string $suite, array $extraOptions = []): void
    {
        if (RerunCommandPolicy::isAllowed($this->describeInvocation($actionAlias, $tags, $feature, $suite, $extraOptions))) {
            return;
        }

        $culprit = 'recorded command';
        $value = $this->describeInvocation($actionAlias, $tags, $feature, $suite, $extraOptions);
        $options = [RunOptions::TAGS => $tags, RunOptions::FEATURE => $feature, RunOptions::SUITE => $suite] + $extraOptions;
        $selectors = [RunOptions::TAGS, RunOptions::FEATURE, RunOptions::SUITE];
        foreach ($options as $name => $optionValue) {
            if ($optionValue === null || $optionValue === '') {
                continue;
            }
            $single = $this->describeInvocation(
                $actionAlias,
                $name === RunOptions::TAGS ? $optionValue : null,
                $name === RunOptions::FEATURE ? $optionValue : null,
                $name === RunOptions::SUITE ? $optionValue : null,
                in_array($name, $selectors, true) ? [] : [$name => $optionValue]
            );
            if (! RerunCommandPolicy::isAllowed($single)) {
                $culprit = '--' . $name . ' value';
                $value = $optionValue;
                break;
            }
        }

        throw new RuntimeException(
            'The ' . $culprit . ' cannot be re-run from the Test Runs console: ' . var_export($value, true)
            . ' contains a character the console refuses (allowed: ' . RerunCommandPolicy::describeAllowedCharacters()
            . '). Refusing to start a run that could not be repeated.'
        );
    }

    /**
     * Formats one CLI option as "--name=value" for the reproducible command, quoting the value only
     * when the shell would otherwise act on it.
     *
     * Why quoting must be conditional: a plain value (a suite name, a simple tag) stays bare, so the
     * recorded command reads like one a person would type.
     *
     * WHY THE CONDITION COMES FROM RerunCommandPolicy: the recorded command is replayed through the console's
     * allow-list, which accepts a bare value only if every character is one of its BARE_VALUE_CHARS. This
     * method used to decide with a regex of its own (whitespace and the cmd metacharacters `& | < > ^ ( )`),
     * a second character list that could drift from the policy - a character the policy later dropped from
     * the bare set would have been recorded bare and refused on every rerun. Quoting exactly when the policy
     * would refuse the bare form keeps the two decisions one.
     */
    private function cliOption(string $name, string $value): string
    {
        return RerunCommandPolicy::isBareValue($value)
            ? ' --' . $name . '=' . $value
            : ' --' . $name . '="' . $value . '"';
    }
}