# BDT: Behavior-driven testing for pages

## Preparing an installation for testing

Run the commands below from the installation root, using the PHP CLI configured for that installation.

1. **Install Chrome and configure PHP.** Follow [Setting up Chrome for testing](Setup/Setting_up_Chrome_for_testing.md)
   to install a portable Chrome and [Configure PHP](Setup/index.md#configure-php) for the CLI PHP settings.
   The account running the tests must be able to access Chrome and write BDT's data directories.

2. **Configure BDT for this installation.** Create or edit
   `<INSTALLATION_ROOT>\config\axenox.BDT.config.json`, not the shipped package configuration.
   Set `BROWSER.MANAGER` and `BROWSER.MANAGER_CONFIG` as described in the
   [Chrome setup guide](Setup/Setting_up_Chrome_for_testing.md#configure-bdt-to-launch-chrome).
   Set the test-user keys in the same installation file:

   | Key | Used for |
   |---|---|
   | `TEST_USER.USERNAME` | Base username for automatic test-user provisioning and login. Interactive runs use this name; parallel lanes use `<username>_lane<N>` so lanes do not share role assignments. It also identifies the test-user family during test-data cleanup. |
   | `TEST_USER.PASSWORD` | Password assigned when a missing test user is created, and credentials used to authenticate the test user in PHP and the browser. |
   | `TEST_USER.FIRST_NAME` | First name assigned when a missing test user is created. |
   | `TEST_USER.LAST_NAME` | Last name assigned when a missing test user is created. |

   **The shipped `TEST_USER.PASSWORD` is only a default. Every installation must set its own
   password in the installation configuration before testing.** Do not reuse a real person's account:
   scenarios change the test user's roles, and cleanup recognizes these usernames as test users.
   Users are provisioned during scenario login, not by Behat initialization. Existing users are reused;
   changing these configuration keys does not automatically update their stored password or names.
   See the password-rotation open question below for an existing installation.

3. **Initialize Behat.** In `Administration > Automated tests`, open the feature-files
   **Command line** console, whose start command initializes Behat, or run:

   ```console
   vendor\bin\action axenox.BDT:Behat init
   ```

   This creates the installation-root `behat.yml` from BDT's template if missing, or loads the
   existing file. It includes apps found in the BDT feature listing, creating their Behat suite
   configuration if missing, and writes the global `behat.yml` with Mink's `base_url` updated to the
   workbench URL. It does not install Chrome or create the test user.

4. **Verify with one interactive run.** Choose a tag that exists in this installation, for example:

   ```console
   vendor\bin\action axenox.BDT:RunTest --tags=@Status::Ready
   ```

   Alternatively, use `--feature` to select one known feature file. For a local browser, expect
   `Allocated port <port> (band <start>-<end>)`; for a remote browser, expect
   `Reserved external Chrome at <endpoint>`. The action prints the generated config filename and
   streams Behat output. Allocation/reservation alone is not proof of success: check that scenarios
   actually ran and inspect their results. After migrating browser settings, check
   `<INSTALLATION_ROOT>\logs\<date>.log`: there should be no `DEPRECATED: BDT browser` line for the
   run. If it is present, check installation and user configuration for legacy browser keys using the
   [precedence table](Setup/Setting_up_Chrome_for_testing.md#legacy-settings-and-precedence).

## Running a test

### RunTest: an interactive tag, feature or suite

BDT starts and cleans up local Chrome itself, using an allocated port and an isolated profile.
For a remote manager, the external browser must already be running; BDT attaches to it rather
than launching or stopping its process.

Examples (replace the app/feature names with ones from your installation):

```console
vendor\bin\action axenox.BDT:RunTest --tags=@Status::Ready
vendor\bin\action axenox.BDT:RunTest --feature=vendor\my\app\Tests\Behat\Features\login.feature
vendor\bin\action axenox.BDT:RunTest --suite=my.APP
```

| Option | Meaning |
|---|---|
| `--tags` | Behat tag filter. |
| `--feature` | Feature file or directory. |
| `--suite` | Behat suite name. Selectors can be combined; at least one of `--tags`, `--feature`, `--suite` is required. |
| `--behat_config` | Base config imported by the generated config. Defaults to `PARALLEL.BEHAT_CONFIG`, then installation-root `behat.yml`. |
| `--chrome_path` | Override the local executable for this run; use the real `chrome.exe`, not the portable launcher. Ignored for an attached browser. |
| `--chrome_endpoint` | Remote CDP HTTP endpoint; forces the remote manager for this run. |
| `--base_url` | Application URL. For a remote manager, the URL reachable from that browser; for a local manager, a Mink `base_url` override only. |

For example, a one-off remote run needs both browser and application addresses (unless the remote
application address is already configured):

```console
vendor\bin\action axenox.BDT:RunTest --tags=@Status::Ready --chrome_endpoint=http://chrome-host:9222 --base_url=http://application-host/
```

### RunParallel: scheduled parallel runs

Use the coordinator action as the command for a scheduled run:

```console
vendor\bin\action axenox.BDT:RunParallel --tags=@Status::Ready
```

**RunParallel requires a local browser manager supporting parallel lanes.** It rejects the remote
manager because lanes would share the same browser and interfere with each other's tabs and logins.
It initializes Behat automatically, queues matching features, and runs one feature per worker process.

| Option | Meaning |
|---|---|
| `--tags` | Behat tag filter; no automatic tag filter is applied when omitted. |
| `--feature` | Restrict to a feature file or directory; mutually exclusive with `--suite`. |
| `--suite` | Restrict to a named suite; mutually exclusive with `--feature`. At least one of `--tags`, `--feature`, `--suite` is required. |
| `--behat_config` | Base config in the installation root; defaults to `behat.yml`, refreshed by initialization. |
| `--chrome_path` | Override the local executable for this run; otherwise use `BROWSER.MANAGER_CONFIG.executable`. |

Worker count is configured with `PARALLEL.MAX_WORKERS`, not a CLI option. Local browser port bands
and headless mode belong in `BROWSER.MANAGER_CONFIG`.

### Results

- Both actions record runs, features, scenarios and steps in the BDT database. Inspect **Test runs**
  and the scenario/step details in the automated-tests UI.
- With the shipped formatter configuration, HTML reports go to
  `<INSTALLATION_ROOT>\data\axenox\BDT\Reports`.
- RunParallel also writes worker and coordinator logs under
  `<INSTALLATION_ROOT>\data\axenox\BDT\Logs\<run_uid>` and stores a machinery summary in the run's
  **Log** attribute. See [How to read a BDT parallel run log](Parallel_Run/reading-the-run-log.md).
  A healthy machinery log does not mean every scenario passed.

## Open questions for installation operators

- What is the approved password-rotation procedure for already provisioned base and lane users?
  The provisioning code only sets a password when creating a missing user; it does not migrate
  existing users when `TEST_USER.PASSWORD` changes.
- Which Chrome version should a new installation standardize on? The shipped executable path does
  not pin a browser version.
- Which Linux host/browser combination will be used for the first host validation? Linux process
  handling exists but has not yet been verified on a Linux host.

## Developing Behat contexts and steps

### Write tests

- [Introduction to the Gherkin language](Introduction/Writing_tests_-_Gherkin_language.md)

### Debug a test

You will need a local workbench installation as described in the [installation guide](https://github.com/ExFace/Core/blob/1.x-dev/Docs/Installation/index.md) and a PHP IDE like Visual Studio Code with PHP debugging set up correctly.

1. Open your favorite command line: e.g. Windows `cmd`;
2. Navigate to the workbench installation folder: e.g. `cd c:\wamp\www\exface\exface`
3. Make sure xDebug is set to debug CLI commands: e.g. set environment variables via `set XDEBUG_MODE=debug& set XDEBUG_SESSION=1`.
4. Run the Behat suite or scenario you wish to debug: e.g. `vendor\bin\Behat --suite=ui5.test`