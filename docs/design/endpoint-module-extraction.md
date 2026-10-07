# Design: the RMM module in RivetCore: extraction of the endpoint agent (Phase 0) and growth into a full RMM

Status: DRAFT for owner review, 2026-10-07. Design only: no code has moved. Companion: [ADR-010](../architecture/ADR-010-endpoint-agent-module.md) (file name kept; title is "RMM module"). **Scope update 2026-10-07 (owner):** the module must grow into a complete, switchable RMM; sections 9 to 13 were added for that, and the whole document uses the `RivetCore\\Rmm\\*` names (see 1.2). Sections 1 to 8 describe **Phase 0** (move + adopt in both editions), which is the only part to implement first.
Author context: written from `origin/beta` of RivetIT at `c26957c0b` (release `v26.10.26`, agent tag `agent-v0.1.0-beta.1`), RivetCore at `v1.0.0-rc.3`
(branch `security-fixes-2026-10`, clean), and RivetMSP `beta` at `a32b5b551` (DB 2.6.76, pins `rivet/rivet-core ^1.0.0-rc.3`).
Every claim below was checked by reading those trees; file paths are relative to the repository named in the row.

Owner request: "RivetIT has a RMM Agent / Endpoint Agent, so need to take it out of RivetIT and add it to Core" so RivetMSP can use it too.

---

## 0. Summary

* **What it is today** (RivetIT): 20 static PHP classes in `src/EndpointAgent/` (165 KB), five device-facing REST files (`agent_enroll`, `agent_checkin`, `agent_jobs`, `agent_update`, `agent_installer`) and one technician REST file (`endpoint_devices`), two admin/agent UI pages with their POST handlers, a Go Windows agent (`endpoint-agent/`, 94 files, own CI and `agent-v*` tags), 10 tables (`endpoint_agent_*`), 7 test files, 2 fixtures with a generator, 3 docs.
* **Why it extracts cleanly:** the module owns its 10 tables. It touches edition data in exactly 11 tables (`assets`, `asset_interfaces`, `asset_rmm_links`, `rmm_alerts`, `rmm_integrations`, `rmm_scripts`, `rmm_remote_sessions`, `clients`, `locations`, `users`, `user_client_permissions`) plus four edition functions (`encryptSetting`, `decryptSetting`, `logAction`, the `\RmmAssetMapper` ticket auto-close) and the Metrics ingest service. The MSP has the same shapes for all RMM tables (verified column by column for `asset_rmm_links`, `rmm_alerts`, `rmm_integrations`, `rmm_scripts`, `rmm_remote_sessions`, `assets`, `asset_interfaces`, `user_client_permissions`), and the same `module_rmm*` permission names.
* **Design in one paragraph:** Core gets a new, off-by-default module `RivetCore\Rmm\*` (marked `@internal` in 1.0.x) that owns the 10 tables and all domain logic as instance services built on `DatabaseInterface`, plus framework-neutral HTTP handlers (`RmmRequest` in, `RmmResponse` out). Editions implement **4 required contracts** (`RmmTenancyInterface`, `RmmAssetsInterface`, `RmmBridgeInterface`, `SecretBoxInterface`) and **3 optional/defaulted** ones (`RmmMetricSinkInterface`, `RmmAuditInterface`, `RmmModuleStateInterface`; a fourth, `RmmEventsInterface`, arrives in Phase 1), reuse the existing `AccessPolicyInterface`, `ClockInterface`, `UrlPolicy`, and shrink their `api/v1/agent_*.php` files to bridges. The Go agent moves to `endpoint-agent/` at the Core repo root with its CI and `agent-v*` tags; test vectors have one home (`endpoint-agent/testdata/vectors/`) read by both Go and PHP. Tables, wire protocol, credential formats and signing vectors do not change by one byte.
* **Module switch and compute (owner requirement):** master switch OFF by default for new installs and unchanged for existing ones (the existing `endpoint_agent_settings.enabled`), sub-switches per capability, a pre-bootstrap gate that answers `503 module_disabled` with `Retry-After` and **zero database work** when off, and a capacity budget (limits, load shedding, queued ingest, capacity panel, load tests). See sections 12 and 13.
* **Recommended release path:** Core `1.0.0-rc.4` with the module `@internal` and its migrations in a separate opt-in list (the 1.0 API surface and the soak are untouched), promoted to `@api` and merged into `CoreMigrations::all()` in `1.1.0`.
* **Hard prerequisite already satisfied:** RivetIT's `composer.json` requires `rivet/rivet-core ^1.0.0-rc.3` (lock: `v1.0.0-rc.3`), so both editions are already on the 1.0 line.
* **Environment finding:** there is **no Go toolchain on this machine** (`go` not on PATH; `/home/sysadmin/go` holds only `pkg/mod`, `pkg/sumdb`). Go tasks must install Go (module says `go 1.27`; CI uses `go-version-file`) or rely on GitHub Actions; the rest of the work is PHP.

---

## 1. What moves and what stays

### 1.1 Principle

Core never knows an edition (ADR-002, ADR-003). The module is split by **who owns the data**:

* Rows in `endpoint_agent_*` and the protocol logic around them: **Core**.
* Rows in the 11 edition tables, the edition's permission model, session, CSRF, HTML, flash messages, front controller: **edition**, reached only through the contracts of section 2.

All current classes are static and read the global `$mysqli` (`ITFlow\EndpointAgent\Db`, `global $mysqli` in `Checkin`, `Checks`, `Jobs`, `Enrollment`). In Core they become instance services built from `DatabaseInterface`; `$mysqli->begin_transaction()` blocks become `DatabaseInterface::transaction()`. Static `Config::$cache` becomes a per-service cache invalidated on `set()`.

### 1.2 Namespace layout in Core (RMM-shaped, scope update 2026-10-07)

The module is named **RMM** (`RivetCore\Rmm\*`), not "Endpoint": the owner wants a complete RMM, and the code that exists today is its first sub-area (**Agent**). The layout below is the target for all phases (sections 9 and 10); only entries marked *Phase 0* are built by the extraction. **In the move tables of 1.3, a relative class name such as `Enrollment\EnrollmentService` means `RivetCore\Rmm\Agent\Enrollment\EnrollmentService`**, and the old name `Endpoint*` of any Core type is `Rmm*` (`RmmModule`, `RmmRequest`, `RmmBridgeInterface`, ...). Table names (`endpoint_agent_*`), REST names (`endpoint_devices`, `agent_*`), `rivetit_agent`, the Go tree `endpoint-agent/` and wire strings are **not** renamed.

```
src/Rmm/
  RmmModule.php              composition root (Phase 0): new RmmModule(<deps>) -> ->agent() ->monitoring() ->automation() ->remote() ->deviceApi() ->technicianApi() ...
  RmmState.php, RmmStateFile.php   master/sub-switch state and its zero-DB fast-path file (section 12)
  Contracts/                 edition-implemented interfaces (Phase 0: 7; later phases add companions, never methods)
  Http/                      RmmRequest, RmmResponse, ApiError, DeviceApi, TechnicianApi, SapiEmitter
  Support/                   Sql helper over DatabaseInterface, Ids (uuid), Time
  Authz/                     RmmAbility (constants), RmmAuthorizer
  Read/                      RmmReadModel (+ per-area read models later)
  Admin/                     RmmAdmin (settings, tokens, binaries, releases, approvals, mesh, key rotation, capacity panel data)
  Capacity/                  Phase 0: LoadShedder, CapacityReport (section 13)
  Migration/                 Migration0014.., RmmMigrations (opt-in list until 1.1)
  Events/                    RmmEvents: EventCatalog entries + audit mapping (section 9.4)
  Agent/                     Phase 0 = everything that exists today
    Crypto/ (Signer, CanonicalJson, Redactor)  Settings/ (RmmConfig)  Enrollment/  Device/  Checkin/ (CheckinService, MetricMapper)
    Update/ (rings)  Binaries/  Installer/  Link/ (RmmLinker)  Maintenance/
  Monitoring/                Phase 0: Checks\CheckEvaluator (debounce, episodes, alerts). Later: thresholds, suppression, escalation, check templates
  Automation/                Phase 0: Job\JobService (state machine, offer/report/sweep, signing). Later: JobTypeRegistry, script library, schedules, tasks
  Remote/                    Phase 0: Mesh\MeshService, MeshCookie, Technician\TechnicianActions::launchRemote. Later: terminal, file transfer, process/service manager
  Inventory/                 Phase 1: hardware/software/network inventory model (Phase 0 keeps inventory_json as is)
  Policy/                    Phase 2: PolicyStore, Assignment (client / site / group / tag), Resolver (inheritance)
  CustomFields/              Phase 2 (typed, scoped fields for RMM data; the edition `custom_fields` stub is dead and not built on)
  Reporting/                 Phase 5
  Patching/                  Phase 3
  Software/                  Phase 4
  Logs/                      Phase 6
  (Testing under src/Testing)  Rmm*ConformanceTestCase, InMemoryRmm* reference adapters
```

### 1.3 File-by-file move table

Left column is a path in the **RivetIT** repo. "Core" destinations are under `/home/sysadmin/rivet-core`.

#### PHP domain classes (`src/EndpointAgent/`)

| Source | Destination | Notes |
|---|---|---|
| `ApiError.php` (555 B) | `RivetCore\Rmm\Http\ApiError` | Same fields `http`, `errCode`, `headers`. Keep `final`, extends `RuntimeException`. |
| `Db.php` (2.8 KB) | **deleted in Core**; replaced by `RivetCore\Rmm\Support\Sql` (internal wrapper `one/all/val/run/insert/utcNow/iso` over `DatabaseInterface`) | Uses `ExecutionResult::affectedRows` and `insertId`. RivetIT keeps a deprecated `ITFlow\EndpointAgent\Db` shim only while its UI pages still call it (task T7b), then deletes it. |
| `Config.php` (8.5 KB) | `Settings\RmmConfig` | `INTEGRATION_TYPE='rivetit_agent'` stays. `integrationId()` now asks `RmmBridgeInterface::ensureIntegration()`. `generateSigningKey`/`signingKey` use `SecretBoxInterface`. `defaultChecks`, `checks`, `signedChecks`, `validateChecks` unchanged. |
| `Signer.php` (5 KB) | `Crypto\Signer` (+ `Crypto\CanonicalJson` extracted from `canonical/str/toObject`) | Pure. Byte-identical behaviour; first thing ported, validated by the vectors (section 4). Needs `ext-sodium` (add to Core `composer.json` `require`). |
| `Redactor.php` (1.9 KB) | `Crypto\Redactor` | Pure. Pattern list unchanged (it redacts RivetIT's own credential shapes `rvte1...`, 64-hex tokens). |
| `Enrollment.php` (27 KB) | `Enrollment\EnrollmentService` (tokens, `enroll`, `placeDevice`, `resolvePending`, rate limit), `Enrollment\DeviceValidator` (`validateDevice`, `normalizeMac`, `cleanSerial`, `cleanText`, `JUNK_SERIALS`), `Enrollment\AssetMatcher` (`matchAsset` algorithm) | The asset SQL (`SELECT ... FROM assets`, `JOIN asset_interfaces`, `INSERT INTO assets`) moves behind `RmmAssetsInterface`. Junk-serial rule, ambiguity/scope/ownership decisions stay in Core. `Enrollment::audit()` becomes `RmmAuditInterface::record()`. The enrollment transaction uses `DatabaseInterface::transaction()`. |
| `Devices.php` (8 KB) | `Device\DeviceService` | `transfer()` replaces `UPDATE assets`/`UPDATE rmm_alerts` with `RmmAssetsInterface::moveToClient()` and `RmmBridgeInterface::reassignAlerts()`, and validates the client with `RmmTenancyInterface::clientName()`. |
| `Checkin.php` (17.6 KB) | `Checkin\CheckinService` + `Checkin\MetricMapper` (`samplesFor`, bits-to-bytes `/8`) | Caps (`MAX_BODY_BYTES` 1 MiB, `MAX_CHECKS` 100, `MAX_BUFFERED` 100, `MAX_DISKS` 32, `MAX_INVENTORY_BYTES` 65536, `FUTURE_SKEW_S` 300) preserved. `new MetricIngestService($mysqli)` becomes `RmmMetricSinkInterface::ingest()`. `$_SERVER['REMOTE_ADDR']` becomes a parameter from `RmmRequest`. |
| `Checks.php` (5.4 KB) | `Checks\CheckEvaluator` | Debounce/episode logic unchanged; `rmm_alerts` SQL moves to `RmmBridgeInterface::openAlert/resolveAlert`; the `\RmmAssetMapper::autoCloseAlertTicket` call moves inside the edition's `resolveAlert()` (RivetIT: public method at `includes/class_rmm_asset_mapper.php:167`; RivetMSP: the method is `private` at `includes/class_rmm_asset_mapper.php:165`, so MSP must make it reachable, see section 6). |
| `Jobs.php` (16 KB) | `Job\JobService` | `Jobs::uuid()` stays (also used by `Installer`). Signed job object built by `Crypto\Signer::jobMessage`. `begin_transaction` -> `transaction()`. |
| `Updates.php` (7 KB) | `Update\UpdateService` | The `$GLOBALS['config_base_url']` fallback in `addRelease()` (legacy manual release) becomes an explicit constructor argument `?string $hostFallback`. |
| `Binaries.php` (16.5 KB) | `Binaries\BinaryStore` + `Binaries\UploadLimits` (`maxBytes`, `iniBytes`, `effectiveUploadLimit`) | `storageDir()` takes the directory from the constructor (RivetIT passes the value of `EA_BINARY_DIR` or `<root>/backups/endpoint-agent`; Core never reads constants). `serviceBase()` takes `allowInsecureHttp` from the constructor (was `EA_ALLOW_INSECURE_HTTP`). `stream()` stops calling `header()`/`exit`: it returns an `RmmResponse` with a stream body (file + trailer + exact length) that `SapiEmitter` or the edition emits. |
| `Installer.php` (13.4 KB) | `Installer\InstallerService` | `departmentName()` uses `RmmTenancyInterface::clientName()`; the `locations` check uses `locationInClient()`. Download filename prefix `RivetIT-Agent-Setup-` stays (constructor option `filenamePrefix`, default unchanged). `psQuote`, `powershellSnippet` unchanged. |
| `InstallerStamp.php` (4.2 KB) | `Installer\InstallerStamp` | Pure. `MAGIC='RIVETIT-EMBED-v1'`, `FOOTER_LEN=52`, `MAX_PAYLOAD=16384`, `PAYLOAD_VERSION=1`: never change. |
| `Link.php` (5.2 KB) | `Rmm\Agent\Link\RmmLinker` | Orchestration only; every SQL statement against `asset_rmm_links`/`assets` moves to the edition's `RmmBridgeInterface` / `RmmAssetsInterface` implementation. `agentKey()` (`'rivetit:'.$deviceId`) stays in Core. |
| `Mesh.php` (8.4 KB) | `Mesh\MeshService` + `Mesh\MeshCookie` (`encodeCookie/decodeCookie/newLoginKey/validNodeId/normalizeUrl`) | `decryptSetting` -> `SecretBoxInterface`; the `curl_init` health probe uses Core's existing `Webhooks\UrlPolicy::vet()` (RivetIT currently calls `rivetWebhookUrlPolicy()` from `includes/event_bus.php`, which is itself a `UrlPolicy`), injected, and Guzzle with `CURLOPT_RESOLVE` pinning like the webhook dispatcher. `EA_ALLOW_INSECURE_HTTP` becomes a constructor flag. Stays inside Core but is optional (`mesh_enabled`), see risk R6. |
| `Maintenance.php` (1.7 KB) | `Maintenance\MaintenanceService` | Offline flip: Core selects stale device keys and calls `RmmBridgeInterface::markOffline($integrationId, $agentKeys)` in chunks of 500 (replaces the cross-table `UPDATE ... JOIN`). Pruning of `endpoint_agent_checkins`, `_enroll_attempts`, `_jobs` unchanged. |
| `Actions.php` (7.6 KB) | `Technician\TechnicianActions` | `rmm_scripts` lookup -> `RmmBridgeInterface::savedPowerShellScript()`; `rmm_remote_sessions` insert -> `RmmBridgeInterface::recordRemoteSession()`; `$_SERVER['REMOTE_ADDR'/'HTTP_USER_AGENT']` become parameters. |
| `Authz.php` (4.1 KB) | `Authz\RmmAuthorizer` + `Authz\RmmAbility` | Decisions come from `AccessPolicyInterface`; `clientScopeSql()` is replaced by `RmmTenancyInterface::visibleClientIds()`. See section 2.2 for the ability list and what happens to the reason strings. |
| `View.php` (3.8 KB) | `Read\RmmReadModel` (+ the list/detail queries currently written inline in the UI pages and `api/v1/endpoint_devices.php`) | Gives the UI and the technician API one source for list/detail/token/binary/release/attempt data so editions stop writing SQL against `endpoint_agent_*`. |

#### REST controllers

| Source (RivetIT) | Destination | Reduced edition file |
|---|---|---|
| `api/v1/includes/agent_device_api.php` (`ea_send`, `ea_error`, `ea_require_tls`, `ea_body`, `ea_bearer`, `ea_audit_context`, `ea_guard`, `ea_device_rate_limit`) | `Http\RmmRequest`, `Http\RmmResponse`, `Http\SapiEmitter`, error mapping in `Http\DeviceApi` | Edition keeps a ~40-line `agent_device_api.php` that builds the request (trusted-proxy TLS decision with `$_SERVER`, `getIP()`, bearer header, `php://input` stream, `api_rate_limit` closure), calls Core and emits. The TLS/proxy decision stays in the edition because it depends on `REMOTE_ADDR` and `X-Forwarded-Proto` trust. |
| `api/v1/agent_enroll.php` (904 B) | `DeviceApi::enroll()` | 5-line bridge (below). |
| `api/v1/agent_checkin.php` (839 B) | `DeviceApi::checkin()` | 5-line bridge. |
| `api/v1/agent_jobs.php` (1.4 KB) | `DeviceApi::jobs()` (GET long-poll `wait` 0..5 s, POST report) | 5-line bridge. |
| `api/v1/agent_update.php` (2.2 KB) | `DeviceApi::update()` | 5-line bridge. |
| `api/v1/agent_installer.php` (3.4 KB) | `DeviceApi::installer()` | 5-line bridge. |
| `api/v1/endpoint_devices.php` (4.8 KB) | `TechnicianApi::handle(RmmRequest, int $userId, string $userName)` | Edition keeps `api_mobile_require_user_token()`, `api_mobile_audit_context()` and passes the authenticated user; routing of `/endpoint_devices/{id}[/jobs[/{job}[/cancel]]|/remote]` moves to Core. |
| `api/v1/index.php` dispatch (line 122) | stays | The device endpoints are routed above the Bearer parsing on purpose; unchanged. |
| `api/v1/openapi.yaml` agent sections | Core `docs/rmm/openapi-device.yaml` (canonical) | Editions copy/merge on release. |

Bridge shape (each of the five device files):

```php
<?php
defined('FROM_API') || die();
require_once __DIR__ . '/includes/agent_device_api.php';
ea_dispatch('checkin');   // builds RmmRequest, calls RmmModule::deviceApi()->checkin($req), emits the RmmResponse
```

#### UI and edition glue (stays in the edition)

| Source | Decision | Reason |
|---|---|---|
| `admin/settings_endpoint_agent.php` (46 KB), `agent/rmm_agent_device.php` (20 KB) | stay, rewritten to call `RmmReadModel`/`RmmAdmin` instead of raw SQL | HTML, theme, nav and CSRF are edition concerns; RivetMSP's agent layout differs (`agent/includes/side_nav.php`, `agent/rmm_asset.php`). Core ships **data, not markup**: read models and validated admin operations. A shared HTML partial in Core would couple Core to a CSS framework (rejected, see ADR-010). |
| `admin/post/settings_endpoint_agent.php` (16 KB), `agent/post/rmm_agent.php` (3.5 KB) | stay, thin: CSRF, permission gate, `$_POST` extraction, flash; call `RmmAdmin::*` / `TechnicianActions::*` | Validation and SQL (including the inline `UPDATE endpoint_agent_releases` at line ~247 and mesh settings) move to `RmmAdmin`. |
| `agent/post/rmm_remote.php` (line 51-57) | stays; keeps its early branch that routes an agent device to `TechnicianActions::launchRemote()` | Depends on the edition's RMM remote-connect page. |
| `cron/cron.php` block at line 1692 | stays; body becomes `$module->maintenance()->run()` | One cron entry; MSP adds the same block (section 6). Optional later: a `Cron\JobCatalog` entry. |
| `includes/rmm_client_factory.php` (line 31), `cron/metrics_collect.php:311`, `agent/rmm_checks.php:13`, `admin/settings_integrations.php:9` | stay (RivetIT) / must be added (MSP) | They exclude `type='rivetit_agent'` from vendor-RMM loops. |
| `admin/settings.php:56`, `admin/includes/side_nav.php`, `admin/includes/inc_all_admin.php:69`, `includes/settings_search_index.php:89`, `agent/asset_details.php:399` | stay | Navigation and links. |
| `scripts/endpoint_agent_publish.php` | stays as an edition CLI wrapper around `BinaryStore::publish()` | Needs the edition bootstrap (config.php, DB). |
| `includes/database_version.php`, `admin/database_updates.php` steps 2.6.145/2.6.146, `db.sql` block (lines 8007-8240) | stay (see section 3) | Edition version ledger. |

#### Go agent

| Source | Destination | Notes |
|---|---|---|
| `endpoint-agent/` (94 files: `main.go`, `setup.go`, `install_core.go`, `cmd_*.go`, `*_windows.go`, `internal/{agent,api,buffer,collect,embed,jobs,logx,store,svc,update}`, `e2e/{run_e2e.sh,fakeserver}`, `scripts/install-windows.ps1`, `winres/`, `rsrc_windows_{amd64,arm64}.syso`, `Makefile`, `README.md`, `go.mod` (`module rivetit-agent`, `go 1.27`), `go.sum`) | `rivet-core/endpoint-agent/` (same layout) | Move with `git subtree split`-style history preservation if the owner wants history (otherwise a plain copy commit citing the source SHA). `module rivetit-agent` is **not** renamed (no importer; renaming adds risk for zero benefit). Agent-visible names (`RivetIT Agent` service, install dir, `rivetit-agent-*.exe`, `RIVETIT-EMBED-v1`) are **not** renamed, because the self-update path replaces the running exe by those names; see risk R3. |
| `.github/workflows/endpoint-agent.yml` | `rivet-core/.github/workflows/endpoint-agent.yml` | Same job (vet on linux/windows amd64/arm64, `go test -race`, 20 s fuzz, `./e2e/run_e2e.sh`, `make dist`, disabled Authenticode step), `paths:` filter unchanged (`endpoint-agent/**`), `tags: ['agent-v*']`. Release body text changes from "Upload the executables in RivetIT under Administration > Endpoint agent" to edition-neutral wording and a link to the Core docs. `softprops/action-gh-release` publishes to **rivet-core** Releases. |
| `internal/jobs/testdata/agent_job_signing_vectors.json`, `internal/embed/testdata/agent_installer_trailer_vectors.json` | `endpoint-agent/testdata/vectors/` (single copy) | Go tests read `../../testdata/vectors/`; PHP tests read `<core>/endpoint-agent/testdata/vectors/`. See section 4.4. |
| tags `agent-v*` (existing: `agent-v0.1.0-beta.1`) | new tags in the Core repo; the RivetIT tag and its GitHub pre-release remain as history | Next tag `agent-v0.1.0-beta.2` (suffix tags publish as pre-releases, workflow already does that). |

Because both editions **commit `vendor/`** and Composer installs Core from the GitHub dist archive, Core's `.gitattributes` must add `/endpoint-agent export-ignore` (8 MB of `.syso` and Go source must never reach an edition's `vendor/`). `/docs`, `/tests`, `/scripts` are already export-ignored, so the vectors and docs in those folders do not ship either; the PHP classes and migrations do.

