# Setting up Chrome browser for automated UI tests

In order to be able to control the version of Chrome you are testing with explicitly and to be able to test multiple versions, it is recommended to use one or more portable Chromes.

## Install a portable version of Chrome

1. Install [Chrome portable](https://portableapps.com/de/apps/internet/google_chrome_portable) to any location accessible to the user, that runs your web server
    - E.g. install in `<path_to_workbench>/data/axenox/BDT/GoogleChromePortable`
2. Create a folder for user data so that the portable Chrome installation does not interfere with regular ones.
    - E.g. `<path_to_workbench>/data/axenox/BDT/ChromeUserData`
3. Launch chrom in debug mode

## Configure BDT to launch Chrome

By default BDT starts Chrome itself. The browser is configured like the metamodel connector: the
app-config key `BROWSER.MANAGER` selects the browser type and `BROWSER.MANAGER_CONFIG` holds the
settings of that type. The shipped config selects local Chrome with headless enabled, but installation
or user legacy settings still take precedence unless those layers select a manager explicitly. To migrate
or customize the browser, put both keys into the installation-level BDT configuration at
`<INSTALLATION_ROOT>/config/axenox.BDT.config.json`:

```json
{
  "BROWSER.MANAGER": "\\axenox\\BDT\\Behat\\BrowserManagers\\LocalChromeManager",
  "BROWSER.MANAGER_CONFIG": {
    "executable": "data\\axenox\\BDT\\GoogleChromePortable\\App\\Chrome-bin\\chrome.exe",
    "headless": true,
    "port_band_interactive": "9201-9251",
    "port_band_scheduled": "9251-9300"
  }
}
```

- `executable` must be the real `chrome.exe`, **not** `GoogleChromePortable.exe`: its single-instance
  lock breaks concurrent runs.
- The shipped config sets `headless` to `true`. If a replacement manager config omits it, Chrome runs
  headless unless a PHP debugger is attached. Set it to `false` to watch the browser.
- The port bands are the debugging-port windows for interactive runs (`RunTest`) and scheduled
  parallel runs (`RunParallel`). Keep them disjoint.
- A setting the selected browser type does not understand (for example a port band on a remote
  browser) makes the run fail with `No setter method found for UXON property "..."`.

To test against a browser that runs elsewhere, e.g. in a container, see
[Run Chrome in a local Podman container](Running_Chrome_in_a_local_Podman_container.md) and
[Run Chrome in Azure Container Apps](Running_Chrome_in_Azure_Container_Apps.md).

> **Deprecated:** the former keys `PARALLEL.CHROME_PATH`, `PARALLEL.CHROME_HEADLESS`,
> `PARALLEL.PORT_BAND_INTERACTIVE`, `PARALLEL.PORT_BAND_SCHEDULED`, `REMOTE.CHROME_ENDPOINT` and
> `REMOTE.BASE_URL` still work in this release when no installation or user layer selects a manager
> (or the effective selector is blank), but BDT logs a
> deprecation warning. The `chrome:` section of a hand-written `behat.yml` is deprecated in favor of
> `browser:` as well. Legacy local values override the shipped local defaults. Once
> `BROWSER.MANAGER` is set in an installation or user layer, migrate all relevant legacy settings into
> `BROWSER.MANAGER_CONFIG`.