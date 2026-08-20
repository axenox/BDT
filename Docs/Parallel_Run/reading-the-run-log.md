# How to read a BDT parallel run log

This guide explains the text stored in the **Log** attribute of a BDT test run.
You do not need to be a developer to read it. It looks long, but it always has
the same shape, and most of it is just bookkeeping.

---

## 1. What this log is — and what it is not

**It is the report of the test *machinery*.** It answers one question:

> Did the run itself work — did every feature file actually get executed by a browser?

**It is not the test result report.** Whether the individual test steps passed or
failed is stored separately, in the run's scenarios and steps (the child records
of this run, visible in the normal test-run views).

So there are two independent things:

| Question | Where to look |
|---|---|
| Did the run machinery work? | **This log** |
| Did the tests pass? | The run's **scenarios / steps** records |

A run can be perfectly healthy in this log and still have failing tests — that is
a normal, useful outcome. The opposite is the alarming one: this log reporting
that features never ran at all.

---

## 2. The 30-second read

1. Scroll to the top: the **Run summary** tells you how the run was configured and
   whether anything went wrong at run level.
2. Scan down the sections. Each one is one feature file. Look only at the
   `Worker:` line.
   - `Worker: done` → that feature was executed properly. ✅
   - `Worker: failed` → that feature did **not** finish properly. ⚠️
3. If everything says `done`, the machinery worked. Any red tests are real test
   failures and belong in the scenario/step records, not here.

That's it. Everything below is detail for when something says `failed`.

---

## 3. Vocabulary in plain words

| Term | Meaning |
|---|---|
| **Feature** | One test file (e.g. `login.feature`). It contains several scenarios. |
| **Worker** | One browser + one test process, executing exactly one feature. |
| **Lane** | A numbered work station. A lane runs one worker at a time, then picks up the next feature. Lane 2 may run five different features during a run. |
| **Coordinator** | The supervisor process. It starts the lanes, hands out features, watches for hangs, and writes this log. |
| **Queue** | The list of features still waiting for a free lane. |
| **Headless** | Chrome runs invisibly (no window on screen). Normal for automated runs. |
| **Run UID** | The unique ID of this run. Useful when asking someone to look into it. |

Mental picture: a supermarket. Features are customers in one line, lanes are the
checkouts. As soon as a checkout is free, the next customer goes there. Nobody is
pre-assigned to a checkout.

---

## 4. Section by section

### 4.1 "Run summary" — always at the very top

This block is written first and always stays at the top, even when some of its
lines were added late in the run.

**`Run UID: ...`**
The run's identifier.

**Sweep lines (optional)**
Housekeeping notes about leftover Chrome browsers or folders from earlier runs
that were cleaned up. Harmless — informational only.

**`Configuration:`** followed by a block like:

```
===== BDT parallel run configuration =====
Lanes:    4 (limited by PARALLEL.MAX_WORKERS=4)
Features: 27 queued, dispatched one per worker process as lanes free up
Chrome:   headless (auto: CHROME.HEADLESS not set, workers run debugger-off)
Lifetime: 14400 s granted (close-out reserved 120 s; stop dispatching and kill lanes at +14280 s from process start)
Debugger: not attached
==========================================
```

Read it like this:

- **Lanes** — how many features ran at the same time, and why that number. Either
  it hit the configured maximum, or there simply weren't enough features to fill
  all lanes.
- **Features** — how many feature files this run was supposed to execute.
- **Chrome** — whether the browser was invisible (headless) or visible.
- **Lifetime** — how much total time the run was granted before the scheduler
  kills it, and the moment at which the coordinator stops handing out new
  features so it still has time to write its results. `unknown` means no time
  budget was passed in and the run relies purely on the external timeout.
- **Debugger** — normally `not attached`. Only relevant when a developer is
  stepping through code locally.

**Possible additional lines in this section:**

- `No feature files matched the requested scope/tags - nothing to run.`
  → The filter selected zero test files. Nothing failed; nothing ran. Usually a
  wrong tag or a wrong path in the request.
- `coordinator deadline reached at +X s ... stopping dispatch and killing N running lane(s); M feature(s) still queued`
  → **The run ran out of its allotted time.** The features that were still
  running were stopped mid-test and M features never got a turn. This is a
  capacity/time-budget problem, not a broken test. See §6.
- `Coordinator error: ...`
  → The supervisor itself failed. This is the most serious case: the run may have
  executed little or nothing. Hand this to a developer.
- `Generated: ...`
  → Timestamp of when this log was written. Always the last line of the section.

### 4.2 "Fleet" — appears only in a rare failure case

If you see a `Fleet` section saying no lane could be set up, then **no test ran at
all** — the run could not even open its work stations (typically no free network
ports, or a config file could not be written). Nothing in this run's results is
meaningful. This is a server/setup problem for a developer.

### 4.3 One section per feature — the bulk of the log

Each executed feature gets its own section, with a heading like:

```
#7 order_creation.feature (lane 3)
```

- `#7` — this was the 7th feature handed out in this run.
- `order_creation.feature` — the test file.
- `(lane 3)` — which work station executed it.

**Sections are in the order features were *started*, not the order they
finished.** Section #7 may well have finished after section #12.

Inside a section you can find:

