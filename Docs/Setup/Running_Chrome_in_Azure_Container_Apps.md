# Run BDT with Chrome in Azure Container Apps

PHP, Behat, BDT, authentication, database access and reports stay on the application server.
Only the browser runs in Azure Container Apps. BDT's interactive `RunTest` already supports a
remote Chrome endpoint. The application server must include remote-container support and the
hostname-compatible CDP probes described below.

Configure the environment and Container App in the **Azure portal**. Use **Cloud Shell** for the
registry image import and **App Service SSH** for runner configuration and connectivity checks.

```text
Application server: PHP / Behat / BDT -> private CDP endpoint -> Container App: Chrome
                                                                      |
                                                                      v
                                                            Application under test
```

## Repeatable setup checklist

1. **Check the Azure subscription.** In Cloud Shell, use `az account show -o table` and
  `az account list -o table`. Switch with `az account set --subscription "<SUBSCRIPTION_NAME_OR_ID>"`.
  Registry names are resource names, without `.azurecr.io`; `-g` selects a resource group, not a
  Container Apps environment.
2. **Import and verify the image.** Use `az acr import` as shown below; no local Docker or custom
  build is needed. Check it with
  `az acr repository show --name <REGISTRY_NAME> --image <IMAGE_NAME>:<IMAGE_TAG> -o json`.
  The Repositories page lists stored images; use Cloud Shell for the import rather than assuming
  the page has a fetch/import button. Record the image digest for repeatable deployments.
3. **Use a private, VNet-connected environment.** Confirm App Service can reach it and Chrome can
  reach the application under test. DNS, routing, firewall rules and certificate trust still matter.
  Never publish the unauthenticated Chrome debugging endpoint to the internet.
4. **Configure image-pull identity in the portal.** Attach a user-assigned managed identity under
  **Identity > User assigned**,
  grant it the appropriate registry pull role, and select that same identity for registry
  authentication under **Containers > Edit and deploy**. Operator access to an image does not give
  the app access to it.
5. **Deploy the image through Containers > Edit and deploy.** Leave Command override empty: the image
  already contains `/headless-shell/run.sh`. Set browser flags through Arguments override, not
  Command override. Keep `--disable-dev-shm-usage`; use the window, locale and timezone settings
  below.
6. **Set ingress and lifecycle settings in the portal.** Under **Ingress**, use TCP, target port
  `9222` and exposed port `9222`. Under **Scale**, set minimum and maximum replicas to `1`; use
  single revision mode under **Revisions and replicas**. For an external environment, disable public
  network access and configure its private endpoint before publishing CDP. Check ingress settings
  after image updates; existing values may be preserved.
7. **Deploy BDT and configure CLI PHP.** Deploy compatible runtime files as listed below, including
  hostname handling and the Linux launcher. Check the CLI PHP
  `error_reporting` mask: ordinary warnings and notices must not become Behat step exceptions.
  Keep the server's existing BDT configuration when using explicit remote command options.
8. **Verify from App Service SSH.** Check revision health
  and logs, then test `/json/version` and a WebSocket upgrade from the runner's actual network.
  Cloud Shell connectivity alone does not establish App Service connectivity. See verification
  commands below, including the FQDN Host-header override for manual diagnostics.
9. **Run a tagged test through the hostname.** Pass `--tags="@<TEST_TAG>"`, `--chrome_endpoint`
  and the container-reachable `--base_url` as shown below. BDT and DMore handle Chrome's HTTP Host
  restriction automatically. Inspect results, screenshots and cleanup before
  expanding coverage. Remote parallel execution is not covered.

## The container registry

**What it is.** Azure Container Registry (ACR) is a private store for container images. It replaces
the public Docker Hub reference in the deployment: Container Apps pulls the browser image from your
registry instead of from the internet.

**What it is for here.** Three things:

- **A fixed browser version.** The public `stable` tag changes without notice, so two runs a month
  apart can use different Chrome builds. An image in your own registry, referenced by digest, keeps
  the browser identical until you deliberately update it. A UI test that silently changes browser
  is a test you cannot trust.
