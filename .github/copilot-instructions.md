# Coding Instructions — axenox/BDT

Conventions for anyone (human or AI assistant) writing code in this repository.
GitHub Copilot: treat this file as binding. When a suggestion conflicts with a rule here, the rule
wins.

<!-- If anything here is unclear, the exface\Core framework (the main/core app) has further base rules: ../../../exface/core -->

---

## 1. Language

* **All code, comments, docblocks, commit messages, task descriptions and documentation are in
  English.** No exceptions, regardless of the language the discussion happened in.
* Log messages and exception messages are English too — they end up in a database read by people who
  did not write the code.

---

## 2. Every function starts with a docblock that explains WHY

Not *what* — the signature and the body already say what. The docblock says **why this function
exists at all**, and when the answer is non-obvious, **what went wrong that made it necessary**.

```php
/**
 * Builds the absolute profile dir of one lane of one run - the single source of truth for the path.
 *
 * WHY A HELPER: the path was previously rebuilt by hand in three places. When the dir became
 * run-scoped, only one of them was updated; the other two matched nothing, killed nothing and -
 * because removing a non-existent dir reports success - reported success while doing nothing.
 * Every Chrome tree leaked from that point on. Routing all call sites through this method makes
 * that class of drift impossible.
 *
 * @param string $workingDir Installation root
 * @param string $runUid     UID of the run owning the lane
 * @param int    $lane       Lane number
 * @return string Absolute profile dir path
 */
private function laneProfileDir(string $workingDir, string $runUid, int $lane): string
```

Rules of thumb:

* A defensive guard, a `try`/`catch`, an ordering constraint or a "never throws" promise **must**
  state the failure it protects against. Otherwise the next reader deletes it as noise.
* If a decision has a rejected alternative, name it: *"WHY X AND NOT Y"*.
* Non-obvious ordering gets a comment at the call site too (e.g. "resources before logging").

---

## 3. Exceptions

```php
use exface\Core\Exceptions\RuntimeException;   // ✅ always import

throw new RuntimeException('...');             // ✅
throw new \RuntimeException('...');            // ❌ never PHP's global one
```

* Never throw PHP's global `\RuntimeException`. Import the exface one and throw it without a leading
  backslash.
* Catching `\Throwable` is fine — that is the catch-all type. Only the *throw* side is restricted.

---

## 4. Comments about Behat hooks

Write hook names in plain words, never in annotation form:

```php
// ✅ ...caught in the AfterStep hook
// ❌ ...caught in @afterStep
```

Writing `@afterStep` inside a comment makes it look like the function is bound to that hook.

---

## 5. Reuse before you write

**Before adding any helper, check whether one already exists.** Duplicated helpers are how this
codebase produced real production bugs (two constructions of the same path silently drifted apart).

Do this check for **every file you touch**, not only the one you are changing.

Existing shared building blocks — use them instead of reimplementing:

| Concern | Use |
|---|---|
| Chrome port band + free-port probing | `PortProbingTrait` |
| Killing orphan Chrome / removing profile dirs / stale sweeps | `ChromeProfileReaperTrait` |
| Retrying a DB write the server chose as a deadlock victim | `DeadlockRetryTrait` |
| Creating / counting / finalizing the run row | `RunRecordWriter` |
| Lane profile directory path | `RunParallel::laneProfileDir()` |
| Feature key normalization | `RunParallel::featureKeyFromPath()` |
| Path normalization | `FilePathDataType::normalize()` |
| Substring helpers | `StringDataType` |
| Structured log output | `MarkdownLogBook` |
| Date/time values for the DB | `DateTimeDataType::now()` / `DATETIME_FORMAT_INTERNAL` |

If two call sites need the same value, they must call **one** function — never build it twice.

---

## 6. Fix root causes, never mask symptoms

* No `try`/`catch` that swallows an error to make a symptom disappear. Every catch either
  **re-throws**, or is a documented boundary (housekeeping, diagnostics, a hook that must not kill
  the process) and says so in a comment.
* No guard that hides a wrong state instead of fixing why the state is wrong.
* Prefer failing loudly over degrading silently. A silently-wrong green test run is the worst
  possible outcome in this project.
* Apply changes **incrementally, one hypothesis at a time**, and validate before layering the next
  one. Do not reach for infrastructure-level changes (e.g. RCSI) until narrower fixes have provably
  failed.
* **Get the actual log before committing to a fix.** More than one confident hypothesis here was
  fully eliminated by a real stack trace.

---

## 7. Behat hooks must never throw

`BeforeStep`, `AfterStep`, `BeforeScenario`, `AfterScenario`, `BeforeFeature`, `AfterFeature` and
friends must catch `\Throwable` internally. An uncaught exception from a hook kills the process with
exit code 255 and loses the entire feature run.

Corollary: because `DatabaseFormatter` catches everything in its hooks, **a database write failure
looks exactly like a successful run with empty rows**. Whenever you add a write there, make sure the
failure is at least logged somewhere that does not depend on the database.

---

## 8. Resources before logging

