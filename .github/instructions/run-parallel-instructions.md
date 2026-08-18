# RunParallel — Parallel BDT Test Execution

`axenox\BDT\Actions\RunParallel` is the **coordinator** that runs the whole Behat test base in
parallel and records the result as one single, observable run in the database.

This document explains what it does, why it is built the way it is, and how to read its output.
It is written for anyone who has to operate, debug or extend the parallel runner — no prior
knowledge of the code is assumed.

---

## 1. The one-paragraph version

You start one command (`vendor/bin/action axenox.BDT:RunParallel --tags=@Status::Ready`).
The coordinator finds every feature file that matches the scope, opens **one run row** in the
database, and then hands those features out — one feature per Behat process — to a fixed number of
**lanes** running side by side. Each lane has its own Chrome port, its own Chrome profile directory
and its own test user. When a lane finishes a feature, it immediately takes the next one from the
queue. When everything has run, the coordinator closes the run row with a timestamp, a duration and
a Markdown log. All per-scenario and per-step results are written by the workers themselves,
directly under the same run UID.

---

## 2. Vocabulary

| Term | Meaning |
|---|---|
| **Coordinator** | The `RunParallel` PHP process. Owns the queue and the run row. Runs no tests itself. |
| **Lane** | A durable execution *slot*: fixed lane number, fixed Chrome port, fixed generated config file, fixed profile directory. A lane lives for the whole run. |
| **Worker** | One `vendor\bin\behat` process. Executes **exactly one feature file**, then exits. A lane runs many workers over the course of a run. |
| **Queue** | The ordered, in-memory list of all matched feature files. Only the coordinator touches it. |
| **Attach-mode** | The mode a worker's `DatabaseFormatter` runs in when it was given a `run_uid`: it writes children (`run_feature` / `run_scenario` / `run_step`) under the existing run, and never creates, counts or finalizes the run row itself. |
| **Run row** | One `axenox.BDT.run` record. Created and closed by the coordinator, and only by the coordinator. |

---

## 3. Architecture at a glance

```
                       vendor/bin/action axenox.BDT:RunParallel --tags=...
                                          |
                         +----------------v-----------------+
                         |          COORDINATOR             |
                         |  - Behat init                    |
                         |  - resolve scope & config        |
                         |  - CREATE run row  --------------+---> axenox.BDT.run  (run_uid)
                         |  - expected feature/scenario cnt |
                         |  - build ONE queue of features   |
                         |  - drain loop (poll all lanes)   |
                         |  - FINALIZE run row + run log    |
                         +--+---------+---------+-----------+
                            |         |         |
              lane 1        |  lane 2 |  lane 3 |  lane 4        (durable slots)
        port 9301           |  9302   |  9303   |  9304
        behat_scheduled_lane1.yml     ...
                            |         |         |
                        +---v---+ +---v---+ +---v---+
                        |behat  | |behat  | |behat  |   <- one worker = ONE feature file
                        |worker | |worker | |worker |
                        +---+---+ +---+---+ +---+---+
                            |         |         |
                        Chrome    Chrome    Chrome      (own profile dir per run+lane)
                            |         |         |
                            +---------+---------+
                                      |
                            run_feature / run_scenario / run_step
                            (written by the workers, under run_uid)
```

---

## 4. Why a queue and not fixed buckets

The earlier design split the features into N disjoint buckets up front and gave each bucket to one
long-lived Behat process. That had two structural defects:

1. **Silent loss.** If a lane was killed (hang or timeout), every remaining feature in its bucket
   was simply never executed and produced no database row at all. The only trace was
   "expected > actual" — exactly the silent shortfall this framework exists to prevent.
2. **No rebalancing.** A lane that drew light features finished early and idled while another lane
   still had heavy work. Wall-clock time was dictated by the unluckiest bucket.

The queue fixes both:

