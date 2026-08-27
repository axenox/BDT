# RunParallel — Troubleshooting Log

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
