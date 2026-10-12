# Setting up an installation for UI testing

## Install headless browsers

- [Install Chrome](Setting_up_Chrome_for_testing.md)
- [Run Chrome in a local Podman container](Running_Chrome_in_a_local_Podman_container.md)

## Configure PHP

- Make sure `error_reporting` is set to `E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING & ~E_NOTICE` in the `php.ini`, that is used on CLI. Otherwise, warnings or notices will stop the test from running because they will be treated as errors by Behat.