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

    C:\wamp\www\nbr\releases\35.02.17+2026...\data\axenox\BDT\chrome_profiles\<run_uid>_lane1

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
`ChromeManager::isOwnLeftover()` use it, so a leftover survives neither a deployment nor a
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

Affected paths: `ChromeManager::stop()`, `RunParallel::cleanupLaneChromes()`,
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
`ChromeManager::isAlive()` asked Chrome's `/json/version` endpoint exactly once with a 2-second
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
contains a message from ChromeManager saying the remote-debugging port is held by a process it
does not recognise as its own, and that it refuses to kill it. The rest of the run continues on
the remaining lanes, so the run ends with failures but no crash.

**Cause.** The coordinator used to only *probe* a port before handing it to a lane: it opened a
socket to it and, if nothing answered, considered the port free. But the port is not bound at
that moment - Chrome binds it seconds later, after the lane config is written, the worker process
is spawned, Behat initialises and ChromeManager launches the browser. Any other run that probes
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
- `Failing phase: pre-navigation wait` — the previous page never settled. The navigation itself was
  never attempted, so look at the step BEFORE this one.
- `Recovery: ... Chrome not reachable, restart requested` — the process died; see the Chrome
  process management sections above.