* All matched features live in **one ordered queue owned by the coordinator**.
* A lane executes **one feature per Behat process**. When that process exits, the lane pulls the
  next feature from the queue.
* Work therefore flows to whichever lane is free — no lane idles while work remains.

**Why the queue needs no locking:** the coordinator is the only process that ever reads or writes
it. Workers receive a single feature on the command line and know nothing about the queue. There is
no claim protocol to get wrong and no new contention point in a system that already fights
optimistic-locking conflicts.

**Why one feature per process:** the process boundary carries the bookkeeping. The feature a lane
was executing when it was killed is unambiguous (there is exactly one), so it can be recorded as
failed instead of vanishing, and the untouched rest of the queue keeps flowing.

---

## 5. Full run lifecycle

### Phase 0 — Input and scope

* Options: `--tags`, `--feature`, `--suite`, `--behat_config`, `--chrome_path`.
* **At least one of `--tags`, `--feature`, `--suite` is mandatory.** A completely unscoped
  invocation would run the entire test base — the "looks green but ran the wrong thing" footgun.
* `--feature` and `--suite` are mutually exclusive (same question, two ways → loud failure instead
  of a hidden precedence rule).

### Phase 1 — Behat init

`Behat init` runs first, blocking. It rewrites the installation-root `behat.yml`, registers app
suites and refreshes `base_url` to the live workbench URL. Lanes never init themselves, so a stale
`base_url` here would break every worker.

Only after init are `behat_config`, `chrome_path` and the scan roots resolved — each with its own
loud validation. Defaulting is allowed; guessing is not.

### Phase 2 — Open the run row

`RunRecordWriter::create()` inserts the `axenox.BDT.run` row. From this moment on there is a
`run_uid` that every worker will attach to. `behat_command` stores the reconstructed **coordinator**
invocation (not a behat command — a parallel run has N of those).

The living run log (a `MarkdownLogBook`) is opened right here, so even a failure *before* the fleet
launches still ends up in the database.

### Phase 3 — Reclaim leftovers from earlier runs

Two sweeps, both needed:

* **Identity-based:** profile directories are named `<run_uid>_laneN`. An unfinished run whose
  `started_on` is older than `start + max_runtime + margin` has provably either finalized or been
  killed by the launcher, so it can no longer own a live browser and its directory is reclaimed
  immediately. When the coordinator lifetime is **unknown** (the launcher env var is absent), this sweep
  is skipped entirely — nothing can be proven dead. See §7b.
* **Age-based (6 h):** for everything that cannot be attributed (interactive profiles,
  half-deleted leftovers).

### Phase 4 — Expected counts

`ExpectedTestCountCalculator` scans the resolved roots with the tag filter and produces
`expected_feature_count` / `expected_scenario_count`, written onto the run row.

This is done **by the coordinator, up front, over all matched features** because:

* attach-mode workers deliberately skip it, and
* the expected totals must cover everything that *should* have run, even if a worker later dies —
  otherwise silent-stop detection is impossible.

A feature file that fails to parse aborts the run **here**, loudly, instead of letting a worker die
later with an opaque exit code 255.

If nothing matched: the run is finalized cleanly and reports "nothing to run". It is never allowed
to fall through to a worker with no feature path — Behat would then run the entire suite.

### Phase 5 — Fleet size and banner

```
workerCount = max(1, min(PARALLEL.MAX_WORKERS, number of matched features))
```

The banner printed before anything starts states the lane count *and the reason for it*, the Chrome
visibility, and whether a debugger is attached. It is purely informational, and it is echoed again
in the final message and into the run log, so the expectation the user saw at the start is the one
they see at the end.

### Phase 6 — Lane setup (once per run)

For each lane:

1. Allocate a free port from the band (probe: bound = busy, refused = free).
2. Write `behat_scheduled_lane<N>.yml` next to the base config.
3. Create the profile directory `data/axenox/BDT/chrome_profiles/<run_uid>_lane<N>`.