- **No dependency on Docker Hub** at deploy or scale time, including its rate limits.
- **Private, authenticated pulls**, which many Azure environments require anyway.

### Can you only build new images, or also pull `chromedp`?

**You can pull it.** Open **Cloud Shell** from the portal toolbar and run `az acr import`. It copies
an existing public image straight into your registry, without a Dockerfile, build pipeline or local
Docker installation. The copy happens server-side in Azure.

```bash
az acr import --name <REGISTRY_NAME> --source docker.io/chromedp/headless-shell:stable --image <IMAGE_NAME>:<IMAGE_TAG>
```

Use a versioned tag rather than `stable`, and record the imported digest.

Then read back the digest and deploy that, so the deployment cannot drift even if the tag is later
overwritten:

```powershell
az acr manifest list-metadata --registry <REGISTRY_NAME> --name <IMAGE_NAME> --query "[].{tag:tags[0],digest:digest}" -o table
```

To update later, import again under a new tag and change the digest in the deployment. That is the
whole update process.

### When you would build your own image

Importing is the right first step. Build a custom image only when you hit a concrete need:

- An HTTP proxy inside the container if an FQDN must be retained and client-side header handling
  is unsuitable. BDT's hostname handling does not require a proxy or custom image.
- Fonts or locale data. `headless-shell` is deliberately minimal, and missing fonts can render the
  application differently from a full Chrome installation.
- Diagnostic tools such as `curl`, which the image does not ship.

Keep building and pinning separate: ACR Tasks can rebuild automatically when the base image changes,
but that reintroduces exactly the silent-drift problem a pinned digest removes. For a test browser,
update on purpose.

## How Container Apps differs from a plain container instance

Container Apps is built for stateless HTTP services that scale on demand. A debugging browser is the
opposite: one long-lived, stateful, single-tenant process. The platform will not stop you, but the
defaults are wrong for this workload and must be overridden.

| Default | Why it breaks CDP | Required setting |
|---|---|---|
| `minReplicas: 0` | The browser is shut down when idle. | **Scale: Minimum replicas = 1** |
| `maxReplicas: 10` | CDP is stateful: the client reads a WebSocket URL over HTTP, then connects to *that* browser. With several replicas the two calls can reach different Chromes. | **Scale: Maximum replicas = 1** |
| HTTP ingress | Adds an HTTP proxy with a documented 240-second request timeout. | Use TCP ingress for this setup. |

Microsoft documents replica counts as "a target amount, not a guarantee", and notes that extra
replicas are pre-warmed during platform maintenance. Even pinned to one replica, a second browser can
exist briefly. BDT's tab ownership assumes one exclusive browser, so treat a maintenance window as a
period in which runs may fail rather than as something the configuration can prevent.

**Use TCP ingress for this setup.** It passes the connection through without an HTTP proxy.
Configure private DNS and disable public network access to keep CDP on the private endpoint route.

## Deploy through the Azure portal

### 1. Environment

Open the Container Apps environment and configure private access under **Networking**.

For an environment with an **External** virtual IP, use an environment-level **private endpoint**
for private access, not its public static IP. An existing external environment does not
need to be recreated as internal for this approach.

- Private endpoints require a workload-profiles environment and **Public network access: Disabled**.
- The private endpoint connection must target this Container Apps environment and be **Approved**.
- Link the private DNS zone `privatelink.<REGION>.azurecontainerapps.io` to the VNet used by App Service,
  or configure equivalent resolution through the network's DNS service.
- Verify private DNS and TCP `9222` from App Service before treating this path as usable. Do not enable
  public CDP access to work around a private connectivity failure.

### 2. Container app

Use a **user-assigned managed identity** for registry authentication.

Prepare registry access in the portal:

