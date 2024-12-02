# BDT: Behavior Drive Testing for pages

## Preparing an installation for testing

1. Install prerequisites - see [setup guidelines](Setup/index.md) if not
2. Got to `Administration > Automated tests`. The system will set up everything automatically

## Running a test

1. Make sure, your headless browsers are running - see [setup guidelines](Setup/index.md) if not
2. TODO

## Developing Behat contexts and steps

### Debug a test

You will need a local workbench installation as described in the [installation guied](https://github.com/ExFace/Core/blob/1.x-dev/Docs/Installation/index.md) and a PHP IDE like Visual Studio Code with PHP debugging set up correctly.

1. Open your favorite command line: e.g. Windows `cmd`;
2. Navigate to the workbench installation folder: e.g. `cd c:\wamp\www\exface\exface`
3. Make sure xDebug is set to debug CLI commands: e.g. set environment variables via `set XDEBUG_MODE=debug& set XDEBUG_SESSION=1`.
4. Run the Behat suite or scenario you wish to debug: e.g. `vendor\bin\Behat --suite=ui5.test`