A lane that cannot be set up is simply not opened — the queue redistributes over the lanes that
work. **Only losing all lanes is fatal**, and then every queued feature is recorded as
"never started".

The generated lane config imports the base `behat.yml` and only adds the per-lane overrides:

```yaml
imports:
  - behat.yml
default:
  extensions:
    Behat\MinkExtension:
      sessions:
        CHROME_DEBUG_API:
          chrome:
            api_url: 'http://localhost:9301'
    axenox\BDT\Behat\DatabaseFormatter\DatabaseFormatterExtension:
      run_uid: '<run uid>'
      lane_id: '<run uid>_lane1'
      chrome:
        port: 9301
        executable: 'C:\...\chrome.exe'
        user_data_dir: 'data/axenox/BDT/chrome_profiles/<run_uid>_lane1'
```

> Configuration reaches the workers **only through this YAML file**. `BEHAT_PARAMS` environment
> overrides do *not* reach extension `load()` methods — this was verified empirically.

### Phase 7 — Fill and drain

The main loop runs while any worker is alive **or** work can still be dispatched:

1. **Fill:** every idle lane takes the next feature from the queue. The profile directory is
   recreated (the previous feature's was deleted), the worker log is opened *before* `start()`, and
   the process is launched with `Process::fromShellCommandline()`.
2. **Poll (every 100 ms):** each running lane's incremental stdout/stderr is streamed into its own
   log file and the buffers cleared, so a long run never accumulates output in coordinator memory.
3. **Heartbeat (every 60 s):** the coordinator reads a *marker* identifying the newest `run_step`
   row per feature (see §7).
4. **Judge:** idle timeout, then total wall-clock timeout, then exit-code classification.
5. **Refill:** a released slot is refilled **in the same iteration**, not on the next poll tick.

When the loop ends, anything still in the queue (possible only if every lane was retired) is
recorded explicitly as "never started".

### Phase 8 — Close-out

In `finally`: kill any orphaned Chrome tree belonging to this run and remove its profile dirs.
Cleanup is guarded so it can never propagate an exception — housekeeping must not mask the run
outcome.

Then, in one sequence, each step announced in the coordinator log *before* it runs:

1. Stage the run log digest onto the run sheet.
2. `finalize()` — one `dataUpdate` carrying `finished_on`, `duration_ms` and the log.
3. Close the coordinator log **after** all of the above.

> The ordering here is not cosmetic. A run once ended with `finished_on = NULL`, an empty log and no
> diagnostic trace — because the log handle had been closed one statement before the phase that
> failed. Everything after the drain was, by construction, invisible.

Finally: re-throw a coordinator error if there was one; otherwise throw if any worker failed
(so a scheduled task marks the run red); otherwise return a success message.

---

## 6. Failure classification — what counts as a failure

| Situation | Worker exit | Treated as |
|---|---|---|
| All tests passed | `0` | ✅ normal completion |
| **Some tests failed** | `1` | ✅ **normal completion** — not a worker failure |
| Crash / fatal error | `2`, `255`, … | ❌ worker failure |
| Killed by timeout / taskkill | `null` (no exit code) | ❌ worker failure |
| Launch failed (config, profile dir, …) | — | ❌ worker failure, **lane retired** |
| Never dispatched (all lanes retired) | — | ❌ recorded as "never started" |

**Exit 1 is deliberately not a failure.** Authoritative per-scenario pass/fail lives in the
attach-mode child rows. A worker that ran to completion did its job, even if the tests it ran were
red. "Did the fleet work?" and "did the tests pass?" are two different questions with two different
answers in two different places.

**Every feature leaves a trace.** One that runs produces child rows; one whose worker dies is
recorded as a worker failure naming that exact feature; one that never got a lane is recorded as
never started. Nothing is dropped — that is the entire point of the queue.

### Poison-feature policy

A feature killed by a timeout is **never requeued**. Re-serving it to the next free lane would let
one pathological feature hang every lane in turn and consume the whole run. It is recorded as failed
exactly once, and the run continues without it.

### Lane retirement

After a timeout we cannot tell whether the *feature* hung or the *lane* is broken (Chrome never
comes up on that port, profile dir cannot be cleared). So:

* An isolated bad feature → lane returns to the pool, next feature resets the counter.
* `LANE_MAX_CONSECUTIVE_TIMEOUTS` (2) consecutive timeouts → the lane is **retired** and takes no
  more work. Remaining features flow to lanes that still function.

---

## 7. The two timeouts, and why the heartbeat reads the database

### Total (wall-clock) timeout — `PARALLEL.WORKER_TIMEOUT_SECONDS`, default 1800 s

Enforced by Symfony `Process` via `checkTimeout()`. Fires **even while the worker is making
progress**. Because a worker now runs exactly one feature, this bounds a single feature — it names
the one feature that hung instead of aborting everything a lane had left to do. Set to `0` to
disable.

### Idle timeout — `PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS`, default 600 s

**Deliberately not Symfony's `setIdleTimeout()`.** Symfony's idle timer resets only on process
*output*. But a long `works as expected` step emits **no stdout at all** while it runs — it only
keeps INSERTing one `run_step` row per substep. An output-only idle timeout would therefore kill a
lane that is actually progressing perfectly.

So the coordinator implements idleness itself, and treats **either** signal as progress:

* new console output from that lane, **or**
* a new `run_step` row for the feature *that lane is currently executing*.

The DB half is scoped to the lane's own feature on purpose: a fleet-wide signal would be advanced by
healthy sibling lanes and would keep a genuinely hung lane alive forever.

### Why a marker and not a count

The drain loop only ever asks one question: *"did this lane produce anything new since the last
poll?"* A count answers that by reading every step row of the run on every poll — thousands of rows,
growing all run long, holding shared locks on exactly the tables the workers are inserting into. A
**marker** (`created_on | step_uid` of the newest row) answers the same question from one row per
feature. The count was never used as a number anywhere.

Two further properties matter:

* The UID is part of the sort key because `created_on` alone is not deterministic — two rows in the
  same second have no defined order, and consecutive polls could alternate between them and report
  progress that never happened. Since UIDs are not lexicographically ordered, the marker is compared
  for **change**, never for growth.
* `created_on` is **never** compared to the coordinator's clock. The database server and the test
  server are different machines; any "no row in the last N seconds" arithmetic would silently
  absorb their clock skew.

### Failure direction

A stalled count reads as "no progress" and kills a working lane. An imprecise marker reads as "still
alive", and the worst case of a falsely-alive lane is that the wall-clock ceiling catches it
instead — far cheaper than deleting a healthy feature run.

---

## 7b. The coordinator lifetime — `EXFACE_TASK_TIMEOUT_SECONDS`

The two worker timeouts above bound a single **feature**. Neither bounds the **whole run** — that
ceiling is external: it is the launcher timeout enforced by Symfony `Process` in Core's
`CliCommandRunner`, fed by the scheduler task's `command_timeout` (see `18_SCHEDULER.json`, currently
`3 hours` = 10800 s). When that timeout expires the coordinator process is hard-killed wherever it
happens to be — for a long run, inside the fleet drain — leaving the run row with `finished_on` NULL,
no duration and no log even though hours of results were produced, indistinguishable from a traceless
crash.