1. Create or select a user-assigned managed identity.
2. Under the registry's **Access control (IAM)**, grant it pull permission: `AcrPull` for an RBAC registry, or
  `Container Registry Repository Reader` with access to `<IMAGE_NAME>` for an ABAC-enabled registry.
3. Attach it to the Container App under **Identity > User assigned > Add**.

Create or open the Container App in the required environment, then configure its container:

1. Open **Containers > Edit and deploy**. Select the ACR login server and imported image version
  or digest. Choose **Managed identity** authentication and select the user-assigned identity above.
2. Leave **Command override** empty. Enter browser flags under **Arguments override**, one per entry:

```text
--disable-dev-shm-usage
--disable-extensions
--window-size=1920,1080
--remote-allow-origins=*
--lang=<LANGUAGE_TAG>
--accept-lang=<LANGUAGE_TAG>
```

3. Add the environment variable `TZ=<TIMEZONE>`. Start with CPU `2` and memory `4 GiB`, then adjust
  to the workload. Choose an IANA timezone and the application's language tag.
4. Deploy the revision, then configure **Ingress**, **Scale** and revision mode using the
  [portal settings reference](#portal-settings-reference) below.

#### Optional CLI alternative

The following is an alternative to portal creation, not an additional required step. Use the
identity's full Azure resource ID as `<USER_ASSIGNED_IDENTITY_RESOURCE_ID>`.

```powershell
az containerapp create `
  --name <CONTAINER_APP_NAME> `
  --resource-group <RESOURCE_GROUP> `
  --environment <ENVIRONMENT_NAME> `
  --image <REGISTRY_NAME>.azurecr.io/<IMAGE_NAME>@sha256:<DIGEST> `
  --registry-server <REGISTRY_NAME>.azurecr.io `
  --user-assigned <USER_ASSIGNED_IDENTITY_RESOURCE_ID> `
  --registry-identity <USER_ASSIGNED_IDENTITY_RESOURCE_ID> `
  --transport tcp `
  --ingress external `
  --target-port 9222 `
  --exposed-port 9222 `
  --min-replicas 1 `
  --max-replicas 1 `
  --cpu 2 --memory 4Gi `
  --env-vars TZ=<TIMEZONE> `
  --args "--disable-dev-shm-usage" "--disable-extensions" "--window-size=1920,1080" "--remote-allow-origins=*" "--lang=<LANGUAGE_TAG>" "--accept-lang=<LANGUAGE_TAG>"
```

Two settings are easy to get wrong:

- **App ingress and environment public access are separate settings.** `--ingress external` allows
  traffic from outside the Container Apps environment; it is not proof of private access. On the
  external environment, keep public network access disabled and use the approved private
  endpoint. App ingress limited to the environment is not the App Service access path.
- **Pass browser flags with `--args`, never `--command`.** `--command` replaces the image entrypoint
  `/headless-shell/run.sh`, which is what starts Chrome and the forwarder that exposes the endpoint.
  `--args` appends to it. If you must set `--command`, it has to start with `run.sh` itself.

`--user-assigned` attaches the identity to the app; `--registry-identity` selects that same identity
for image pulls. No registry password is stored in the app. Deploying through Cloud Shell uses
the operator's rights to update the app, but image pulls still use the configured app identity.

Keep `--disable-dev-shm-usage`. The CPU and memory values are a starting point, not a measured
figure. Choose an IANA timezone for `<TIMEZONE>` and the application's language tag for
`<LANGUAGE_TAG>`.

### 3. Networking

The application server must reach the environment's private ingress address on port 9222 over the
virtual network, through peering, VPN or ExpressRoute. Chrome must separately be able to reach the
application under test and trust its HTTPS certificate.

CDP has no authentication: anything that reaches port 9222 controls the browser and everything the
browser can reach. Keep it on the private network.

### Private endpoint and DNS

In the portal, open **Container Apps environment > Networking > Private endpoint connections**.
Open the approved private endpoint, then **DNS configuration**, or follow its **Network interface >
IP configurations** link to find the private IP. Do not use the external environment's Static IP,
an ACR private endpoint, or an App Service private endpoint.

From **App Service SSH**, check:

```bash
nslookup <CONTAINER_APP_FQDN>
```

The final resolved address must match the environment private endpoint's IP. Apps in this environment
share the endpoint IP; TCP ingress distinguishes apps by their unique exposed ports. Keep the hostname in
the BDT endpoint setting so DNS, rather than a hard-coded address, selects the endpoint.

### Portal settings reference

Configure these fields in the portal:

| Step | Where in the portal | Field |
|---|---|---|
| Import the image | Portal toolbar, **Cloud Shell** | Run `az acr import` above; verify the image under the registry's **Repositories** page. |
| Private access | Container Apps environment, **Networking** | Disable public network access; configure an approved private endpoint and private DNS. |
| Image-pull identity | Container app, **Identity > User assigned**, then **Containers > Edit and deploy** | Attach the registry-pull identity and select it for Managed identity authentication. |
| Replica limits | Container app, **Scale** | Set minimum and maximum both to 1 |
| Revision mode | Container app, **Revisions and replicas** | Use single revision mode. |
| Ingress | Container app, **Ingress** | **Ingress type: TCP**, target port 9222, exposed port 9222 |
| Browser flags | Container app, **Containers** then edit the container | **Arguments override**, one flag per entry. Leave **Command override** empty. |
| Time zone | Same container edit page | Environment variable `TZ` = `<TIMEZONE>` |

Two portal details are easy to get wrong:

- **"Accepting traffic from anywhere" does not mean private access is configured.** App ingress must
  permit the App Service path, but environment public network access must remain disabled. Verify
  private endpoint approval, DNS and routing separately.
- **TCP ingress requires a VNet-connected environment.** If the option is missing, inspect the
  environment's networking configuration before continuing.

For an existing app, open **Identity > User assigned > Add** and attach the identity granted registry
pull permission above. Then open **Containers > Edit and deploy**, edit the container, choose
**Managed identity** authentication, and select that **user-assigned identity** for the registry.
The selected identity must be the one granted pull permission, not the app's or environment's
system-assigned identity. Allow time for role propagation, then deploy a new revision.

## Configure BDT

### CLI warning reporting

Behat turns reported PHP warnings into step exceptions. Adjust the CLI PHP error-reporting mask
when ordinary framework warnings should not fail test steps. This is separate from the logger's
severity threshold.

From App Service SSH, find the configuration used by **CLI PHP**:

```bash
php --ini
```

Set this mask in that CLI configuration, not in `RunTest`:

```ini
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT & ~E_WARNING & ~E_NOTICE
```

`E_ALL & ~E_DEPRECATED & ~E_STRICT` still reports warnings and notices. Check the effective setting
with a new PHP process:

```bash
php -r 'var_dump((error_reporting() & E_WARNING) !== 0);'
```

Expected: `bool(false)`. Start a new `RunTest` after the change; CLI PHP reads its configuration on
each start, so no Chrome-container restart is required. An explicit Behat `calls.error_reporting`
setting overrides the inherited PHP mask. Fatal errors and exceptions remain failures; this
workaround does not repair the underlying source of a warning.

### Endpoint and application URL

Use explicit command options to run without changing BDT configuration:

```text
vendor/bin/action axenox.BDT:RunTest --tags="@<TEST_TAG>" --chrome_endpoint="http://<CONTAINER_APP_FQDN>:9222" --base_url="https://<APPLICATION_HOST>/<APPLICATION_BASE_PATH>/"
```

`<CONTAINER_APP_FQDN>` is the full Chrome Container App hostname, without `/json/version` or a
WebSocket path. `<APPLICATION_HOST>` is the website being tested, not the Chrome container.
Replace `<APPLICATION_BASE_PATH>` with the required URL path, keeping the trailing slash. For an
application served at the site root, omit that placeholder and use `https://<APPLICATION_HOST>/`.
The application URL must be reachable **from inside Chrome**, and must not refer to the runner's
`localhost`. A tag may select scenarios in more than one feature.

For persistent defaults instead, merge these optional settings into the installation-level BDT
configuration at `<INSTALLATION_ROOT>/config/axenox.BDT.config.json`. Do not overwrite the server's
whole configuration with the local file:

```json
{
  "BROWSER.MANAGER": "\\axenox\\BDT\\Behat\\BrowserManagers\\RemoteChromeManager",
  "BROWSER.MANAGER_CONFIG": {
    "endpoint": "http://<CONTAINER_APP_FQDN>:9222",
    "base_url": "https://<APPLICATION_HOST>/<APPLICATION_BASE_PATH>/"
  }
}
```

`BROWSER.MANAGER` selects the browser type and `BROWSER.MANAGER_CONFIG` holds the settings of that
type; a remote browser accepts only `endpoint` and `base_url` (port bands or an executable are
rejected as unknown settings). Explicit `--chrome_endpoint` and `--base_url` options override these
values for one run - without changing the server configuration, so the command above keeps working
unchanged. When `--chrome_endpoint` is given, BDT uses the remote browser even if `BROWSER.MANAGER`
is a local one, and does not apply the local settings to it.
If `--chrome_path` is also supplied, it is ignored for the attached browser and the run output says so.

> **Deprecated:** the former keys `REMOTE.CHROME_ENDPOINT` and `REMOTE.BASE_URL` still work in this
> release when no installation or user layer selects `BROWSER.MANAGER` (or its effective value is
> blank), but BDT logs a deprecation warning. Move them to
> `endpoint` and `base_url` in `BROWSER.MANAGER_CONFIG`.

To select a specific feature instead of a tag, replace `--tags` with `--feature="<FEATURE_PATH>"`.

Use `RunTest`, not `RunParallel`: parallel runs need one browser per worker, which this setup does
not provide. `RunParallel` stops immediately with "does not support parallel lanes" when the
configured browser is a remote one.

### Browser lifecycle state

Configuration does not start a browser. `hasActiveSession()` becomes true only after a successful
start or attachment and becomes false on stop, independently of whether the browser is still alive.
Recovery uses `hasStartBeenAttempted()` separately: a local attempt begins when the profile has
been resolved, and a remote attempt begins before contacting the endpoint. That marker survives
stop and failed restarts so step recovery can retry. A healthy repeated start reuses the session.

Local PID and port getters expose runtime values, not configured defaults. The resolved local
profile remains available after stop for the existing best-effort cleanup sweep; configuring a
profile alone must not grant that cleanup ownership.

Losing a driver page does not necessarily mean Chrome exited. WebSocket exceptions are recognized by
type as well as message so a closed page triggers recovery even when the error only says
"Connection has unexpectedly closed". Recovery reattaches to a healthy browser rather than stopping
it, replays the cached browser-side login, and restores the scenario's recorded resume point.
The same login replay follows navigation-time session reattachment: a new isolated context has no
authentication cookies from the previous context, even when the browser PID is unchanged.
User provisioning is not repeated.

### Remote tab cleanup

The manager snapshots existing page tabs on attachment and retains that snapshot across reattach
attempts until `stop()`. A failed health check must not replace the snapshot: doing so would
misclassify test-created tabs as pre-existing and leave them open. Stopping closes only page tabs
absent from the snapshot, clears the snapshot, and never terminates the external browser.

Chrome acknowledges tab-close requests before the tabs necessarily disappear from `/json/list`.
When verifying cleanup, poll for the target to disappear with a bounded timeout rather than
assuming an immediate list response proves the close failed.

### How hostname handling works

The remote browser manager keeps the configured hostname in the HTTP URL, resolves its IPv4 address, and sends
that address and the explicit port in the Host header. DMore's installed `HttpClient` already does
the same for discovery, tab creation and cleanup. Chrome advertises a reachable numeric WebSocket
URL, which DMore's `ChromeDriver` passes directly to its WebSocket client. Neither client needs a
`localhost` override or a rewritten WebSocket URL for this path.

Literal IP and `localhost` endpoints keep their existing headers. This matches DMore's IPv4 behavior;
an IPv6-only DNS endpoint is not covered. The HTTP URL's hostname remains available for TLS server
name and certificate checks, but raw `http://...:9222` CDP itself is not encrypted or authenticated.

## Verify connectivity

For manual discovery from App Service SSH, send an accepted Host header:

```bash
curl --max-time 10 -H "Host: localhost:9222" "http://<CONTAINER_APP_FQDN>:9222/json/version"
```

The override makes Chrome advertise `ws://localhost:9222/devtools/browser/<BROWSER_ID>`. That
`localhost` is not reachable as the remote browser from App Service. Keep the path but connect to
the Azure FQDN. Use the ID from the latest discovery response; it can change when Chrome restarts:

```bash
curl -i --http1.1 --max-time 5 \
  -H "Host: localhost:9222" \
  -H "Connection: Upgrade" \
  -H "Upgrade: websocket" \
  -H "Sec-WebSocket-Version: 13" \
  -H "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==" \
  "http://<CONTAINER_APP_FQDN>:9222/devtools/browser/<BROWSER_ID>"
```

Expected: **HTTP 101** with a WebSocket accept key. For the sample handshake key,
the expected accept value is `s3pPLMBiTxaQ9kYGzzhZRbK+xOo=`. The key is a handshake nonce, not a
credential. A curl timeout after HTTP 101 is expected: this checks the upgrade, not CDP commands.

Run a scoped `RunTest` after connectivity checks. Inspect its recorded results, screenshots and
remaining browser pages to verify reporting and run-owned page cleanup.

## Issues and resolutions

| Issue | Cause and resolution |
|---|---|
| Behat reports a PHP warning as a step exception | Use the CLI warning-reporting mask above, verify it in a new PHP process, and rerun. Check an explicit `calls.error_reporting` override if the warning still fails a step. Fix the warning's source separately. |
| `ActivationFailed` after updating the image | Inspect image-pull errors, startup logs, ports and probes. Check that ingress still targets Chrome's TCP port `9222`, and verify registry authentication. |
| System logs only show connection to the events collector | Those messages are not Chrome logs or an explanation of the failure. Inspect revision state, available replica/container logs and explicit image-pull errors. |
| Test fails partway through with a lost browser connection | Check replica count first. More than one replica splits CDP calls across different browsers. Confirm `--min-replicas 1 --max-replicas 1`, and check whether platform maintenance was in progress. |
| Browser unreachable after an idle period | `minReplicas` is still at its default of 0 and the app scaled to zero. |
| Chrome starts but no endpoint is reachable | `--command` replaced the image entrypoint. Use `--args`, or start the command with `run.sh`. |
| `ImagePullFail` with `UNAUTHORIZED` / "authentication required" | Attach the registry-pull identity to the app, grant it the appropriate pull role, and select that same identity for registry authentication. Seeing the image in the portal or Cloud Shell only proves the operator has access, not that the app can pull it. |
| Browser cannot open the application | Container-side DNS, routing or certificate trust. Verify `base_url` (`BROWSER.MANAGER_CONFIG` or `--base_url`) is reachable from the container, not just from the application server. |
| Run uses local Chrome or the wrong endpoint | An explicit `--chrome_endpoint` on the command overrides the configuration file. Changing the deployment does not change BDT settings. |
| Run fails with `No setter method found for UXON property "..."` | `BROWSER.MANAGER_CONFIG` contains a setting the remote browser does not have (for example `executable` or `port_band_interactive`). Remove it. |

## Not covered

- **Parallel runs.** `RunParallel` has no remote support yet; it needs one endpoint per worker plus
  coordinated cleanup.
- **Downloads.** Files land inside the container, while BDT's checks look at server folders.
- **Automatic recovery.** BDT reattaches and resets its own tabs, but restarting the container app is
  an operator action.
