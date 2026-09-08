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