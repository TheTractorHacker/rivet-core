# RMM (endpoint agent)

Status: skeleton. Phase 0 of [the design](../design/endpoint-module-extraction.md) and [ADR-010](../architecture/ADR-010-endpoint-agent-module.md): contracts, HTTP objects, protocol constants, schema and conformance kit are in place; the domain services and handlers arrive with the next tasks.

## Overview

`RivetCore\Rmm` is the edition-neutral home of the endpoint agent server side (enrollment, devices, check-in, signed jobs, updates, installers, MeshCentral launch) and the base of a full RMM. It is **off by default** and costs nothing when off. Platforms in scope for Phase 0: Windows and Linux; macOS is deferred (the check-in keeps room for platform capability negotiation, see `RmmProtocol::PLATFORMS`).

- **Owns:** the ten `endpoint_agent_*` tables, created by migrations `0014_endpoint_agent_core` (exact RivetIT DB 2.6.146 DDL, `CREATE TABLE IF NOT EXISTS`, `utf8mb4_general_ci`), `0015_endpoint_agent_converge` (installs that stopped at RivetIT 2.6.145: `ca_pem`, `arch`, `binary_id`, the `(version, ring, arch)` key) and `0016_rmm_module_switches` (`features_json`, `limits_json`, `shed_level`, `ingest_mode`, `max_devices`; defaults reproduce today's behaviour). All three are in `CoreMigrations::all()`; on an install that already has the tables they are no-ops (apart from 0016's columns). Never touched: `enabled`, keys, credentials, rows.
- **Does not own:** `assets`, `asset_interfaces`, `asset_rmm_links`, `rmm_alerts`, `rmm_integrations`, `rmm_scripts`, `rmm_remote_sessions`, `clients`, `locations`, `users` (ADR-002): they are reached only through the contracts below.
- The wire protocol, credential formats and signing vectors are frozen; `RmmProtocol` holds every frozen constant and `tests/Unit/Rmm/FrozenConstantsTest.php` pins them.

## Contracts an edition must implement

Required (all in `RivetCore\Rmm\Contracts`):

| Interface | Purpose | Reference adapter | Conformance case |
|---|---|---|---|
| `RmmTenancyInterface` | clients, locations, per-user client scope | `Testing\InMemoryRmmTenancy` | `Testing\RmmTenancyConformanceTestCase` |
| `RmmAssetsInterface` | asset match (serial, MAC, hostname), create, fill blanks, move | `Testing\InMemoryRmmAssets` | `Testing\RmmAssetsConformanceTestCase` |
| `RmmBridgeInterface` | integration row, link rows, alerts with ticket auto-close, saved scripts, remote-session log | `Testing\InMemoryRmmBridge` | `Testing\RmmBridgeConformanceTestCase` |
| `SecretBoxInterface` | encrypt the signing and Mesh keys with the edition's key | `Testing\InMemorySecretBox` | `Testing\SecretBoxConformanceTestCase` |

Optional or defaulted: `RmmMetricSinkInterface` (`Support\NullRmmMetricSink` for editions without Metrics; `Testing\RmmMetricSinkConformanceTestCase`), `RmmAuditInterface` (`Testing\RmmAuditConformanceTestCase`), `RmmModuleStateInterface` (edition kill switch and state-file directory; `Testing\RmmModuleStateConformanceTestCase`).

Reused: `DatabaseInterface`, `AccessPolicyInterface` (abilities `rmm.device.view`, `rmm.job.run_saved`, `rmm.job.reboot`, `rmm.job.run_script`, `rmm.remote.launch`, `rmm.admin`), `ClockInterface`, `Webhooks\UrlPolicy`.

Run `RmmBridgeInterface` on the same database connection Core uses so its writes take part in Core's transactions. The in-memory adapters are reference implementations for Core's tests (`@internal`), not API.

## Key classes

- `Http\RmmRequest`, `Http\RmmResponse`, `Http\RmmFileBody`, `Http\ApiError`: framework-neutral HTTP objects. The edition decides TLS/proxy trust, builds the request and emits the response; `Http\SapiEmitter` emits through PHP's SAPI (it never exits, verifies a file body's size and SHA-256 before sending, streams in 64 KiB chunks).
- `RmmProtocol`: the frozen constants.
- `Migration\RmmSchema`: the ten DDL statements (internal).

```php
use RivetCore\Migration\{CoreMigrations, MigrationRunner};

(new MigrationRunner($db, CoreMigrations::all(), $clock))->run();   // creates or converges the endpoint_agent_* tables
```

## Configuration

None yet. The module switch (`endpoint_agent_settings.enabled`, sub-switches in `features_json`, limits in `limits_json`) is specified in design sections 12 and 13.

## How it fails

Not yet applicable (no services). `SapiEmitter` answers a file body that fails verification with a generic 500 `internal` JSON error.

## Security notes

Device authentication, SYSTEM-level job execution and remote access make this a high-risk module: a focused review is required before it is enabled by default anywhere.

## Used by

Not yet adopted: RivetIT adopts first (it already has the tables), then RivetMSP.

## Links

[Design](../design/endpoint-module-extraction.md) | [ADR-010](../architecture/ADR-010-endpoint-agent-module.md) | [Migration](migration.md) | [Conformance kit](../conformance.md)