The launched action is a fresh OS process, so the authoritative number must reach it from outside.
There is exactly **one** source, because there is exactly one place that enforces it: Core's
`CliTaskQueue` sets the environment variable **`EXFACE_TASK_TIMEOUT_SECONDS`** to the exact timeout it
computed and will enforce on this process. It is not a copy of the ceiling, it **is** the ceiling, so it
can never drift.

There is deliberately **no** CLI option and **no** config key for this number. Either would be a human
copy of a value owned elsewhere and would silently go stale. Worse, a run started by hand has no
external ceiling at all, so a hand-written lifetime would only make the coordinator cut itself short
against a deadline nobody enforces — a deadline with no enforcer is not a deadline.

When the env var is absent, `resolveMaxRuntime()` returns **NULL**. NULL means "unbounded in practice"
(nothing kills this process), not "unlimited by design": every consumer must refuse to conclude anything
from it — no self-imposed deadline, and the identity-based orphan sweep is skipped. A present-but-non-
positive value is refused loudly.

Given the lifetime, the coordinator sets a **self-imposed deadline** measured from the very start of
`perform()` (not from run-row creation — `Behat init` alone can take minutes): at
`start + max_runtime − CLOSEOUT_BUDGET_SECONDS` it stops dispatching new features and kills its running
lanes, then finalizes the run normally. The reserved close-out budget means the launcher's hard kill
can no longer land inside the finalize. Features killed or never dispatched at the deadline are recorded
as failures (naming the deadline), so a suite that outgrew its window surfaces red instead of silently
truncating. A deadline kill does **not** count towards a lane's consecutive-timeout strike count.