#### Tests, fixtures, docs

| Source (RivetIT) | Destination | Notes |
|---|---|---|
| `tests/fixtures/agent_job_signing_vectors.json` + `generate_agent_job_signing_vectors.php` | `endpoint-agent/testdata/vectors/agent_job_signing_vectors.json` + `scripts/endpoint-vectors.php` | Seed string `'RivetIT-agent-TEST-seed'` stays. Generator must reproduce the committed file byte for byte (CI check). |
| `tests/fixtures/agent_installer_trailer_vectors.json` (101 KB) + generator | same folder + same script | Same rule. |
| `tests/endpoint_agent_deploy_unit.php` (112 lines) | `tests/Unit/Rmm/InstallerTest.php`, `BinariesTest.php` | Pure/unit, no HTTP. |
| `tests/endpoint_agent_enroll.php`, `_checkin.php`, `_jobs.php` | `tests/Integration/Rmm/{Enrollment,Checkin,Jobs}Test.php` | Rewritten against `ScratchDb` + `InMemoryRmmAssets/Rmm/Tenancy/MetricSink` reference adapters (Core's `FakeDatabase` only returns canned rows, so domain logic needs the scratch MariaDB the CI already provides). |
| `tests/endpoint_agent_migration.php` (43 lines) | `tests/Integration/Rmm/SchemaDiffTest.php` | Section 3.4. RivetIT keeps a 10-line test that its `db.sql` still contains the tables. |
| `tests/endpoint_agent_authz.php` (200 lines, the role matrix) | Core: `tests/Unit/Rmm/AuthorizerTest.php` (plumbing with a stub policy); **RivetIT keeps the matrix** as an adapter test | The matrix (roles 1-7, user 16 limited to Dept B) is a property of RivetIT's policy adapter; RivetMSP gets its own copy. |
| `tests/endpoint_agent_deploy_http.php` (339 lines, real HTTP via `php -S`) and `tests/endpoint_agent_lib.php` (harness) | stay in the edition | They prove the bridge, TLS handling, routing and rate limits end to end; they become the **golden regression** for the adoption (section 5). |
| `tests/mock/mock_meshcentral.php` | `tests/Support/MockMeshCentral.php` | Used by Mesh tests. |
| `tests/load/agent_ingest_load.php` | stays in the edition | Load needs the full stack. |
| `docs/ENDPOINT_AGENT.md` (28 KB) | Core `docs/modules/rmm.md` (module page in the standard template) + `docs/rmm/PROTOCOL.md` (wire spec) ; RivetIT keeps a short `docs/ENDPOINT_AGENT.md` for RivetIT-only content (permission mapping, UI locations, Odoo/rmm links) and links to Core | Sections 1-7, 9-12 are edition-neutral; section 7 (permissions), 8 (Administration) are mapped per edition. |
| `docs/ENDPOINT_AGENT_BUILD.md` | Core `docs/rmm/AGENT_BUILD.md` | |
| `docs/user-guide/09-endpoints-and-integrations.md` | stays | End-user guide of RivetIT. |
| `endpoint-agent/README.md` | moves with the agent | |

### 1.4 What explicitly stays out of Core

* The edition's `api_rate_limit()` / `api_mobile_*` / `getIP()` / CSRF / session / flash / nav / HTML (injected or bridged).
* Vendor-RMM sync code (`class_tactical_rmm.php`, `class_level_rmm.php`, `RmmAssetMapper`'s sync logic, Metrics providers): ADR-002 "Metrics stays" is **not** reversed; Core only calls a 1-method sink.
* `rmm_integrations`, `asset_rmm_links`, `rmm_alerts` DDL (edition-owned; Core never creates them).

---

## 2. Contracts

Rules applied (from ADR-003/004): one small interface per concern, array shapes documented (they are part of the contract), no new methods on existing interfaces, every type tagged `@internal` in 1.0.x. New interfaces live in `RivetCore\Rmm\Contracts`. Existing contracts are reused wherever they fit, and no contract is added for something that can be a constructor argument.

### 2.1 What is reused instead of a new contract

| Need | Reuse | Why no new contract |
|---|---|---|
| Storage | `DatabaseInterface` | ADR-001. Both editions already pass `MysqliDatabaseAdapter`. |
| "Is this user allowed to view/run/remote/administer on this client" | `Contracts\AccessPolicyInterface` (ADR-003) | Exactly the problem it was built for (`$subjectType='client'`, `$subjectId=$clientId`). |
| Time | `Contracts\ClockInterface` | Replaces `time()`, `gmdate()`, `Db::utcNow()`. |
| Outbound URL safety for the MeshCentral probe | `Webhooks\UrlPolicy` (constructor argument) | Already in Core, already has `allowedNetworks`. |
| Rate limiting of device calls | `\Closure(string $bucket, int $limit, int $windowSeconds): bool` constructor argument of `DeviceApi` | RivetIT's `api_rate_limit()` and Core's `Redis\RateLimiter::hit()` have different return shapes and fail modes; a closure keeps each edition's behaviour (including fail-closed). |
| Public base URL, CA PEM, binary directory, "allow http on loopback" | Constructor options of `RmmModule` / stored in `endpoint_agent_settings` (`service_url`, `ca_pem`) | They are configuration, not behaviour. |
| Audit | `Audit\AuditService` via the new thin `RmmAuditInterface` (2.3.6) | See there. |

### 2.2 Authorization: abilities over `AccessPolicyInterface`

Core defines the abilities (constants in `RmmAbility`) and calls `can($userId, $ability, 'client', $clientId)`. `$clientId = 0` means the role-level question ("may this user do this anywhere"), exactly like `Authz::check($uid, ..., 0)` today.

| Ability | Replaces `Authz::` | RivetIT policy rule today (`src/EndpointAgent/Authz.php`) |
|---|---|---|
| `rmm.device.view` | `VIEW` | `module_rmm >= 1` and department access |
| `rmm.job.run_saved` | `RUN_SAVED` (and `collect`, cancel, job output visibility) | view + `module_rmm_scripts >= 2`, not a module-only login |
| `rmm.job.reboot` | `REBOOT` | same as run_saved |
| `rmm.job.run_script` | `RUN_SCRIPT` | view + `module_rmm_scripts >= 3`, not module-only |
| `rmm.remote.launch` | `REMOTE` | view + `module_rmm_remote_connect >= 1`, not module-only |
| `rmm.admin` | `ADMIN` | `role_is_admin` |

`RmmAuthorizer::check(int $userId, string $ability, int $clientId): ?string` keeps the old signature (null = allowed, otherwise a safe reason string). It adds the module switch (`RmmConfig::enabled()` -> "The endpoint agent is not enabled.") and maps a denied ability to a generic reason ("Your role cannot run jobs on devices." style). The edition-specific reasons that exist today ("Module-only logins cannot run jobs...", "Your account is not active.") become **generic per-ability texts** in Core; the exact strings are not protocol (the REST error `code` values `forbidden`/`not_found` are). The inactive-account check (`users.user_status/user_archived_at/user_type`) moves into the edition policy (a policy must already deny an inactive user). **Owner decision D4** covers whether the old texts must be kept (an optional `ExplainingAccessPolicyInterface extends AccessPolicyInterface { explain(...): ?string }` companion can carry them, following the ADR-004 "new capability = new companion interface" pattern).

### 2.3 The six Phase-0 data contracts (the seventh, `RmmModuleStateInterface`, is in 11 and 12.2)

All in `RivetCore\Rmm\Contracts`, `@internal` until 1.1.

#### 2.3.1 `RmmTenancyInterface` (required): clients, locations and per-user client scope

Justification: Core must (a) validate a client for tokens/installers/transfers, (b) print its name in the installer payload, (c) restrict device lists to a user's clients. RivetIT calls these "departments", RivetMSP "clients"; both store them as `clients.client_id` / `locations.location_client_id` (identical columns), so the contract is spelled in neutral "client" terms.

```php
interface RmmTenancyInterface
{
    /**
     * Client ids the user may see devices for, or null when unrestricted.
     * null: administrators and users without any per-client restriction rows (today: no user_client_permissions row).
     * []: restricted to nothing. Client id 0 (no client) is always treated as visible by Core and is not listed here.
     *
     * @return list<int>|null
     */
    public function visibleClientIds(int $userId): ?array;

    /** Display name of a client, or null when no such client exists (this doubles as the existence check). */
    public function clientName(int $clientId): ?string;

    /** True when the location exists and belongs to the client (0 is "no location" and is handled by Core). */
    public function locationInClient(int $locationId, int $clientId): bool;
}
```

Replaces: `Authz::clientOk`, `Authz::clientScopeSql`, `Installer::departmentName`, `clients`/`locations` lookups in `Installer::issue` and `Devices::transfer`.

#### 2.3.2 `RmmAssetsInterface` (required): the asset side of identity matching and linking

Justification: the identity policy (docs section 3) is Core logic, but the candidate lookups and the asset create/update are against `assets` and `asset_interfaces`, which Core must not touch (ADR-002). One interface, seven methods, no policy in it (junk serials, scope, ambiguity and ownership decisions remain in `AssetMatcher`).

```php
interface RmmAssetsInterface
{
    /**
     * Non-archived assets whose serial equals $serial exactly (case as stored). Core has already refused junk serials.
     *
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findBySerial(string $serial, int $limit = 10): array;

    /**
     * Non-archived assets with an interface whose MAC equals one of $macs, ignoring case and treating '-' and ':' alike.
     * $macs are already normalised by Core to lower-case colon form (aa:bb:cc:dd:ee:ff).
     *
     * @param list<string> $macs
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findByMacs(array $macs, int $limit = 20): array;

    /**
     * Non-archived assets whose name equals $hostname case-insensitively. A hostname match is only ever a hint (Core never links on it).
     *
     * @return list<array{asset_id:int, asset_name:string, client_id:int, serial:?string}>
     */
    public function findByHostname(string $hostname, int $limit = 10): array;

    /** @return array{asset_id:int, client_id:int, archived:bool}|null null when the asset does not exist */
    public function find(int $assetId): ?array;

    /**
     * Create an asset for an unmatched device (policy auto_create or an administrator's "create asset"). Returns the new id.
     * Type is 'Server' when os_version contains "server" (case-insensitive), otherwise 'Laptop'; status 'Active'; the OS text is "Windows <os_version>".
     *
     * @param array{hostname:string, os_version:string, manufacturer:?string, model:?string, serial:?string} $device
     */
    public function createForDevice(array $device, int $clientId, int $locationId): int;

    /**
     * Fill blanks only, never overwrite what a human typed: serial and model when empty, make when '', OS when empty.
     *
     * @param array{serial:?string, model:?string, manufacturer:?string, os:string} $facts
     */
    public function fillBlanks(int $assetId, array $facts): void;

    /** A device moved to another client (administrator action): the asset follows it. */
    public function moveToClient(int $assetId, int $clientId, int $locationId): void;
}
```

#### 2.3.3 `RmmBridgeInterface` (required): the edition's RMM tables

Justification: the integration row, the per-asset link row (what the asset page RMM card, RMM dashboard and the `asset_offline`/`asset_online` automation read), alerts, the saved-script library and the remote-session log are all edition tables with the same shape in both editions. One interface keeps the "write the link/alert/ticket path the edition already has" logic together; it must be implemented with the **same database connection** as `DatabaseInterface` so its writes participate in Core's transactions (documented in the conformance case).

```php
interface RmmBridgeInterface
{
    /** Find or create the synthetic integration row (rmm_integrations.type = $type). Idempotent. Returns its id. */
    public function ensureIntegration(string $type, string $name): int;

    public function integrationExists(int $integrationId, string $type): bool;

    /**
     * Create or update the asset's link row for this integration (unique per asset + integration), deleting a link this agentKey
     * had on a different asset. New rows start with rmm_status 'unknown'.
     *
     * @param array{hostname:string, os_name:string, os_version:string, manufacturer:string, model:string} $facts
     */
    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void;

    /** Delete the link row of this agentKey (device retired). */
    public function removeLink(int $integrationId, string $agentKey): void;

    /**
     * Push a check-in into the link: status 'online', last_seen/last_sync now, and rmm_status_changed_at = now when the previous status was not 'online'.
     * Returns false when the asset has no link yet (Core then calls upsertLink() and retries once).
     *
     * @param array{hostname:string, os_version:string, manufacturer:string, model:string, cpu:string, ram_gb:string, logged_in_user:string,
     *              cpu_pct:?int, ram_pct:?int, disk_pct:?int, needs_reboot:bool, last_boot:?string} $health  last_boot is 'Y-m-d H:i:s' or null
     */
    public function applyHealth(int $integrationId, int $assetId, array $health): bool;

    /**
     * Flip these agents' links from 'online' to 'offline' and set rmm_status_changed_at (this feeds the asset_offline automation). Returns rows changed.
     *
     * @param list<string> $agentKeys at most 500 per call
     */
    public function markOffline(int $integrationId, array $agentKeys): int;

    /**
     * Open an alert (status 'new') or return the existing one: (integrationId, alertKey) is unique, so a re-delivered check-in never creates a second row.
     * $severity is 'warning' or 'error'. Returns the alert id.
     *
     * @param array<string, mixed> $raw stored as JSON in raw_data_json
     */
    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int;

    /** Mark the alert resolved and run the edition's existing conservative auto-close of its linked ticket (a no-op when already resolved). */
    public function resolveAlert(int $integrationId, int $alertId): void;

    /** Device transferred: open alerts of this asset and integration follow the new client. */
    public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void;

    /** Body of an enabled PowerShell script in the saved library, or null. */
    public function savedPowerShellScript(int $scriptId): ?string;

    /** One row in the remote-session log. $reference is 'meshcentral:session:<id>'; no URL or token is ever stored. */
    public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void;
}
```

Alert key and agent key formats are generated by Core and are frozen (section 4): `agent:<device_id>:<check_key>:<episode>` and `rivetit:<device_id>`.

#### 2.3.4 `RmmMetricSinkInterface` (optional, default `NullRmmMetricSink`)

Justification: RivetIT feeds `ITFlow\Metrics\MetricIngestService`; RivetMSP **has no Metrics subsystem** (no `src/Metrics`, no `device_metric_*` tables, verified), so ingest must be optional, not assumed.

```php
interface RmmMetricSinkInterface
{
    /**
     * @param list<array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string}> $samples
     *        key is a metric registry key (cpu.utilization, memory.utilization, disk.utilization, network.rx_bytes_per_s,
     *        network.tx_bytes_per_s, system.uptime_seconds, system.pending_reboot, memory.total_bytes, disk.total_bytes, disk.free_bytes).
     *        Core never sends a null or zero-for-missing value; the sink drops out-of-range values (never clamps), as MetricSample::tryOf does.
     */
    public function ingest(array $samples, int $integrationId): void;
}
```

#### 2.3.5 `SecretBoxInterface` (required)

Justification: the Ed25519 private key (`signing_private_key_enc`) and the MeshCentral login key (`mesh_login_key_enc`) are stored encrypted with the edition's key; Core must not own that key (existing `encryptSetting()`/`decryptSetting()` in both editions' `functions.php`). Ciphertext format is the edition's and is **unchanged**, so existing rows keep decrypting. Generic enough to be reused by other Core modules later.

```php
interface SecretBoxInterface
{
    public function encrypt(string $plaintext): string;
    /** Returns '' when the ciphertext is empty, damaged or from another key (never throws). */
    public function decrypt(string $ciphertext): string;
}
```

#### 2.3.6 `RmmAuditInterface` (required, but Core ships a default)

Justification: today's `Enrollment::audit()` calls the edition's `logAction('Endpoint Agent', ...)`, which writes the legacy `logs` row shown on the activity page. Existing installs must keep seeing those rows, so the edition keeps a one-method bridge; editions that do not care use Core's `AuditServiceRmmAudit` (writes `audit_events` through `AuditService`).

```php
interface RmmAuditInterface
{
    /** $action is a short title ("Enrolled", "Job Submitted"); $description is already free of secrets (scripts are logged as a hash). */
    public function record(string $action, string $description, int $clientId, int $entityId): void;
}
```

### 2.4 Count and honesty check

Six interfaces here (seven with `RmmModuleStateInterface`, section 11); four must be hand-written per edition (`Tenancy`, `Assets`, `Rmm`, `SecretBox`, each 20-80 lines over helpers both editions already have), one is a 3-line `logAction` bridge, one is optional. Considered and **rejected**: a `ScriptLibraryInterface` (folded into `Rmm`, one method), a `UrlProvider` (config), a `MeshClientInterface` (Mesh is a pure function of config + key; no vendor API), an `AlertSinkInterface` separate from `Rmm` (alerts and links share the integration row and the same transaction), a ticket-creation hook (tickets are still created by each edition's existing alert-to-ticket path; Core only opens/resolves `rmm_alerts`, exactly as today).

### 2.5 HTTP value objects (`RivetCore\Rmm\Http`)

```php
final class RmmRequest
{
    public function __construct(
        public readonly string $method,                 // 'GET' | 'POST' ...
        public readonly string $endpoint,               // 'agent_enroll' | 'agent_checkin' | 'agent_jobs' | 'agent_update' | 'agent_installer' | 'endpoint_devices'
        /** @var list<string> */ public readonly array $pathSegments,   // technician API: segments after the resource
        /** @var array<string,string> */ public readonly array $query,
        /** @var array<string,string> */ public readonly array $headers, // lower-case names; Core reads authorization and content-type
        public readonly string $clientIp,
        public readonly ?string $userAgent,
        public readonly bool $secureTransport,           // decided by the edition (trusted-proxy rule)
        public readonly ?int $declaredLength,
        /** @var resource|null */ public readonly mixed $bodyStream,  // Core performs the bounded read itself
    ) {}
}

final class RmmResponse
{
    /** @param array<string,string> $headers */
    public function __construct(public readonly int $status, public readonly array $headers, public readonly ?string $body, public readonly ?RmmFileBody $file = null) {}
    // file: path, exact length, optional trailer bytes (installer stamp); SapiEmitter streams it in 64 KiB chunks after verifying size and SHA-256.
}
```

`DeviceApi` reproduces today's behaviour per endpoint, including error JSON `{"error": "...", "code": "..."}`, `Cache-Control: no-store`, `Retry-After`, `Allow`, body caps and the TLS rule (426 `tls_required` unless `allowInsecureHttp`). It never exits; the edition's bridge emits the response and exits.

---

## 3. Database

### 3.1 Ownership and migrations

Core takes ownership of the 10 `endpoint_agent_*` tables: `endpoint_agent_settings`, `_enrollment_tokens`, `_enroll_attempts`, `_devices`, `_checkins`, `_checks`, `_jobs`, `_mesh_nodes`, `_releases`, `_binaries`. (Core owns no edition table; `rmm_*`, `asset_*` remain edition DDL.)

New migrations, appended to the Core ledger `rivet_core_migrations` (ids frozen once released):

| Id | Class | Content |
|---|---|---|
| `0014_endpoint_agent_core` | `Rmm\Migration\Migration0014EndpointAgent` | `CREATE TABLE IF NOT EXISTS` for all 10 tables with the **exact final DDL** below, then `INSERT IGNORE INTO endpoint_agent_settings (id) VALUES (1)`. |
| `0015_endpoint_agent_converge` | `Rmm\Migration\Migration0015EndpointAgentConverge` | Guarded convergence for installs that stopped at RivetIT DB 2.6.145: add `endpoint_agent_settings.ca_pem` (`text DEFAULT NULL`), `endpoint_agent_releases.arch` (`varchar(10) NOT NULL DEFAULT ''`) and `.binary_id` (`int(11) DEFAULT NULL`), drop index `uniq_version_ring` if present, add `uniq_version_ring_arch (version, ring, arch)` if absent. Every step checked through `information_schema`, never `ADD COLUMN IF NOT EXISTS` alone, so it behaves the same on MariaDB and MySQL 8 (CI matrix, ADR-008). |

| `0016_rmm_module_switches` | `Rmm\Migration\Migration0016ModuleSwitches` | Additive columns on `endpoint_agent_settings` for the module switch and capacity controls (section 12.5): `features_json`, `limits_json`, `shed_level`, `ingest_mode`, `max_devices`. Defaults reproduce today's behaviour (NULL = legacy features, `max_devices` 0 = unlimited). Included in the schema-diff test. |

Splitting 0014/0015 keeps 0014 a pure "create final shape" and puts the one historical wrinkle (the 2.6.145 `releases` table had `uniq_version_ring` and no `arch`/`binary_id`/`ca_pem`) in its own reviewable step. Both are idempotent and additive (no drop of data, one index swap that already happens in RivetIT 2.6.146).

DDL source of truth is `db.sql` lines 8007-8240 of RivetIT (identical to migration 2.6.145 plus the 2.6.146 additions). All tables: `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci`. Column list (names, types, defaults and keys must be copied verbatim by the implementation agent; the schema-diff test enforces it):

* `endpoint_agent_settings`: `id tinyint(4) NOT NULL DEFAULT 1` PK; `enabled tinyint(1) 0`; `service_url varchar(500) ''`; `integration_id int(11) 0`; `check_in_interval_s 300`; `collect_interval_s 60`; `offline_after_s 900`; `stale_after_s 604800`; `failure_debounce 3`; `recovery_debounce 2`; `retention_days 30`; `job_retention_days 180`; `job_output_max_bytes 65536`; `job_default_timeout_s 300`; `job_max_timeout_s 3600`; `job_expiry_s 3600`; `job_ack_timeout_s 120`; `job_max_attempts 3`; `enroll_max_ttl_h 72`; `unmatched_policy varchar(20) 'approval'`; `checks_json text NULL`; `signing_key_id varchar(32) ''`; `signing_public_key varchar(100) ''`; `signing_private_key_enc text NULL`; `signing_key_created_at datetime NULL`; `mesh_enabled tinyint(1) 0`; `mesh_url varchar(500) ''`; `mesh_domain varchar(100) ''`; `mesh_login_key_enc text NULL`; `mesh_account_template varchar(100) 'rivetit-support'`; `mesh_policy varchar(20) 'unattended'`; `mesh_token_ttl_s 300`; `coexistence_policy text NULL`; `updated_at datetime NULL ON UPDATE current_timestamp()`; `ca_pem text NULL`.
* `endpoint_agent_enrollment_tokens`: PK `token_id` AI; `UNIQUE uniq_selector (token_selector)`.
* `endpoint_agent_enroll_attempts`: PK `attempt_id bigint(20)` AI; `KEY idx_ip_time (ip_hash, attempted_at)`, `KEY idx_time (attempted_at)`.
* `endpoint_agent_devices`: PK `device_id` AI; `UNIQUE uniq_install (install_id)`; keys `idx_token_hash`, `idx_asset`, `idx_machine_guid`, `idx_serial`, `idx_client`.
* `endpoint_agent_checkins`: PK `(device_id, seq)`; `KEY idx_received (received_at)`.
* `endpoint_agent_checks`: PK `(device_id, check_key)`.
* `endpoint_agent_jobs`: PK `job_id char(36)`; keys `idx_device_state (device_id, state)`, `idx_state_updated (state, updated_at)`.
* `endpoint_agent_mesh_nodes`: PK `device_id`; `KEY idx_node (mesh_node_id)`.
* `endpoint_agent_releases` (final): PK `release_id` AI; `UNIQUE uniq_version_ring_arch (version, ring, arch)`; columns include `arch varchar(10) NOT NULL DEFAULT ''`, `binary_id int(11) DEFAULT NULL`.
* `endpoint_agent_binaries`: PK `binary_id` AI; `UNIQUE uniq_version_arch (version, arch)`.

The implementation task copies the complete statements from RivetIT `db.sql` (do not retype from this summary).

### 3.2 How each edition applies them

RivetIT (current DB version `2.6.146`, `includes/database_version.php`):

* Steps `2.6.145` and `2.6.146` in `admin/database_updates.php` **stay unchanged**. They are already idempotent (`CREATE TABLE IF NOT EXISTS`, `information_schema`-guarded ALTERs); on an install they have run they are history, on an install that has not yet reached them they still create the same tables in the same shape. After Core owns the tables they are redundant but harmless, and rewriting migration history is riskier than keeping it.
* New step `2.6.147` (name: "Core owns the endpoint agent tables"): the existing pattern from step `2.6.123`->`2.6.124` (`admin/database_updates.php:9653`): `if (class_exists(\RivetCore\Migration\MigrationRunner::class)) { (new MigrationRunner(new MysqliDatabaseAdapter($mysqli), array_merge(CoreMigrations::all(), RmmMigrations::all()), new SystemClock()))->run(); bump to 2.6.147; }`. On an install that already has all 10 tables in final shape, 0014 and 0015 are no-ops that only record themselves in `rivet_core_migrations`. `includes/database_version.php` -> `2.6.147`.
* `db.sql`: unchanged tables block (a fresh install must equal an upgraded one, `EDITION_CHECKLIST.md` section 3); append `0014_endpoint_agent_core`, `0015_endpoint_agent_converge` to the `INSERT INTO rivet_core_migrations` rows, regenerate `db.sql` from an updated database, not by hand (RivetIT release procedure traps: collation and row size, see 3.3).

RivetMSP (DB `2.6.76`, Core pinned): new step `2.6.77` runs the same runner list (MSP's updater already calls the runner after rc.3, commit `a32b5b551`); its `db.sql` gets the 10 tables and the two ledger rows. A fresh MSP install therefore receives the tables from `db.sql`, an existing MSP from the runner. Nothing agent-related exists in MSP today.

Fresh-install rule (Core `EDITION_CHECKLIST`): "a migration that creates a table is not run on a fresh install if the id is recorded without the table existing, so add both or neither". The implementation must add the DDL and the ledger rows together.

### 3.3 Traps

* **Collation:** every table must say `COLLATE=utf8mb4_general_ci` explicitly (MariaDB 11 defaults to `uca1400`; RivetIT's `endpoint_agent_migration.php` already asserts this). The Core migration writes it on every table.
* **Row size:** the edition `settings` table is at the InnoDB row limit, which is why config lives in `endpoint_agent_settings`. Nothing in this design adds a column to `settings`. `endpoint_agent_devices` (wide, with `mediumtext` inventory and several `text` columns) and `endpoint_agent_settings` (4 `text`) are inside the limit today; the schema diff proves the same DDL, so the limit is unchanged. If a MySQL 8 CI leg complains about `int(11)` display width (deprecated warning only), keep the width: Core's migrations 0002-0013 already do.
* **MariaDB-only DDL:** `DEFAULT current_timestamp()` and `ON UPDATE current_timestamp()` parse on MySQL 8.0.13+ (the Core CI matrix covers 8.0/8.4); do not use `ADD COLUMN IF NOT EXISTS` in 0015 (MySQL lacks it), use the `information_schema` guard shown in RivetIT's 2.6.146 step.
* **Sequence/ledger:** Core's ledger ids are append-only and per-schema locked (`MigrationRunner::LOCK_NAME` + schema hash); the editions' updaters must catch `MigrationInProgressException` (already done in both).

### 3.4 Backward-compatibility proof (schema diff)

`tests/Integration/Rmm/SchemaDiffTest.php` (skipped without `RIVETCORE_TEST_DB_*`, runs in CI on MariaDB 11/10.11 and MySQL 8.0/8.4) builds three scratch schemas and compares them:

| Schema | Built by |
|---|---|
| **A: reference** | the 10 `CREATE TABLE` statements extracted verbatim from RivetIT `db.sql` (a copy of the extract is committed as `tests/Fixtures/rmm/rivetit-db-2.6.146.sql`, with the source SHA in its first line) |
| **B: fresh Core** | `Migration0014` then `0015` on an empty schema |
| **C: upgraded** | the RivetIT 2.6.145 DDL (from migration step 2.6.145: `releases` with `uniq_version_ring`, no `arch`/`binary_id`/`ca_pem`), seeded with rows (one device, token, release, job), then `0014`, `0015` |

For every table compare `information_schema.TABLES` (`ENGINE`, `TABLE_COLLATION`), `COLUMNS` (name, `COLUMN_TYPE`, `IS_NULLABLE`, `COLUMN_DEFAULT`, `EXTRA`, `CHARACTER_SET_NAME`, `COLLATION_NAME`; ordinal position ignored), `STATISTICS` (index name, `NON_UNIQUE`, `SEQ_IN_INDEX`, `COLUMN_NAME`, `SUB_PART`). A == B == C must hold, the C data must survive intact (row counts and a checksum of one device row), running both migrations twice changes nothing, and `MigrationRunner::status()` lists them applied. Additionally, in RivetIT: run the real updater on a scratch copy of the live schema (the RivetIT release procedure) and diff `information_schema` before/after the `2.6.147` step: **zero differences expected**.

---

## 4. Wire protocol and crypto compatibility

Everything in this section is **frozen**. The implementation tasks add a Core test (`tests/Unit/Rmm/FrozenConstantsTest.php`) that asserts each constant and the regexes below, so a refactor cannot move them silently.

### 4.1 Endpoints, methods, caps, rate limits (as implemented today)

| Endpoint (under `/api/v1/`) | Auth | Method | Body cap | Rate limit | Success |
|---|---|---|---|---|---|
| `agent_enroll` | enrollment token in JSON body (`enrollment_token`, `device{...}`) | POST | 16384 B | DB-backed: 10 failures or 60 attempts per 600 s per IP hash (`sha256('ea-enroll|'.ip)`), 429 + `Retry-After: 600` | 201 |
| `agent_checkin` | `Authorization: Bearer <64-hex device token>` | POST | 1 048 576 B (413 `too_large`) | 40 per 60 s per device (`agent_checkin:<id>`) | 200 |
| `agent_jobs` | Bearer | GET (`wait` clamp 0..5 s long poll, 0.5 s poll step, up to 5 jobs) / POST (report) | POST 262 144 B | 120 per 60 s (`agent_jobs:<id>`) | 200 |
| `agent_update` | Bearer | GET `?arch=amd64\|arm64&version=X.Y.Z` | none | 60 per 60 s (`agent_update:<id>`) | 200 octet-stream or generic 404 |
| `agent_installer` | enrollment token in body (`token`, `arch`) or Bearer; **never** in the URL (400 `token_in_url`) | POST | 4096 B | DB-backed: per IP 10 failures / 30 attempts per 600 s (`sha256('ea-installer|'.ip)`), per token 30 downloads, per selector 20 failures | 200 octet-stream, generic 404 |
| `endpoint_devices[/...]` | user API token (legacy shared key refused) | GET/POST | edition | edition | per resource |

Error body `{"error":"<message>","code":"<code>"}`; codes in use: `invalid_token`, `revoked`, `expired` (401), `forbidden` (403), `not_found` (404), `method_not_allowed` (405, with `Allow`), `conflict` (409), `too_large` (413), `invalid` (422), `tls_required` (426), `rate_limited` (429, `Retry-After`), `internal` (500), `unavailable` (409/503), `token_in_url` (400), plus the technician-side `confirmation_required`, `queued`, `cancelled`, `device_offline`, `unmapped`, `not_configured`, `device_retired`, `mesh_unavailable`, `disabled`. JSON flags: `JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION`; every JSON response carries `Cache-Control: no-store`.

### 4.2 Credential and identifier formats

* Enrollment token: `rvte1.<12 hex selector>.<40 hex secret>`; only `sha256(secret)` stored; plaintext shown once. Max uses clamp 1..5000; TTL clamp 1..`enroll_max_ttl_h`; ring `pilot|stable`.
* Device token: 256-bit random, 64 hex chars, matched by `^Bearer\s+([A-Za-z0-9]{64})$`, stored as `sha256`, validity 365 days, constant-time compare.
* Installer trailer: payload JSON (`version=1, installer_id, server_url, enrollment_token, department, ca_pem|null, created_at, expires_at`) followed by 52-byte footer `pack('N', len) . sha256(payload) . 'RIVETIT-EMBED-v1'`, max payload 16384.
* Job id: UUID v4 string; alert key `agent:<device>:<check>:<episode>`; agent link key `rivetit:<device_id>` in `asset_rmm_links.tactical_agent_id`; integration `type='rivetit_agent'`, default name `RivetIT Endpoint Agent` (name configurable for MSP, the type is not).
* Signing key id: `substr(hash('sha256', <public key base64 string>), 0, 16)` (the hash is over the **base64 text**, not the raw key).
* Binary storage name `^bin_[0-9a-f]{32}\.bin$`; arch map `amd64 => 0x8664, arm64 => 0xAA64` (PE machine check); version regex `^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?\z`; upload cap 64 MiB default, floor 1024 B.
* Device identity rules and `JUNK_SERIALS` (16 entries) unchanged; MAC form lower-case colon; `link_state` values `linked|pending_approval|rejected`; job states `queued|running|succeeded|failed|timed_out|cancelled|expired`; job reasons `result_lost`, `never_started`, `no_result_by_deadline`, `device_retired`.

### 4.3 Canonical JSON and Ed25519

* Canonical JSON: UTF-8, object keys sorted by UTF-8 bytes recursively, arrays in order, no whitespace, strings escape only `" \ \b \f \n \r \t` and other code points below U+0020 as lower-case `\u00xx`, everything else raw, **integers only** (floats rejected in checks by `validateChecks`).
* Ed25519 (libsodium detached): keys stored base64 of the 64-byte secret and 32-byte public key; signatures base64.
* What is signed: job object without `signature` (fields `job_id, device_id, attempt, type, script, params, timeout_s, max_output_bytes, issued_at, expires_at`); each check definition (`key, type, params, interval_s`); update manifest = signature over the **lowercase hex SHA-256 text** of the package. Agents pin the public key received at enrollment, so a key rotation forces re-enrollment: the module must not rotate or re-derive keys during extraction.
* MeshCentral login cookie: AES-256-GCM, 12-byte IV, key = first 32 bytes of the hex `loginTokenKey`, payload `{u:'user/<domain>/<account>', a:3, time}`; viewmode 11. Unchanged.

### 4.4 Vectors: one source of truth

* Home: `rivet-core/endpoint-agent/testdata/vectors/{agent_job_signing_vectors.json, agent_installer_trailer_vectors.json}`.
* Generator: `rivet-core/scripts/endpoint-vectors.php` (PHP, uses `Crypto\Signer` and `Installer\InstallerStamp`); a CI step `php scripts/endpoint-vectors.php --check` regenerates into a temp dir and fails on any byte difference (Ed25519 signatures are deterministic, so regeneration is stable).
* Go: `internal/jobs/fixture_test.go` and `internal/embed/vectors_test.go` read `../../testdata/vectors/...` (today they search the server repo first and fall back to `testdata/`; the fallback logic and the duplicated copies are deleted). Go's module root is `endpoint-agent/`, so the path stays inside the module.
* PHP: `tests/Unit/Rmm/SignerVectorsTest.php`, `InstallerStampVectorsTest.php` read the same files via `dirname(__DIR__, 3).'/endpoint-agent/testdata/vectors/'`.
* Gate: before any behaviour change lands, the **committed bytes of both files must equal the current RivetIT files** (`sha256sum` recorded in the task acceptance), and the first Core PHP test run reproduces them from the ported `Signer`.

---

## 5. Versioning, release and order of operations

### 5.1 Recommendation

| Thing | Recommendation | Reason |
|---|---|---|
| Core PHP module | **`1.0.0-rc.4`**, module `@internal`, migrations in a separate `RmmMigrations::all()` list that editions opt into; **promote to `@api`, merge into `CoreMigrations::all()` in `1.1.0`** | Release gate rule: "any new `@api` surface restarts the soak". `@internal` types are outside the promise (ADR-004), `docs/api-surface.md` must come out byte-identical (add a CI assertion), and an opt-in migration list means an edition that does not enable the module (or a soak install) gets no new tables. rc.3 is a tagged candidate pinned by both editions, but `RELEASE_GATE.md` records that the 30-day clock has not started, so a clearly additive, non-API rc.4 is the least disruptive way to let RivetIT adopt now. The owner may instead hold everything for `1.1.0` (decision D1). |
| Which types become `@api` in 1.1 | `RmmModule`, the 6 `Contracts\*`, `RmmAbility`, `Http\RmmRequest`, `Http\RmmResponse`, `Http\ApiError`, `Migration\RmmMigrations`, `Crypto\Signer`, `Crypto\CanonicalJson`, `Installer\InstallerStamp`, the `Testing\Rmm*ConformanceTestCase` kit | These are what editions call or implement. Services behind `RmmModule` (`EnrollmentService`, `CheckinService`, ...) stay `@internal`; the facade is the supported entry. |
| Composer | add `"ext-sodium": "*"` to `require`; no new packages (Guzzle is already required) | `Signer` uses libsodium; both editions already run it. |
| Editions' constraint | RivetIT/MSP: exact pin `1.0.0-rc.4` (then `^1.1` after promotion); update `composer.lock` and commit vendor drift per `EDITION_CHECKLIST.md` | Matches how rc.3 was taken. |
| Go agent | independent semver `agent-vX.Y.Z` tags **in the Core repo**; first tag `agent-v0.1.0-beta.2` after the move (same binary content as `beta.1` plus the vector-path change; verify the build is reproducible: same `VERSION`/`COMMIT` give byte-identical exes) | Agent and PHP release at different cadences; `UpdateService` already supports staged rings. |
| Protocol compatibility policy | New document `docs/rmm/PROTOCOL.md` states: additive-only, old agents keep working; a server may add response fields, never remove or retype; the agent ignores unknown fields; breaking change = new endpoint name, never silent | The wire protocol has no version field today; this is how it stays safe. |

### 5.2 Order of operations (extraction PR sequence)

Nothing below touches the live RivetIT beta/prod behaviour until step 5, and step 5 is reversible by one `git revert` + the unchanged tables.

1. **Freeze and baseline (RivetIT, no code change).** Record baseline `c26957c0b`; make the copy of the 7 test files and fixtures part of the baseline artifact; capture golden HTTP transcripts by replaying `tests/endpoint_agent_deploy_http.php` and the enroll/checkin/jobs scripts against a scratch DB with request/response recording (request bodies, status, header set, normalised JSON bodies with ids/timestamps masked). See risk R1 for how the parallel session is held.
2. **Core PR 1 (rc.4 candidate branch `endpoint-module`):** contracts, value objects, migrations 0014/0015, `RmmMigrations`, in-memory reference adapters, conformance kit, SchemaDiffTest, `.gitattributes`, CI wiring (phpstan level 6, coverage gate 85% applies, `api-surface.md` unchanged assertion). No domain behaviour yet.
3. **Core PR 2:** pure classes (`Signer`, `CanonicalJson`, `Redactor`, `InstallerStamp`, `DeviceValidator`, `MeshCookie`, `RmmConfig` validators) + vectors single source + their unit tests.
4. **Core PR 3, 4:** services and HTTP handlers (device-facing, then technician/admin) with the ported integration tests; no edition uses them yet.
5. **Core PR 5:** Go agent move + CI + docs. Independent of 3 and 4 after the vectors land; may run in parallel.
6. **Tag Core `1.0.0-rc.4`** (owner pushes; implementation agents must not push or release).
7. **RivetIT adoption (one branch, `endpoint-core`):** composer pin, adapters, bridges, `2.6.147` step, UI onto read models, shim `Db`; run old suite + golden transcripts (identical) + schema diff; deploy to **beta** first (authorized pattern for mw-itflow), 7-day observation with the existing enrolled agents (see section 7 acceptance), then production per the release procedure.
8. **RivetIT cleanup PR:** delete `src/EndpointAgent/*`, `endpoint-agent/`, `.github/workflows/endpoint-agent.yml`, copied fixtures; point docs to Core. Only after the Core agent release exists and one RivetIT-hosted update from a Core-built binary has succeeded on a pilot device.
9. **RivetMSP adoption** (section 6), after RivetIT is stable on Core for the soak period the owner picks.
10. **Core `1.1.0`:** promote to `@api`, merge migrations into `CoreMigrations::all()`, doc and changelog; editions move to `^1.1`.

### 5.3 Where agent binaries come from after the move

Today: the CI (RivetIT repo) builds `rivetit-agent-windows-{amd64,arm64}.exe` + `SHA256SUMS`, attaches them to a GitHub pre-release; the administrator downloads them and uploads them in Administration > Endpoint agent > Agent binaries (or runs `scripts/endpoint_agent_publish.php --version --arch --activate --release`); `Binaries::publish()` validates PE machine type, size and SHA-256 and stores `bin_<hex>.bin` under `backups/endpoint-agent` (or `EA_BINARY_DIR`), then per-department installers are stamped from the current binary and agents self-update from `/api/v1/agent_update` (served by the edition host, so customers' endpoints never contact GitHub).

After: **identical flow**, only the release source changes to `github.com/TheTractorHacker/rivet-core/releases` (tags `agent-v*`). Optional follow-up (not in the first cut): `Binaries\ReleaseFetcher` (Guzzle, `UrlPolicy`, pinned to the Core repo URL) behind a "Fetch latest from RivetCore releases" button that downloads the exe and `SHA256SUMS`, verifies the digest and calls `BinaryStore::publish()`. Air-gapped installs keep the upload path. The disabled Authenticode step in the workflow stays disabled until the owner supplies a certificate (R8).

---

## 6. RivetMSP adoption plan

### 6.1 What MSP already has (verified)

* Same RMM tables, same columns: `asset_rmm_links` (incl. `rmm_status_changed_at`, health columns), `rmm_alerts` with `UNIQUE (integration_id, tactical_alert_id)`, `rmm_integrations`, `rmm_scripts`, `rmm_remote_sessions`; `assets` with `asset_client_id`, `asset_serial`, `asset_archived_at`; `asset_interfaces.interface_mac`; `user_client_permissions (user_id, client_id)`; `clients`, `locations.location_client_id`.
* Same permission module names: `module_rmm`, `module_rmm_alerts`, `module_rmm_alerts_ack`, `module_rmm_scripts`, `module_rmm_remote_connect`, `module_rmm_sync`; same `encryptSetting`/`decryptSetting`/`logAction`/`getIP`/`api_rate_limit` functions; Core already adapted (`src/Core/Adapter/{Database,Http,Itsm,Redis,Settings,Webhooks}`).
* `createTicketFromRmmAlert()` in `includes/rmm_functions.php`; `RmmAssetMapper::autoCloseAlertTicket` exists but is `private` (`includes/class_rmm_asset_mapper.php:165`).

### 6.2 What MSP lacks or does differently

| Gap | Impact | Plan |
|---|---|---|
| No Metrics subsystem (`MetricIngestService`, `device_metric_*` tables absent) | The Performance tab and metric samples do not exist | Ship `NullRmmMetricSink`; devices still show last check-in metrics from `endpoint_agent_devices.last_metrics_json` and the link health columns. Porting Metrics is a separate project (D5). |
| `lookupUserPermission($module)` reads the **session** role (`$session_user_role`, `$session_is_admin`); there is no per-user-id lookup and no "module-only login" concept | `AccessPolicyInterface::can($userId, ...)` is called with arbitrary users (API tokens, tests) | `RivetMSP\Core\Adapter\Endpoint\MspEndpointPolicy`: resolve role by `users.user_role_id` and read `user_role_permissions` for that role (the SQL inside `lookupUserPermission`, keyed by user id); admin via `user_roles.role_is_admin`. Same matrix as RivetIT minus the limited-login rule. |
| "Departments" wording | UI strings only; the schema is `client_id` in both | MSP UI says "client"; Core API field names are `client_id` already. |
| Cron sync loops over **all** enabled `rmm_integrations` (`cron/cron.php:1551`), `agent/rmm_assets.php:63`, `agent/rmm_dashboard.php:182`, `agent/rmm_checks.php:13`, `agent/post/rmm_check.php`, `admin/settings_integrations.php:9`, `includes/rmm_client_factory.php` | A `type='rivetit_agent'` row would be fed to vendor clients and error on every run | Add `AND type <> 'rivetit_agent'` where the code means "vendor integration", and the same early exit in `rmm_client_factory.php` that RivetIT has at line 31. |
| `RmmAssetMapper::autoCloseAlertTicket` private | Core's `resolveAlert` path needs the conservative ticket close | Make it public (as in RivetIT) or expose a small `rmm_auto_close_alert_ticket()` function; MSP adapter calls it. |
| Updater is at DB 2.6.76 | Needs a version step | `2.6.77`: run the runner with `RmmMigrations`, add `db.sql` tables and ledger rows. |

### 6.3 MSP files to add / change

Add: `src/Core/Adapter/Endpoint/{MspEndpointTenancy,MspEndpointAssets,MspEndpointRmm,MspEndpointSecretBox,MspEndpointAudit,MspEndpointPolicy}.php`, `src/Core/Adapter/Endpoint/EndpointBootstrap.php` (builds `RmmModule`), `api/v1/{agent_enroll,agent_checkin,agent_jobs,agent_update,agent_installer,endpoint_devices}.php` + `api/v1/includes/agent_device_api.php` (copy of RivetIT bridges, adjusted) + the dispatch lines in `api/v1/index.php`, `admin/settings_endpoint_agent.php` + `admin/post/settings_endpoint_agent.php`, `agent/rmm_agent_device.php` + `agent/post/rmm_agent.php`, the `agent/post/rmm_remote.php` early branch, the cron block, nav entries (`agent/includes/side_nav.php`, admin settings list, settings search), the `2.6.77` step, `db.sql`, `tests/core/EndpointAdapterConformanceTest.php` (the new kit), role matrix test.

RivetMSP **client-facing** features (client portal view of devices) are out of scope; Core gives read models, the MSP can add them later.

### 6.4 Risks specific to MSP

* The adapters are near copies of RivetIT's. Because ADR-002 forbids Core from touching edition tables, the copy cost is paid once per edition; the conformance kit keeps the two honest. (Alternative D3: Core ships one reference SQL adapter for the shared fork schema; rejected by default because it puts `assets`/`asset_rmm_links` SQL in Core.)
* The agent product name is "RivetIT Agent" (Windows service, Add/Remove Programs, installer file name). Shipping it unchanged to MSP customers is a branding decision (R3/D2), not a technical blocker.
* MSP's tickets from `rmm_alerts` are created by its own cron/automation path; verify it treats `tactical_alert_id` values starting `agent:` as ordinary alerts (RivetIT does; MSP's `class_rmm_asset_mapper` sync only touches rows of its own integration id, but MSP-specific automation rules by `rmm_integrations.type` need a read).

---

## 7. Work breakdown (agent tasks)

Conventions for every task: work in a **worktree** of the named repo (never the live working trees `/var/www/mw-itflow.foleyit.com`, `/var/www/...mw`, or the production MSP tree); scratch DB only (`RIVETCORE_TEST_DB_*` / `RIVETIT_TEST_DB_*`, name must contain `scratch`, never copy live rows or secrets); **do not commit to shared branches, push, tag, release or touch live nginx** (only the owner does; existing deploy authorizations cover itflow/mw-itflow deploys, not GitHub pushes or releases); keep PHPStan level 6, `declare(strict_types=1)`, `@api`/`@internal` on every type, no `mysqli`/superglobals/`global $...` under `src` (CI grep), all SQL with `?` placeholders.

Dependency graph: `T1 -> T2 -> {T3, T6} -> T4 -> T5 -> T9 -> T7 -> T8`; T6 needs only T3's vectors; T9 (module switch, capacity, simulator) needs T4+T5 and the Go part T6b; T7 needs T4+T5+T6+T9 (agent release exists); T8 needs T7 stable. T6b is the small agent change of section 12.4/13.6 and ships in the same agent release as the move.

| # | Task | Repo and file ownership | Depends | Deliverables | Acceptance tests (all must pass) |
|---|---|---|---|---|---|
| **T1** | Baseline, freeze list, golden artifacts | Reads RivetIT `origin/beta@c26957c0b` in a worktree; writes only `rivet-core/tests/Fixtures/rmm/golden/**` and `rivet-core/docs/design/endpoint-baseline.md` | none | Baseline SHA and `sha256sum` of the two fixture files and 5 REST files; recorded HTTP golden transcripts (enroll, re-enroll, reinstall, scope mismatch, checkin incl. duplicate seq and buffered samples, job offer/report/redaction, update manifest + download, installer download + 404s, 401/403/413/426/429 cases); `information_schema` dump of the 10 tables from a scratch DB built by RivetIT's updater; the 2.6.145-only schema dump; list of paths to freeze | The existing RivetIT suites pass on the baseline in a scratch env: `tests/endpoint_agent_{migration,deploy_unit,enroll,checkin,jobs,authz,deploy_http}.php` all green and recorded; transcripts replay deterministically twice (masking rules documented) |
| **T2** | Core skeleton: contracts, value objects, migrations, kit | `rivet-core`: `src/Rmm/{Contracts,Http,Migration,Support}/**`, `src/Testing/Rmm*ConformanceTestCase.php`, `src/Testing/InMemory*`, `.gitattributes`, `composer.json` (`ext-sodium`), `phpstan.neon` if needed, `tests/Integration/Rmm/SchemaDiffTest.php`, `tests/Unit/Rmm/{FrozenConstantsTest,ApiTagsEndpointTest}.php`, CI assertion that `docs/api-surface.md` is unchanged, `docs/modules/rmm.md` skeleton | T1 | The six interfaces with exact signatures of section 2; `RmmRequest/Response/ApiError/SapiEmitter`; migrations 0014/0015 + `RmmMigrations`; in-memory reference adapters; conformance cases proven against the references and deliberately broken variants (ADR-009 style) | `composer lint`, `vendor/bin/phpstan analyse` level 6 clean, `vendor/bin/phpunit` all suites on PHP 8.2-8.5; `SchemaDiffTest` A==B==C on MariaDB 11, 10.11 and MySQL 8.0/8.4 (CI matrix), data survival and idempotence asserted; `api-surface.md` byte-identical; `grep` rule (no mysqli/superglobals) clean; every type has `@api` or `@internal` (`ApiTagsTest`) |
| **T3** | Pure classes and vectors | `rivet-core`: `src/Rmm/{Crypto,Installer/InstallerStamp,Mesh/MeshCookie,Enrollment/DeviceValidator,Settings(validation)}`, `endpoint-agent/testdata/vectors/**` (copied byte-identical), `scripts/endpoint-vectors.php`, `tests/Unit/Rmm/**` | T2 | Ported `Signer`, `CanonicalJson`, `Redactor`, `InstallerStamp`, `DeviceValidator` (JUNK_SERIALS, `normalizeMac`, `cleanSerial`, `cleanText`, `validateDevice`), `MeshCookie`, `RmmConfig::validateChecks` | `sha256sum` of both vector files equals T1's baseline; `php scripts/endpoint-vectors.php --check` reproduces them byte for byte; unit tests cover all 4 job vectors, canonical-only strings, update-manifest and check-definition signatures, 14 negative trailer vectors, redaction patterns (port the RivetIT cases), `FuzzCanonical`-equivalent property test in PHP; PHPStan/coverage gates |
| **T4** | Device-facing services and `DeviceApi` | `rivet-core`: `src/Rmm/{Settings,Enrollment,Device,Checkin,Checks,Job,Update,Rmm,Maintenance,Http/DeviceApi,RmmModule}.php`, `tests/Integration/Rmm/{Enrollment,Checkin,Jobs,Updates,Maintenance,DeviceApi}Test.php`, `tests/Support/MockMeshCentral.php` (later) | T2, T3 | Services over `DatabaseInterface` + contracts; `DeviceApi::{enroll,checkin,jobs,update}` (and `installer` stub waiting for T5) | Ported scenarios from `tests/endpoint_agent_{enroll,checkin,jobs}.php` green against `ScratchDb` with the in-memory adapters; **golden transcripts from T1 replay identically through `DeviceApi`** (bodies, status, header set); frozen caps and rate-limit closures asserted; transaction behaviour (rollback on `ApiError`, `FOR UPDATE` serialisation, idempotent duplicate `seq`) asserted; PHPStan/coverage gates |
| **T5** | Technician, admin, installer, binaries, mesh | `rivet-core`: `src/Rmm/{Technician,Authz,Read,Admin,Binaries,Installer/InstallerService,Mesh/MeshService,Http/TechnicianApi}`, matching tests, `docs/modules/rmm.md`, `docs/rmm/{PROTOCOL,AGENT_BUILD,openapi-device}.*`, `docs/adapters.md` row, `CHANGELOG.md` and `UPGRADING.md` entries, `docs/architecture/ADR-010` finalised | T4 | `TechnicianActions`, `RmmAuthorizer`/`RmmAbility`, `RmmReadModel`, `RmmAdmin`, `BinaryStore` (publish/inspect/stream via `RmmResponse` file body), `InstallerService`, `MeshService` (probe via injected `UrlPolicy`), `TechnicianApi`, `DeviceApi::installer` | Ported `endpoint_agent_deploy_unit.php` and the non-HTTP parts of `_deploy_http.php` green; authorizer plumbing tests with stub policy (view/denied/disabled module, role-level `clientId=0` question, same 404 for missing and out-of-scope); upload: wrong machine type, oversize, duplicate version with different content refused; stream verifies size + SHA-256 before sending; Mesh probe refuses a private address unless allowed by `UrlPolicy`; golden transcripts for installer and technician API replay identically |
| **T6** | Go agent move, CI, release | `rivet-core`: `endpoint-agent/**`, `.github/workflows/endpoint-agent.yml`, `.gitattributes` (`/endpoint-agent export-ignore`), `docs/rmm/AGENT_BUILD.md` | T3 (vectors path) | The tree moved (history preserved if chosen), tests re-pointed to `../../testdata/vectors`, workflow with edition-neutral release text, README links | **A Go toolchain is required (not installed here): install Go matching `go.mod` or use a CI run.** `go vet ./...` on linux, windows/amd64, windows/arm64 and `-tags 'devtools agenttest'`; `go test -race -count=1 ./...` (93 top-level tests unchanged); 20 s `FuzzEmbedded`; `./e2e/run_e2e.sh` green against the fake server **and** a second run against the ported PHP `DeviceApi` served by a scratch `php -S` harness (T4/T5 fixtures); `make dist VERSION=0.1.0-beta.2 COMMIT=<sha>` twice gives byte-identical exes; Composer dist archive of Core contains no `endpoint-agent/` (verify with `git archive` listing) |
| **T7** | RivetIT adoption and cleanup | `mw-itflow` **worktree on a new branch** (never the live tree): `composer.json/lock/vendor`, `src/Core/Adapter/Endpoint/**` (6 adapters + bootstrap), `api/v1/{agent_*,endpoint_devices}.php`, `api/v1/includes/agent_device_api.php`, `admin/{settings_endpoint_agent,post/settings_endpoint_agent}.php`, `agent/{rmm_agent_device,post/rmm_agent}.php`, `cron/cron.php` block, `admin/database_updates.php` step 2.6.147, `includes/database_version.php`, `db.sql`, tests, docs; deletion of `src/EndpointAgent/**`, `endpoint-agent/`, the old workflow, copied fixtures in a **final separate commit** | T4, T5, T6 + owner tag of Core rc.4 | Adapters, bridges, UI on read models, `2.6.147`, regenerated `db.sql` (not by hand), shim `Db` removed at the end | The 7 existing RivetIT endpoint test files green **unchanged** against the new code; T1 golden transcripts identical; `information_schema` identical before/after the `2.6.147` step on a scratch copy of the schema (and on a copy restored from a beta backup); adapter conformance cases pass; role matrix test (`endpoint_agent_authz.php`) green; `agent_installer` download stamped binary byte-identical to a baseline-built one for the same inputs; **compat of enrolled agents**: a Windows or Linux-test agent enrolled against the baseline server keeps checking in, runs a signed job, and updates through `agent_update` against the new server (e2e harness, same device token and pinned key); db.sql fresh-install vs upgraded schema diff empty; beta smoke test per the verification discipline (real DB, live smoke test) before any production step; **switch**: `api/v1/rmm_gate.php` is the first include of `api/v1/index.php`, disabling the module in Administration gives 503 `module_disabled` with no DB connection, nav/search/cron respect it, an install with the agent already enabled stays enabled after `2.6.147` |
| **T8** | RivetMSP adoption | `rivetmsp-beta` **worktree**: files listed in 6.3 | T7 stable; owner go | MSP adapters and policy, bridges, UI pages, cron block, integration-type exclusions, `2.6.77` step, `db.sql`, `tests/core/EndpointAdapterConformanceTest.php`, `tests/core` role matrix | New conformance cases green on MSP adapters; role matrix equivalent to RivetIT's (minus limited logins); MSP suites (`tests/core`, `tests/api_auth_hardening.php`, nav coverage) unchanged green; scratch install of MSP from `db.sql` has the 10 tables, upgrade from 2.6.76 by the updater equals it (schema diff); enrolling a Linux-test agent against a scratch MSP: asset linked, `rmm_alerts` row opens/resolves, ticket auto-close path exercised, offline flip sets `rmm_status_changed_at`; vendor-RMM cron sync does not touch or error on the `rivetit_agent` integration; `config_core_rmm_enabled` defaults to 0 on a fresh install and a disabled MSP answers device endpoints with 503 `module_disabled` at the gate |
| **T9** | Module switch, capacity controls, simulator, panel data | `rivet-core`: `src/Rmm/{RmmState,RmmStateFile,Capacity/**}`, `Migration0016`, `Contracts/RmmModuleStateInterface`, `Admin` additions, `endpoint-agent/cmd/rmm-sim/**`, `endpoint-agent/internal/agent` (T6b: `module_disabled` branch, interval jitter), `tests/Integration/Rmm/{ModuleSwitch,LoadShedder,Capacity}Test.php`, `docs/rmm/CAPACITY.md`; edition-side templates for `rmm_gate.php` in `docs/rmm/` (the editions copy them in T7/T8) | T4, T5 (+T6 for the agent part) | State file writer/reader with the fail-safe rules, `features_json`/`limits_json` validation, gate template, `LoadShedder`, `CapacityReport`, `max_devices` and interval clamps, pruning in batches, queued-ingest mode (off by default) with the `rmm.ingest` handler and the releasing stub, `rmm-sim` | Disabled mode: with the state file saying off, gate template returns the exact 503 headers/body and a test proves zero DB connections and zero queries (counting `DatabaseInterface` double and the server counter in the integration run); missing/garbled/stale-version state file never turns the module off; sub-switch off returns `feature_disabled` and omits the feature from the check-in response; disabling then re-enabling keeps every row and queued `rmm.*` jobs (released, not dead-lettered); **baseline (old) agent and new agent both back off against a disabled server** (e2e: request count over 5 minutes at most 1 per 15 min per agent, buffer kept); shedder level transitions with hysteresis under injected backlog/latency; `rmm-sim` runs 500 devices at 300 s equivalent against scratch and meets the rc targets of 13.7; the model table of 13.2 is compared with the measured statements/rows per check-in (within 25%) |

Cross-cutting checks the owner or a reviewer runs after T7: fresh install of RivetIT from `db.sql` + enrollment end to end; upgrade of the production-shaped schema; `git grep -n 'EndpointAgent\\\\' -- . ':!vendor'` returns only the bridges' use of Core.

---

## 8. Risks and decisions

### 8.1 Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | **The "build7" session that wrote this feature may still be changing it** (`git log` shows its merges into `origin/beta`; the repo has unmerged RivetIT work in flight). Changes after the baseline would be lost or force rework. | Agree a freeze window on these paths: `src/EndpointAgent/**`, `endpoint-agent/**`, `api/v1/{agent_*,endpoint_devices}.php`, `api/v1/includes/agent_device_api.php`, `admin/{settings,post/settings}_endpoint_agent.php`, `agent/{rmm_agent_device,post/rmm_agent}.php`, `tests/endpoint_agent_*`, `tests/fixtures/*agent*`, `docs/ENDPOINT_AGENT*.md`, migrations 2.6.145/146 in `admin/database_updates.php`. Until Core reaches parity (T4+T5), any change there is allowed only if mirrored into `rivet-core/docs/design/endpoint-delta.md` (file, commit SHA, one line) so the port tasks replay it; a RivetIT CI/pre-commit check (`git diff --stat $BASELINE -- <paths>` must be empty or delta-logged) enforces it. After T7 lands, the freeze flips: edits go to Core only. Wire or schema changes are not allowed during extraction. |
| R2 | Behavioural drift during static-to-instance refactor (transactions, `FOR UPDATE`, `affected_rows` semantics for `INSERT IGNORE`, `Db::val` null vs 0) | Golden transcripts (T1), ported scenario tests, conformance case that checks `execute()` affected-row and insert-id semantics (already in `DatabaseContractTestCase`), one connection for all contracts. |
| R3 | **Branding:** names baked into the agent (service `RivetIT Agent`, install path, `rivetit-agent-*.exe`, `RIVETIT-EMBED-v1`, `RivetIT-Agent-Setup-<client>-x64.exe`, `rivetit-support` Mesh account, `rivetit:<id>` link key, `rivetit_agent` integration type) are identity for self-update and existing installs. Renaming breaks them. | Keep all of them in v1 of the extraction. A neutral rebrand (new product name) is a separate, versioned migration project with a dual-name transition. |
| R4 | The two editions get more coupled through one agent protocol: a Core fix to the protocol reaches both. | By design (that is the goal); protect with the frozen-constants test, vectors and the compatibility document. |
| R5 | `@internal` module consumed by editions means Core's "no BC promise" covers code they depend on (until 1.1). | The edition pins an exact rc.4; the `Contracts` and `RmmModule` surface is treated as stable de facto and promoted at 1.1. |
| R6 | **MeshCentral coupling:** `Mesh` needs a reachable MeshCentral server and the login key; stored encrypted. In Core it adds an outbound HTTP surface (SSRF policy). | Optional (`mesh_enabled=0` default), uses Core's `UrlPolicy`, tested with the mock; the key never leaves `SecretBoxInterface`. If the owner prefers, Mesh can stay edition-side behind an `EndpointRemoteInterface` (adds a 7th contract); recommendation is Core. |
| R7 | **Windows-only agent.** Linux/macOS are listed as follow-ups in the docs; the Linux build is a test build. | Unchanged by extraction; Core's `os` column and protocol allow more OSs later. |
| R8 | Authenticode signing is disabled in the workflow; unsigned binaries trigger SmartScreen. | Out of scope; needs a certificate and secrets in the Core repo. |
| R9 | No Go toolchain on this host; CI in the Core repo must be enabled for the agent workflow (it is new there) and GitHub Actions minutes/secrets are the owner's. | See T6 acceptance; first run on a branch before any `agent-v*` tag. |
| R10 | Security review and soak: the module adds credentialed endpoints (device auth, job execution with SYSTEM rights, remote access). It has had RivetIT's nightly review (`docs/security/nightly/2026-10-07.md`) but not Core's. | Add the module to `docs/security/threat-model.md` and request a focused review of T4/T5 output before `1.1.0` promotion; do not enable by default in any edition. |
| R11 | The existing agent PHP tests run real HTTP against `php -S` with RivetIT's whole harness, which is not portable to Core. | Core gets scratch-DB integration tests with in-memory adapters; the HTTP-level tests stay as the editions' regression (golden). |
| R13 | **Compute:** the RMM can saturate a small server (about 40 rows written per check-in at defaults; 5,000 devices is about 700 rows/s). | Switch + budget + shedding + capacity panel (sections 12-13); defaults lowered for new installs; load test gates the rc and GA. |
| R14 | **Scope creep:** "complete RMM" is multi-quarter. Phase 0 must ship alone and stay byte-compatible. | Phases are separate releases, each behind its own sub-switch, off by default. |
| R12 | Reason strings from `Authz::check` change (2.2). Anything that asserts exact text (`tests/endpoint_agent_authz.php`) must be updated. | Decision D4; list the asserted strings in T1. |

### 8.2 Decisions needed from the owner

| # | Question | Recommendation |
|---|---|---|
| D1 | Release vehicle: `1.0.0-rc.4` with the module `@internal` and opt-in migration list, or hold for `1.1.0` after `1.0.0` final? | rc.4 `@internal` opt-in (section 5.1); promote at 1.1.0. Choose 1.1.0-only if you want zero change to the candidate while the soak runs. |
| D2 | Agent branding for MSP customers: ship "RivetIT Agent" as is, or accept a later neutral rebrand project? | Ship as is now; rebrand later (R3). |
| D3 | Adapter sourcing: per-edition adapters (recommended, ADR-002) vs a Core-shipped reference SQL adapter for the shared fork schema. | Per-edition, with the conformance kit. |
| D4 | Keep the exact RivetIT denial texts ("Module-only logins cannot run jobs...") via an optional `ExplainingAccessPolicyInterface`, or accept generic texts? | Accept generic texts unless the UI relies on them. |
| D5 | MSP metrics: `NullRmmMetricSink` now, or port the Metrics subsystem to MSP first? | Null now; separate project. |
| D6 | Who hosts agent binaries: GitHub Releases of `rivet-core` (recommended; edition hosts the download for endpoints) or a separate release repo or only manual uploads? Do you want the `ReleaseFetcher` button in the first cut? | Core repo releases; fetcher as follow-up. |
| D7 | Keep Mesh inside Core (recommended) or an `EndpointRemoteInterface`? | Inside Core. |
| D8 | Preserve git history of `endpoint-agent/` and `src/EndpointAgent/` in Core (subtree split) or copy with a provenance note? | Subtree split for the Go tree (large history, useful `git blame`); copy for PHP, which is being rewritten. |
| D9 | Freeze mechanism for the parallel RivetIT session (R1): hard freeze, or delta-log sync? | Delta-log sync with the baseline SHA, hard freeze on schema/protocol. |
| D10 | Ownership of the first Core agent tag and the rc.4 tag, and of enabling GitHub Actions for the agent workflow in the Core repo. | Owner pushes tags; agents prepare branches only. |
| D11 | Effect on the security review and API freeze: accept the module as `@internal` in the rc line and a post-1.0 security review before promotion? | Yes (R10). |
| D12 | Accept the one wire-visible change in Phase 0: a disabled server answers **503 `module_disabled` + `Retry-After: 3600`** instead of 403 `forbidden` (old agents back off better, never drop data)? | Yes (12.4). |
| D13 | While the module is off, freeze edition RMM link statuses (banner) instead of flipping everything to offline (which would fire `asset_offline` automations)? | Freeze with banner. |
| D14 | Phase order after 0: Linux + inventory (1), then policies/scripts (2), alerting (3), patching (4)? And is macOS (Phase 7) wanted at all? | As listed; macOS only on explicit demand. |
| D15 | New-install defaults: *Recommended* profile (collect 300 s, raw retention 7 days) and `max_devices` 500? Existing installs keep today's values. | Yes. |

---

# Part II: from agent module to complete RMM (scope update 2026-10-07)

Sections 1 to 8 are **Phase 0**: move what exists and adopt it in both editions, byte-compatible. Sections 9 to 13 say what a complete RMM needs, how the Phase 0 design is shaped so later areas drop in without another redesign, how an admin switches the whole thing on and off cheaply, and how its compute cost is bounded. Sizes: **S** = one agent session (hours, one reviewable PR), **M** = 2 to 4 days of agent sessions, **L** = 1 to 2 weeks, **XL** = multi-week with real-world testing on target OSs.

## 9. Gap analysis: what exists versus what a credible RMM needs

Reference class: Tactical RMM (open source, the closest peer and already a vendor integration in RivetIT), NinjaOne, Datto RMM, Level, Action1. "Today" is verified from the RivetIT code at `c26957c0b`.

### 9.1 Capability table

| Area | Today (verified) | A credible RMM has | Gap | Where it lands |
|---|---|---|---|---|
| **Agent platforms** | Windows amd64/arm64 service (`endpoint-agent/*_windows.go`). A Linux collector exists (`internal/collect/collector_linux.go`, `reboot_other.go`) but is a **test build only**; no Linux installer/service, no macOS | Windows, Linux (systemd), macOS (launchd), signed packages (MSI/pkg/deb/rpm), proxy support, offline install | Linux S-M productise (service unit, install script, deb/rpm via `nfpm`), macOS XL (launchd, pkg, notarisation, TCC permissions), signing/MSI L | Phase 1 (Linux), Phase 7 (macOS) |
| **Enrollment and identity** | Token enrollment, install_id/machine_guid/serial identity rules, approval queue, per-client installer stamping, reinstall/rotate/revoke/retire | Same, plus site/group at enroll, auto-tagging, bulk deployment tokens, Intune/GPO scripts | Small: sites, tags | Phase 1 |
| **Hardware / software inventory** | `inventory_json` (OS, CPU model, memory total, disks, network adapters/MACs, serial, make, model, uptime, pending reboot, logged-in user) stored as one blob; applied to the asset blanks and `asset_rmm_links` columns | Normalised hardware (CPU, RAM modules, disks/SMART, GPU, BIOS, TPM), **installed software list** with versions and change history, services, users, network config, warranty | Software inventory and normalised tables missing; history missing | Phase 1 (M-L) |
| **Metrics** | cpu, memory, per-disk utilisation, network rx/tx, uptime, free/total bytes at 60 s collect / 300 s check-in, through the edition's Metrics subsystem (RivetIT only) | Same plus per-process top-N, temperatures, battery, ping/latency probes; graphs, retention/rollups | Edition-dependent (MSP has no Metrics); probes missing | Phase 1 for MSP sink, Phase 3 probes |
| **Checks / monitoring** | 4 check types (`service`, `disk`, `pending_reboot`, `script`), a **single global** schedule (`checks_json`), signed, debounce 3/2, alert episodes in `rmm_alerts` | Check types: CPU/RAM thresholds, disk, ping/port/HTTP, service, process, event log, script, cert expiry, backup status, patch status; **per-policy assignment**, per-device overrides | Policy assignment, more check types | Phase 2-3 |
| **Alerts** | `rmm_alerts` rows with stable key, auto-resolve, edition's alert-to-ticket and conservative auto-close; edition-side `rmm_maintenance_mode` column exists but agent alerts ignore it | Severity/threshold tiers, **suppression and maintenance windows**, flap detection, escalation chains, notifications (email/SMS/chat), dependency suppression, alert templates, auto-remediation scripts | Windows/suppression/escalation/auto-remediation missing | Phase 3 (M-L) |
| **Jobs / scripts** | 3 job types (`powershell`, `reboot`, `collect`), signed (Ed25519 over canonical JSON), run as SYSTEM, timeout, output cap and redaction, destructive confirm, at-most-once for destructive, lost-ack handling. Saved scripts come from the edition's `rmm_scripts` (no params, no variables) | Script library with **parameters, variables, secrets, versions, tags**, shells (PowerShell, cmd, bash, zsh, python), run-as options, **schedules and recurring tasks**, bulk run on a group/policy, result history, script sharing, approval for destructive | Registry of job types, shells for Linux/macOS, library model, scheduler, bulk targets | Phase 2 (L-XL) |
| **Remote access** | MeshCentral launch via a login cookie minted per click (`Mesh`), session logged in `rmm_remote_sessions`; node mapping manual | Remote desktop (attended/unattended), **remote terminal/shell**, **file browser and transfer**, service/process manager, registry editor, wake-on-LAN, session recording/consent | Terminal/files/process/service manager missing (Mesh provides desktop, terminal and files in its own UI) | Phase 6 (M for job-based service/process manager, L to embed Mesh terminal/files; native relay XL and not recommended) |
| **Patching** | none (only `pending_reboot` check and a `reboot` job) | Windows Update orchestration without WSUS (scan, approve, install, rings, deferrals), third-party patching (winget/choco), **maintenance windows**, **reboot policy**, patch compliance, rollback/report per device, Linux apt/dnf/zypper, macOS softwareupdate | Whole area | Phase 4 (XL) |
| **Software deployment** | none | Package catalog (winget, Chocolatey, MSI/EXE/pkg/deb/rpm with detection and silent args), install/uninstall/update, assignment to policies, status per device, bandwidth control | Whole area | Phase 5 (XL) |
| **Policies / templates** | one global check schedule; per-department installer stamping only | Policies assigned by **client, site, group, tag, device** with inheritance and override, versioned, assigned check/patch/script/agent-config sets | Whole area; this is the structural prerequisite for most others | Phase 2 (L) |
| **Custom fields** | none (RivetIT's `custom_fields` is a dead stub, documented as such) | Typed fields on client/site/device available to scripts as variables | Whole area | Phase 2 (M) |
| **Event log / syslog** | none (only `script` checks can read logs) | Windows event-log and syslog/journald collection, filtering, alert-on-event, retention | Whole area, storage heavy | Phase 8 (L, optional) |
| **Agent self-update** | Ed25519-signed manifest, rings `pilot`/`stable`, rollout %, `min_version`, never downgrade, failed-version skip, hosted binaries (`agent_update`), probation/rollback in the agent (`internal/update`) | Same plus per-client/policy channel pinning, update windows, bandwidth limits | Mostly done | Phase 2 (S) |
| **Offline behaviour** | ring buffer of up to 100 samples + checks, replayed on reconnect, idempotent by `(device, seq)`, exponential backoff with jitter and `Retry-After` | Same, plus queued jobs delivered on return (already: queued jobs offered until `expires_at`) | Done | |
| **Scoping** | per-client (department), optional location; user scope via `user_client_permissions` | Client > site > group > tag, role-based and per-client technician scope | Sites/groups/tags missing | Phase 1-2 |
| **Reporting** | none for agent data (device list and device page only) | Health summary, patch compliance, inventory/software reports, uptime/SLA, alert history, scheduled PDF/CSV, client-facing reports | Whole area | Phase 5 (M-L) |
| **API / events / audit** | Technician REST (`endpoint_devices`, jobs, remote), audit rows via `logAction`; **no RMM events in Core's `Webhooks\EventCatalog`** (only generic `asset.*`) | Webhook/event for every state change, public API for every action, audit trail | Events missing | Phase 1 (S-M) |
| **Security** | Signed jobs/checks/updates, per-device token (hash only), SYSTEM-only execution, confirm for destructive, redaction, bounded bodies, audited actions, key rotation forces re-enrol | Plus approval workflow for destructive/bulk actions (two-person), per-script allow-lists, run-as least privilege, tamper protection, secret variables, mTLS option, SBOM/signing of binaries | Approvals, secrets, signed binaries | Phase 2/9 |

### 9.2 Structural decisions that make growth cheap (apply in Phase 0)

1. **Job-type registry (PHP and Go).** Today job types are an `if/elseif` in `Actions::submitJob` (type to ability) and a `switch` in `jobs/executor.go:209`. Phase 0 introduces `Rmm\Automation\JobTypeRegistry` with entries `{type, ability, destructive default, platforms, param validator, default timeout, output policy}` seeded with the three existing types and **identical behaviour**; the Go side gets `jobs.Register(type, Handler)` with the same three. Adding `shell`, `package`, `patch_scan`, `patch_install`, `service`, `process`, `file_get` later is a registration plus a handler, no protocol change (`endpoint_agent_jobs.type` is `varchar(20)`, `params_json` is free-form). Unknown job types are refused by the agent (already true) and **never offered** to an agent that did not announce support (next point).
2. **Capability negotiation in check-in (additive wire change, Phase 1; the field is reserved in Phase 0).** The agent adds `capabilities` to check-in: `{ "proto": 1, "platform": "windows|linux|darwin", "arch": "...", "job_types": [...], "check_types": [...], "features": ["inventory.software", ...] }`; the server stores it (`endpoint_agent_devices.capabilities_json`, additive nullable column, migration 0017), filters offered jobs/checks by it, and returns `features` (what the server module currently allows, section 12). Old agents omit it and are treated as `windows`, the three job types, the four check types: exactly today's behaviour. Unknown fields are ignored on both sides (documented compatibility rule, section 5.1).
3. **Per-OS collectors.** `collect.Platform` is already an interface (`collect/platform.go`) with `collector_windows.go` and `collector_linux.go`; keep the pattern: one file per OS under build tags (`collector_darwin.go` later), a shared `Inventory`/`Metrics` shape, `ErrUnsupported` for data a platform cannot give. New data goes into the inventory object as **optional keys**; the PHP side never requires a key.
4. **Policy assignment model (tables arrive in Phase 2, shape fixed now).**
   * `rmm_policies(policy_id, kind, name, body_json, version, created_by, ...)`; `kind` in `check_set | patch | script_schedule | agent_config | software | alert`.
   * `rmm_policy_assignments(assignment_id, policy_id, scope_type, scope_id, priority, enforce)` with `scope_type` in `global | client | site | group | tag | device`.
   * Resolution: collect assignments that match a device, order by specificity (`device > tag > group > site > client > global`) then `priority`; merge object keys (check keys, schedule keys) with later (more specific) winning unless a less specific assignment has `enforce=1`; produce a **resolved bundle** and a SHA-256 `config_version`. Check-in carries the agent's current `config_version`; the server returns the bundle only when it differs (the signed `config.checks` of today is the first bundle kind, so Phase 0's `signedChecks()` becomes `ConfigBundle::forDevice()` returning the same bytes).
   * Sites map to the edition's locations (`RmmTenancyInterface` grows a companion `RmmSitesInterface` in Phase 1); groups and tags are Core-owned tables (`rmm_groups`, `rmm_tags`, `rmm_device_tags`), because neither edition has them.
5. **Feature areas are individually switchable** (section 12) and every area owns its tables and read models, so a disabled area costs nothing and an enabled one cannot touch the rest.
6. **No logic in the edition.** Everything the editions add in Phase 0 (adapters, bridges, UI) stays valid when new areas arrive: new areas add read models and `RmmAdmin` operations; edition UIs add pages per area.

### 9.3 New contracts later phases will need (companions, never new methods on Phase 0 interfaces)

| Phase | Companion interface | Purpose |
|---|---|---|
| 1 | `RmmSitesInterface` | list a client's sites (locations) and resolve a site name |
| 1 | `RmmEventsInterface` (optional, Null default) | publish `rmm.*` events to the edition event bus, which feeds webhooks, automation rules and Core's `EventCatalog` consumers |
| 3 | `RmmTicketInterface` | create, link and conservatively close a ticket for an alert (today this stays in the edition's own alert-to-ticket path), plus `RmmNotifierInterface` for escalation notices if the editions do not already own that |
| 2 | `ExplainingAccessPolicyInterface` (optional) | reason strings (decision D4) |
| 2 | `RmmScriptLibraryInterface` (optional) | only if an edition keeps its own script table instead of Core's library (RivetIT's `rmm_scripts` is Tactical-shaped; Core ships `rmm_scripts_v2` and a one-time import) |

### 9.4 Events, audit, API, security rules for every new area

* Every state change emits an audit row (`RmmAuditInterface`) and, when `RmmEventsInterface` is bound, an event with an id registered in `Webhooks\EventCatalog` (ids are never reused, ADR-004). Planned ids: `rmm.device.enrolled`, `rmm.device.approved`, `rmm.device.rejected`, `rmm.device.revoked`, `rmm.device.retired`, `rmm.device.online`, `rmm.device.offline`, `rmm.alert.opened`, `rmm.alert.resolved`, `rmm.job.submitted`, `rmm.job.completed`, `rmm.job.failed`, `rmm.agent.updated`, `rmm.module.enabled`, `rmm.module.disabled`; later `rmm.patch.*`, `rmm.software.*`, `rmm.policy.*`. Payloads carry ids and names, never scripts, outputs or secrets (Core's audit and webhook redaction apply).
* Every action has a technician REST route and an ability in `RmmAbility`; REST and UI share `TechnicianActions` so they cannot drift (existing rule).
* Destructive or bulk actions (reboot, patch install, uninstall, run on a group) require `confirm`, and in Phase 2 an optional **approval step** (second user with `rmm.approve`, stored in `rmm_approvals`); scheduled/policy-driven jobs run only scripts whose body hash was approved at schedule time.
* Least privilege: agent runs as the OS service account (SYSTEM today); later `run_as` options are explicit job fields, per-ability gated, and never inferred from input. Free text from a device never reaches a script (existing rule, tested by the vectors and the Phase 0 tests).
* Secrets (script variables, package credentials) are stored only through `SecretBoxInterface` and injected at execution; `Crypto\Redactor` runs on every output.

## 10. Phased roadmap

All phases beyond 0 are **outlined, not designed to the line**; each starts with its own short design addendum. Priority order reflects what makes the product credible fastest and what later phases depend on.

| Phase | Content | Size | New tables (Core) | New contracts | Agent (Go) work | Acceptance (summary) |
|---|---|---|---|---|---|---|
| **0** | Extraction and adoption of what exists, byte-compatible (sections 1 to 8), **plus** the module switch (section 12), the capacity limits and load shedding (section 13), the job-type registry and the reserved capability field | **L** (about 2 to 3 weeks of agent sessions: Core PHP port M-L, Go move S, RivetIT adoption M, MSP adoption M, switch and capacity M) | the 10 `endpoint_agent_*` tables (0014/0015) + settings columns (0016) | `Tenancy`, `Assets`, `Bridge`, `SecretBox`, `MetricSink`, `Audit`, `ModuleState` | move, vector path, disabled-server backoff (T6b) | section 7 acceptance, schema diff, golden transcripts, existing agents unaffected, disabled mode zero-DB, load test targets (13.7) |
| **1** | Foundation: Linux agent productised (systemd, deb/rpm, install script), capability negotiation live, normalised inventory incl. **software list with history**, sites/groups/tags, `rmm.*` events, MSP metric sink (or Metrics port, D5) | **L-XL** (Linux agent M, inventory L, tags/sites/events M) | `rmm_inventory_hw`, `rmm_inventory_software`, `rmm_inventory_history`, `rmm_groups`, `rmm_tags`, `rmm_device_tags`; column `capabilities_json` | `RmmSitesInterface`, `RmmEventsInterface` | Linux collector completion and service, software inventory per OS, capability block | A Linux VM enrols, reports inventory/software, runs `collect`; Windows agents unchanged; event ids in `EventCatalog` with tests |
| **2** | **Policies and check templates** assigned by client/site/group/tag/device, scheduled scripts and recurring tasks, **script library** (params, variables, secrets, versions), bulk run on a target set, approval for destructive, agent-config channel pinning, custom fields | **XL** (policy engine L, scheduler M, library M, custom fields M, approvals S-M) | `rmm_policies`, `rmm_policy_assignments`, `rmm_scripts_v2`, `rmm_script_versions`, `rmm_schedules`, `rmm_approvals`, `rmm_custom_fields`, `rmm_custom_field_values` | `ExplainingAccessPolicyInterface` (opt), `RmmScriptLibraryInterface` (opt) | `shell` job type (bash/zsh/PowerShell/cmd), schedule execution offline-safe, `config_version` bundles | Resolver unit tests with a full inheritance matrix; a policy edit reaches only assigned devices; scheduled job runs once per window and survives agent restarts and offline periods |
| **3** | **Alerting maturity**: threshold tiers, maintenance windows, suppression, flap detection, escalation, auto-remediation, more check types (cpu/ram, ping/port/http, process, cert expiry, event-log basic), tickets through `RmmTicketInterface` | **L** | `rmm_alert_rules`, `rmm_maintenance_windows`, `rmm_escalations` | `RmmTicketInterface`, `RmmNotifierInterface` | new check types (Go, per OS where meaningful) | A window suppresses alerts without losing state; escalation fires once per step; flapping device does not storm tickets |
| **4** | **Patching**: Windows Update orchestration (scan/approve/install via the Windows Update Agent API through the agent), approval rings, maintenance windows, reboot policy, compliance; Linux apt/dnf; third-party via winget | **XL** (Windows L-XL, Linux M, compliance reports M); needs real Windows test machines | `rmm_patch_catalog`, `rmm_patch_device_state`, `rmm_patch_approvals`, `rmm_patch_runs` | none new | `patch_scan`, `patch_install` job types, WUA integration, reboot orchestration | Pilot ring installs approved patches in its window and reboots per policy; compliance report matches the endpoints |
| **5** | **Software deployment** and **reporting**: package catalog (winget, Chocolatey, MSI/EXE with detection and silent args; apt/dnf), install/uninstall/update by policy; reports (health, patch compliance, inventory, uptime), scheduled CSV/PDF | **XL** (deployment L-XL, reporting M-L) | `rmm_packages`, `rmm_package_versions`, `rmm_package_installs`, `rmm_reports`, `rmm_report_runs` | none new | `package` job type, detection rules | Install/uninstall verified by detection; reports reproducible from stored data |
| **6** | **Remote tools**: service/process manager and file get/put as signed jobs (S-M each); embedded Mesh terminal/files (M-L); native relay for terminal/desktop is **not recommended** (XL, needs a persistent-connection service that PHP-FPM cannot hold) | **M-L** | `rmm_file_transfers` | none | `service`, `process`, `file_get`, `file_put` handlers with size caps | Every action is authorised, audited, size-capped, signed |
| **7** | macOS agent (launchd, pkg, notarisation, signing identity), Authenticode-signed Windows binaries, MSI | **XL** (needs Apple developer account and signing certificates) | none | none | `collector_darwin.go`, packaging | Install and update on a real Mac; signed artefacts verify |
| **8** | Event-log / syslog collection (optional, off by default, storage heavy) | **L** | `rmm_log_events` (partitioned, short retention) | none | log readers per OS with filters and rate caps | Bounded per-device volume, retention pruned in batches, alert-on-event works |

**Realistic single-session items** (S): the job-type registry skeleton; capability field parsing; `rmm.*` event catalog entries; a new check type that only needs an OS API (for example process-running); `service`/`process` jobs; a report export; the Linux service unit and install script. **Multi-week items**: policies, patching, software deployment, macOS, native remote, event-log collection.

## 11. Final contract list (Phase 0) and what changed from section 2

Eight interfaces in `RivetCore\Rmm\Contracts`, **four required** to implement, four optional or defaulted:

| # | Interface | Required | Default Core ships | Phase |
|---|---|---|---|---|
| 1 | `RmmTenancyInterface` (clients, locations, user scope) | yes | `InMemoryRmmTenancy` (tests) | 0 |
| 2 | `RmmAssetsInterface` (asset match/create/fill/move) | yes | `InMemoryRmmAssets` | 0 |
| 3 | `RmmBridgeInterface` (integration row, links, alerts, saved scripts, remote-session log) | yes | `InMemoryRmmBridge` | 0 |
| 4 | `SecretBoxInterface` | yes | `InMemorySecretBox` (tests only) | 0 |
| 5 | `RmmMetricSinkInterface` | no | `NullRmmMetricSink` | 0 |
| 6 | `RmmAuditInterface` | no | `AuditServiceRmmAudit` | 0 |
| 7 | `RmmModuleStateInterface` (edition kill switch and state-file location, section 12.2) | no | `SettingsRmmModuleState` (reads the module's own table only) | 0 |
| 8 | `RmmEventsInterface` | no | `NullRmmEvents` | 1 |

Reused unchanged: `DatabaseInterface`, `AccessPolicyInterface`, `ClockInterface`, `UrlPolicy`, `JobQueue`/`JobWorker` (section 13), `Redis\RateLimiter` (optional, section 13.3). Section 2.4's justification stands; the new ones are justified above (7: the edition-level master switch and the zero-DB fast path need edition input; 8: events cross into the edition event bus).

## 12. Module switch: enable and disable, zero cost when off

### 12.1 What an admin sees

| Level | Switch | Default for a new install | Existing RivetIT install that uses the agent |
|---|---|---|---|
| Edition kill switch | RivetMSP: `settings.config_core_rmm_enabled` (follows the existing `config_core_<module>_enabled` convention read by `CoreBridge::enabled()`, column added by the MSP updater, default 0). RivetIT: none (its `settings` row is at the row-size limit and `rivetCoreModuleOn()` returns true for modules without a switch); RivetIT relies on the master below | MSP off; IT n/a | n/a |
| **Master** | `endpoint_agent_settings.enabled` (existing column, today the only switch, default 0) shown as Administration > RMM > "Enable RMM module" | **OFF** | **unchanged value** (migration never touches it: an install that enabled the agent stays ON; one that never did, or an admin switched off, stays OFF; disabling already keeps data) |
| Sub-switches | `endpoint_agent_settings.features_json` (new nullable column, migration 0016): `monitoring`, `metrics`, `jobs`, `remote`, `updates`, later `inventory_software`, `policies`, `patching`, `software`, `logs`, `reports` | when master turns ON for the first time the admin picks a preset: **Light** (`monitoring` + `updates`), **Standard** (+ `metrics`, `jobs`, `remote`), **Custom**; NULL means "legacy defaults" | `NULL`: `monitoring`, `metrics`, `jobs`, `updates` on; `remote` follows the existing `mesh_enabled`; everything new is off. So behaviour after the upgrade is exactly today's |

Effective state: `enabled = editionAllows && master`; a feature is active when `enabled && features[feature]`. The task owner's wording "migration sets the flag ON when endpoint_agent tables contain devices/tokens, else OFF" is satisfied without a data-dependent rule: the existing `enabled` column already holds exactly that fact. The migration must **not** infer ON from rows (an admin who disabled the module on purpose would be switched back on).

### 12.2 Where state lives and how it is read (`RmmModuleStateInterface`, `RmmState`, `RmmStateFile`)

```php
interface RmmModuleStateInterface
{
    /** Edition-level kill switch. RivetMSP: config_core_rmm_enabled; RivetIT: true. Must not throw; false on any failure. */
    public function editionAllows(): bool;

    /** Directory (writable by the web user, shared by all web nodes) where Core keeps the zero-DB state file, or null to disable the fast path. */
    public function stateDirectory(): ?string;
}
```

* **Source of truth is the database** (`endpoint_agent_settings`): `enabled`, `features_json`, `limits_json`, and `shed_level` (written by the load shedder, 13.4).
* **Fast path:** whenever `RmmAdmin` saves a switch, a limit or the shed level changes, Core writes `<stateDir>/rmm_state.php` atomically (temp file + `rename`) containing `return ['v'=>1,'enabled'=>bool,'features'=>[...],'shed'=>int,'retry_after'=>int,'written_at'=>int];`. It is read with an `include` that PHP opcache caches, so a request that only needs "is the module on" costs a `stat()` and no database access.
* **Fail-safe:** a missing, unreadable or version-mismatched file means **"unknown", never "off"**: the request proceeds on the normal path (one primary-key SELECT on the settings row) and the first such request rewrites the file. A stale file can only delay a switch by the interval until `RmmAdmin` rewrites it, which happens in the same request as the save. For multi-node deployments the directory must be shared; otherwise leave `stateDirectory()` null and accept the single-SELECT cost.
* `RmmModule::enabled()`, `RmmModule::featureOn($name)` read the file first, the DB second, and cache per request.

### 12.3 Zero cost when disabled

| Surface | Behaviour when **master is off** | Mechanism |
|---|---|---|
| Device REST (`agent_enroll`, `agent_checkin`, `agent_jobs`, `agent_update`, `agent_installer`) | **No database work, no bootstrap**: HTTP **503**, headers `Retry-After: 3600`, `Cache-Control: no-store`, `Content-Type: application/json`, body `{"error":"The RMM service is disabled on this server.","code":"module_disabled"}` | An edition `api/v1/rmm_gate.php` is the **first** `require` of `api/v1/index.php`, before `config.php` and `includes/db.php` (today `index.php` loads config, opens the mysqli connection and loads global settings before routing, `api/v1/index.php:16-23`, so without a pre-bootstrap gate every agent request would still open a DB connection). The gate parses the path, matches only the 5 device endpoints and `endpoint_devices`, includes the state file and either returns the 503 (technician API: 404 `{"error":"The endpoint agent is not enabled.","code":"disabled"}`, as today) or falls through. It loads no Core class. |
| Single feature off | `agent_jobs` (jobs off) and `agent_update` (updates off) return 503 `{"code":"feature_disabled"}` with `Retry-After: 3600`; the check-in response simply omits `update` and reports `jobs_pending: 0`; metrics off drops sample ingest, monitoring off skips check evaluation, link health stays (it is monitoring's data, so off with it). Check-in itself keeps working so devices stay "online" for whatever features remain. | `CheckinService` consults `RmmState` once per request (file/array lookup) |
| Cron | `cron.php` block (line 1692) and Core job handlers do nothing: the block reads the state file first and skips the autoload and every query | `if (!RmmStateFile::enabled($dir)) { skip }` before `require vendor/autoload.php` |
| Navigation and pages | No sidebar item, no settings tile, no device page link, no search-index entry | edition nav includes call `RmmModule::enabled()` (file read); pages `exit` with the standard "module off" notice |
| Includes | Bridges `require` Core only after the gate passes; Composer's autoloader is lazy, so no `Rmm\*` class is loaded when the module is off | structure of the 5-line bridge |
| Job queue | Handlers for `rmm.*` job types are registered only when enabled; when disabled, a registered stub **releases** the job (`JobQueue::release`, counted as `deferred`, no attempt spent) so queued work survives disable/enable instead of dead-lettering (Core's worker dead-letters unknown types) | `RmmModule::registerJobHandlers(JobWorker)` |
| Data | Nothing is deleted or altered. Devices, tokens, jobs, checks, binaries, signing key all stay. Queued jobs keep their `expires_at` and expire normally (`sweep()` marks them on the first run after re-enable). Re-enable resumes: agents come back by themselves on their own backoff | |
| Status display | Edition link rows are **not** flipped to offline while the module is off (that would fire `asset_offline` automations for every device because an admin flipped a switch); the UI shows a banner "RMM module is off: device status is frozen" and the first maintenance run after re-enable computes true statuses from `last_checkin_at`. Decision D13 | |

### 12.4 What existing agents do against a disabled server

Today a disabled server answers **403 `forbidden`** after a database lookup (`Devices::authenticate` ends with the `Config::enabled()` check; `agent_enroll` checks it first). In the agent that status falls into the `default` branch of `checkinFailed` (`internal/agent/agent.go:~505`): **the in-flight check-in is dropped** and the next try waits the loop's backoff. That is hostile in both directions (data loss, and the retry period depends on the attempt counter). The change:

* Server: use **503 + `Retry-After: 3600` + `code: module_disabled`** (above). Old agents already treat `>= 500` as transient (`APIError.Transient`) and apply `DelayWithRetryAfter(attempt, retryAfter)`, where `Retry-After` is a floor and capped at 1 h (`ParseRetryAfter`), and they keep the in-flight body and the ring buffer. So **un-updated agents already back off to once per hour with no code change**; this is verified by an e2e test on the baseline agent binary (T6b).
* Agent (T6b, new release `agent-v0.1.0-beta.2` or later): explicit branch for `code == "module_disabled"`/`"feature_disabled"`: keep credential and buffer, reset the failure counter so it does not count as an error, log once at INFO, delay = `max(Retry-After, 15 min)` with full jitter, stop job polling and updates until a check-in succeeds; enrollment attempts that get `module_disabled` stop retrying quickly (the installer reports "server disabled" exit code). Ring buffer limits stay (100 samples), so a long disable loses only old samples.
* Wire note for the frozen-constants table: **this is the only intentional wire-visible change in Phase 0** (the disabled response moves from 403 to 503). Golden transcripts exclude the disabled case; the e2e test asserts both the old-agent and new-agent behaviour. It is decision D12.

### 12.5 Migrations and edition changes for the switch

* Core migration `0016_rmm_module_switches`: additive columns on `endpoint_agent_settings`: `features_json text NULL`, `limits_json text NULL`, `shed_level tinyint(1) NOT NULL DEFAULT 0`, `ingest_mode varchar(10) NOT NULL DEFAULT 'sync'`, `max_devices int(11) NOT NULL DEFAULT 0` (0 = unlimited, today's behaviour). `information_schema`-guarded; folded into the schema-diff test (schema A is extended with them; schema C proves the upgrade path).
* RivetIT: step 2.6.147 also writes the first state file (if a directory is configured). No `settings` column.
* RivetMSP: step 2.6.77 adds `settings.config_core_rmm_enabled tinyint(1) NOT NULL DEFAULT 0`, the Administration > Core modules page lists it with the other `config_core_*` flags, and `MspRmmModuleState::editionAllows()` reads it.
* Both editions: `rmm_gate.php` (pre-bootstrap gate), nav/search conditions, cron conditions.

## 13. Compute and resource budget

The RMM is the first Core module whose load scales with the **number of managed devices times time**, not with technician activity. The design bounds it three ways: do less per check-in, do the writes cheaper, and refuse politely when overloaded.

### 13.1 What one check-in costs (from the code)

Per accepted check-in (`Checkin::handle`/`process`, steady state, defaults `collect_interval_s=60`, `check_in_interval_s=300`):

* one request that opens a transaction, `SELECT ... FOR UPDATE` on the device row, `INSERT IGNORE` into `endpoint_agent_checkins`, and 2 to 3 `UPDATE`s on `endpoint_agent_devices`;
* 5 sample batches (the current one + 4 buffered 60 s samples), each producing about 7 metric samples (cpu, memory, 1 to 3 disk, network rx/tx), about **35 sample rows** into the edition's Metrics tables through one `ingest()` call (RivetIT);
* one `asset_rmm_links` update (plus one select);
* per check result: one `SELECT` and one `UPDATE` on `endpoint_agent_checks` (3 default checks = 6 statements);
* plus, on inventory change or daily: a larger `UPDATE` with up to 64 KiB of JSON and about 10 more samples.

Order of magnitude: **20 to 25 statements and about 40 rows written per check-in** at the defaults. RivetIT already ships a counting probe and a throughput script (`tests/load/agent_ingest_load.php`, "Part 1: cost probe" measures exact statements and rows); the numbers below are a **model and must be replaced by its measurements** in Phase 0 (acceptance 13.7).

### 13.2 Estimate model

`check-ins/s = devices / check_in_interval_s`; `rows/s = check-ins/s x rows per check-in`; storage/month = `rows per check-in x check-ins per day x 30 x bytes per row` (about 120 B per sample row including its index, an assumption). PHP workers busy = `check-ins/s x seconds per request` (assumed 0.15 s average, peak 3x because of jitter and restarts).

| Devices | Profile | Check-ins/s | Rows written/s | Statements/s | Avg PHP workers busy (peak) | Raw sample storage per month (30 d) |
|---|---|---|---|---|---|---|
| 50 | Defaults (collect 60 s, check-in 300 s) | 0.17 | 7 | 4 | 0.03 (0.1) | 1.8 GB |
| 500 | Defaults | 1.7 | 70 | 40 | 0.25 (0.8) | 18 GB |
| 5,000 | Defaults | 16.7 | 700 | 400 | 2.5 (7.5) | 180 GB |
| 50 | **Recommended** (collect 300 s, check-in 300 s, 7-day raw + hourly rollups) | 0.17 | 2.3 | 3 | 0.03 | 0.4 GB raw, steady about 0.1 GB |
| 500 | Recommended | 1.7 | 23 | 30 | 0.25 | 3.6 GB raw, steady about 1 GB |
| 5,000 | Recommended | 16.7 | 233 | 300 | 2.5 | 36 GB raw, steady about 10 GB |
| 5,000 | Light (monitoring only: checks + link health, metrics off, check-in 600 s) | 8.3 | 8.3 x 8 = 67 | 100 | 1.2 | under 1 GB (no samples) |

Conclusions the defaults must reflect: the **collect interval and the retention of raw samples dominate storage and write rate**, the request rate is modest even at 5,000 devices (about 17 requests/s), and the **write amplification per check-in** (40 rows) is the real cost. Therefore: new installs default to the *Recommended* profile (collect 300 s), raw retention 7 days with rollups kept per the edition's Metrics retention, `check_in_interval_s` 300 (floor 60, ceiling 3600), and the admin can raise resolution deliberately (a warning shows the projected storage). Existing installs keep their current intervals (no silent change).

### 13.3 Controls (all in `limits_json` unless noted; Phase 0)

| Control | Default (new install) | Behaviour |
|---|---|---|
| `max_devices` (column) | 0 = unlimited (existing) / suggested 500 on new installs | Enrollment refuses beyond it: 403 `{"code":"device_limit"}`; retired/revoked devices do not count |
| `check_in_interval_s` floor/ceiling | 60 / 3600 | Server-side clamp when saving; `next_check_in_s` always within it. Agents also add +/-10% jitter and a random start delay (T6b) so a server restart does not produce a thundering herd |
| `collect_interval_s` floor | 30 | clamp |
| Max check-ins per minute (global) | 0 = off; suggested 4 x devices / interval | Sliding counter in the state store (Redis through `RateLimiter` when available, otherwise a per-minute counter in the state file directory); over the limit: **503 + `Retry-After` of 30 to 120 s with jitter** (not 429, which agents also back off from but which signals a per-client fault) |
| Per-device rate limits | 40/60 s check-in, 120/60 s jobs, 60/60 s update | frozen (section 4.1) |
| Body caps | 1 MiB check-in, 16 KiB enroll, 256 KiB job report, 4 KiB installer | frozen; `max_buffered` 100 |
| `ingest_mode` (column) | `sync` | `sync` = today's behaviour. `queued` = see 13.4 |
| Raw retention | 30 days existing / 7 days new installs (edition Metrics retention drives the sample tables; Core prunes only `endpoint_agent_*`) | pruning in batches (see below) |
| Pruning | `endpoint_agent_checkins` and `_jobs`, `_enroll_attempts` | `DELETE ... LIMIT 5000` loops with a 20 ms sleep and a per-run row cap (today single unbounded `DELETE`s in `Maintenance::run`) |
| Redis (optional) | off | When `Redis\RateLimiter` and `CronGuard` are available (Core's Redis module, fail open): the global counter and the maintenance lock use them; the module works identically without Redis |

Batched inserts: `MetricMapper` already produces all samples of a check-in in one list; the sink is called once per check-in (a single multi-row `INSERT ... VALUES (...),(...)` in the edition sink). In queued mode the worker merges the samples of up to 50 check-ins into one statement.

### 13.4 Queued ingestion and load shedding

* **Queued mode** (Phase 0b, after the byte-compatible extraction is stable): the web request validates, applies the cheap state changes synchronously (idempotency row, `last_checkin_at`, device identity, checks) and, instead of writing samples, enqueues **one** `integration_jobs` row of type `rmm.ingest` through Core's `JobQueue` with the sample list as payload (typical 2 to 3 KB; the column is `text`, so payloads above 60 KB are written synchronously instead). A **separate low-priority worker** (a cron-driven `JobWorker` with `priority` below interactive jobs, its own time budget, `JobTimeout`) drains it in batches. This keeps the request tiny and moves bursty writes off the web path. It costs one extra row write per check-in, so it is **off by default** and recommended only above about 1,000 devices or on slow disks.
* **Load shedding** (`Capacity\LoadShedder`, evaluated by the maintenance cron and at most once per 10 s on the request path from cheap signals): signals are queue backlog (`JobQueue::stats()` pending count and oldest age for `rmm.ingest`), recent check-in latency (rolling p95 kept in the state directory/Redis), and an optional DB threshold. Levels are written to `shed_level` and the state file:
  * **L0** normal.
  * **L1** (backlog above T1 or p95 above 1 s): responses stretch `next_check_in_s` by 2x (agents already obey it, `agent.go:597`), buffered samples older than 15 minutes are acknowledged but not ingested, expensive optional work (inventory re-apply) is deferred.
  * **L2** (backlog above T2 or p95 above 3 s, or DB errors): check-ins are answered `503 + Retry-After 60-300 s (jittered)` for devices already seen in the last check-in window, **device-state writes are never skipped for enrollment, jobs reports or revoke**; agents keep their buffer and retry.
  * Recovery needs two consecutive healthy evaluations (hysteresis). Every transition is audited and emits `rmm.module.*` style events later.
* **Index review for the hot tables** (Phase 0 task, no DDL change in the extraction): `endpoint_agent_devices` lookups are by `token_hash` (indexed), `install_id` (unique), `asset_id`, `client_id`; `endpoint_agent_checkins` is PK-only plus `idx_received` for pruning (appends are sequential per device: keep); `endpoint_agent_checks` PK lookup. Findings go in the capacity report; **no index is added or dropped in the extraction** (schema must stay identical). Candidate later: `endpoint_agent_jobs (device_id, state, expires_at)` if offer queries show filesort at scale, and the edition metric tables' own index and partitioning review (the RivetIT Metrics tables dominate storage and are outside Core).

### 13.5 Administration > RMM > "Performance and capacity" panel (spec; data from `RmmReadModel`/`CapacityReport`, markup in the edition)

* **Header cards:** enrolled devices (and limit), devices online/offline/stale, check-ins in the last minute and last hour (from `endpoint_agent_checkins` counts, indexed on `received_at`), current shed level, ingest mode.
* **Queue and latency:** `rmm.ingest` queue depth and oldest age, p50/p95 check-in latency (rolling), error rate, rejected-by-limit count, last 24 h sparkline.
* **Table sizes:** rows and data+index MB of every `endpoint_agent_*` table and of the edition's metric tables (edition supplies the list through `RmmMetricSinkInterface` companion `describeStorage()`, or Core reads `information_schema.TABLES` for its own tables only), growth per day.
* **Projection:** the model of 13.2 filled with the live numbers: projected check-ins/s, rows/s, storage at the current retention, workers needed; the settings that drive it are shown next to it.
* **Warnings:** device count over 80% of the limit; projected storage above free disk if the edition can tell, otherwise above a configurable GB budget; collect interval below 60 s with more than 200 devices; queue age above 5 min; shed level above 0; Redis recommended above 1,000 devices; raw retention above 14 days with more than 500 devices.
* **One-click "Reduce load" presets** (each shows the before/after projection and asks for confirmation; they only change settings, never delete data): *Lengthen check-in to 600 s*, *Collect every 300 s*, *Metrics off (monitoring only)*, *Raw retention 7 days*, *Enable queued ingest*. Agents pick up new intervals on their next check-in (`next_check_in_s`, `collect_interval_s` in the signed config).
* Everything on the panel is read-only for non-admins and audited when changed.

### 13.6 Capacity levers on the agent side (Phase 0, Go)

Full-jitter exponential backoff with `Retry-After` floor (exists), +/-10% interval jitter and random start delay (new), honour `module_disabled`/`feature_disabled` (new), honour server-supplied `next_check_in_s` and `collect_interval_s` (exists), upload only deltas when idle (inventory is already sent on change and at least daily), keep the ring-buffer cap at 100. No new agent traffic is added in Phase 0.

### 13.7 Load-test plan

* **Simulator:** `endpoint-agent/cmd/rmm-sim` (Go, same module, reuses `internal/api` types and `internal/jobs` verification so it speaks the real protocol) and the existing PHP script `tests/load/agent_ingest_load.php` (kept as the in-process cost probe, moved to `rivet-core/scripts/` or kept in the edition). The simulator enrolls **N fake devices** with one multi-use token (`max_uses = N`), then each device checks in with a realistic body (profile `defaults`: 5 buffered batches + 3 check results, 1.9 to 2.3 KB; profile `inventory`: first check-in with inventory) at a configured aggregate rate with +/-10% jitter, optionally with a `--herd` mode (all devices connect within 10 s, simulating a server restart), a `--disabled` mode (module off), and a `--shed` mode (inject backlog). It reports requests/s, latency percentiles, status code histogram, bytes, and verifies server state afterwards (row counts, no duplicate `seq`, no lost buffered samples within the cap). Run only against a scratch database and scratch URL (never the live beta DB).
* **Targets on this host** (14 vCPU Linux VM used for the agent footprint measurement; MariaDB local; PHP-FPM with 16 workers):
  * **rc gate (Phase 0):** 500 simulated devices at 300 s (1.7 req/s): p95 under 250 ms, p99 under 600 ms, 0 errors, DB CPU under 15% of one core average; 3x peak (5 req/s) p95 under 400 ms; `--herd` with 500 devices in 10 s: no more than 1% 503, zero data loss, recovery to normal within 2 minutes.
  * **GA gate (before 1.1 promotion):** 5,000 devices at 300 s (16.7 req/s) on the *Recommended* profile: p95 under 400 ms, error rate under 0.1%, ingest queue age under 30 s in queued mode; `--herd` 5,000 in 60 s: no more than 2% 503, zero state loss, recovery under 5 minutes; a 24 h soak with 500 devices shows flat memory and no unbounded table growth beyond the projection (within 20%).
  * **Disabled mode:** 2,000 requests/s of mixed agent calls against a disabled module: every one answered 503 in under 5 ms at the gate, **0 database connections and 0 queries** (assert with the server's connection counter and `Com_select` delta equal to 0 over the run), PHP-FPM CPU negligible.
  * Model validation: measured statements and rows per check-in within 25% of 13.1; otherwise the table in 13.2 is corrected in the docs before release.
* The test results are stored under `docs/RELEASE_GATE.md` style evidence with the host description; numbers from this host are a floor, not a capacity promise (the existing script's own caveat).
