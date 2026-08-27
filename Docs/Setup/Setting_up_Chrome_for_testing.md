# Setting up Chrome browser for automated UI tests

In order to be able to control the version of Chrome you are testing with explicitly and to be able to test multiple versions, it is recommended to use one or more portable Chromes.

## Install a portable version of Chrome

1. Install [Chrome portable](https://portableapps.com/de/apps/internet/google_chrome_portable) to any location accessible to the user, that runs your web server
    - E.g. install in `<path_to_workbench>/data/axenox/BDT/GoogleChromePortable`
2. Create a folder for user data so that the portable Chrome installation does not interfere with regular ones.
    - E.g. `<path_to_workbench>/data/axenox/BDT/ChromeUserData`
3. Launch chrom in debug mode