```php
$process->stop(0);          // 1. free the OS resource
$this->reapLaneProfile(...) // 2. reclaim disk / kill orphan Chrome
$this->writeRunLog(...)     // 3. file-based log (no DB dependency)
$logger->error(...);        // 4. DB-backed logging LAST, and guarded
```

The workbench logger writes to a database that may be exactly what is broken. Anything after a
failing logger call is skipped — which is how orphaned Chrome trees leaked from every timed-out lane
for months. **Freeing OS resources must never be gated on our ability to record that we did.**

Same rule for log handles: **close them last**. Anything that happens after `fclose()` is invisible
by construction.

---

## 9. Database access

* Use the DataSheet API — no hand-written SQL.
* **Always set an explicit row limit** when you only need N rows (`setRowsLimit(1)`), and
  **`setAutoCount(false)`** when the total is not used. An unlimited read that gets silently
  truncated by a default page size is a whole class of invisible bug.
* Use **`getAliasWithRelationPath()`**, not `getAlias()`, in sorter and return-column logic —
  `getAlias()` silently fails when the attribute sits behind a relation.
* Resolve the effective attribute (including `text_attribute_alias` for `InputComboTable`) **before**
  any column lookup or data source read.
* Wrap writes that can race other processes in `DeadlockRetryTrait::runWithDeadlockRetry()`. A
  deadlock victim has a rolled-back transaction and *must* be re-run; everything else must
  propagate untouched.
* Never compare a DB timestamp to the local clock. The database server and the test server are
  different machines — clock skew turns "no row in the last N seconds" into a silent lie. Compare a
  previous DB value to a current DB value instead.
* MS SQL is production, MySQL is local dev. Dialect differences matter (bracket quoting,
  `DATEDIFF` vs `TIMESTAMPDIFF`) — do not assume the local behaviour is the deployed one.

---

## 10. Widgets and nodes (UI5 test nodes)

* For widgets that must exist in the PowerUI model — e.g. all dialog content — iterate the **widget
  model**, not the DOM. The model is the principled source of children; DOM-first queries drift.
* Do not trigger actions while merely *checking* a widget. Skip action-triggering children.
* Column lookup comes **before** any data source read.

---

## 11. Environment

* **Windows.** All shell commands use CMD/PowerShell syntax. Bash/Unix commands fail here.
  `findstr` not `grep`; `Get-CimInstance` for process inspection.
* Paths: normalize separators explicitly rather than relying on PHP's platform-dependent behaviour
  (`basename()` only treats `\` as a separator when PHP itself runs on Windows).
* Windows silently strips trailing dots from file names, and enforces a total path length limit —
  sanitize and bound anything used as a file name.
* In single-quoted YAML a backslash is a **literal character**. Do not double it — the only escape
  is `''` for a quote. Doubling changed real values and silently broke every string comparison
  downstream.

---

## 12. Security

* Never interpolate an operator-supplied value into a shell string without validating it. This
  action is reachable from a web UI by users far less privileged than the account the fleet runs
  under.
* **Refuse, do not escape.** Windows `cmd` quoting is parsed differently by `cmd`, by the `.bat`
  stub and by the PHP process that finally receives it — any escaping scheme mangles some legitimate
  inputs while still leaving gaps. Refusing loudly is both safe and lossless.

---

## 13. Working style

* **Investigate before implementing.** Read the actual source file. Never invent a method signature
  or a class structure — verify it first.
* **Deliver only the changed code blocks**, never a full file repost. The file may have been edited
  concurrently. (Exception: when the full file is explicitly requested.)
* **Proactively flag bugs and vulnerabilities you notice**, even when unrelated to the current task.
  Report them; do not fix them unasked.
* **When writing a task**: describe only *what the problem was* and *what action was decided*. Do
  not enumerate the specific code changes or the files to modify. Tasks are written in English.
* **Error log workflow**: classify errors as framework-owned vs. scenario/application-level, group
  by root-cause family, and fix one family at a time. Scenario errors belong to the tester.

---

## 14. Documentation duty

Any fix touching the `RunParallel` action **must** be added to `TROUBLESHOOTING.md`, written so that
someone who has never seen the code can understand it:

* what the observable symptom was,
* what the actual root cause turned out to be,
* what was changed, and
* how to recognize the same problem next time.

Structural changes (new phase, new invariant, new config key) also go into `RUN_PARALLEL.md`.

---

## 15. Quick checklist before submitting a change

- [ ] Every new/changed function has a WHY docblock.
- [ ] All comments and messages are in English.
- [ ] `RuntimeException` imported from `exface\Core\Exceptions`, thrown without a backslash.
- [ ] No hook name written in `@annotation` form inside a comment.
- [ ] No new helper that duplicates an existing one — checked the whole file, not just the diff.
- [ ] No `catch` that hides a problem; every catch re-throws or documents why it must not.
- [ ] Every data read has an explicit limit / `setAutoCount(false)` where appropriate.
- [ ] Resource cleanup happens before any DB-backed logging.
- [ ] Log handles closed after all work, not before.
- [ ] `TROUBLESHOOTING.md` updated if this touched `RunParallel`.
