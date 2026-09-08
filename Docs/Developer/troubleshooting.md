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