| Line | Meaning |
|---|---|
| `Feature: ...` | Full path of the test file. |
| `Lane: 3 (port 9223)` | Work station and its browser port. Only interesting for developers. |
| `Worker: done` | The process ran to the end and reported its results properly. **This does not mean all tests passed.** |
| `Worker: failed` | The process did not finish properly — it crashed, hung, or was stopped. Results for this feature are incomplete. |
| `Worker error: ...` | Only when failed. The reason, in one line. See §5. |
| `Worker log: lane3_7_order_creation.log` | The name of the full, unabridged log file on the server for this one feature. That file has everything; this section is only a digest. |
| `Behat summary:` + a block | The test counts for this feature. |
| `Last output before failure:` + a block | Only when failed. The final lines the process managed to print — the best clue about what it was doing when it died. |

**The Behat summary block** looks like:

```
12 scenarios (10 passed, 2 failed)
84 steps (79 passed, 2 failed, 3 skipped)
2m41.35s (38.4Mb)
```

This is the *test* outcome for that one feature: how many scenarios and steps
passed or failed, and how long it took. This is where you see red tests — not in
the `Worker:` line.

If it says `Behat summary: not reached - the worker never printed a run summary`,
the process was stopped before it could report. That always comes together with
`Worker: failed`.

---

## 5. What each "Worker error" actually means

| Message | In plain words | Typical cause | Who should look |
|---|---|---|---|
| `idle timed out after N s with no output and no new run_step for its feature` | The test froze. For N seconds it printed nothing and recorded no new step. | The browser or the application under test stopped responding; a step waiting for something that never appears. | Test owner / developer |
| `timed out after N s (total wall-clock ceiling)` | The feature was still working, but exceeded the maximum time a single feature is allowed. | A genuinely very long feature, or a slow environment. | Test owner — either split the feature or raise the limit |
| `killed at coordinator deadline before the launcher timeout (feature was still running)` | The feature did nothing wrong; the **whole run** ran out of time and had to stop. | Total workload doesn't fit into the granted lifetime. | Operations — see §6 |
| `never started - coordinator deadline reached before it could be dispatched` | This feature never got a turn: time ran out first. | Same as above. | Operations |
| `never started - all lanes were retired before it could be dispatched` | Every work station had been taken out of service, so nothing could run this feature. | Systemic problem — repeated hangs, or a broken environment. | Developer |
| `launch failed: ...` | The work station could not even start a browser/process for this feature. | Ports, configuration, or file permissions on the server. | Developer / server admin |
| `exit code 2` / `exit code 255` | The test process crashed. | A broken feature file, a PHP error, a bad configuration. | Developer |
| `terminated without exit code` | The process was killed from outside (task manager, server shutdown, out-of-memory). | Server-side interference. | Server admin |
| `Worker log file missing or unreadable` | The digest could not read the feature's own log file. | The log directory was cleaned up, or the disk had a problem. | Developer |

Note: an ordinary "some tests failed" is deliberately **not** a worker error. A
worker that ran all its tests and reported five failures is a *healthy* worker
(`Worker: done`) with failing tests.

---

## 6. "Coordinator deadline reached" — the one worth understanding

Every run is granted a fixed lifetime by the scheduler. The coordinator reserves
the last 120 seconds of that lifetime to close the run down cleanly: write this
log, finish the run record, close the browsers. When it reaches that point:

1. It stops handing out new features.
2. It stops the features still running (they are recorded as failed with
   `killed at coordinator deadline...`).
3. Everything still queued is recorded as `never started`.

**This is a good outcome, not a bug.** The alternative is being hard-killed by
the scheduler mid-write, leaving a run with no log and no result at all.

What it tells you: the workload no longer fits the time window. The remedy is
operational — more lanes, a longer lifetime, splitting the suite, or fixing the
one long-running feature that dominates the total time. Not a test defect.

Small caveat: the deadline line states *how many* features never ran, but not
which ones by name. The names are in the run's error message and in the
coordinator's own log file on the server (§7).

---

## 7. Where the fuller story lives

This log is a deliberately short digest — the run record must stay small. If it
is not enough, three more sources exist on the server, under the run's own log
directory (`data/axenox/BDT/Logs/<run uid>/`):

- **`coordinator.log`** — the supervisor's minute-by-minute diary, in
  chronological order: every launch, every stop, every timeout, with timestamps.
- **`laneN_<seq>_<feature>.log`** — the complete, unabridged output of one
  feature run. This is the file named on the `Worker log:` line.
- **The run's scenario and step records** in the database — the authoritative
  pass/fail detail per test step, with screenshots.

If the log ends with `... (run log truncated at 65536 bytes)`, the digest hit its
size limit. It is cut from the *end*, so the summary and configuration at the top
are always intact; the missing part is the tail of the feature sections. The
files above still have everything.

---

## 8. Frequently misread

**"Worker: done but the tests failed — is the log wrong?"**
No. `done` describes the process, not the test outcome. Check the Behat summary
block and the step records.

**"Half the sections say failed with a timeout — is the application broken?"**
Maybe, but check the top first. If the Run summary shows
`coordinator deadline reached`, those features were stopped because *the run* ran
out of time, and they tell you nothing about the application.

**"Section #3 comes before section #9, so it finished first."**
No. Sections are ordered by when a feature was *started*. Finish order is
different, and lanes run in parallel.

**"Lane 2 keeps failing — is lane 2 broken?"**
Lanes are just numbered slots; the same lane runs many different features. A lane
that hits several consecutive timeouts is taken out of service on purpose, so the
rest of the queue is not fed into a station that seems unhealthy. If you see one
lane failing repeatedly, that is a real signal worth reporting.

**"Nothing ran and there is no error."**
Look for `No feature files matched the requested scope/tags` at the top. The
filter matched nothing — usually a tag or path typo in the request.