The lifetime the coordinator was given is logged at startup and printed in the startup banner (mirrored
into the run log), so the granted number is readable straight from the run record.

The same lifetime also governs the orphan-profile sweep (§5, Phase 3): past `start + max_runtime + margin` an
unfinished run has provably either finalized or been killed, so it can no longer own a live browser.
When the lifetime is unknown the identity sweep is skipped entirely — nothing bounds an unfinished run,
so nothing can be proven dead, and only the age-based (mtime) sweep reclaims anything.

---

## 8. Isolation: ports, profiles, users, debugger

### Chrome ports

Resolved from a **band**, per execution path:

1. `port_band` in `bdt_parallel.yml` next to the base `behat.yml` (per project), else
2. app config `PARALLEL.PORT_BAND_SCHEDULED`.

A key that is present but malformed fails loudly — a typo must never silently fall back to a default
band and reintroduce the very collision the band exists to prevent.

The band is only a *search window*. Real safety is layered: the in-run held-list prevents two lanes
getting the same port; the probe skips bound ports; and the residual probe→bind race is caught
loudly by `ChromeManager`'s foreign-process guard rather than by silently killing someone else's
Chrome.

### Chrome profile directories

`data/axenox/BDT/chrome_profiles/<run_uid>_lane<N>` — **run-scoped, not lane-scoped.**

Why: the scheduled fleet runs as `NT AUTHORITY\SYSTEM` while interactive/web runs run as a different
account. With a fixed `laneN` name, a later run would open a profile created by a *different Windows
account*. Chrome then cannot decrypt that profile's DPAPI-protected state and cannot acquire the
per-profile `ProcessSingleton` lock (Windows sharing violation, error 32) — it aborts on launch and
every login fails.

The directory is **deleted after every feature run and recreated before the next one**, so the next
feature in that slot cannot inherit a locked `ProcessSingleton` file or a half-written profile.

The path is built by **one** helper (`laneProfileDir()`). This is not style: when the directory
became run-scoped, only the writer was updated and the two reapers kept building the old fixed
`laneN` name. They matched nothing, killed nothing, and — because removing a non-existent directory
reports success — reported success while silently doing nothing. Every Chrome tree leaked from that
point on.

### Test users

Each lane gets a `lane_id` (`<run_uid>_lane<N>`) through the lane config. `setupUser()` uses it to
namespace the provisioned test user, so two workers running scenarios that resolve to the same role
do not collide on a shared `USER_AUTHENTICATOR` row. A worker started with a `run_uid` but **no**
`lane_id` fails loudly at startup — a broken lane_id contract must not degrade into optimistic-lock
errors halfway through the night.

### Xdebug

Every worker is started with `XDEBUG_MODE=off` and `XDEBUG_SESSION` / `XDEBUG_TRIGGER` removed.

