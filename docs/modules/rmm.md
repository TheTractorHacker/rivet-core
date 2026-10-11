# RMM (endpoint agent)

Status: Phase 0 complete in Core (the extraction of RivetIT's endpoint agent): contracts, HTTP objects, migrations, the device-facing services and `DeviceApi`, and the technician, administration, installer, binary and MeshCentral side. Editions adopt it next (RivetIT first). Design: [endpoint-module-extraction.md](../design/endpoint-module-extraction.md); decision: [ADR-010](../architecture/ADR-010-endpoint-agent-module.md); wire protocol: [PROTOCOL.md](../rmm/PROTOCOL.md); API description: [openapi-device.yaml](../rmm/openapi-device.yaml). Phase 1 (software inventory, tags and groups, events, the database metric sink, check history, the live document) is built in Core as of v1.0.0-rc.9 and not yet adopted by an edition; see "Phase 1" below. Phase 3 (alerting maturity: threshold tiers, flap detection, maintenance windows, dependency suppression, storm control, escalation, twelve more check types) is built in Core behind the `alerting` sub-switch; see "Phase 3" below.

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
| `rmm.job.run_script` | queue free-form script text; publish scripts to the library (Phase 2) | `module_rmm_scripts >= 3`, not a module-only login |
| `rmm.job.approve` | Phase 2: approve or reject a run or schedule that needs a second person (a script flagged `requires_approval`, or a run on more devices than the `approval_bulk_threshold` limit); never one's own request | new: give it to fewer people than `run_saved`, e.g. `role_is_admin` or `module_rmm_scripts >= 4` |
| `rmm.remote.launch` | open a MeshCentral session | `module_rmm_remote_connect >= 1`, not a module-only login |
| `rmm.device.manage` | approve, reject, revoke, retire, transfer, re-enroll, rotate, set ring, map a MeshCentral node | `role_is_admin` |
| `rmm.token.manage` | create and revoke enrollment tokens, issue installers | `role_is_admin` |
| `rmm.binary.publish` | upload agent binaries, make one current, manage releases and rings | `role_is_admin` |
| `rmm.alert.manage` | acknowledge and resolve alerts, maintenance windows of one client or one device, a device's parent (Phase 3; needs `rmm.device.view` too) | suggested: like `rmm.job.run_saved` |
| `rmm.admin` | module settings, switches, signing key, MeshCentral settings; Phase 2: policies, schedules, custom field definitions, secret field values, re-signing scripts; Phase 3: escalation policies, storm-control limits and maintenance windows that span clients (all, site, group, tag) | `role_is_admin` |

A policy that enforces anything must deny an ability it does not know. An inactive account, a module-only login and the client scope (RivetIT: `user_client_permissions`) are the edition's rules; the client scope reaches Core through `RmmTenancyInterface::visibleClientIds()`.

Optional or defaulted: `RmmMetricSinkInterface` (`Support\NullRmmMetricSink` for editions without Metrics, `Support\DatabaseMetricSink` for editions that want history without a metrics subsystem; `Testing\RmmMetricSinkConformanceTestCase`), `RmmMetricReaderInterface` (what a sink that keeps history can answer: latest, peak, series; implemented by `DatabaseMetricSink`; `Testing\RmmMetricReaderConformanceTestCase`), `RmmEventsInterface` (the edition's event bus for the `rmm.*` events; `Support\NullRmmEvents` by default; `Testing\RmmEventsConformanceTestCase`), `RmmEscalationInterface` (Phase 3: the edition's delivery of escalation notices, acknowledgement and severity changes; `Support\NullRmmEscalation` by default, which delivers nothing and says so; `Testing\RmmEscalationConformanceTestCase`, reference `Testing\InMemoryRmmEscalation`), `RmmAuditInterface` (`Support\AuditServiceRmmAudit`; `Testing\RmmAuditConformanceTestCase`), `RmmModuleStateInterface` (edition kill switch and state-file directory; `Testing\RmmModuleStateConformanceTestCase`).

Reused: `DatabaseInterface`, `ClockInterface`, `Webhooks\UrlPolicy` (the MeshCentral probe: pass one with the networks an administrator allowed; the default refuses private addresses).

Run `RmmBridgeInterface` on the same database connection Core uses so its writes take part in Core's transactions. The in-memory adapters are reference implementations for Core's tests (`@internal`), not API.

## Key classes

| Class | Role |
|---|---|
| `RmmModule` | the composition root: `deviceApi()`, `technicianApi()`, `technician()`, `admin()`, `readModel()`, `installerService()`, `binaryStore()`, `mesh()`, `authorizer()`, `housekeeping()`, `enabled()`; Phase 2: `policyStore()`, `policyActions()`, `scripts()`, `scriptActions()`, `customFields()`, `fieldActions()`, `approvals()`, `schedules()`, `setScheduleGate()` |
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

## Phase 2: policies, script library, schedules, approvals, custom fields

Added in the release after v1.0.0-rc.9 (migration `0019_rmm_policies_and_scripts`, DB-additive: eleven new tables, nothing existing is altered). Two new sub-switches, both off until an administrator turns them on: `policies` (the check-in delivers the effective policy) and `scripts` (the scheduler, approvals housekeeping and library jobs run). Without them a check-in, a job and a cron run behave exactly as before and the golden transcripts replay identically. Nothing here is adopted by an edition yet.

| Area | What it does | Core classes | Storage |
|---|---|---|---|
| Policies | settings assigned to a global, client, site (location), group, tag or device scope; the most specific scope wins each setting; priority, `enforce`, `disable` and per-assignment `overrides`; check templates, intervals inside the global clamps, three feature toggles, update-ring pinning; versioned | `Policy\PolicyResolver` (pure), `PolicyLayer`, `PolicySettings`, `EffectiveConfig` (pure), `PolicyStore`, `EffectivePolicy`, `Technician\PolicyActions` | `rmm_policies`, `rmm_policy_versions`, `rmm_policy_assignments` |
| Delivery | `CheckinService` lays the resolved policy over the instance configuration and answers with the usual fields; an agent that announced `policies` also gets `config.policy` and the feature `policies` | `Checkin\CheckinService`, `Settings\RmmSettings::signCheckList()` | none |
| Script library | named scripts (`powershell`, `bash`, `python`), immutable numbered versions signed with the instance Ed25519 key, a parameter definition (string, int, bool, choice, secret), tags, `requires_approval`, `destructive`; the integrity check (hash and signature) runs before every run | `Scripts\ScriptService`, `ScriptLanguage`, `ParamSchema` | `rmm_scripts_v2`, `rmm_script_versions` |
| Running | one device (`POST endpoint_devices/{id}/jobs` with `library_script_id`, or `TechnicianActions::submitJob`), or a target: device, tag, group, client, site, policy or all (`POST endpoint_devices/runs`); parameters checked per device; `{{field.name}}` filled per device | `Scripts\ScriptRunner`, `BulkRunner`, `TargetResolver`, `Technician\ScriptActions` | `rmm_job_extra` (script, version, schedule, approval, idempotency key, sealed secrets) |
| Schedules | an interval or a UTC cron expression, a target, jitter, overlap `skip` or `queue`, a window for offline devices, a pinned version and body hash; housekeeping turns each due slot into one run and one job per device, guarded by an idempotency key; per-device result history | `Scripts\ScheduleService`, `ScheduleRunner`, `CronSchedule`, `Contracts\ScheduleGateInterface` | `rmm_schedules`, `rmm_schedule_runs`, `rmm_job_extra` |
| Approvals | pending_approval, approved, rejected, cancelled, expired; the request is frozen as canonical JSON with its SHA-256; a different user with `rmm.job.approve` for every client reached decides once; an approved run is queued as the requester, inside the requester's clients, and finished by housekeeping if the process died half way | `Approvals\ApprovalService`, `Scripts\ApprovedRunExecutor` | `rmm_approvals` |
| Custom fields | typed values (text, number, bool, date, list, secret) per client, site or device; defaults; usable as script parameters | `Fields\CustomFieldService`, `FieldPlaceholders`, `Technician\FieldActions` | `rmm_custom_fields`, `rmm_custom_field_values` |
| Starter pack | 73 library scripts (41 Windows PowerShell, 32 Linux bash; risk class, dry run by default for anything that changes the machine, approval per risk), 3 check template sets and 3 policy drafts (disabled, unassigned), shipped in a versioned, checksummed folder; `StarterInstaller` plans and installs through `ScriptService` and `PolicyStore` (idempotent by pack, item and content hash, an edited script is never overwritten, uninstall removes only untouched items); routes `endpoint_devices/starter/{catalog,plan,install,uninstall}` (`rmm.admin`); event `rmm.starter.installed`; optional extra packs through the `starter_packs` option. See [docs/rmm/STARTER_PACK.md](../rmm/STARTER_PACK.md) | `Starter\StarterPack`, `StarterInstaller`, `StarterLint`, `Technician\StarterActions`, `Http\StarterApi` | none (markers in script version notes, script tags and policy descriptions) |

**Settings and precedence.** See PROTOCOL.md 3.2.2 for the wire effect and `PolicyResolver` for the rules. A policy cannot switch on what the instance has off: `feature.*` toggles can only narrow. The instance's own check list stays the base layer, so a policy adds, replaces or removes checks by key.

**Script safety, in order of what protects what.**
- A script version is immutable and signed; `ScriptService::load()` refuses a version whose text no longer matches its hash or whose signature does not verify (an edit in the database, or a signing-key rotation until `resign` is called). A schedule or an approval pins a version and its hash, so a later edit never changes what was approved.
- Script text is never written to the audit log or to an event: audit lines carry the name, the version and the first 16 characters of the SHA-256.
- Parameters are validated against the definition and passed to the agent as environment variables and generated variable definitions, never spliced into code (PROTOCOL.md 3.3). Parameter names that would shadow an interpreter variable (`path`, `home`, `error`, `if`, ...) are refused when the script is saved.
- A `secret` parameter (a literal or a `{{field.name}}` placeholder of a secret field) is sealed with the edition's `SecretBoxInterface` in `rmm_job_extra`, replaced by a marker in the job row, put back only when the job is signed for the device, removed from the reported output, and wiped when the job is final (housekeeping wipes the leftovers of jobs that never reported). A secret field can only fill a secret parameter, alone. In an approved run or a schedule a secret parameter must be a placeholder, so no secret is stored in a request.
- Everything runs as the agent's service account (SYSTEM on Windows, root on Linux); there is no run-as choice.

**Limits** (the `limits_json` setting): `approval_bulk_threshold` (0 = never; a run or schedule on more devices than this needs approval), `approval_expiry_h` (48), `schedule_batch` (200 jobs per run per housekeeping pass; divided by 2 at load-shedding level 1, by 4 at level 2, paused at level 3) and `bulk_run_max` (1000 devices in one run).

**Events**: `rmm.policy.assigned`, `rmm.script.run`, `rmm.approval.requested`, `rmm.approval.decided`. The policy and approval events are not about one device: `device_id` is 0, `asset_id` null, `hostname` empty.

**What an edition does to adopt Phase 2**
1. Run the Core migrations (0019 creates the tables) before deploying the new code.
2. Map the new ability `rmm.job.approve` in its `AccessPolicyInterface` (an unknown ability is denied, so until it does nobody can approve; nothing else is affected).
3. Switch on `policies` and `scripts` (`RmmSettings::update(['features_json' => ...])`) once agents of this release are rolled out. Old agents keep working and receive the policy's values without the new keys.
4. Optional: implement `Contracts\ScheduleGateInterface` (the alerting phase's maintenance windows) and call `RmmModule::setScheduleGate()`.
5. Render the new pages from `readModel()->automation()` (`AutomationReader`) and the actions `policyActions()`, `scriptActions()`, `fieldActions()`; the REST routes are served by `technicianApi()` unchanged.

Capacity numbers for the scheduler and the check-in policy read are in [CAPACITY.md](../rmm/CAPACITY.md).

## Phase 3: alerting maturity

Migration `0020_rmm_alerting_maturity` (eight new tables, nothing existing altered, idempotent). It stays dormant until the **`alerting` sub-switch** (`features_json`) is turned on: with it off the check evaluator runs the Phase 0 code, no Phase 3 table is read and a check-in costs the same number of statements as before (measured, see [CAPACITY.md](../rmm/CAPACITY.md)). Old agents and the golden transcripts are unaffected either way. Wire changes are additive and agent-invisible except the optional `value` of a check result and the new check types, which are offered only to an agent that announced `check:<type>` ([PROTOCOL.md](../rmm/PROTOCOL.md) 3.2.2).

| Area | Behaviour | Core classes | Storage |
|---|---|---|---|
| Threshold tiers | A check that reports a `value` can carry `params.thresholds`: `warn` and `crit` limits (`gt`, `gte`, `lt`, `lte`), `hysteresis`, and a duration (`for_samples` consecutive samples AND `for_minutes`). The final status is the worse of the agent's own verdict and the tier of the value. A duration replaces the failure debounce (the alert opens when the tier takes effect); lowering a tier is immediate and the recovery debounce still applies | `Alerting\Thresholds`, `CheckPipeline` | `rmm_check_eval` |
| Per-device override and policies | `PUT endpoint_devices/{id}/checks/{key}/thresholds` replaces fields of the definition's thresholds for one device (`rmm.device.manage`); a check must declare `thresholds` to be overridable. Phase 2's policies work as they are: a policy's `check.<key>` template is a normal check definition, so it can carry `params.thresholds`, `flap` and `ignore_parent`, and the evaluation reads them from the device's own effective check list (the check-in hands that list's alerting parameters on, also in queued mode). The `threshold_resolver` option (`ThresholdResolverInterface`) replaces how the override and the definition are combined | `ThresholdResolverInterface`, `DefaultThresholdResolver`, `CheckEvalStore`, `AlertingEngine::definitionsOf()` | `rmm_check_eval.override_json` |
| Severity | `warn` and `fail` results are two severities (`warning`/`error` to the bridge). A rise or fall of an open alert updates Core's record, tells the edition (`RmmEscalationInterface::raiseAlertSeverity`) and emits `rmm.alert.escalated` with `reason: severity` | `AlertService` | `rmm_alert_meta` |
| Flap detection | `params.flap` (`window` 6 to 50, `high_pct`, `low_pct`): when the share of status changes in the window reaches the high mark the check is "flapping" until it falls to the low mark; while it flaps no alert opens and none resolves | `FlapDetector` | `rmm_check_eval` |
| Maintenance windows | One-time or recurring (daily, weekly, monthly; time zone aware; day numbers a month does not have are clamped to its last day; DST gaps and repeats resolved by Core itself). Scope: all, client, site (`location_id`), group, tag, device. `mute`: alerts that would open are held back and open after the window if the check is still bad, escalation pauses, an open alert stays open; `suppress`: results are recorded but not evaluated at all. `MaintenanceService::activeFor($device)` is the lookup for any consumer; `MaintenanceScheduleGate` (Phase 2's `ScheduleGateInterface`, installed by default while `alerting` is on) makes a scheduled script wait while a `suppress` window is open for the device (`defer`: it starts after the window if the schedule's own window is still open); a `mute` window never holds a script back | `Alerting\MaintenanceSchedule`, `MaintenanceService`, `ScopeMatcher` | `rmm_maintenance_windows` |
| Dependency suppression | A device may name one parent (same client, no loops). While an ancestor (up to 5 levels) is offline, alerts of its children do not open (`params.ignore_parent: true` opts a check out) | `DependencyService` | `rmm_device_parents` |
| Storm control | At most N alerts may open per client and in total per window (defaults 100 and 500 per 10 minutes; 0 = off). A held alert is asked again on its next sample, so nothing is lost; one summary alert (`agent:storm:<scope>:<window>`) says that storm control is active and is resolved when the rate drops | `StormControl` | `rmm_alerting_settings`, `rmm_storm_summaries` |
| Dedupe and grouping | One record per (device, check, episode), enforced by a unique key, on top of the bridge's unique alert key; alerts of one check kind in one client share a `group_key` and `GET alerts/groups` counts them | `AlertService` | `rmm_alert_meta` |
| Escalation | Policies (scope, minimum severity, steps `after_min` + targets, optional repeat) evaluated by `Housekeeping::run()`, never inside a check-in. Acknowledging or resolving stops the clock. Delivery is the edition's: `RmmEscalationInterface::notify()`; a `false` answer or an exception is retried after a minute, 5 times per step, then the chain moves on | `Alerting\EscalationService`, `AlertingHousekeeping` | `rmm_escalation_policies`, `rmm_escalation_steps`, `rmm_alert_meta` |
| Events | `rmm.alert.opened`, `.escalated`, `.acknowledged`, `.resolved`, `rmm.maintenance.started`, `.ended` through `RmmEventsInterface` (the maintenance events are not about one device: `device_id` 0, like the Phase 2 events; a device-scoped window carries the device in `scope_id`) | `RmmEvent`, `Support\RmmEventPublisher` | none |
| Check types | `cpu`, `memory` (`mem_used_pct`, `swap_used_pct`, Linux `psi_some_avg10`), `process`, `port`, `ping`, `http` (status, latency, text, certificate expiry), `cert` (files, directories, Windows stores, a TLS endpoint), `ntp` (SNTP offset), `smart`; Windows `av` (Defender); Linux `systemd_failed`, `inodes`. Every type has a param schema with size caps and defaults in `Checks\CheckCatalog`; `GET endpoint_devices/check_types` serves it | `Checks\CheckCatalog`, `Settings\ChecksValidator`; Go: `endpoint-agent/internal/collect/checks_ext*.go` | none |

**REST routes** (all additive, shapes in [openapi-device.yaml](../rmm/openapi-device.yaml)): `endpoint_devices/alerts` (list, `groups`, `{id}`, `{id}/ack`, `{id}/resolve`), `maintenance` (CRUD), `escalation_policies` (CRUD), `alerting/settings`, `check_types`, and per device `{id}/alerts`, `{id}/alerting` (why a bad check has no alert), `{id}/maintenance`, `{id}/parent`, `{id}/checks/{key}/thresholds`. Grants: reads `rmm.device.view`; acknowledge, resolve, parents and windows of one client or device `rmm.alert.manage`; windows over all, a site, a group or a tag, escalation policies and the storm limits `rmm.admin`; threshold overrides `rmm.device.manage`. The same operations are plain calls on `RmmModule::alertingActions()` (`Alerting\AlertingActions`) and the reads on `alerts()` (`AlertService::list/find/groups`).

**What an edition does to adopt Phase 3**
1. Run the Core migrations (0020).
2. Optional: implement `RmmEscalationInterface` and pass it as the `escalation` option of `RmmModule`. Without it nothing is delivered and escalation steps stay due (they are retried, then skipped; Core never marks an undelivered notice as sent). Use `Testing\RmmEscalationConformanceTestCase`.
3. If its own screens acknowledge or resolve alerts, call `$module->alerts()->acknowledge($alertId, $userId)` / `resolveExternal($alertId)` from that path so Core's record and the escalation clock agree.
4. Map the new ability `rmm.alert.manage` in its `AccessPolicyInterface`.
5. Switch on `alerting` (`RmmSettings::update(['features_json' => [..., 'alerting' => true]])`) once agents of this release are rolled out. Alerts that were open before are adopted by the next housekeeping run.
6. Render the new read models; it should also stop honouring its own `rmm_maintenance_mode` flag for agent alerts if it wants windows to be the single source (Core's windows do not read that flag).

## Scale: the write path, storage, workers, read cache, herd control

Migration `0021_rmm_scale_storage` (five new tables, nothing existing altered). All of it is on by default only where it changes no behaviour (the cheaper check-in, the cache of the dashboard counters); the rest is off until a limit or an option turns it on. Measurements and the model are in [CAPACITY.md](../rmm/CAPACITY.md) 13 to 16; [SCALING.md](../rmm/SCALING.md) says what is done and what is pending (the 24 hour / 10,000 device validation is NOT done).

| Area | Behaviour | Core classes | Storage |
|---|---|---|---|
| Write path | A steady check-in: one device `UPDATE`, no re-read of the device row, check rows read in one statement and upserted in one, one job probe. Same response bytes (the golden replay is the proof). Opt-in `check_touch_s` skips the "last reported" refresh of an unchanged healthy check | `Checkin\CheckinService`, `Checks\CheckEvaluator`, `Job\JobService` | none |
| Rollups and partitions | Daily tier from the hourly rows (windows over 7 days read it); opt-in, probed time partitions of the history tables with `DROP PARTITION` retention, the batched DELETE as the fallback | `Storage\MetricRollup`, `PartitionMaintenance`, `StorageMaintenance`; `Support\DatabaseMetricSink` | `rmm_metric_daily`, `rmm_storage_state` |
| Workers | SKIP LOCKED claiming (lease fallback), exact per-queue limit, dead-worker reclaim, autoscaling hint and Prometheus gauges | `Jobs\JobClaimer`, `Capacity\IngestQueue`, `AutoscaleHint` | `integration_jobs` |
| Read path | Stale-while-revalidate cache for the fleet counters, the panel and (shared cache) the release list; soft and hard invalidation hooks | `Support\RmmCache` | `rmm_cache` or your PSR-16 cache |
| Herd control | Warm start ramp, token bucket per client for check-ins and enrollment, both optional; the gate template enforces the warm start before any database work | `Capacity\HerdControl`, `DbTokenBucketStore`, `RedisTokenBucketStore` | `rmm_token_buckets`, `rmm_herd.json` |
| External metric store | Optional time-series store behind a bounded outbox; the database sink stays for "latest" and as the fallback | `Metrics\*`, `Contracts\ExternalMetricStoreInterface` | `rmm_metric_outbox` |

**What an edition does to adopt it:** see UPGRADING.md ("Adopting RMM scale"). The new `RmmModule` options are `cache` (a PSR-16 cache), `redis` (a `RedisClientProviderInterface`) and `metric_reader`; the new accessors are `storage()`, `partitions()`, `cache()` and `herd()`.

## Phases 6 and 8: remote tools and log collection

Built after v1.0.0-rc.10 (migration `0022_rmm_remote_and_logs`, five new tables, nothing existing altered). Everything is off until an administrator turns on the sub-switches `remote_tools`, `filetransfer` and `logs` (none of them is in the legacy defaults, so an upgrade changes nothing and the golden transcripts of the old agents replay identically). The operator guide, with the risk texts, the limits and the built-versus-remaining list, is [REMOTE_AND_LOGS.md](../rmm/REMOTE_AND_LOGS.md); the wire contract is PROTOCOL.md 3.2.4 and 3.6 to 3.8.

| Area | Behaviour | Core classes | Storage |
|---|---|---|---|
| Job types | `svc_list`, `svc_control`, `proc_list`, `proc_kill`, `file_get`, `file_put` in the registry, each with a capability flag the device must have announced (`remote:services`, `remote:processes`, `remote:files`; a device that announced nothing is never assumed to have it), a strict parameter validator and a default timeout. `JobType::$capability` is the new registry field; `JobService::create()` refuses (`code: unsupported`) and `offer()` skips a job the device cannot run | `Remote\RemoteJobTypes`, `RemoteProtocol`, `Job\JobType`, `JobTypeRegistry`, `JobService` | `endpoint_agent_jobs` |
| Services and processes | Queue a list, read the latest answer, control a service, end a process. Confirmation rules and the agent's own-service guard are on the server and again on the agent. The generic `POST .../jobs` refuses these types, so nothing can bypass the validators, the confirmation or the switch | `Remote\RemoteActions` | `endpoint_agent_jobs` |
| File transfer | Ledger of transfers (state machine `awaiting_content` / `queued` / `receiving` / `delivering` / `complete` / `failed` / `expired` / `deleted`), a one-time token per transfer (only its SHA-256 is stored), chunks of at most 1 MiB, the server hashes what it stored, size cap, storage budget, expiry and pruning; download is open to `rmm.remote.manage`, upload to `rmm.admin` | `Remote\TransferService`, `PathPolicy`, `Http\DeviceApi::transfer()` | `endpoint_agent_transfers`, files under `transfer_dir` |
| Mesh | `POST endpoint_devices/{id}/remote` takes `view` (`desktop`, `terminal`, `files`); the last two also need `rmm.remote.manage` and `remote_tools`. `MeshService::launch($dev, $user, $id, $force, $view)` and `MeshCookie::launchUrl(..., $viewmode)` (11, 12, 13). `GET .../remote/mesh` says what a caller may open; `PUT .../remote/node` maps the node | `Mesh\MeshService`, `MeshCookie`, `Technician\TechnicianActions::launchRemote()` | `endpoint_agent_mesh_nodes` |
| Log ingest | `agent_logs`: bounded batch, per-minute budget, row caps, duplicate detection, redaction, level mapping; `config.logs` offered in the check-in only to an agent that announced `logs:collect` while the switch is on and nothing is shed | `Logs\LogService`, `Remote\RemoteSettings`, `Checkin\CheckinService` | `endpoint_agent_log_events`, `endpoint_agent_log_state` |
| Retention | `Housekeeping::run()` prunes by age, then trims devices above their cap, then the fleet above its cap, in transactions of 2000 rows that also lower the counters (keys `pruned_logs_retention`, `pruned_logs_device_cap`, `pruned_logs_fleet_cap`, `transfers_expired`, `transfers_pruned`, `log_rule_alerts_recovered`) | `Logs\LogService::prune()`, `Remote\TransferService::prune()` | |
| Search | newest first; device, client, time range (a fleet search spans at most 7 days, a device search 90), severity or worse, source, event id, text (substring, wildcards escaped); client scoped | `Logs\LogService::search()`, `RmmReadModel::logSearch()` | |
| Log rules | `Logs\LogRuleService` (CRUD, evaluation per accepted batch, `sweep()` from housekeeping); a match reports `log.rule_<id>` through `CheckEvaluator::apply()`; never a regular expression | `Logs\LogRuleService`, `LogActions` | `endpoint_agent_log_rules` |
| Events | `rmm.file.transferred`, `rmm.log.rule_matched` (and the existing `rmm.job.*`, `rmm.check.*`, `rmm.alert.*`) | `RmmEvent`, `Webhooks\EventCatalog` | |

**Read model additions** (`RmmReadModel`, no authorization inside, the caller has authorized the device): `remoteSnapshot($deviceId, 'services'|'processes')`, `fileTransfers($deviceId)`, `logSearch($filters, $visibleClientIds, $limit, $offset)`, `logStats($deviceId)`. `jobs()` and `deviceView()` no longer carry the output of a remote-tool job (it is read through the remote endpoints, which need `rmm.remote.manage`); `job()` asks for `rmm.remote.manage` on those types.

**Plain calls** for an edition's pages: `$module->remoteActions()` (`listServices`, `controlService`, `listProcesses`, `killProcess`, `snapshot`, `jobResult`, `requestDownload`, `createUpload`, `uploadContent`, `listTransfers`, `transfer`, `downloadContent`, `deleteTransfer`, `meshInfo`, `settings`, `updateSettings`) and `$module->logActions()` (`deviceLogs`, `fleetLogs`, `stats`, rule CRUD). Each returns an `ActionResult`; `downloadContent()` returns the path, length, SHA-256 and a safe file name for the caller to stream (`Http\FileDownload` does it for the REST route).

**What an edition does to adopt Phases 6 and 8**
1. Run the Core migrations (0022; 0021 belongs to the RMM scale work).
2. Route the two new device endpoints like the others: `agent_transfer` and `agent_logs` map to `DeviceApi::handle()` through an `RmmRequest` whose `endpoint` is that name (a raw body stream, the `X-Transfer-*` headers lower-cased into `headers`, the same TLS and client-IP rules). `agent_transfer` accepts GET and POST, `agent_logs` POST. Add both to the pre-bootstrap gate's list if you use one; the state file already carries the sub-switches.
3. Give the module a transfer folder: the `transfer_dir` option (or `binary_dir`). Without one, file transfer answers `not_configured`.
4. Map the new ability `rmm.remote.manage` in your `AccessPolicyInterface` (RivetIT: `module_rmm_remote_connect >= 2`, or `module_rmm_scripts >= 3`; it is stronger than `rmm.remote.launch`). `rmm.admin` already covers sending files and the settings. No new ability for logs: reading uses `rmm.job.run_saved`, rules use `rmm.alert.manage`.
5. Serve the new technician routes: `TechnicianApi` already mounts them (`RemoteApi`); a raw `PUT` body (a file) must reach it as the request's `bodyStream` with no JSON decoding in front.
6. Optional: subscribe to `rmm.file.transferred` and `rmm.log.rule_matched`.
7. Render the pages, using `remoteActions()`, `logActions()` and the read-model methods above, and show the two risk texts verbatim when an administrator turns a switch on.
8. Roll out an agent of this release (it announces `remote:*` and `logs:collect`), then switch the features on. An older agent keeps working and is simply never offered a remote tool or a log config.

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

Constructor options of `RmmModule`: `binary_dir`, `transfer_dir` (Phase 6: where file transfers are stored, default `<binary_dir>/transfers`), `max_upload_bytes` (default 64 MiB), `allow_insecure_http` (loopback test servers only), `allow_linux`, `host_fallback`, `integration_name`, `installer_prefix`, `client_label` (what your edition calls a client in text users read, default `client`; RivetIT: `department`) and `denial_reasons` (ability => sentence, replaces that ability's generic denial text). `deviceApi()` takes a `$disabledAnswer`: `uniform` (default, `503 module_disabled`) or `compat` (RivetIT's legacy `403 forbidden`); see UPGRADING. Everything else is stored in `endpoint_agent_settings` and edited through `Admin\RmmAdmin`. The module switch (master, sub-switches, limits, load shedding) and its zero-database state file are specified in the design (sections 12 and 13) and in [CAPACITY.md](../rmm/CAPACITY.md).

## How it fails

- Device endpoints answer `{"error", "code"}` with the statuses listed in [PROTOCOL.md](../rmm/PROTOCOL.md); an unexpected exception is a generic `500 internal` (details go to the PHP log only). `SapiEmitter` answers a file body that fails verification with the same 500.
- `TechnicianApi` answers `401 {"error":"Unauthorized"}` without a principal, `404 disabled` when the module is off (after the 401: an anonymous caller learns nothing about the switch, and the pre-bootstrap gate deliberately does not answer this endpoint), 403/404 as above, `413` for a body over 1 MiB, `400` for a body that is not a JSON object, `500 internal` for an unexpected exception.
- `TechnicianActions` and `RmmAdmin` never throw for a refusal: they return an `ActionResult`. `InvalidArgumentException` comes only from programming errors (an unknown setting column). `BinaryStore::publish()` throws `RuntimeException` when the storage directory is not writable.
- A policy that throws is treated as a denial.

## Security notes

Device authentication, SYSTEM-level job execution and remote access make this a high-risk module: a focused review is required before it is enabled by default anywhere. Credentials are stored as hashes (device and enrollment tokens), the signing key and the Mesh login key are sealed with the edition's key, no read model contains a secret, job output is redacted and capped when it is stored, script text is never written to the audit log (a hash is), and every denial is a generic message.

What is **verified**: the golden transcripts of the original replay identically through `DeviceApi` and `TechnicianApi` (`php scripts/rmm-golden/replay-core.php replay`); the RivetIT role matrix (`RoleMatrixTest`), deployment (`DeployTest`), administration (`AdminTest`), read models (`ReadModelTest`) and technician actions (`TechnicianTest`) run against a scratch database. **Not verified**: a real MeshCentral server (only `tests/Support/MockMeshCentral.php`; the Phase 6 terminal and files views use MeshCentral's `viewmode` 12 and 13 from its URL scheme and have not been opened in a real MeshCentral), the Windows installer on a Windows host, the Windows code of the Phase 6 and 8 agent (cross-compiled and vetted only), the Linux agent on a physical host.

## Used by

Not yet adopted: RivetIT adopts first (it already has the tables), then RivetMSP.

## Links

[Design](../design/endpoint-module-extraction.md) | [ADR-010](../architecture/ADR-010-endpoint-agent-module.md) | [Protocol](../rmm/PROTOCOL.md) | [OpenAPI](../rmm/openapi-device.yaml) | [Agent build](../rmm/AGENT_BUILD.md) | [Migration](migration.md) | [Conformance kit](../conformance.md)
