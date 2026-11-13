# Setting up Chrome browser for automated UI tests

In order to be able to control the version of Chrome you are testing with explicitly and to be able to test multiple versions, it is recommended to use one or more portable Chromes.

## Install a portable version of Chrome

1. Install [Chrome portable](https://portableapps.com/apps/internet/google_chrome_portable) somewhere
   accessible to the account running the tests (web server or scheduled task).
   The shipped path assumes `<INSTALLATION_ROOT>\data\axenox\BDT\GoogleChromePortable`.
2. For direct `vendor\bin\behat` runs, keep a separate profile directory:
   `<INSTALLATION_ROOT>\data\axenox\BDT\ChromeUserData`. This is the `browser.config.user_data_dir`
   default in [Behat/BaseConfig.yml](../../Behat/BaseConfig.yml), with debugging port `9222`.
   It keeps test-browser data separate from a normal Chrome profile. Direct Behat runs also use the
   browser manager; they do not require a manually launched Chrome.

**Do not launch local Chrome manually before RunTest or RunParallel.** BDT launches it itself.
Those actions replace the direct-Behat profile/port defaults with isolated profiles under
`data\axenox\BDT\chrome_profiles` and dynamically allocated ports.

## Configure BDT to launch Chrome

By default BDT starts Chrome itself. The browser is configured like the metamodel connector: the
app-config key `BROWSER.MANAGER` selects the browser type and `BROWSER.MANAGER_CONFIG` holds the
settings of that type. To configure a new installation or migrate legacy settings, put both keys into
`<INSTALLATION_ROOT>\config\axenox.BDT.config.json`:

```json
{
  "BROWSER.MANAGER": "\\axenox\\BDT\\Behat\\BrowserManagers\\LocalChromeManager",
  "BROWSER.MANAGER_CONFIG": {
    "executable": "data\\axenox\\BDT\\GoogleChromePortable\\App\\Chrome-bin\\chrome.exe",
    "headless": true,
    "port_band_interactive": "9201-9251",
    "port_band_scheduled": "9252-9300"
  }
}
```

- `executable` must be the real `chrome.exe`, **not** `GoogleChromePortable.exe`: its single-instance
  lock breaks concurrent runs.
- The shipped config sets `headless` to `true`. If a replacement manager config omits it, Chrome runs
  headless unless a PHP debugger is attached. Set it to `false` to watch the browser.
- The port bands are the debugging-port windows for interactive runs (`RunTest`) and scheduled
  parallel runs (`RunParallel`). Keep them disjoint. A project's `bdt_parallel.yml` beside the base
  Behat config can override them with `port_band_interactive` and `port_band`, respectively.
  Range endpoints are inclusive: the shipped bands `9201-9251` and `9251-9300` overlap at `9251`;
  the installation example above deliberately starts the scheduled band at `9252`.
- Relative executable paths are resolved from the installation working directory used for Behat.
- `BROWSER.MANAGER_CONFIG` is replaced as a whole by a higher configuration layer, not merged
  property by property. Include all settings required by the selected manager.
- A setting the selected browser type does not understand (for example a port band on a remote
  browser) makes the run fail with `No setter method found for UXON property "..."`.

## Remote browser

A remote manager attaches to an externally started Chrome; it does not launch or stop that process.
Use RunTest, not RunParallel:

```json
{
  "BROWSER.MANAGER": "\\axenox\\BDT\\Behat\\BrowserManagers\\RemoteChromeManager",
  "BROWSER.MANAGER_CONFIG": {
    "endpoint": "http://chrome-host:9222",
    "base_url": "http://application-host/"
  }
}
```

Both `endpoint` and `base_url` are required. `endpoint` is the Chrome DevTools HTTP(S) address
reachable from the test server. `base_url` is the workbench address **as seen by the remote browser**:
it is used for application navigation and becomes Mink's `base_url`. A container's `localhost`
usually refers to the container, not the application server, so the test server's own URL is not
necessarily usable. The manager normalizes the addresses and adds a trailing slash to `base_url`.
Credentials, query strings and fragments are not accepted in these URLs.

For deployment examples, see
[Run Chrome in a local Podman container](Running_Chrome_in_a_local_Podman_container.md) and
[Run Chrome in Azure Container Apps](Running_Chrome_in_Azure_Container_Apps.md).

## Legacy settings and precedence

The old keys are `PARALLEL.CHROME_PATH`, `PARALLEL.CHROME_HEADLESS`,
`PARALLEL.PORT_BAND_INTERACTIVE`, `PARALLEL.PORT_BAND_SCHEDULED`, `REMOTE.CHROME_ENDPOINT` and
`REMOTE.BASE_URL`. They remain readable for migration but should not be used for a new installation.

The following table assumes no user-level overrides and no CLI overrides:

| What the installation config contains | Which settings BDT uses | `DEPRECATED: BDT browser` warning? |
|---|---|---|
| No `BROWSER.MANAGER` and no legacy keys | Shipped local manager and effective `BROWSER.MANAGER_CONFIG` (shipped config unless replaced in the installation). | No |
| Legacy local keys, no `BROWSER.MANAGER`, no non-empty legacy remote endpoint | Local manager; legacy local values override shipped local defaults. | Yes |
| A non-empty `REMOTE.CHROME_ENDPOINT`, no `BROWSER.MANAGER` | Remote manager with legacy `REMOTE.CHROME_ENDPOINT` and `REMOTE.BASE_URL`; no local defaults are imported. Both remote values are required. | Yes |
| A non-empty `BROWSER.MANAGER`, with or without legacy keys | The selected manager and effective `BROWSER.MANAGER_CONFIG`; legacy keys are ignored. | No |
| A blank `BROWSER.MANAGER` and any legacy keys | Legacy selection: non-empty remote endpoint selects remote; otherwise local. | Yes |
| A blank `BROWSER.MANAGER` and no legacy keys | Local manager with effective `BROWSER.MANAGER_CONFIG`. | No |

User-level settings also participate: BDT checks both installation and user layers for a manager
selector and legacy keys. A non-empty effective manager selected in either layer disables legacy
selection; an explicitly blank effective selector allows it again. When legacy selection is active,
only legacy keys actually present in those layers are used and named in the warning.
Merely adding `BROWSER.MANAGER_CONFIG` without a selector does not disable legacy selection.

To migrate, explicitly set a non-empty `BROWSER.MANAGER` and move all relevant values into
`BROWSER.MANAGER_CONFIG`; check user configuration too. The old `chrome:` extension node in a
hand-written Behat config is also deprecated; use `browser:` as in
[Behat/BaseConfig.yml](../../Behat/BaseConfig.yml).

## Linux status and open questions

Linux process handling exists (process groups and `/proc`), but has not yet been verified on a Linux
host. The process host warns that wrapper scripts such as `google-chrome` can have a different name
from the real Chrome process, preventing process enumeration; point `executable` at the real binary.
Do not treat Windows validation or parser unit tests as Linux host validation.

The code does not pin a supported Chrome version or identify a validated Linux host/browser
combination. These remain open installation decisions; see the
[installation open questions](../index.md#open-questions-for-installation-operators).