This is not a nicety. A coordinator launched under an IDE debugger passes its Xdebug trigger to
every worker; they all connect back to the single IDE debug client on port 9003, which services only
a couple of sessions at a time. The 3rd and 4th worker then block **silently, producing no output**,
until an earlier worker exits. That — not the worker count, not the port band, not `Process::start()`
— was the real cause of the "concurrency is capped at 2" symptom.

**Consequence: you cannot step through a parallel run.** Use the non-parallel single-worker path.

---

## 9. Where everything is written

### On disk

```
data/axenox/BDT/Logs/<YYYYMMDD>/<run_uid>/
    coordinator.log                        <- the fleet timeline (DIAG lines)
    lane1_1_Login.log                      <- one file per FEATURE RUN
    lane2_2_OrderCreate.log
    lane1_3_OrderEdit.log
    ...
data/axenox/BDT/chrome_profiles/<run_uid>_lane<N>/     (removed after each feature run)
<installation root>/behat_scheduled_lane<N>.yml        (overwritten every run)
```

* The date folder comes from the **run start**, not from `date()` at write time — a nightly run
  starting at 23:55 must not scatter its own logs across two folders.
* The worker log name carries lane + dispatch sequence + feature name, so the directory listing
  alone tells you which file is which without opening anything.

### In the database

| Table | Written by | Contains |
|---|---|---|
| `axenox.BDT.run` | **coordinator only** | started/finished, duration, expected counts, `behat_command`, Markdown run log |
| `axenox.BDT.run_feature` | worker (attach-mode) | one row per feature, `filename` normalized |
| `axenox.BDT.run_scenario` | worker (attach-mode) | one row per scenario/outline |
| `axenox.BDT.run_step` | worker (attach-mode) | one row per step and substep |

The run log stored on the run row is an **orchestration log, not a test report**: run configuration,
per-feature worker status, and Behat's counts block. Per-scenario detail is deliberately excluded —
it already exists authoritatively as child rows, and duplicating it used to fill the whole byte
budget and truncate away the coordinator diagnostics the log exists for.

> The run log answers *"did the fleet work?"*.
> The child rows answer *"did the tests pass?"*.

---

## 10. Configuration reference

### CLI options

| Option | Required | Meaning |
|---|---|---|
| `--tags` | one of three | Behat tag expression, e.g. `@Status::Ready` |
| `--feature` | one of three | Single feature file or directory. Mutually exclusive with `--suite` |
| `--suite` | one of three | Named Behat suite. Mutually exclusive with `--feature` |
| `--behat_config` | no | Base `behat.yml` the lanes import. Defaults to the installation-root file |
| `--chrome_path` | no | Real `chrome.exe`. Defaults to `PARALLEL.CHROME_PATH` |

> The coordinator lifetime is **not** a CLI option — it arrives only via the `EXFACE_TASK_TIMEOUT_SECONDS`
> environment variable the launcher sets (see §7b). There is deliberately no `--max_runtime` option.

### App config (`axenox.BDT`)

| Key | Default | Meaning |
|---|---|---|
| `PARALLEL.MAX_WORKERS` | — (required, ≥1) | Lane ceiling |
| `PARALLEL.PORT_BAND_SCHEDULED` | — | Port search window, e.g. `9301-9400` |
| `PARALLEL.WORKER_TIMEOUT_SECONDS` | 1800 | Total wall-clock ceiling per feature. `0` disables |
| `PARALLEL.WORKER_IDLE_TIMEOUT_SECONDS` | 600 | Idle ceiling per feature. `0` disables |
| `PARALLEL.CHROME_HEADLESS` | unset → headless | Chrome window visibility for the fleet |
| `PARALLEL.CHROME_PATH` | — | Path to the real `chrome.exe` |

> `PARALLEL.CHROME_PATH` is separate from `behat.yml`'s `chrome.executable` **on purpose**: that one
> points at `GoogleChromePortable.exe`, whose single-instance lock is exactly what a fleet must not
> use.

