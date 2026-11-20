# Troubleshooting BDT test runs

Every fix to the `RunParallel` action is recorded here, written for a reader who has never seen the
code. Newest entries at the top.

Each entry has four parts:

* **Symptom** — what was actually observed, from the outside.
* **Root cause** — what was really wrong (not the first hypothesis).
* **Fix** — what was changed and why that is the correct level to fix it at.
* **How to recognize it again** — the fingerprint.

---

## Quick symptom index

| What you see | Go to |
|---|---|
| Rerun from the Test Runs console answers "Command not allowed!" | [Rerun console only accepts the two run actions](#rerun-console-only-accepts-the-two-run-actions) |
| A run is refused at start with "cannot be re-run from the Test Runs console" | [Rerun console only accepts the two run actions](#rerun-console-only-accepts-the-two-run-actions) |
| "No feature/scenario matched the requested scope/tags - nothing was run." | [An empty scope reported success](#an-empty-scope-reported-success) |
| Rerun starts `--tags="A&amp;&amp;B"` and ends as "no feature files matched ... Nothing to run" | [Rerun button sends HTML-encoded values](#rerun-button-sends-html-encoded-values) |
| A RunTest failed after its run row was created and the output has no `Run UID:` line | [RunTest: Run UID missing from an error after the row exists](#runtest-run-uid-missing-from-an-error-after-the-row-exists) |
| Rerunning a recorded command from the Test Runs console fails; the console shows `vendor\/bin\/action ... --tags=\"...\` | [Rerun command arrives double-encoded in the console](#rerun-command-arrives-double-encoded-in-the-console) |
| A run that threw an error (coordinator failed, feature file parse error, crashed Behat, all lanes timed out) shows as passed on the Test Runs page | [A failed run showed as passed](#a-failed-run-showed-as-passed) |
| RunParallel / RunTest fails with `behat_config is not a file`, or tests run against a stale `base_url` after a deployment | [RunParallel: Behat init is no longer part of the action](#runparallel-behat-init-is-no-longer-part-of-the-action) |
| Concurrent RunTests fail with optimistic-locking errors on the test user | [RunTest: concurrent runs shared one test user](#runtest-concurrent-runs-shared-one-test-user) |
| A parallel run failed at coordinator level / during close-out and the error does not name the run, or `--feature` with `--suite` is refused, or a `--suite` run looks like it stopped early | [RunParallel: run UID missing from error output, --feature with --suite refused](#runparallel-run-uid-missing-from-error-output---feature-with---suite-refused) |
| Behat stops at startup with `Cache path "/tmp/behat_gherkin_cache/<hash>" is not writeable` | [Shared temporary Gherkin cache is not writable](#shared-temporary-gherkin-cache-is-not-writable) |
| A browser-setting change affects interactive runs but not scheduled lanes | [Generated browser configs drift](#generated-browser-configs-drift) |
| `"User authentication" ... field "Modified on" is required` on a fresh environment | [Timestamping guard killed the INSERT](#timestamping-guard-killed-the-insert) |
| `finished_on` is NULL, run log empty, no diagnostic trace | [Invisible close-out](#invisible-close-out) |
| Run completed on disk but DB rows are empty | [Silent DB write failure](#silent-db-write-failure) |
| Deadlock victim errors during parallel runs | [Deadlock on shared writes](#deadlock-on-shared-writes) |
| Healthy lane killed as "idle" | [Output-only idle timeout](#output-only-idle-timeout) |
| Features silently never ran (expected > actual) | [Static buckets lost work](#static-buckets-lost-work) |
| Chrome processes / profile dirs pile up on the server | [Leaked Chrome trees](#leaked-chrome-trees) |
| Chrome fails to launch, every login fails | [Cross-account profile reuse](#cross-account-profile-reuse) |
| Real concurrency capped at ~2 lanes | [Xdebug serialized the fleet](#xdebug-serialized-the-fleet) |
| Run duration in the DB is 1000× too small | [duration_ms holds seconds](#duration_ms-holds-seconds) |

---

## RunParallel: Behat init is no longer part of the action

**Symptoms**
- RunParallel (or RunTest) fails right at the start with `behat_config is not a file: <installation root>\behat.yml. Run `vendor/bin/action axenox.BDT:Behat init` first ...`. No run row exists for such a failure.
- After a deployment or a changed workbench URL, tests run against a stale `base_url` and every login fails or opens the wrong application.

**Cause**
- Behat init used to run inside the actions. It is a setup step of its own (scheduler step, the Test Runs console's start command) and running it again inside every action call duplicated that step and rewrote the root `behat.yml` while other Behat processes could be reading it. It was removed from RunParallel and RunTest, so nothing refreshes `behat.yml` unless the caller does. On a fresh installation, or after the file was deleted, it does not exist at all.

**Fix**
- Run `vendor/bin/action axenox.BDT:Behat init` before RunParallel / RunTest: as its own scheduler step ahead of the run, as the console start command, or in the agent's setup. Run it again after every deployment or URL change so `base_url` is current.
- The actions only read the existing root `behat.yml` (or `--behat_config` / `PARALLEL.BEHAT_CONFIG`, which must also be in the installation root) and resolve it before any port, run row or Chrome work, so a missing file fails loudly and leaves nothing behind.

**How to recognize it again.** `behat_config is not a file` with the init hint = init was not run. A run that starts but logs in against the wrong URL = init was not re-run after the URL changed (compare `base_url` in the root `behat.yml` with the workbench URL).

---

## RunTest: concurrent runs shared one test user

**Symptoms**
- Two RunTest runs started at the same time (for example an AI agent and a tester) failed at login with an optimistic-locking error on the test user's authenticator row (`USER_AUTHENTICATOR`), though each run was fine alone.

**Cause**
- RunTest ran with the base test user, so concurrent runs that resolve to the same role wrote the same user row. Only fleet lanes had their own user (`<TEST_USER.USERNAME>_laneN`).

**Fix**
- Every RunTest run gets `lane_id = <run_uid>_interactive<slot>` and therefore its own test user `<TEST_USER.USERNAME>_interactive<slot>`. The slot is a number 1..N reserved across processes (flock files `interactive_user_slot_<n>.lock` under `data/axenox/BDT/portlocks`) for the whole run, so it is unique among concurrent interactive runs; N is `TEST_USER.INTERACTIVE_SLOTS` (default 10, integer >= 1) and caps how many such users exist (later runs reuse them). The username logic accepts `_laneN` and `_interactiveN` and throws for any other lane_id format; the test-data cleanup recognises both.
- The slot does not depend on the browser: local and remote (attached) runs draw from the same pool. An earlier version derived the number from the browser port or the endpoint URL's port; for remote browsers that is 443 for every plain https endpoint, so concurrent remote runs shared one user again.
- The slot is taken before the run row is created. When all N slots are in use the run is refused with "All N interactive test-user slots are in use ... raise TEST_USER.INTERACTIVE_SLOTS" - it never falls back to the base user or a shared slot.

**How to recognize it again.** Look for users named `<TEST_USER.USERNAME>_interactive<slot>` next to the lane users. The "All N interactive test-user slots are in use" error means more RunTests run at once than the pool has slots: wait, or raise the key. An error "has no `_laneN` or `_interactiveN` token" means a lane_id with another format reached the formatter. A stuck slot clears itself when its owning process dies (the lock is an flock); the lock file's text names the pid.

---

## RunParallel: run UID missing from error output, --feature with --suite refused

**Symptoms**
- A parallel run failed at coordinator level or during close-out, and the error message did not say which run it was. Finding its results in the DB meant guessing from the start time.
- Passing --feature and --suite together was refused with "Pass either --feature or --suite, not both."
- A --suite run without --tags reported more expected scenarios than actually ran, and looked as if it had stopped early.

**Cause**
- The run UID was added only to the success, nothing-to-run and worker-failure messages. The coordinator-error path and a failing finalize threw without it.
- The two scope options were treated as mutually exclusive. Behat itself supports them together: it runs that feature within that suite.
- Expected counts were computed with only the --tags given on the command line. Behat also applies the suite's own tag filter from behat.yml, so the coordinator counted scenarios that Behat skipped.

**Fix**
- Once the run row exists, every RunParallel outcome (success, nothing matched, worker failures, coordinator error, close-out failure) contains a line `Run UID: <uid>`. Use this UID to read the run, its features, scenarios and steps from the DB. If an error has no such line, the run row was never created and there is nothing in the DB for it.
- --feature and --suite can be combined. The feature must be inside the suite's configured paths; otherwise the run is refused before it starts, because a feature outside the suite would run nothing and still look green.
- Expected counts now apply the same tag filter Behat applies: --tags when given, otherwise the suite's own filter.
- RunTest now creates and finalizes its own run row the same way, so both actions report the run UID in the same format.

**How to recognize it again.** An error from RunParallel or RunTest without a `Run UID:` line after the run has started is a regression of this fix. An expected scenario count above the actual one on a `--suite` run that finished cleanly points at the tag filter (compare the suite's `filters.tags` in behat.yml with the tags the run was started with).

---

## An empty scope reported success

**Symptoms**
- A run whose `--tags` / `--feature` / `--suite` matched no scenario ended without an error: RunParallel returned "Nothing to run" as a normal message, while its `error_message` and status said failed; RunTest (not yet reading the counts) started Behat, which exits 0 on an empty selection, and printed "all scenarios passed" for a run that executed nothing.

**Cause**
- Nothing treated "zero matching scenarios" as an error. The expected counts were calculated, but only RunParallel looked at them, and it returned a success result after recording the failure, so the CLI/scheduler and the DB disagreed. Typical triggers: a tag with a typo, a tag filter that excludes the whole feature, a rerun with a mangled tag expression.

**Fix**
- Both actions decide through `RunScope::isEmptyScope()` once the expected scope is calculated (and the run row exists). The run is finalized with `error_message` = "No feature/scenario matched the requested scope/tags - nothing was run." and the action THROWS the same text with the `Run UID:` line. RunTest does it before the port, config and Behat; RunParallel before any lane starts. The run status is failed (error_message set).

**How to recognize it again.** An error with that text: check the tag expression (spelling, `@` prefix, the suite's own `filters.tags` when `--tags` is not given) and that the feature is inside the suite. A run with a UID, `error_message` set and no feature rows is this case.

---

## Rerun console only accepts the two run actions

**Symptoms**
- The rerun console answers "Command not allowed!" for a recorded command - or, before the whitelist fix, ran a command that had nothing to do with a test run (e.g. `whoami & echo action`).
- Or: a run could be STARTED with a value that the console then refuses on a rerun (a tag containing `+`, an endpoint path with `;`), so the run could never be repeated from the page and the only symptom was the bare "Command not allowed!".

**Cause**
- `WebConsoleFacade::performCommand()` allows a command when ANY `allowed_commands` pattern gives a non-zero `preg_match()` on it. That is a search, not a full match. The rerun dialog (`RunTestDialog`, `Model/axenox.BDT.run/08_OBJECT_ACTION.json`) allowed `/action.*/i` and `/behat.*/i`, so every command that merely contained "action" or "behat" passed. The command comes from the browser and the stored `behat_command` is prefilled into it, so a writable `behat_command` was a way to put a command of choice into a console that executes it. (A command containing `vendor/bin/action` is executed in-process by `ConsoleFacade::getOutputGenerator()` via Symfony's `StringInput`, not through a shell; other commands go through `CliCommandRunner::runCliCommand()`, which does use a shell. The patterns do not rely on that routing.)

**Fix**
- The patterns match the WHOLE command (`^...$`, flag `D` so `$` does not accept a trailing newline) and are WHITELISTS of characters. They are defined once, with a docblock for every part, in `Behat/Common/RerunCommandPolicy.php`; `08_OBJECT_ACTION.json` carries a copy that **must be updated by hand and re-exported whenever the constants change**:
  - `vendor/bin/action axenox.BDT:RunTest|RunParallel` followed only by ` --name=value` options. A bare value may contain only letters of any script (`\p{L}`, e.g. `ä ö ü ß` - the pattern has the `u` flag), digits and `@ _ . : / \ , ~ = - ? +`. A double-quoted value may additionally contain space, `&`, `|`, `(` and `)`; it may never contain `"`, `%`, `!`, `;`, `$`, a backtick or a line break, and may not end in a backslash. `?` and `+` are allowed because an allowed `vendor/bin/action` command is parsed in-process by `StringInput`, where they are plain characters; `$` and the backtick are live inside double quotes on POSIX shells, and the BDT also runs on Linux. This is exactly how `RunScope::cliOption()` records values; a value with `<`, `>` or `^` (not valid in a tag expression) is refused.
  - `vendor/bin/action axenox.BDT:Behat init` as an exact command (the console's start command).
- **The actions refuse at start what the console would refuse on a rerun.** `RunScope::assertRerunnable()` builds the very command `describeInvocation()` records and asks `RerunCommandPolicy::isAllowed()` about it - there is no second character list. RunTest does it as the last check of `performImmediately()`, RunParallel right after the scope is resolved, so no port, run row or browser exists yet. The message names the option (`--tags value cannot be re-run from the Test Runs console: ...`) and lists the allowed characters (derived from the same constants). Note that `ChromeDevToolsHttpClient::normalizeEndpoint()` already refuses an endpoint or base URL with a query string or fragment, so `?` never comes from there; a `;` or `$` in the URL PATH does get through it and is refused by this check.
- `behat_command` is no longer editable (`EDITABLEFLAG` 0 on `axenox.BDT.run.behat_command`); only the actions write it.
- The patterns are pinned by `RerunConsoleAllowListTest`: it tests the PHP constants (`RerunCommandPolicy`), what `RunScope::describeInvocation()` records for every option, the start-time refusal, and that the copy in `Model/axenox.BDT.run/08_OBJECT_ACTION.json` equals `RerunCommandPolicy::patterns()` - so a stale model copy fails a test instead of silently leaving the console on old patterns.

**How to recognize it again.** A run is refused at start with "cannot be re-run from the Test Runs console": the named value contains a character outside the whitelist (`"`, `%`, `!`, `;`, `$`, a backtick, `<`, `>`, `^`, tab, line break); fix the value. A rerun of an OLD recorded command is refused although the run itself started: the command contains such a character (runs started before this check) - run it by hand. If a new character or option style is needed, extend the constants in `RerunCommandPolicy`, the copy in the model file and `RerunConsoleAllowListTest` together; the model-copy test tells you when the copy was forgotten. Other consoles have their own lists (`feature-files` page, queue); check that they are anchored too.

---

## Rerun button sends HTML-encoded values

**Symptoms**
- After the double-encoding fix, the Test Runs rerun button started `vendor/bin/action axenox.BDT:RunTest --tags="@Foo&amp;&amp;@Bar"`. Behat received `&amp;` as part of the tag expression, matched nothing and the run ended with "no feature files matched the requested scope/tags. Nothing to run." The same happened through RunParallel.

**Cause**
- The path is: table row -> action `RunTestDialog` input mapper -> `Console::doPrefill()` -> `EuiConsole::buildJs()` -> console. The first step is where the entities come from: `JEasyUIFacade::buildResponseData()` HTML-escapes EVERY string column of a table response for display (`htmlspecialchars`, `&` -> `&amp;`), so the grid holds `&amp;`. The single-value getter (`EuiDataTable::buildJsValueGetter()`) un-escapes, but the action INPUT getter (`buildJsDataGetter()` -> `getSelections` rows) did not - so the action received the display-escaped text and used it as data.
- It looked like a BDT problem because BDT is the first place where a table value becomes a command line. Any text value with `&`, `<` or `>` taken from a selected row of a read-only table reached any action escaped.

**Fix**
- At the layer that leaves the entities: `exface/jeasyuifacade`, `EuiDataTable::buildJsDataGetter()` / `buildJsActionRowsProjection()` now undo exactly the one level of display escaping (`exfTools.string.htmlUnescape`) on the rows of a read-only table before they are posted as action input. Editable tables are untouched: their rows come from user edits, which are raw text. **This is a change in the `exface/jeasyuifacade` package, not in axenox/BDT - it has to be committed and released there.**
- Defensive guard in BDT (`RunScope::assertNotHtmlEncoded()`, called first by RunTest and RunParallel): a tag expression, suite name or feature path containing an HTML entity (`&name;`, `&#NN;`, `&#xHH;`) is refused with "looks HTML-encoded ... Pass the raw text". A valid Behat tag expression never contains `;`, and a mangled one used to end as a silent "nothing to run" that an agent cannot tell apart from a genuinely empty scope. A bare `&&` stays valid.

**How to recognize it again.** The refusal message "looks HTML-encoded (it contains the entity ...)" from RunTest/RunParallel means some caller escaped the value for display and passed that text on. Look at the layer between the stored value and the command (a UI getter, a template, a proxy).

---

## RunTest: Run UID missing from an error after the row exists

**Symptoms**
- A RunTest whose feature does not parse failed in the deferred phase (scope/expected-count calculation). The run row WAS created, but the output had no `Run UID:` line - neither in the stream nor in the exception message - so the reason for the broken feature could not be found in the DB.

**Cause**
- Only the stream carried the UID (a `yield` right after the row was created). When the generator throws, the caller sees the exception, and the exception message had no UID line. RunParallel had added the line by hand at each of its own call sites; RunTest had no such code on its error path.
- Second cause, in the web console: core's `IteratorStream::read()` collected the yielded chunks in a local string and let the generator's exception escape, which discarded every chunk yielded before the failure - the banner and the `Run UID:` line included. The console then showed only the error. `RunTestRunUidTest` drains the generator itself and never goes through that stream, so it could not see this.

**Fix**
- The UID line is built in ONE place, `RunLifecycle` (`appendRunUid()` for messages, `withRunUid()` for exceptions), and both actions use it for every message and exception after the row exists: RunTest wraps whatever escapes the deferred phase, RunParallel its coordinator, close-out, nothing-to-run, worker-failure and success messages. The line is never added twice.
- RunTest still creates the row first and calculates scope and counts after it (same order as RunParallel), so a parse error is recorded under the run's UID in the run log and in `error_message`. The `Run UID:` line is the very first thing yielded after the row exists, before the scope calculation, the expected counts, the port and the config. The row is finalized on this path with the failure recorded, so the run status cannot be passed.
- The slot reservation still happens BEFORE the row (all slots taken fails with no row and no UID, by design).
- core's `IteratorStream` (`exface/core`, `Facades/AbstractHttpFacade/IteratorStream.php`) now hands out the output collected before a generator error first and throws the error on the next read. **This is a change in the `exface/core` package; it has to be committed and released there.** Test: `RunUidStreamDeliveryTest`.

**How to recognize it again.** An exception from RunTest or RunParallel without a `Run UID:` line, while a run row with that start time exists in the DB.

---

## Rerun command arrives double-encoded in the console

**Symptoms**
- Rerunning a recorded RunTest/RunParallel command from the Test Runs console failed with exit code 1. The console ran `vendor\/bin\/action axenox.BDT:RunTest --tags=\"@Foo&&@Bar\` instead of the recorded `vendor/bin/action axenox.BDT:RunTest --tags="@Foo&&@Bar"`.

**Cause**
- The recorded command (`axenox.BDT.run.behat_command`) is injected into the console's `start_commands` through the placeholder `[#behat_command#]` (model object action `RunTestDialog`). core's `Console::doPrefill()` replaced the placeholder with the value JSON-encoded (`escapeCommandPlaceholderValue()`), put it into the start-commands array, and the facade (`EuiConsole::buildJs()`) encoded that array to JSON AGAIN. Every `/` came out as `\/` and every `"` as `\"`.
- The slashes went unnoticed because Windows reads `\/` as one separator. Quotes only appeared when the recorded command started quoting values that contain `&`, `|`, `<`, `>`, `^`, `(`, `)` or whitespace - which it has to, otherwise cmd reads `&&` as a command separator and splits the command.

**Fix**
- Fixed where the encoding happens, in core: `exface\Core\Widgets\Console::escapeCommandPlaceholderValue()` now returns the value unchanged; the facade's `json_encode` is the only encoding. The recorded string reaches the shell exactly as stored. `cliOption()` keeps quoting values that cmd would act on. **This is a change in the `exface/core` package, not in axenox/BDT - it has to be committed and released there.** Without it the console still garbles quoted values.

**How to recognize it again.** A console command that shows `\/` or `\"` although the recorded `behat_command` has none. Compare the DB value with what the console runs; if they differ, the placeholder replacement is encoding again.

---

## A failed run showed as passed

**Symptoms**
- RunParallel threw `Parallel run coordinator failed: Feature files failed to parse: ...`, a RunTest feature did not parse, a RunTest Behat process crashed between scenarios, or every lane of a RunParallel hit the wall-clock timeout. The run row was finalized and the UID was in the message, but the Test Runs page showed the run as passed (or skipped). Agents reading the DB took such a run for green.

**Cause**
- The status shown on the page is the column `status` of the SQL view `bdt_run_stats` (`Install/Sql/<DB>/Views/05_run_stats.sql`), exposed by the model object `axenox.BDT.run_stats` and read from the run through the relation `run_stats__status`. It was derived ONLY from the child rows (steps). A run that failed before or between its children has nothing to read: no failed step, so on MySQL the sum of passed steps compared as `NULL = 0` and fell through to "passed", on MsSQL to "skipped". The view could not see a run-level failure.

**Fix**
- New column `bdt_run.error_message` (attribute `error_message` of `axenox.BDT.run`, shown on the Test Runs page). The action that owns the run writes the run-level failure there in the same finalize update (via `RunLifecycle::stageFailure()`): the coordinator error, the worker failures (including timeouts), "no feature matched", and for RunTest the exception or the crash/timeout reason.
- `bdt_run_stats.status` has ONE run-level rule: a FINISHED run with a non-empty `error_message` is `101` (failed). It is checked right after the two "still running" branches. Everything else - the step-based verdicts and the running (10) / stalled (102) states of unfinished runs - is unchanged.
- The parse-error message carries each file's own parser error (vendor-relative file name, one line, capped; at most 5 files, then "... and N more"), so it is readable in the exception, the run log and `error_message`.
- Deploy on every installation: (1) apply the migration `Migrations/1.2/20261120_0800_1_NEW_error_message_column_for_run_object.sql`; (2) re-create the views (`Views/05_run_stats.sql`); (3) import the BDT model (attribute `error_message`, Test Runs page column). The installer does all three: `vendor\bin\action axenox.PackageManager:InstallApp axenox.BDT`.
- Agents read the status through the data sheet read tool: object `axenox.BDT.run`, columns `run_stats__status` (10 running, 102 stalled, 30 undefined, 101 failed, 98 skipped, 100 passed) and `error_message` (the reason).

**How to recognize it again.** A failed run still shows passed/skipped: check `SELECT COUNT(*) FROM information_schema.columns WHERE table_name = 'bdt_run' AND column_name = 'error_message'` (0 = migration missing), `SHOW CREATE VIEW bdt_run_stats` (no `error_message` = old view), and whether the run's `error_message` is empty (the model attribute is missing or the close-out could not write it - look for the logged exception, because a staging failure must not stop `finished_on` from being written). A failure that left no `error_message` and only passed children cannot be detected by the view.

---

## Shared temporary Gherkin cache is not writable

**Symptom.** RunTest, RunParallel and direct Behat runs can fail before any scenarios start with:

```text
Cache path "/tmp/behat_gherkin_cache/<hash>" is not writeable. Check your filesystem permissions or disable Gherkin file cache.
```

`<hash>` is the version-specific subdirectory added by the Gherkin parser.

**Root cause.** BDT did not configure the parser cache, so Behat used
`sys_get_temp_dir() . '/behat_gherkin_cache'`. On Azure App Service this is a shared temporary folder.
If another OS user, such as an administrator running Behat from a shell, created it first, the
application user could not write to it. This is a startup filesystem failure, not a scenario failure.

**Fix.** The shared BDT base configuration now sets `default.gherkin.cache` to
`%paths.base%/data/axenox/BDT/GherkinCache`, alongside the application's other BDT data.
The application user must have write access to `data/axenox/BDT`.

`gherkin.cache` is a supported setting and `%paths.base%` is the directory of the top-level config,
not the directory of an imported file. It is therefore the installation root for the root-level
`behat_interactive_*.yml`, `behat_scheduled_lane*.yml` and `behat.yml` configs. A direct
`vendor\bin\behat` invocation from the installation root discovers that `behat.yml` and uses the
same base path. BDT's template, generated browser overrides and `Behat init` do not override the
cache setting.

Deploy the new BDT version. On an old BDT version, stop Behat runs, delete `/tmp/behat_gherkin_cache`
once (using its owner or an administrator if necessary), then run Behat only as the application
user so it recreates the cache with the correct owner. Never use `chmod 777`: cache files are read
with `unserialize()`, so allowing other users to write them is unsafe.

**How to recognize it again.** The error names `/tmp/behat_gherkin_cache` and occurs before
scenarios start. If the error persists after deployment, check that the active config
imports BDT's updated `Behat/BaseConfig.yml` and does not explicitly override `gherkin.cache`.
If the error instead names `data/axenox/BDT/GherkinCache`, check the application user's write
permissions there.

---

## Generated browser configs drift

**Symptom.** Interactive and scheduled runs had separate config-generation implementations, so a
browser setting added to one could be missing from the other. This was a maintenance risk identified
from the code, not a newly reproduced runtime failure.

**Root cause.** RunTest built an array and serialized it with Symfony YAML, while RunParallel
assembled YAML text and escaped path values itself. Every shared browser change required two edits.

**Fix.** Both actions now use `GeneratedBehatConfig` to build extensions and write the generated file
with `Yaml::dump()`. Lane run/user identities and the header are parameters; profile creation and
port allocation remain in the actions. Existing parsed keys and values are preserved, including
the different historical formatter-key spellings. Manual YAML path escaping is no longer needed.

**How to recognize it again.** If the same browser behaves differently between RunTest and
RunParallel, compare the parsed generated configs. Shared browser settings belong in the builder,
not in action-specific YAML strings. The builder tests cover local/remote overrides, optional lane
identities and Windows paths (including spaces and apostrophes) through a YAML round trip.

---

## Timestamping guard killed the INSERT

**Symptom.** On a **newly set up environment** every lane died right at login with
`Cannot rollback transaction after error! Initial error: "User authentication" konnte nicht
gespeichert werden. Das Feld "Modified on" ist erforderlich und darf nicht leer sein.`
The same code ran fine on environments that had been used before.

**Root cause.** `AuthenticatorTimeStampingTrait::withoutAuthenticatorTimeStamping()` called
`$behavior->disable()` on the whole `TimeStampingBehavior` of `exface.Core.USER_AUTHENTICATOR`.
`AbstractBehavior::disable()` unregisters **every** listener of the behavior — not just the
optimistic-lock check on update, but also `OnBeforeCreateDataEvent → onCreateSetValues()`, which is
what fills `CREATED_ON` / `MODIFIED_ON` / `*_BY_USER`.

On an environment that had been used before, the authenticator row already exists, so
`AbstractAuthenticator::logSuccessfulAuthentication()` issues an **UPDATE** that carries the
previously read `MODIFIED_ON` value — nothing is ever NULL and the missing listener is invisible. On
a fresh environment the row does not exist yet, so the very same code issues an **INSERT** with no
timestamps at all, and `exf_user_authenticator.modified_on` is `datetime NOT NULL`. The database
rejects it, the core turns that into a `DataQueryNotNullConstraintError` and the enclosing
transaction can no longer be rolled back — hence the misleading rollback wrapper on top.

The error message names an *attribute*, so it reads like a metamodel/required-flag problem. It is
not: it is a plain NOT NULL violation reported through the attribute name.

**Fix.** The guard only ever needed the conflict check gone, never the timestamps. It now flips
`check_for_conflicts_on_update` off via `setCheckForConflictsOnUpdate(false)` and restores it in
`finally`, instead of disabling the behavior. The "changed in the meantime" error the guard exists
for is still suppressed, while creates and updates keep getting their timestamps.

**How to recognize it again.** A NOT NULL / "field is required" error for a *system* attribute
(`Modified on`, `Created on`) that appears only on environments where the affected row is being
created for the first time. Whenever a behavior is switched off to suppress one of its checks, ask
which of its *other* listeners went away with it.

---

## Invisible close-out

**Symptom.** A run finished with `finished_on = NULL`, no run log on the run row, and the
coordinator diagnostic file gave no clue whatsoever about what happened. 30 of 34 features had
recorded results, so the fleet itself had clearly worked.

**Root cause.** Two independent problems in the same phase:

1. The diagnostic log handle was closed **one statement before** the close-out phase. Everything
   that happened after the fleet drained — staging the log, finalizing the run row — was invisible
   by construction. A crash there could leave no trace at all.
2. `RunRecordWriter::finalize()` performs a **single** `dataUpdate()` carrying `finished_on`,
   `duration_ms` and the entire staged run log at once. If that one write is lost, the run row stays
   open forever *and* the whole diagnostic digest disappears with it — indistinguishable from "the
   coordinator crashed without a trace".

**Fix.**

* The log handle is held on the instance and closed **after** all close-out work, through a
  dedicated `closeCoordinatorLog()` that is safe to call twice.
* Each close-out stage announces itself in the log **before** it runs, so a process killed mid-phase
  leaves behind the name of the phase that was in progress.
* The close-out `catch` re-throws immediately; it exists only so the reason reaches the file before
  the handle closes.
* `finalize()` is wrapped in deadlock retry (see below).

**How to recognize it again.** `finished_on` NULL + empty `log` column + a `coordinator.log` whose
last line is a `DIAG drain:` line rather than a `DIAG close-out:` line.

---

## Silent DB write failure

**Symptom.** A run appears to complete normally — worker logs on disk are complete and green — but
the database rows are missing or empty. Identical symptom to a coordinator death.

**Root cause.** `DatabaseFormatter` catches every `\Throwable` in its lifecycle hooks. It has to:
an uncaught exception from a Behat hook kills the process with exit code 255 and destroys the whole
feature run. But the consequence is that a failing database write lets the run finish normally on
disk while writing nothing.

**Fix.** Not a code change but a diagnostic rule: **the on-disk logs are the tiebreaker.** A
coordinator death leaves a truncated `coordinator.log`; a DB write failure leaves a complete one.
Anything new written into a hook must additionally be logged somewhere that does not depend on the
database.

**How to recognize it again.** Complete worker logs + complete `coordinator.log` + empty child rows.

---

## Deadlock on shared writes

**Symptom.** Repeated deadlock-victim errors during parallel runs. Five identical stack traces all
pointing at the same call path.

**Root cause.** `UI5Browser::setupUser()` unconditionally rewrote `USER_ROLE_USERS` on **every**
scenario login, including Background steps. Parallel lanes whose scenarios resolved to the same role
therefore wrote the same role rows concurrently, producing lock cycles.

An earlier hypothesis — that the heartbeat's `run_step` count poll caused the deadlocks — was fully
eliminated once the real stack traces arrived. *Get the actual log before committing to a fix.*

**Fix.** Layered, at the level each problem belongs to:

* Skip the `dataUpdate()` entirely when the role set is unchanged (do not write what does not
  change).
* Serialize user provisioning with `flock`.
* Wrap the write in `DeadlockRetryTrait`.
* Independently: all three `RunRecordWriter` writes (`create`, `setExpectedCounts`, `finalize`) are
  wrapped in deadlock retry, because being chosen as a victim is a *documented, expected* outcome
  with a rolled-back transaction — re-running is the only correct response.

Note that the retry is **not** a blanket catch: it re-throws everything except deadlock and lock
timeout. Constraint violations, connection losses and schema errors still propagate.

**How to recognize it again.** MS SQL messages containing "chosen as the deadlock victim" and
"Rerun the transaction", clustered around login/Background steps.

---

## Output-only idle timeout

**Symptom.** Lanes that were working perfectly were killed as "idle".

**Root cause.** Symfony `Process::setIdleTimeout()` resets its timer only on **process output**. But
a long `works as expected` step emits no stdout at all while it runs — it only keeps INSERTing one
`run_step` row per substep. So the loudest possible proof of progress was invisible to the timer.

**Fix.** Symfony's idle timeout is no longer used. The coordinator detects idleness itself and
accepts **either** signal as progress: new console output from that lane, **or** a new `run_step`
row for the feature *that lane is currently executing*. The DB half is scoped per lane on purpose —
a fleet-wide signal would be advanced by healthy sibling lanes and would keep a genuinely hung lane
alive forever.

The DB probe reads a **marker** (`created_on | step_uid` of the newest row), not a count: the loop
only ever asks "anything new since last poll?", and a count answered that by reading thousands of
rows on every poll while holding shared locks on exactly the tables the workers were inserting into.

**How to recognize it again.** `DIAG drain: lane N idle timed out` for a feature whose child rows
show steps still being written around that timestamp.

---

## Static buckets lost work

**Symptom.** Features that were never executed left no trace at all. The only evidence was
`expected_feature_count > actual`.

**Root cause.** Features were split into N disjoint buckets up front, one long-lived Behat process
per bucket. When a lane was killed, every remaining feature in its bucket was simply dropped. Also,
buckets never rebalanced — a lane with light features idled while another still ran.

**Fix.** One coordinator-owned queue; each lane runs **exactly one feature per Behat process** and
pulls the next one when it exits. The process boundary now carries the bookkeeping: the feature a
lane was executing when it was killed is unambiguous, so it is recorded as failed instead of
vanishing, and the untouched remainder of the queue keeps flowing to other lanes.

Two policies come with it:

* **Poison feature:** a timed-out feature is never requeued — one pathological file would otherwise
  hang every lane in turn and consume the whole run.
* **Lane retirement:** after 2 *consecutive* timeouts the lane itself is taken out of rotation,
  which distinguishes "one bad feature" from "one broken lane".

**How to recognize it again.** Any gap between expected counts and actual child rows that no failure
entry explains.

---

## Leaked Chrome trees

**Symptom.** Chrome processes and locked profile directories piled up on the server — six
`chrome.exe` under `lane1` surviving a single timed-out lane.

**Root cause.** Three separate causes, all found in the same area:

1. **Logging blocked cleanup.** The workbench logger writes to the database. When the database could
   not accept writes (a full PRIMARY filegroup), the logger call *threw* — and everything after it,
   including killing the detached Chrome tree, was skipped. For every timed-out lane, on every run.
2. **Path drift.** The profile directory path was rebuilt by hand in three places. When it became
   run-scoped, only the writer was updated; the two reapers kept building the old fixed `laneN`
   name, matched nothing, and — because removing a non-existent directory reports success — reported
   success while doing nothing.
3. **YAML backslash doubling.** Windows paths were written into single-quoted YAML with doubled
   backslashes. In single-quoted YAML a backslash is a **literal character**, so the value actually
   changed. Win32 tolerates repeated separators, so Chrome launched fine — but every string
   comparison in the reapers, which compared against single-separator coordinator paths, silently
   matched nothing.

**Fix.**

* Resource reclamation now runs **before** any DB-backed logging, and cleanup is independently
  guarded so a failing logger can only cost a log line.
* All path construction routed through single helpers (`laneProfileDir()`, `chromeProfilesRoot()`).
* Only single quotes are escaped in YAML values; backslashes are left alone.
* Reaping happens after **every feature run** (not only at end of run), plus an end-of-run backstop,
  plus an identity-based and an age-based sweep at the start of the next run.

**How to recognize it again.** `chrome.exe` processes whose command line points at a
`<run_uid>_laneN` directory belonging to a run that has already finished.

---

## Cross-account profile reuse

**Symptom.** Chrome aborted on launch and every login failed. Windows sharing violation (error 32)
on the profile's `ProcessSingleton` file; DPAPI decryption failures.

**Root cause.** Profile directories were named `laneN` — lane-scoped, not run-scoped. The scheduled
fleet runs as `NT AUTHORITY\SYSTEM` while interactive and web runs run as a different account, so a
later run would open a profile created by a *different Windows account*. Chrome could neither
decrypt that profile's DPAPI-protected state nor acquire its per-profile lock.

**Fix.** Profile directories are named `<run_uid>_laneN`, so no two runs — and therefore no two
Windows accounts — ever share one. The directory is deleted after each feature run and recreated
before the next, so a slot's next feature cannot inherit a locked `ProcessSingleton` file either.

**How to recognize it again.** Chrome exits immediately at launch; the profile directory is owned by
a different account than the one running the fleet.

---

## Xdebug serialized the fleet

**Symptom.** No matter how many lanes were configured, real concurrency was capped at about 2. The
3rd and 4th worker produced **no output at all** until an earlier worker exited.

**Root cause.** Two layers:

1. The drain was originally written through `CliCommandRunner`'s generator, which can only be
   drained with a blocking `foreach` — lane N+1 was not even read until lane N's process exited.
2. Even after that, a coordinator launched under an IDE debugger passed its Xdebug trigger to every
   worker through the inherited environment. All workers connected back to the single IDE debug
   client on port 9003, which services only a couple of sessions at once, so the rest blocked
   silently at startup.

**Fix.** Symfony `Process` is driven directly in a non-blocking round-robin drain, and every worker
is started with `XDEBUG_MODE=off` plus `XDEBUG_SESSION` / `XDEBUG_TRIGGER` removed from its
environment.

**Consequence to remember:** breakpoints do not hit inside fleet workers. Use the non-parallel
single-worker path to step through a test. The startup banner says so explicitly when a debugger is
attached.

**How to recognize it again.** `DIAG launch:` lines show all lanes started, but `DIAG drain: first
output` for the later lanes only appears after an earlier lane's completion line.

---

## duration_ms holds seconds

**Symptom.** Run durations in the database are roughly 1000× smaller than reality.

**Root cause.** `RunRecordWriter::computeDurationSeconds()` returns **seconds** but its result is
written into the `duration_ms` column.

**Status.** Known. The unit mismatch is in the writer, not in the column — fixing the writer alone
would make new rows inconsistent with historical ones, so the fix has to decide what happens to
existing data.

**How to recognize it again.** A run that visibly took two hours shows a `duration_ms` in the
thousands.

---

## Template for new entries

```markdown
## <Short name>

**Symptom.** What was observed from the outside, before anyone knew the cause.

**Root cause.** What was actually wrong. If an earlier hypothesis was wrong, say so and say what
eliminated it.

**Fix.** What changed, and why that is the right level to fix it at.

**How to recognize it again.** The fingerprint - log line, column value, process state.
```

## Chrome processes that can never be killed after a deployment

### Symptom
- Dozens of `chrome.exe` processes on the server that no BDT cleanup ever removes.
- At the same time `data\axenox\BDT\chrome_profiles\` is almost empty (often only the
  single `interactive<port>` profile of a tester).
- Server memory climbs run after run. Once it is near full, tests fail with
  `Connection timeout: Empty read; connection dead?` (Chrome alive but too slow — screenshots
  still work) or `Could not fetch version information from http://127.0.0.1:<port>/json/version`
  (Chrome cannot start at all — whole features fail with no screenshots).

### Cause
BDT identifies a Chrome by the `--user-data-dir` on its command line. That path used to be
compared as a full absolute path, e.g.

    C:\wamp\www\example\releases\35.02.17+2026...\data\axenox\BDT\chrome_profiles\<run_uid>_lane1

The `releases\<version>` segment changes with every deployment, while `data` is a junction into
the shared data tree — so the very same physical profile has a different absolute path after a
deployment. Every cleanup sweep therefore stopped recognizing Chrome processes started by the
previous release, while the shared profile dirs themselves were still deleted by the next run.
Result: processes without profile dirs that nothing could ever match again. They kept running and
holding memory until the machine was near full, at which point healthy browsers started timing out
and new ones could no longer be launched.

### Fix
Chrome ownership is matched on a release-independent identity — the path tail starting at the
profiles root folder, e.g. `chrome_profiles\<run_uid>_lane1` — instead of the full absolute path
(`ChromeProfileReaperTrait::profileIdentity()` / `profilesRootMarker()`). All three sweeps
(`reapChromeProfileDir`, `reapStaleChromeProfiles`, `reapProfilesOfInactiveRuns`) and
the local manager's own-leftover check use it, so a leftover survives neither a deployment nor a
coordinator restart. As a side effect a zombie from an older release that squats a port is now
reclaimed instead of failing the lane with "Port is occupied by a FOREIGN process".

### If it happens again (manual cleanup)
List them (check the release version inside the command line — an old one confirms this issue):

    Get-CimInstance Win32_Process |
      Where-Object { $_.Name -eq 'chrome.exe' -and $_.CommandLine -like '*\axenox\BDT\chrome_profiles\*' } |
      Select-Object ProcessId, CommandLine | Format-List

Kill them (only touches Chromes bound to a BDT profile; a human's browser is never matched):

    Get-CimInstance Win32_Process |
      Where-Object { $_.Name -eq 'chrome.exe' -and $_.CommandLine -like '*\axenox\BDT\chrome_profiles\*' } |
      ForEach-Object { Stop-Process -Id $_.ProcessId -Force }


## Chrome cleanup reported success but did nothing

### Symptom
- Run logs show no cleanup warnings at all, yet `chrome.exe` processes and/or profile dirs are left
  behind after the run.
- Happens far more often on runs that were executed while the server was low on memory.

### Cause
BDT finds the Chromes it may kill by listing every `chrome.exe` with its command line via PowerShell.
That listing used to return an EMPTY LIST when the PowerShell call itself failed — which every caller
read as "no Chrome left to clean up, done". Spawning `powershell.exe` is one of the first things that
fails when the machine is out of memory, i.e. exactly when orphaned browsers exist. So the cleanup
silently skipped its work and reported success, and — worse — still deleted the profile dirs, leaving
live browsers with no profile on disk.

### Fix
The listing now returns NULL when it could not be obtained (a completion marker is written as the last
line of the PowerShell script, so a truncated result that still exits 0 is detected too, and the call
is retried up to three times before giving up). Every caller distinguishes the two cases:

- Empty list  -> nothing to clean up, proceed as before.
- NULL        -> the sweep is SKIPPED and a WARNING is logged naming what was left behind.
  Profile dirs are NOT deleted in this case: removing a profile while its browser may still be alive
  is what produces an orphan process that later sweeps cannot attribute.

Affected paths: `LocalChromeManager::stop()`, `RunParallel::cleanupLaneChromes()`,
`RunParallel::reapLaneProfile()`, `RunTest::cleanupInteractiveChrome()`, and both sweeps in
`ChromeProfileReaperTrait`.

### What to do when you see the warning
Nothing is lost — the next run's startup sweep reclaims what was skipped. Repeated warnings mean the
server is under real resource pressure; check free memory and the number of running `chrome.exe`
processes before the next nightly run.

## Chrome was restarted (or the login replayed) although the browser was fine

### Symptom
- Lane logs show repeated Chrome restarts / `recoverChrome()` runs on a run that otherwise looks
  healthy, often several lanes at once.
- Steps fail with `Connection timeout: Empty read; connection dead?` while screenshots of the same
  step are still being captured — i.e. the browser was demonstrably alive.
- Gets dramatically worse the closer the server is to running out of memory.

### Cause
The local manager's `isAlive()` asked Chrome's `/json/version` endpoint exactly once with a 2-second
ceiling, and every caller turned a negative into a destructive action: kill the browser, start a new
one, and replay the login. Two seconds is generous for an idle server and far too little for one that
is swapping, so healthy-but-slow browsers were killed. Each restart added load, which made the next
probe more likely to time out — a restart storm that amplified the original resource shortage.

### Fix
The verdict is now asymmetric. A positive answer on the first fast probe is accepted immediately, so
the per-step cost is unchanged. A negative is confirmed by two further probes with a longer ceiling
before Chrome is declared dead. A closed port still refuses instantly, so a genuinely dead Chrome is
confirmed in about a second; only the ambiguous "port open, answer late" case pays the longer waits.

### What to look for in the logs
A browser that fails the fast probe but answers a confirmation probe now logs:

    isAlive(<port>): no answer within 2 s but answered on confirmation attempt N - Chrome is SLOW,
    not dead. Not restarting it. Check server memory/CPU load.

This line is the earliest visible warning that the server is running short on memory. Previously the
same condition was invisible, because the browser was simply killed and replaced. If it appears
repeatedly across lanes, reduce `PARALLEL.MAX_WORKERS` or free memory on the server before the next
nightly run.

### Parallel run fails with "port is used by a foreign process, refusing to kill it"

**Symptom.** One or more lanes of a scheduled parallel run die at Chrome launch. The lane log
contains a message from the local browser manager saying the remote-debugging port is held by a process it
does not recognise as its own, and that it refuses to kill it. The rest of the run continues on
the remaining lanes, so the run ends with failures but no crash.

**Cause.** The coordinator used to only *probe* a port before handing it to a lane: it opened a
socket to it and, if nothing answered, considered the port free. But the port is not bound at
that moment - Chrome binds it seconds later, after the lane config is written, the worker process
is spawned, Behat initialises and the local manager launches the browser. Any other run that probes
the same port inside that window also sees it as free and takes it as well. Whichever browser
binds first wins the port; the other lane finds a browser it does not own sitting on its port and
stops, by design, rather than killing someone else's Chrome.

This could happen when two parallel runs overlapped (for example a scheduled run still finishing
while the next one started), or when two projects on the same server ended up on the same port
band because neither had a `bdt_parallel.yml` override and both fell back to the same app-config
default.

**Fix.** The coordinator no longer just probes: it now *reserves* each lane's port with a
cross-process lock file (one small file per port, under the installation's port-lock folder), the
same mechanism the interactive run already used. A port is handed out to at most one run at a
time, and it stays reserved from the moment the lane picks it until the end of the run - long
after Chrome has bound it. The reservation is released in the run's close-out, after the lane
Chrome processes have been cleaned up, so the next run never inherits a port that is still being
torn down. If a run crashes or is killed, the operating system drops its locks automatically, so
no port is ever stranded.

**What you may notice.**
- A lane setup line in the coordinator diagnostic log now reads `lane N ready on port P (reserved)`.
- Small `port_<number>.lock` files accumulate in the port-lock folder, one per port in the band.
  They are left behind on purpose and are reused; an existing file does NOT mean the port is busy.
  Do not delete them while runs are in progress.
- If every port in the band is taken, a lane reports the band as exhausted and is skipped. That is
  the same behaviour as before; only the reason can now also be "reserved by another run" rather
  than only "already in use".

**If it still happens.** Check that the projects sharing the server do not share a port band:
give each one a `port_band` entry in its `bdt_parallel.yml` next to `behat.yml`. Also make sure
the accounts that run tests can all write to the port-lock folder - the scheduled fleet and an
interactive run typically run under different Windows accounts, and a lock file one account
cannot open is skipped, which shrinks the usable band.

## "Cannot open path ... in browser after 3 attempts" while the browser is alive and logged in

**Symptom**
A step fails with `Cannot open path "<page>" in browser after 3 attempts`, but the screenshot
attached to the failed step shows the application open and the user still logged in.

**Cause**
Chrome itself was healthy; only the WebSocket (CDP) connection of that lane's Mink session had
dropped. All three retries were sent over the same dead socket, so they could not succeed. Two
different situations produced the identical message: a broken navigation, and a broken wait that
runs immediately before the navigation.

**Fix**
- When Chrome still answers its debug port, the session is now reattached to the running process
  instead of retrying over the dead socket. Chrome keeps its cookies, so no re-login happens.
- The tab left behind by the disconnected session is now closed over Chrome's HTTP debug endpoint.
  Previously each reattach leaked one tab, adding to the memory pressure that makes lanes fail.
- The error message now names the failing phase (`pre-navigation wait` or `navigation`) and lists
  what recovery was attempted on each round, including whether a reattach failed.

**What to check when it still happens**
Read the `Failing phase` and `Recovery` parts of the message:
- `Recovery: ... session reattach FAILED` — Chrome answers its debug port but refuses new tabs.
  Usually memory pressure: check the Chrome process count and free memory on the server.

## Process-host selection differs between cleanup paths

### Symptom
If OS-specific process handling is implemented separately by an action and the browser manager,
the same launch or profile cleanup can behave differently depending on which path invokes it.

### Cause
The process-host accessor was duplicated in `RunParallel`, `RunTest`, and the Chrome manager.
Those copies selected the same host today, but future changes could make cleanup and launch diverge.

### Fix
`ChromeProfileReaperTrait` now owns the single lazy process-host accessor. `RunParallel`, `RunTest`,
and `LocalChromeManager` use that shared implementation; OS selection remains in `ProcessHostFactory`.

### What to check when it happens
Keep process-host construction in `ProcessHostFactory` and use the trait accessor for every class
that composes `ChromeProfileReaperTrait`. A separate `getProcessHost()` implementation is a sign
that the shared process lifecycle is starting to drift again.
- `Failing phase: pre-navigation wait` — the previous page never settled. The navigation itself was
  never attempted, so look at the step BEFORE this one.
- `Recovery: ... Chrome not reachable, restart requested` — the process died; see the Chrome
  process management sections above.

## RunParallel stops with "does not support parallel lanes"

**Symptom:** RunParallel fails immediately, before any lane starts, with:
`The configured browser manager (...RemoteChromeManager) does not support parallel lanes.`

**Cause:** The browser is configured as a remote browser (for example Chrome in an Azure container). A remote endpoint is a single browser. Parallel lanes would all share it, close each other's tabs and log each other out. Earlier versions did not check this and failed later in confusing ways.

**What to do:**
- For a remote browser, use RunTest. It runs one test session against the remote endpoint.
- For parallel runs, set `BROWSER.MANAGER` to `LocalChromeManager` and fill `BROWSER.MANAGER_CONFIG` (at least `executable` and the port bands).

## Browser settings moved to BROWSER.MANAGER / BROWSER.MANAGER_CONFIG

**Symptom:** The log shows a deprecation warning about `PARALLEL.CHROME_*` or `REMOTE.*` keys or about the `chrome:` section in behat.yml, or a run fails with `No setter method found for UXON property "..."`.

**Cause:** Browser settings are now configured like the metamodel connector: `BROWSER.MANAGER` selects the browser type, `BROWSER.MANAGER_CONFIG` holds its settings. Each browser type accepts only the settings it understands. A remote browser, for example, has no port bands.

**What to do:**
- Move the old values into `BROWSER.MANAGER_CONFIG`:
  - `PARALLEL.CHROME_PATH` → `executable`
  - `PARALLEL.CHROME_HEADLESS` → `headless`
  - `PARALLEL.PORT_BAND_INTERACTIVE` / `PARALLEL.PORT_BAND_SCHEDULED` → `port_band_interactive` / `port_band_scheduled`
  - `REMOTE.CHROME_ENDPOINT` → `endpoint`
  - `REMOTE.BASE_URL` → `base_url`
- If the error names a property, remove it from `BROWSER.MANAGER_CONFIG`: it does not apply to the selected browser type.
- A remote browser needs both `endpoint` and `base_url`. Without `base_url` the browser in the container would try to open the application under the server's own URL, which it usually cannot reach.

## RunParallel reports that the browser executable is not a file

**Symptom:** RunParallel stops before initialization or lane startup with `Browser executable is not a file`.

**Cause:** The configured executable path is missing or points to a directory. Previously a bad path
could surface as a separate launch failure in each lane. The coordinator now checks it once before
creating the run or starting workers.

**What to do:** Check `BROWSER.MANAGER_CONFIG.executable`, the legacy `PARALLEL.CHROME_PATH`, or the
`--chrome_path` override. Relative paths are resolved from the installation root; use the actual
`chrome.exe`, not its containing directory.

## Legacy browser settings appear to be ignored after migration

**Symptom:** A run selects local Chrome instead of a configured legacy remote endpoint, or uses the
default executable or headless setting instead of a legacy override.

**Cause:** A shipped default `BROWSER.MANAGER` previously prevented the legacy configuration branch
from running. The factory now checks the installation and user scopes: if neither selects a manager,
legacy local values override local defaults and a legacy remote endpoint selects the remote manager
without importing local settings.

**What to do:** Leave `BROWSER.MANAGER` unset in the installation and user layers while using legacy keys, or explicitly configure the
manager and migrate all its settings to `BROWSER.MANAGER_CONFIG`. A deprecation warning identifies
legacy settings still in use.

## RunParallel banner shows a Chrome port that no lane uses

**Symptom:** The coordinator banner reports `port 9222` while lanes use another port range.

**Cause:** The coordinator does not own a browser port; the normal manager description previously reported
the default driver port in the banner. The banner now uses a port-free coordinator description and displays the resolved scheduled lane band,
including any `bdt_parallel.yml` override, and passes the same band to the fleet.

**What to do:** Compare `lanes on ports` in the banner with the lane port in the worker log. Check
`bdt_parallel.yml` and `BROWSER.MANAGER_CONFIG.port_band_scheduled` if the band is unexpected.
The coordinator reference now declares the parallel-lane capability as well as the browser interface,
so banner calls to the lane-specific description are checked without changing runtime behavior.