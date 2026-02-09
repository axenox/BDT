# Setting up Chrome browser for automated UI tests

In order to be able to control the version of Chrome you are testing with explicitly and to be able to test multiple versions, it is recommended to use one or more portable Chromes.

## Install a portable version of Chrome

1. Install [Chrome portable](https://portableapps.com/de/apps/internet/google_chrome_portable) to any location accessible to the user, that runs your web server
    - E.g. install in `<path_to_workbench>/data/axenox/BDT/Chrome`
2. Create a folder for user data so that the portable Chrome installation does not interfere with regular ones.
    - E.g. `<path_to_workbench>/data/axenox/BDT/ChromeUserData`
3. Launch chrom in debug mode

## Ensure Chrome in the background

### On Windows Server

Create a file `Chrome.bat` - e.g. in `<path_to_workbench>\data\axenox\BDT` with the following contents:

```
@echo off
"C:\<path_to_deployment_folder>\shared\data\axenox\BDT\GoogleChromePortable\GoogleChromePortable.exe" --headless --no-sandbox --window-size=1920,1080 --disable-extensions --disable-gpu --remote-debugging-address=127.0.0.1 --remote-debugging-port=9222 --user-data-dir="C:\<path_to_deployment_folder>\shared\data\axenox\BDT\GoogleChromeUserData"
```

Create a Windows Scheduler task to run Chrome:

- Run as `SYSTEM`
- Trigger
  - Stop task if running > 8 Hours
  - Enabled (!)
- Action
  - Command: `cmd`
  - Arguments: `/c C:\<path_to_deployment_folder>\shared\data\axenox\BDT\Chrome.bat`

### On Windows (developer machine)

Create a file `Chrome.bat` - e.g. in `<path_to_workbench>\data\axenox\BDT` with the following contents: 
```
"<path_to_workbench>\data\axenox\BDT\Chrome\GoogleChromePortable.exe" --remote-debugging-address=127.0.0.1 --remote-debugging-port=9222 --user-data-dir="<path_to_workbench>\data\axenox\BDT\ChromeUserData"
```

Now you can easily start Chrome by running `Chrome.bat`.