Do not disable both timeouts — a truly hung worker would then block its lane forever.

### Per-project override — `bdt_parallel.yml` (next to `behat.yml`)

```yaml
port_band: 9301-9400              # scheduled fleet
port_band_interactive: 9401-9500  # interactive RunTest
```

Kept separate from `behat.yml` on purpose: `behat.yml` is *worker* config, the bands are
*coordinator* config. Mixing them would let a worker read or break the band.

---

## 11. How to debug a run after the fact

1. **Start at the run row.** Is `finished_on` set? If not, the coordinator died during close-out —
   go straight to `coordinator.log`.
2. **Read the run row's `log` column.** Configuration banner, then one section per feature run with
   worker status and Behat's counts block.
3. **`coordinator.log`** is the fleet timeline. Grep for:
   * `DIAG setup:` — lane/port allocation
   * `DIAG launch:` — dispatch, with queue depth
   * `DIAG drain:` — first output, DB progress, timeouts, retirements, completions
   * `DIAG close-out:` — the three close-out stages
4. **`lane<N>_<seq>_<feature>.log`** is the raw Behat output of one feature run.
5. **Child rows** (`run_feature` / `run_scenario` / `run_step`) are the authoritative test results.

### Reading the silence correctly

* **A quiet coordinator log is not a crash.** It only writes on discrete events. No output means no
  events fired — not that the process died.
* **A run that "completed" with empty DB rows is a DB write failure, not a coordinator death.**
  `DatabaseFormatter` catches all `\Throwable` in its lifecycle hooks, so a failing database write
  lets the run finish normally on disk while leaving the rows empty. The symptom is identical to a
  coordinator death; the logs are what tell them apart.

---

## 12. Design invariants — do not break these

1. **The coordinator is the only writer of the run row.** Workers in attach-mode never create,
   count or finalize it.
2. **Every matched feature leaves a trace** — a child row, a recorded failure, or a "never started"
   entry. Never a silent gap.
3. **Free OS resources before logging anything.** Killing Chrome and removing profile directories
   must never be gated on the ability to record that we did — the workbench logger writes to a
   database that may be exactly what is broken.
4. **Housekeeping never propagates.** Cleanup and diagnostics run inside their own guards; they must
   not mask a run outcome or an original exception.
5. **Close log handles last.** Anything that happens after the log is closed is invisible by
   construction.
6. **Fail loudly instead of guessing.** Missing paths, malformed bands, unparseable features,
   unscoped invocations — all hard failures. A silently-wrong green run is the worst outcome
   possible.
7. **Never build the same path twice.** Profile dirs, feature keys and log paths each have exactly
   one constructing helper.
8. **Feature keys must mirror `DatabaseFormatter::onBeforeFeature()` byte for byte** (forward
   slashes, vendor prefix stripped, lower-cased). A mismatch silently blinds the heartbeat and gets
   healthy lanes killed as idle. The coordinator warns loudly if it sees a key it does not
   recognize.
9. **Shell-interpolated values are refused, not escaped.** Windows `cmd` quoting is genuinely
   ambiguous; a refusal is safe and lossless, an escaping scheme is neither.
10. **Deadlock victims are retried, everything else is re-thrown.** Being chosen as a deadlock victim
    is a documented, expected outcome with a rolled-back transaction — retrying is the only correct
    response. Constraint violations, connection losses and schema errors must still propagate.

---

## 13. Known limitations

* **No cross-run coordination.** Two coordinators started at the same time on the same host rely on
  separate port bands plus the free-port probe, not on a lock.
* **The queue is in memory.** If the coordinator process dies, the remaining queue dies with it.
  That is the deliberate trade for having no claim protocol; the un-run features surface as
  expected-vs-actual on the run row.
* **A retried feature is never retried.** By design (poison-feature policy).
* **No debugging inside the fleet.** See §8.
