# RMM (endpoint agent)

Status: Phase 0 complete in Core (the extraction of RivetIT's endpoint agent): contracts, HTTP objects, migrations, the device-facing services and `DeviceApi`, and the technician, administration, installer, binary and MeshCentral side. Editions adopt it next (RivetIT first). Design: [endpoint-module-extraction.md](../design/endpoint-module-extraction.md); decision: [ADR-010](../architecture/ADR-010-endpoint-agent-module.md); wire protocol: [PROTOCOL.md](../rmm/PROTOCOL.md); API description: [openapi-device.yaml](../rmm/openapi-device.yaml). Phase 1 (software inventory, tags and groups, events, the database metric sink, check history, the live document) is built in Core as of v1.0.0-rc.9 and not yet adopted by an edition; see "Phase 1" below.

## Overview

`RivetCore\Rmm` is the edition-neutral home of the endpoint agent's server side (enrollment, devices, check-in, signed jobs, hosted updates, per-client installers, MeshCentral launch, the technician REST API and the administration operations behind an edition's settings page) and the base of a full RMM. It is **off by default** and costs nothing when off. Platforms in scope for Phase 0: Windows and Linux; macOS is deferred.

- **Owns:** the ten `endpoint_agent_*` tables, created by migrations `0014_endpoint_agent_core` (exact RivetIT DB 2.6.146 DDL, `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`), `0015_endpoint_agent_converge` (installs that stopped at RivetIT 2.6.145: `ca_pem`, `arch`, `binary_id`, the `(version, ring, arch)` key) and `0016_rmm_module_switches` (`features_json`, `limits_json`, `shed_level`, `ingest_mode`, `max_devices`). All three are in `CoreMigrations::all()`; on an install that already has the tables they are no-ops (apart from 0016's columns). There is no opt-in migration list: **an edition that runs `CoreMigrations::all()` gets these tables whether or not it enables the module** (RivetMSP included); the module itself stays off until the master switch is turned on. Never touched: `enabled`, keys, credentials, rows.
- **Does not own:** `assets`, `asset_interfaces`, `asset_rmm_links`, `rmm_alerts`, `rmm_integrations`, `rmm_scripts`, `rmm_remote_sessions`, `clients`, `locations`, `users` (ADR-002): they are reached only through the contracts below.
- **Ships data, not markup.** Core returns read models and validated operations; each edition renders its own pages, keeps its CSRF, session, permission wiring and navigation, and writes no SQL against `endpoint_agent_*`.
- The wire protocol, credential formats and signing vectors are frozen; `RmmProtocol` holds every frozen constant and `tests/Unit/Rmm/FrozenConstantsTest.php` pins them.

## Contracts an edition must implement

Required (all in `RivetCore\Rmm\Contracts`):

| Interface | Purpose | Reference adapter | Conformance case |
|---|---|---|---|
| `RmmTenancyInterface` | clients, locations, per-user client scope | `Testing\InMemoryRmmTenancy` | `Testing\RmmTenancyConformanceTestCase` |
| `RmmAssetsInterface` | asset match (serial, MAC, hostname), create, fill blanks, move | `Testing\InMemoryRmmAssets` | `Testing\RmmAssetsConformanceTestCase` |
| `RmmBridgeInterface` | integration row, link rows, alerts with ticket auto-close, saved scripts, remote-session log | `Testing\InMemoryRmmBridge` | `Testing\RmmBridgeConformanceTestCase` |
| `SecretBoxInterface` | encrypt the signing and Mesh keys with the edition's key | `Testing\InMemorySecretBox` | `Testing\SecretBoxConformanceTestCase` |
| `Contracts\AccessPolicyInterface` (existing, ADR-003) | who may view, run, reboot, open a remote session, administer | none: yours | `Testing\AccessPolicyConformanceTestCase` |

`AccessPolicyInterface` is asked `can($userId, $ability, 'client', $clientId)` (`$clientId = 0` is the role-level question) with the abilities of `Authz\RmmAbility`:

| Ability | Meaning | RivetIT's rule today |
|---|---|---|
| `rmm.device.view` | see devices, checks, job history (every other device ability needs it too) | `module_rmm >= 1` |
| `rmm.job.run_saved` | queue a saved-library script or a collect job, cancel a queued job, read job output | `module_rmm_scripts >= 2`, not a module-only login |
| `rmm.job.reboot` | queue a reboot | the same as run_saved |
| `rmm.job.run_script` | queue free-form script text | `module_rmm_scripts >= 3`, not a module-only login |
| `rmm.remote.launch` | open a MeshCentral session | `module_rmm_remote_connect >= 1`, not a module-only login |
| `rmm.device.manage` | approve, reject, revoke, retire, transfer, re-enroll, rotate, set ring, map a MeshCentral node | `role_is_admin` |
| `rmm.token.manage` | create and revoke enrollment tokens, issue installers | `role_is_admin` |
| `rmm.binary.publish` | upload agent binaries, make one current, manage releases and rings | `role_is_admin` |
| `rmm.admin` | module settings, switches, signing key, MeshCentral settings | `role_is_admin` |

A policy that enforces anything must deny an ability it does not know. An inactive account, a module-only login and the client scope (RivetIT: `user_client_permissions`) are the edition's rules; the client scope reaches Core through `RmmTenancyInterface::visibleClientIds()`.

Optional or defaulted: `RmmMetricSinkInterface` (`Support\NullRmmMetricSink` for editions without Metrics, `Support\DatabaseMetricSink` for editions that want history without a metrics subsystem; `Testing\RmmMetricSinkConformanceTestCase`), `RmmMetricReaderInterface` (what a sink that keeps history can answer: latest, peak, series; implemented by `DatabaseMetricSink`; `Testing\RmmMetricReaderConformanceTestCase`), `RmmEventsInterface` (the edition's event bus for the `rmm.*` events; `Support\NullRmmEvents` by default; `Testing\RmmEventsConformanceTestCase`), `RmmAuditInterface` (`Support\AuditServiceRmmAudit`; `Testing\RmmAuditConformanceTestCase`), `RmmModuleStateInterface` (edition kill switch and state-file directory; `Testing\RmmModuleStateConformanceTestCase`).

Reused: `DatabaseInterface`, `ClockInterface`, `Webhooks\UrlPolicy` (the MeshCentral probe: pass one with the networks an administrator allowed; the default refuses private addresses).

Run `RmmBridgeInterface` on the same database connection Core uses so its writes take part in Core's transactions. The in-memory adapters are reference implementations for Core's tests (`@internal`), not API.

## Key classes

| Class | Role |
|---|---|
| `RmmModule` | the composition root: `deviceApi()`, `technicianApi()`, `technician()`, `admin()`, `readModel()`, `installerService()`, `binaryStore()`, `mesh()`, `authorizer()`, `housekeeping()`, `enabled()` |
| `Http\RmmRequest`, `RmmResponse`, `RmmFileBody`, `ApiError`, `SapiEmitter`, `FileDownload` | framework-neutral HTTP objects; the edition builds the request (TLS and proxy trust, client IP stay its job), Core answers, `SapiEmitter` (or the edition) emits and never exits; a file body is verified (size, SHA-256) before a byte is sent and streamed in 64 KiB chunks |
| `Http\DeviceApi` | the five device endpoints (enroll, check-in, jobs, update, installer) |
| `Http\TechnicianApi` | `endpoint_devices`: list, detail, jobs, cancel, remote launch; the edition authenticates and passes an `Authz\RmmPrincipal` |
| `Authz\RmmAuthorizer`, `RmmAbility`, `RmmPrincipal` | one decision for the REST API, the web handlers and the administration operations |
| `Technician\TechnicianActions`, `ActionResult` | submit and cancel jobs, launch remote, approve or reject a pending device, link or create its asset, revoke, retire, transfer, re-enroll, rotate, ring, map a MeshCentral node, enrollment tokens |
| `Read\RmmReadModel` | device list (filters, pagination, scope), device view, fleet counters, approval queue with candidates and reasons, tokens, releases, binaries, settings summary; Phase 1: software, tags, groups, check history, network peak, live document |
| `Admin\RmmAdmin` | settings (switch, intervals, limits, checks, CA certificate, sub-switch presets), MeshCentral settings, signing key rotation, binaries, releases and rings, installers and deployment commands |
| `Binaries\BinaryStore` | validate (PE and ELF headers), store, publish, make current, offer as an update, serve agent binaries |
| `Installer\InstallerService`, `InstallerDownload`, `InstallerStamp` | per-client installers, token-gated download, deployment snippets (PowerShell for an RMM, Intune or GPO; a shell one-liner for Linux), the stamp format |
| `Mesh\MeshService`, `MeshCookie` | MeshCentral URL and node-id validation, the network-policy probe, the launch |
| `Settings\RmmSettings`, `Job\JobService`, `Device\DeviceService`, ... | the device-facing services (see the design) |

### Compose the module and answer a device request

```php
// docs-test: compose
use RivetCore\Rmm\Http\{RmmRequest, SapiEmitter};
use RivetCore\Rmm\RmmModule;

// $database, $clock, $tenancy, $assets, $bridge, $box, $audit, $policy are your adapters (see "Contracts").
$module = new RmmModule($database, $clock, $tenancy, $assets, $bridge, $box, audit: $audit, policy: $policy,
    options: ['binary_dir' => $binaryDir]);   // where hosted agent binaries live (denied over HTTP, outside your backups)

$api = $module->deviceApi(fn (string $bucket, int $limit, int $window): bool => true);   // your rate limiter: true = within budget
$request = new RmmRequest('GET', 'agent_jobs', [], [], ['authorization' => 'Bearer ' . str_repeat('a', 64)], '203.0.113.5', null, true, null, null);
$response = $api->handle($request);              // 401 invalid_token: the credential is unknown
// (new SapiEmitter())->emit($response);         // in an api/v1/agent_jobs.php bridge: status, headers, body, then exit
```

### Administer: switch on, publish a binary, create a deployment command

```php
// docs-test: administer
use RivetCore\Rmm\Authz\RmmPrincipal;

$admin = $module->admin();
$me = new RmmPrincipal(1, 'Alex');                       // the signed-in administrator
$admin->saveSettings($me, ['enabled' => 1, 'service_url' => 'https://rmm.example.com'])->ok;   // true; the first switch-on mints the signing key
$admin->uploadBinary($me, $uploadedTmpFile, '1.2.0', 'amd64', ['activate' => true, 'release_ring' => 'pilot', 'rollout_pct' => 10]);
$r = $admin->deploymentCommands($me, $clientId, 0, 'stable', 72, 25, 'Front desk', 'amd64');
echo $r->data['commands']['powershell'];                 // paste into an RMM, an Intune platform script or a GPO startup script
echo $r->data['commands']['linux'];                      // run as root next to install-linux.sh
```

### The technician REST API (the edition's `api/v1/endpoint_devices.php`)

```php
// docs-test: technician
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\RmmRequest;

$who = new RmmPrincipal(7, 'Sam');                       // your API-token authentication already ran
$request = new RmmRequest('GET', 'endpoint_devices', [], ['status' => 'online', 'limit' => '50'], [], '198.51.100.7', null, true, null, null);
$response = $module->technicianApi()->handle($request, $who);   // {"data": [...], "total": N}; POST .../jobs, .../remote take a JSON body stream
```

### The same operations as plain calls (a web handler)

```php
// docs-test: actions
$t = $module->technician();
$r = $t->submitJob($who, $deviceId, ['type' => 'reboot', 'confirm' => true]);   // ActionResult: ok, http, code, message, data
if (!$r->ok) { /* $r->http, $r->code ('forbidden', 'not_found', 'conflict', 'invalid', 'confirmation_required'), $r->message are safe to show */ }
$list = $module->readModel()->listDevices(['status' => 'pending_approval'], $module->authorizer()->visibleClientIds(7), 50, 0);
```

## Phase 1: inventory, tags, groups, events, history

Added in v1.0.0-rc.9 (migration `0018_rmm_inventory_foundation`, DB-additive: eleven new tables, none of the ten original ones is altered). Everything is off or empty until used: the software inventory needs the `inventory_software` sub-switch, the events need an `RmmEventsInterface` that is not the null one, the metric history needs the sink. An edition that does nothing sees no change (the golden transcripts replay identically).

| Area | What it does | Core classes | Storage |
|---|---|---|---|
| Capabilities | the agent announces `capabilities` (`software_inventory`, `job:<type>`, `check:<type>`); the server stores them and, only for a device that announced `software_inventory` while `inventory_software` is on, answers `features: ["software_inventory"]` | `Device\DeviceState` | `rmm_device_state` |
| Software inventory | validates and applies the `software` block of a check-in: baseline on the first report, then deltas by hash chain, a full list on request and daily; every install, upgrade, downgrade and removal is logged | `Software\SoftwareService`, `SoftwareHash`, `SoftwareVersion` | `rmm_device_software` (current, with `first_seen_at`, `last_seen_at`, `removed_at`), `rmm_software_history` |
| Tags and groups | free tags on devices; static groups made of devices added by hand plus every device that carries one of the group's tags | `Tags\TagService`, `GroupService`, `Technician\InventoryActions` | `rmm_tags`, `rmm_device_tags`, `rmm_groups`, `rmm_group_devices`, `rmm_group_tags` |
| Events | nine `rmm.*` events (see below), published after commit | `RmmEvent`, `Support\RmmEventPublisher`, `Webhooks\EventCatalog` group `rmm` | none |
| Check history | every change of a check's status, and an unchanged status at most once per `check_history_gap_s` (3600), kept `check_history_days` (7); read as a trend with time-weighted availability | `Checks\CheckEvaluator`, `RmmReadModel::checkHistory()` | `endpoint_agent_check_history` |
| Metric history | hourly rollups (count, sum, min, max) and the latest reading per metric instance; the 24 hour network peak | `Support\DatabaseMetricSink`, `RmmReadModel::networkPeak()` | `rmm_metric_latest`, `rmm_metric_hourly` |
| Live document | the small JSON the device page polls, with an ETag and a poll interval that slows under load | `RmmReadModel::deviceLive()` | none |

**Read model additions** (`RmmReadModel`): `softwareState()`, `softwareFor()`, `softwareHistory()`, `softwareCatalog()`, `outdatedSoftware()`, `deviceTags()`, `deviceGroups()`, `tags()`, `groups()`, `checkHistory()`, `networkPeak()`, `deviceLive()`; `listDevices()` takes the filters `tag`, `group`, `software` and `location_id`, and its extras mode adds each device's `tags`. The technician REST list keeps its frozen shape.

**REST routes** (all additive, JSON shapes in [openapi-device.yaml](../rmm/openapi-device.yaml)): `endpoint_devices/{id}/software`, `.../software/history`, `.../software/refresh`, `.../tags`, `.../checks/{key}/history`, `.../network`, `.../live`, and the fleet routes `endpoint_devices/tags`, `groups`, `software`, `software/outdated`. Reads need `rmm.device.view`; changing a device's tags needs `rmm.device.manage` for its client; creating, renaming and deleting tags and groups needs `rmm.device.manage` as a role; a software refresh needs `rmm.job.run_saved`.

**Events.** `RmmEventsInterface::publish($event, $payload)` receives, after the change is committed (a rolled-back check-in publishes nothing), `rmm.device.enrolled`, `rmm.device.offline`, `rmm.device.online`, `rmm.check.failed`, `rmm.check.recovered`, `rmm.job.completed`, `rmm.job.failed`, `rmm.software.installed` and `rmm.software.removed`. Every payload has `device_id`, `asset_id`, `client_id`, `hostname` and `occurred_at`; the event's own fields are in `Webhooks\EventCatalog` and `RmmEvent`. A bus that throws is logged and ignored. Offline is announced once per offline period by `Housekeeping::run()` (devices silent for longer than the stale window are recorded without an event); online is announced by the first check-in after it; at most 25 software events are published per report (the change log keeps all of them). While the null bus is in place the module does no extra work to detect events.

**What an edition does to adopt Phase 1**
1. Run the Core migrations (0018 creates the tables).
2. Pass an `RmmEventsInterface` on its event bus as the last constructor argument of `RmmModule` (optional).
3. RivetMSP: pass `new Support\DatabaseMetricSink($database, $clock)` as the metric sink; RivetIT keeps its own Metrics subsystem and can implement `RmmMetricReaderInterface` on its adapter to feed the network peak.
4. Switch on `inventory_software` (`RmmAdmin`/`RmmSettings::update(['features_json' => ...])`) once agents of this release are rolled out.
5. Render the new read models and routes on the device and fleet pages; pass the new limits (`check_history_days`, `check_history_gap_s`, `software_history_days`) through its settings page if it wants them editable.

Capacity numbers for the new write paths are in [CAPACITY.md](../rmm/CAPACITY.md).

## Behaviour that matters

- **Authorization is decided on every call.** The role may not view devices at all: 403, which reveals nothing about any device. The device is missing **or** outside the caller's clients: the same 404. The role lacks the specific ability: 403 with a generic reason, audited for jobs and remote sessions. A destructive job (every reboot) needs `confirm`; only a device linked to an asset receives jobs.
- **The authorizer remembers its answers for the life of the object.** `RmmAuthorizer` asks the policy once per user, ability and client and the tenancy once per user, so a page that checks fifteen things costs a handful of edition calls. The module switch is never remembered. An edition whose process outlives a request, or that changes a role mid-request, calls `forget()` (or passes `memoize: false`); the policy and tenancy adapters themselves still answer immediately.
- **Authorized reads.** `RmmReadModel::job()` (one job with its redacted output, same 403/404 rules as the REST API) and `recentFailedJobs()` (metadata only, client-scoped) take an `RmmPrincipal`, so an edition renders them without SQL.
- **Administration works while the module is off** (turning it on is one of the operations); jobs, remote and the technician REST API do not (`404 disabled`).
- **A hostname alone never links a device** to an asset; two machines are never merged; the matching rules are in [PROTOCOL.md](../rmm/PROTOCOL.md).
- **Installers.** `InstallerService::issue()` creates an audited enrollment token and the stamp payload from the current base binary; a token whose installer could not be served is revoked again. Only Windows (PE) agents are hosted: the binaries table has no platform column, so a Linux agent is installed from the release tarball with `install-linux.sh` (the Linux snippet does that); `BinaryStore::detect()` still identifies an ELF file and refuses it with that hint.
- **MeshCentral.** The login key is stored encrypted (`SecretBoxInterface`) and used to impersonate ONE limited MeshCentral account; the login URL is minted at the click and never stored or logged, only a random session id reaches the edition's remote-session log. The health probe goes through the injected `UrlPolicy` (private, loopback, link-local and metadata addresses refused unless the administrator allowed the network; loopback and metadata are never allowed) and is pinned to the vetted addresses.
- **Binaries.** A released version never changes under devices: re-publishing the identical file is a no-op, a different file for an existing `(version, arch)` is refused. File names are random (`bin_<hex>.bin`); deleting only deactivates.
- **Signing key rotation** makes every enrolled agent refuse jobs and updates until it re-enrolls (it pins the key it received); `RmmAdmin::rotateSigningKey()` says so and counts the devices affected.

## Configuration

Constructor options of `RmmModule`: `binary_dir`, `max_upload_bytes` (default 64 MiB), `allow_insecure_http` (loopback test servers only), `allow_linux`, `host_fallback`, `integration_name`, `installer_prefix`, `client_label` (what your edition calls a client in text users read, default `client`; RivetIT: `department`) and `denial_reasons` (ability => sentence, replaces that ability's generic denial text). `deviceApi()` takes a `$disabledAnswer`: `uniform` (default, `503 module_disabled`) or `compat` (RivetIT's legacy `403 forbidden`); see UPGRADING. Everything else is stored in `endpoint_agent_settings` and edited through `Admin\RmmAdmin`. The module switch (master, sub-switches, limits, load shedding) and its zero-database state file are specified in the design (sections 12 and 13) and in [CAPACITY.md](../rmm/CAPACITY.md).

## How it fails

- Device endpoints answer `{"error", "code"}` with the statuses listed in [PROTOCOL.md](../rmm/PROTOCOL.md); an unexpected exception is a generic `500 internal` (details go to the PHP log only). `SapiEmitter` answers a file body that fails verification with the same 500.
- `TechnicianApi` answers `401 {"error":"Unauthorized"}` without a principal, `404 disabled` when the module is off (after the 401: an anonymous caller learns nothing about the switch, and the pre-bootstrap gate deliberately does not answer this endpoint), 403/404 as above, `413` for a body over 1 MiB, `400` for a body that is not a JSON object, `500 internal` for an unexpected exception.
- `TechnicianActions` and `RmmAdmin` never throw for a refusal: they return an `ActionResult`. `InvalidArgumentException` comes only from programming errors (an unknown setting column). `BinaryStore::publish()` throws `RuntimeException` when the storage directory is not writable.
- A policy that throws is treated as a denial.

## Security notes

Device authentication, SYSTEM-level job execution and remote access make this a high-risk module: a focused review is required before it is enabled by default anywhere. Credentials are stored as hashes (device and enrollment tokens), the signing key and the Mesh login key are sealed with the edition's key, no read model contains a secret, job output is redacted and capped when it is stored, script text is never written to the audit log (a hash is), and every denial is a generic message.

What is **verified**: the golden transcripts of the original replay identically through `DeviceApi` and `TechnicianApi` (`php scripts/rmm-golden/replay-core.php replay`); the RivetIT role matrix (`RoleMatrixTest`), deployment (`DeployTest`), administration (`AdminTest`), read models (`ReadModelTest`) and technician actions (`TechnicianTest`) run against a scratch database. **Not verified**: a real MeshCentral server (only `tests/Support/MockMeshCentral.php`), the Windows installer on a Windows host, the Linux agent on a physical host.

## Used by

Not yet adopted: RivetIT adopts first (it already has the tables), then RivetMSP.

## Links

[Design](../design/endpoint-module-extraction.md) | [ADR-010](../architecture/ADR-010-endpoint-agent-module.md) | [Protocol](../rmm/PROTOCOL.md) | [OpenAPI](../rmm/openapi-device.yaml) | [Agent build](../rmm/AGENT_BUILD.md) | [Migration](migration.md) | [Conformance kit](../conformance.md)
