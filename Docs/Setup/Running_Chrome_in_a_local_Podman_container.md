# Run BDT with Chrome in local Podman

PHP, Behat, BDT, WAMP and the database stay on Windows. Only Chrome and its network relay run
in Podman. Use one interactive `RunTest` selection, not `RunParallel`.

## Setup and test

### 1. Start the Podman machine

Prerequisites: WSL2, Podman, running WAMP, BDT dependencies installed through the application's
Composer configuration, and a base Behat configuration created by the normal BDT initialization.

Configure CLI PHP according to the [PHP settings](index.md#configure-php) so Behat does not treat
warnings and notices as errors. Use `php --ini` to identify the CLI configuration file.

Run the following in Windows PowerShell. Start the machine only if it is stopped; initialize a
machine only if none exists.

```powershell
Set-Location "C:\wamp\www\exface\exface\vendor\axenox\BDT"
$manifest = Join-Path $PWD "Docs\Setup\bdt-chrome.kube.yaml"
podman machine ls
```

```powershell
podman machine start
```

This setup uses the rootful engine. Do not switch rootful/rootless during troubleshooting: they
have separate container storage. For the observed WSL startup error, see the cgroup fix below.

### 2. Apply the browser YAML

[bdt-chrome.kube.yaml](bdt-chrome.kube.yaml) creates two pods, but only one browser:

- `bdt-chrome`: Chrome in an isolated network.
- `bdt-cdp-relay`: only `socat`, using the VM host network; listens on loopback 9222 and forwards
  to Chrome. WSL makes the real listening socket reachable from Windows.

```text
Windows PHP/Behat -> localhost:9222 -> VM relay:9222 -> VM:19222 -> Chrome pod:9222
```

```powershell
podman kube play --replace $manifest
podman ps --filter name=bdt-
```

**`--replace` resets the browser session. Do not apply it during a test.** Both pods run without
an open terminal. Podman may show an infra container for each pod; these are not extra browsers.

### 3. Verify Windows to Chrome

```powershell
curl.exe --noproxy "*" --connect-timeout 3 --max-time 5 http://127.0.0.1:9222/json/version
```

Expected: Chrome metadata with a `webSocketDebuggerUrl` starting with
`ws://127.0.0.1:9222/devtools/browser/`. Verify that exact URL, not just the HTTP response:

```powershell
$browserInfo = Invoke-RestMethod "http://127.0.0.1:9222/json/version" -TimeoutSec 5 -ErrorAction Stop
$socket = [System.Net.WebSockets.ClientWebSocket]::new()
$connectionTimeout = [System.Threading.CancellationTokenSource]::new(5000)
try {
    $socket.ConnectAsync([uri]$browserInfo.webSocketDebuggerUrl, $connectionTimeout.Token).GetAwaiter().GetResult() | Out-Null
    $socket.State
} finally {
    $socket.Dispose()
    $connectionTimeout.Dispose()
}
```

Expected state: `Open`. If this fails, check both containers and their logs:

```powershell
podman ps --filter name=bdt-
podman logs --tail 30 bdt-chrome-chrome
podman logs --tail 30 bdt-cdp-relay-relay
```

### 4. Verify Chrome's network can reach WAMP

Use the Windows host address reachable from the pod as `<WINDOWS_HOST_IP>`. Recheck it after a WSL
restart or network-mode change; do not assume it is permanent. Find Windows adapter addresses with:

```powershell
Get-NetIPAddress -AddressFamily IPv4 | Select-Object InterfaceAlias, IPAddress
```

Replace `<WINDOWS_HOST_IP>` before running this check from the same network as Chrome:

```powershell
podman run --rm --pod bdt-chrome docker.io/library/alpine:3 `
  wget -S -O /dev/null -T 10 "http://<WINDOWS_HOST_IP>/exface/exface/"
```

The helper is removed after the check; its first run may download the image. HTTP 401 was the
expected response here: the application is reachable and BDT handles authentication. HTTP 403
with Apache requires the scoped access fix below. A timeout or refusal is a network
problem, not an authentication problem.

`localhost` inside Chrome means the pod itself, not Windows. Application redirects and asset
URLs must also be reachable from the pod.

### 5. Run one feature

In the BDT UI's **Command line**, use your actual feature path and the WAMP URL verified above:

```text
vendor/bin/action axenox.BDT:RunTest --feature="vendor/<actual-feature-path>" --chrome_endpoint="http://127.0.0.1:9222" --base_url="http://<WINDOWS_HOST_IP>/exface/exface/"
```

Replace all placeholders before use. For the **Run file** button, set these values in the
installation-level BDT configuration override, not the vendor package defaults:

```text
<INSTALLATION_ROOT>/config/axenox.BDT.config.json
```

```json
{
  "REMOTE.CHROME_ENDPOINT": "http://127.0.0.1:9222",
  "REMOTE.BASE_URL": "http://<WINDOWS_HOST_IP>/exface/exface/"
}
```

Explicit CLI values override the installation settings. Ensure the command and installation
configuration use the endpoint above; changing the browser YAML does not update BDT settings.
Do not change global `SERVER.BASE_URLS` just to set this run's URL.

Keep both pods running. Remote `RunTest` attaches to Chrome rather than launching a Windows
browser. Use a browser dedicated to this installation; do not share it with other test workers.
Confirm login, an assertion, a screenshot, and normal database/report results. After teardown,
confirm Chrome is still reachable and no local Chrome process/profile was created.

### 6. Restart or remove the pods

After a machine restart, start both existing pods if needed:

```powershell
podman pod start bdt-chrome
podman pod start bdt-cdp-relay
```

To apply configuration changes, use Step 2. To stop and remove both YAML pods:

```powershell
podman kube down $manifest
```

The pods' `restartPolicy: Always` does not start the Podman machine at Windows boot. BDT does not
start or restart Podman for you.

## Issues and resolutions

| Issue | Cause and resolution |
|---|---|
| `podman` not found after installation | Open a new terminal so the installer's PATH change is available. |
| `controller pids is not available` or `Unit ... was already loaded` | WSL/systemd cgroup startup error. Apply the cgroup fix below and verify the active manager. |
| Windows localhost cannot reach Chrome | Confirm the machine and both YAML pods are running. Keep the host-network relay configured and check the logs in Step 3. |
| VPN interferes with VM networking | Use the localhost relay endpoint. Enable user-mode networking if needed, as described below; do not change corporate VPN routes. |
| `RunTest` uses a different endpoint | Correct the command or `REMOTE.CHROME_ENDPOINT` to `http://127.0.0.1:9222`; do not restart Chrome for a settings error. |
| `host.containers.internal` refuses connection | Verify which host it resolves to instead of assuming it is Windows WAMP. Use the Windows address tested in Step 4. |
| Apache 403 | WAMP's `Require local` rejects the incoming WSL client. Apply the application-only access rule below. |
| HTTP 401 from WAMP | Network access works; keep authentication enabled and use BDT's existing login flow. |
| Missing `Behat\Testwork\Output\Formatter` | Install BDT dependencies through the application root, not a nested BDT vendor directory: `composer update axenox/bdt --with-dependencies --no-scripts`. Preserve local changes first. |
| Local Chrome/profile error during a remote run | Set RunTest's endpoint option or config fallback. Changing only Mink's `api_url` does not configure the browser manager. |

### Cgroup startup fix

For the observed Podman 6.0.2 / WSL 3.0.1 failure, set the VM's cgroup manager to `cgroupfs`.
See the [upstream workaround](https://github.com/podman-container-tools/podman/issues/29749).
Do not overwrite an existing drop-in containing custom settings. Restarting the machine stops
all its containers, including unrelated workloads.

```powershell
podman machine ssh --username root "mkdir -p /usr/share/containers/containers.conf.d/"
podman machine ssh --username root "printf '[engine]\ncgroup_manager = \047cgroupfs\047\n' > /usr/share/containers/containers.conf.d/999-podman-machine-wsl-cgroupfs.conf"
podman machine stop
podman machine start
podman info --format '{{.Host.CgroupManager}}'
```

Expected: `cgroupfs`. This resolved startup here. With rootless `cgroupfs`, resource limits are not
enforced by this workaround; keep the existing rootful connection for this setup.

### VPN-compatible machine networking

This setup already has user-mode networking enabled. On another machine, enable it only if needed:

```powershell
podman machine stop
podman machine set --user-mode-networking=true
podman machine start
```

This changes WSL networking and can affect other running WSL distributions. Recheck WAMP access
afterward and keep the relay configured for Windows CDP access.

### Apache application-only access fix

Inside WAMP's existing localhost virtual host, preserve the parent `www/` local-only rule and add a narrower application block:

```apache
<Directory "${INSTALL_DIR}/www/exface/exface/">
  <RequireAny>
    Require local
    Require ip <WSL_CLIENT_IP>
  </RequireAny>
</Directory>
```

Replace `<WSL_CLIENT_IP>` with the **current client IP from Apache's error log**, not the Windows
destination address. Apply with authorization, restart
Apache through WAMP, and repeat Step 4. Do not use blanket `Require all granted` or disable the
firewall. Repeat the application connectivity check after network changes.

Keep CDP private: the relay binds loopback, but the Chrome backend is published on VM interfaces.
The image uses mutable `stable`; pin a tested digest for production.