# RMM endpoint agent: wire protocol

Status: **frozen** (Phase 0). Source of truth is the code of `RivetCore\Rmm`; the tables, constants, examples and vectors below are **generated** by `php scripts/rmm-protocol-doc.php` from `RmmProtocol`, the recorded golden transcripts (`tests/Fixtures/rmm/golden`) and the shared vectors, and a test fails when they are stale. The prose around them describes what the handlers in `src/Rmm/Http/DeviceApi.php` and the services behind them do. Machine-readable form of the same API: [openapi-device.yaml](openapi-device.yaml).

## 0. Compatibility rules

* **Additive only.** A server may add response fields, a new error `code`, a new endpoint; it never removes or retypes a field, changes a status for an existing case or reuses a name. The agent ignores unknown fields (the Linux agent already sends `platform`, `arch` and `capabilities` in the check-in, which a server that does not know them ignores).
* **No version field.** A breaking change is a new endpoint name, never a silent change. Old agents keep working against every newer server.
* **Frozen values** (credential formats, caps, rate limits, key ids, the installer trailer, canonical JSON, signature inputs) are the constants and rules below; `tests/Unit/Rmm/FrozenConstantsTest.php`, the vectors and the golden transcripts pin them.
* **The only intentional wire-visible change of Phase 0:** a switched-off module answers **503 `module_disabled`** with `Retry-After: 3600` instead of 403 (an installed agent already treats 5xx plus `Retry-After` as transient, keeps its credential and its ring buffer, and backs off to once an hour). A single sub-switch that is off answers 503 `feature_disabled` on the endpoint it controls.

## 1. Transport

* HTTPS only. A plain-http request is refused with **426 `tls_required`** unless the module was told it serves a loopback test server. Which requests count as "secure" (HTTPS, port 443, or a loopback/private TLS-terminating proxy that sets `X-Forwarded-Proto: https`) is decided by the edition's front controller and handed to Core as a flag.
* JSON in and out (`Content-Type: application/json`). Bodies are bounded **before** they are read: a declared or actual length over the cap is **413 `too_large`**. A body that is not a JSON object is **422 `invalid`**.
* Every JSON response carries `Cache-Control: no-store`. JSON flags: unescaped slashes and Unicode, invalid UTF-8 substituted, zero fractions preserved.
* Errors are `{"error": "<message>", "code": "<code>"}`. The message is for humans and may change; the **`code` and the status are the contract**. `401` codes are `invalid_token`, `revoked`, `expired`. `405` carries `Allow`. `429` and `503` carry `Retry-After`.
* Downloads (update, installer) send `Content-Type: application/octet-stream`, `Content-Disposition: attachment; filename="..."` (file name restricted to `[A-Za-z0-9._-]`), an exact `Content-Length`, `X-Content-Type-Options: nosniff`, `Cache-Control: no-store`, `Pragma: no-cache`, `X-Accel-Buffering: no`. The file is verified (size, SHA-256) before the first byte is sent and streamed in 64 KiB chunks.
* CORS headers on JSON responses belong to the edition's front controller, not to Core.

## 2. Credentials

| Credential | Format | Stored | Life |
|---|---|---|---|
| Enrollment token | `rvte1.<12 hex selector>.<40 hex secret>` | selector in clear, `sha256(secret)` only; the plaintext is shown once | 1 to `enroll_max_ttl_h` hours; 1 to 5000 uses; a ring (`pilot` or `stable`) and a client (and optional location) it enrolls into |
| Device token | 256 random bits as 64 hex characters, sent as `Authorization: Bearer <token>` (`^Bearer\s+([A-Za-z0-9]{64})$`) | `sha256` only, compared in constant time | 365 days; invalid after rotate, revoke, retire or re-enrollment |

Enrollment-token failures that are not a missing field are answered the same way whatever the reason (`invalid_token`, `revoked`, `expired` for `agent_enroll`; a generic 404 for `agent_installer`); a token **never** travels in a URL (`agent_installer?token=` is 400 `token_in_url`).

## 3. Endpoints

All under `/api/v1/`. Device authentication is the bearer token; the device id always comes from the credential, never from the request.

### 3.1 `agent_enroll` (POST, enrollment token in the body)

Request: `{"enrollment_token": "rvte1...", "device": {install_id (UUID), machine_guid (optional), hostname, os ("windows"; "linux" for the Linux agent), os_version, arch ("amd64" or "arm64"), serial, manufacturer, model, mac_addresses[], agent_version}}`. Body cap 16384 bytes.

Response 201: `{device_id, device_token (shown once), check_in_interval_s, server_time, status ("linked" or "pending_approval"), matched_asset_id, signing_public_key, signing_key_id, config: {checks: [signed check definitions], collect_interval_s}}`.

Identity rules (unchanged): a known `install_id` re-enrolls (a different machine behind the same id is 409 `conflict`); otherwise the same `machine_guid`, then the same non-junk `serial`, is a reinstall, but only within the token's client: a match under another client never reuses that device (an `install_id` held by another client is 409 `conflict`; a `machine_guid` or serial held by another client creates a new device of the token's client in `pending_approval` with reason `cross_client_identity`); a new device is matched to an existing asset by serial and MAC address only, inside the token's client, unambiguously and not already owned; a hostname alone is only a hint and never links; anything else waits for an administrator (`pending_approval`) or, with the `auto_create` policy and no match at all, gets a new asset. A revoked or retired device is 403 `forbidden` until an administrator allows re-enrollment. A full installation (`max_devices`) answers 403 `device_limit` for a new device. Enrollment attempts are limited per address from the database (10 failures or 60 attempts per 600 s; 429 `rate_limited`, `Retry-After: 600`).

### 3.2 `agent_checkin` (POST, device token)

Request: `{seq (integer, idempotency key per device), collected_at (RFC 3339, at most 300 s in the future), agent_version, inventory (object, at most 65536 bytes, or null), metrics {cpu_pct, mem_pct, disk[{mount, used_pct}], net_rx_bps, net_tx_bps}, checks [{key, status ("ok","warn","fail","unknown"), detail, value (optional number)}] (at most 100), buffered [{collected_at, metrics, checks}] (at most 100), update_result (optional {version, state ("ok","failed","rolled_back"), detail})}`. Body cap 1 MiB. A missing metric produces no sample; it is never stored as 0. Duplicate `seq` is a normal 200 (the liveness is real, the data is not processed twice). At most 40 per 60 s per device (429).

Response 200: `{ok, status, matched_asset_id, next_check_in_s, jobs_pending, config: {checks: [signed], collect_interval_s}, update (signed manifest, or null), server_time, signing_key_id}`. `jobs_pending` is 0 and `update` is omitted while the `jobs` or `updates` sub-switch is off. Under load shedding (levels 2 and 3) the interval grows and level 3 answers 503 `unavailable` with a jittered `Retry-After`.

#### 3.2.1 Capabilities and the software inventory (additive, Phase 1)

Every field below is optional on both sides. A server or an agent that does not know them ignores them, an old agent's check-in is processed exactly as before and the responses to it are byte-identical (the golden transcripts prove it).

* **Announce.** The agent already sends `platform`, `arch` and `capabilities` (a sorted list of strings: `job:<type>`, `check:<type>`). Phase 1 adds the flag `software_inventory`. The server stores the list (`rmm_device_state.capabilities_json`, at most 64 strings of at most 64 characters).
* **Offer.** Only when the device announced `software_inventory` AND the server's `inventory_software` sub-switch is on, the check-in response carries `"features": ["software_inventory"]`. The agent sends a `software` block only after it has seen that feature in a response (and stops when a later response no longer has it), so an old server never receives one.
* **Report.** The request may carry `software`:

```json
{"software": {"mode": "full", "hash": "<64 hex>", "count": 2, "truncated": false,
  "items": [{"name": "curl", "version": "8.5.0-2ubuntu10.6", "publisher": "Ubuntu", "source": "dpkg", "installed": "2026-09-02"}]}}
{"software": {"mode": "delta", "base_hash": "<64 hex>", "hash": "<64 hex>", "count": 2, "truncated": false,
  "items": [{"name": "git", "version": "2.43.0", "publisher": "", "source": "dpkg"}], "removed": [{"source": "dpkg", "name": "nano"}]}}
```

  `mode` is `full` (the whole list) or `delta` (`items` are the added or version-changed entries, `removed` the vanished ones; `base_hash` is the hash of the list the delta applies to). `hash` is the hash of the complete list AFTER the report; its definition is in `SoftwareHash` (sha256 of the bytewise-sorted lines `source TAB name TAB version TAB publisher LF`, vectors in `endpoint-agent/testdata/software/hash_vectors.json`). `source` is one of `registry`, `registry32`, `appx`, `dpkg`, `rpm`, `snap`, `flatpak`. An item identity is `(source, name)`. `truncated` is true when the agent cut the list at its caps; the server then never treats absent items as removed.
* **Caps.** At most 5000 items (the agent sends at most 3000 and at most 600 KiB), a name up to 200 characters, a version up to 100, a publisher up to 200; control characters are replaced by a space and the text trimmed (the same rule as `cleanText`, so both sides hash the same strings). The check-in body cap (1 MiB) applies as before.
* **When the agent sends.** A full list on the first report, on a server request (`resync`) and at least once a day; otherwise a delta when the list changed (a delta larger than 300 entries is sent as a full list), nothing when it did not. The agent collects at most once an hour.
* **Self-healing.** The server applies a `delta` only when its `base_hash` equals the hash it holds for the device. Otherwise, when the check-in was shed (load shedding level 1 or more) or the applied list does not hash to `hash`, it records that the device must resync, and the next response carries `"resync": ["software"]`. The agent then sends a full list, but never sooner than 15 minutes after its previous software report. A `full` report is never refused for its hash.

#### 3.2.2 Policies (additive, Phase 2)

A policy decides what a device is told: its check list, its two intervals, three feature toggles and the update ring it is offered. Everything is optional on both sides, and a fleet without policies (the `policies` sub-switch off, or no assignment) is told exactly what it was told before: the golden transcripts of the old agents replay byte for byte.

* **What the response carries.** The fields that already existed carry the effective values: `next_check_in_s`, `config.collect_interval_s` and `config.checks` (each check signed with the instance key exactly as before, so an old agent verifies them as it always did). A check type the device did not announce (`check:<type>`) is left out of its list; an agent that announced no check type at all (an old one) is sent everything and ignores what it cannot run. Intervals are clamped to the global limits (check-in 60 to 3600 s, collect 30 to 3600 s). The update ring a policy pins (`agent.ring`) decides which release the manifest offers; the ring stored for the device is not changed.
* **New, and only for an agent that announced `policies`** (a capability string in the same list as `software_inventory`) while the `policies` sub-switch is on: `"features"` gains `"policies"` (sorted with the others) and the config gains

```json
{"config": {"checks": [...], "collect_interval_s": 60,
  "policy": {"version": "<64 hex>", "applied": true,
             "features": {"software_inventory": true, "script_checks": false, "checks": true}}}}
```

  `version` is the SHA-256 of the canonical JSON of the effective configuration (checks, both intervals, the three toggles, the ring), so it changes when, and only when, something the device is told changes; `applied` is false when no policy reaches the device. The agent keeps the block (its `status` shows it), enforces the toggles locally as a second line behind the server, and forgets it as soon as a response no longer carries it.
* **Resolution.** Scopes from least to most specific are global, client, site (the edition's location), group, tag and device. The most specific layer that sets a setting wins that setting; inside one scope the higher `priority` wins, then the newer assignment; an assignment flagged `enforce` cannot be overridden by anything more specific (the outermost enforcer wins when several do). A setting is an `override` (a value), a `disable` (the key is off: a feature off, a check removed, an interval or ring back to the instance default) or `inherit` (no say). An assignment can carry `overrides` that apply to that assignment only. The resolver is pure and deterministic (`Policy\PolicyResolver`); `GET endpoint_devices/{id}/policy` shows a device's result and where each setting came from.
* **Settings.** `interval.check_in_s`, `interval.collect_s`, `feature.software_inventory` (the software report is not asked of the device), `feature.script_checks`, `feature.checks`, `agent.ring` and `check.<key>` (a check template `{type, params, interval_s}`, validated like the instance's own check list, which stays the base that policies lay over).

#### 3.2.3 Check types, measured values and offers (additive, Phase 3)

Everything below is optional on both sides, and an old agent's check-in is processed and answered exactly as before (the golden transcripts prove it).

* **Value.** A check result may carry `value`, a finite number below 10^12: the number the check measured (latency in ms, percent used, days left, a count). It exists so the server can compare it with the check's `params.thresholds` (below). The four original check types never send it; a server that has no thresholds ignores it. A reading the agent could not take sends no `value` (it is missing, never 0).
* **Check types.** Besides `service`, `disk`, `pending_reboot` and `script`, a check list may contain `cpu`, `memory`, `process`, `port`, `ping`, `http`, `cert`, `ntp`, `smart` (Windows and Linux), `av` (Windows) and `systemd_failed`, `inodes` (Linux). Their params are whole numbers, strings, booleans and short lists with size caps and safe defaults; the schema of every type is in `CheckCatalog` and is served by `GET endpoint_devices/check_types`. Unknown params of these types are refused when the list is saved. The agent derives its own `ok`/`warn`/`fail` from the params (for example `ping.warn_ms`) so a check works without any server-side threshold.
* **Offer.** The four original types go to every agent. A Phase 3 type is included in `config.checks` of the check-in response only for a device that announced `check:<type>` in `capabilities` (the capabilities of the same request, else the stored ones), and never in the enrollment response, so an old agent never sees a type it does not know and a Linux agent never gets `av`. Each offered definition is signed on its own, as before.
* **Thresholds, flap detection, parents (server side).** `params.thresholds` (`{"warn": {"op": "gt", "value": 80}, "crit": {...}, "hysteresis": 5, "for_samples": 3, "for_minutes": 10}`), `params.flap` (`{"window": 20, "high_pct": 50, "low_pct": 25}`) and `params.ignore_parent` travel inside the signed params but are read by the server only; the agent ignores them. Thresholds apply when the server's `alerting` sub-switch is on: the final status of a result is the worse of the agent's own verdict and the threshold tier of its `value`. The semantics, the maintenance windows, dependency suppression, storm control and escalation are described in [modules/rmm.md](../modules/rmm.md); none of it is visible to an agent.
* **Details are safe to show.** A `detail` never contains the query string or credentials of a URL, and `http`/`cert` details name the host and path only.

#### 3.2.8 Per-OS check targeting and stale checks (additive, 1.0.0-rc.24)

The check schedule (`checks_json`, one list for the whole fleet) and the check templates of a policy may now say which OS a check is for. Everything is optional on both sides; the protocol version stays 1 and an old agent keeps working.

* **`os` member.** A check definition may carry `"os": "windows"` or `"os": "linux"`. Left out (or `"any"` in the settings or a policy, which is never stored) means every OS, exactly the behaviour before rc.24. Any other value is refused when the list is saved (`Check <key>: os must be one of windows, linux or any`). A policy check template (`check.<key>`) has the same optional `os` ("applies to").
* **Delivery.** `config.checks` (enrollment and check-in) contains only the checks that apply to the device's OS (`endpoint_agent_devices.os`, as enrolled): `RmmChecks::forOs()`. When the member is set it is delivered, and it is part of the signed object (`Signer::checkMessage`: the canonical JSON of the entry without `signature`), so the signature covers exactly what the device receives. An entry without `os` is byte for byte what it was before, signature included. An old agent verifies the object as delivered, so it accepts an entry with `os` and runs it; it never receives one for the other OS. A device whose OS is neither `windows` nor `linux` is not filtered. The policy engine filters before it computes the policy `version`, so the version describes what the device is given.
* **Agent (second line).** The agent ignores a delivered check whose `os` is `windows` or `linux` and not its own: it does not run it and reports nothing for it. `"any"`, an empty value or an unknown one run everywhere.
* **Stale checks.** When a check key is no longer delivered to a device (other OS, taken off the schedule, removed by a policy) the server deletes that device's `endpoint_agent_checks` row (and `rmm_check_eval` row) and resolves the linked open alert: through the bridge's `resolveAlert` (the edition's conservative ticket auto-close applies; no ticket is created, nothing is re-raised) and, with `alerting` on, in `rmm_alert_meta` with `resolved_by` NULL (the system) and `resolve_reason` `not_applicable`. The text for the alert is `CheckEvaluator::NOT_APPLICABLE_MESSAGE` ("This check no longer applies to the device"). This runs at check-in, after the transaction, and costs one primary-key read of `rmm_device_checkset` unless the set of delivered keys changed (a 16-hex hash of the sorted keys; migration 0031); keys the server reports itself (`log.rule_*`, `change.rule_*`, `patch.*`) are never touched. The first time a device is seen the stored set is only a baseline and only keys of the configured schedule that are not delivered (the other OS's checks) are cleaned; a check the schedule still has for the device's OS that was left out only because the device did not announce `check:<type>` is kept (it stopped reporting, it did not stop applying). `RmmModule::reconcileChecks(?int $deviceId)` does the same for every live device at once (offline ones included) and is idempotent: run it once after upgrading.
* **Missing service.** The `service` check (both platforms) accepts the optional param `missing`: `fail` (default, today's behaviour: "service X not found" is status `fail`), `warn` or `ignore` (reported `ok`, detail "... not found (ignored)"). Server-side `service` params are not schema-checked, so an old server stores it and an old agent ignores it.

### 3.3 `agent_jobs` (GET and POST, device token)

* `GET [?wait=0..5]`: the signed jobs this device may run now (at most 5). With `wait` the request long-polls (poll step 0.5 s). `{"jobs": [{job_id, device_id, attempt, type, script, params, timeout_s, max_output_bytes, issued_at, expires_at, signature}]}`. Up to 120 requests per 60 s per device.
* `POST` (body cap 262144 bytes): the report `{job_id, attempt, state ("running","succeeded","failed","timed_out","cancelled"), exit_code, output, started_at, finished_at}` answers `{"ok": true}`. An unknown job (or one of another device) is 404; an attempt that was never issued, or a second different final state, is 409 `conflict`; the same final state again is an idempotent 200. Output is redacted (credential-shaped text becomes `[REDACTED]`) and capped (`max_output_bytes`, `job_output_max_bytes`) before it is stored.

**Job types (Phase 2, additive).** `powershell` (Windows), `shell` (Linux; bash, POSIX `sh` when bash is missing), `python` (Linux, Python 3; announced as `job:python` only when `python3` is installed), `reboot` and `collect`. The server only offers a job whose type the device announced (`job:<type>`); an agent that announced no `job:` capability is offered everything and refuses by itself what it cannot run. **Parameters** (`params`, signed with the job) reach a script in three ways and never as code: the whole object as JSON in `RIVETIT_JOB_PARAMS`; one environment variable `RIVETIT_PARAM_<name>` per scalar value, byte for byte; and, for a lower-case name that is not a reserved word, a script variable of the same name defined by a few generated lines at the top of the script. A string is read from the environment at run time, never written into those lines; an integer or a boolean is re-formatted from its parsed value (digits, `true` or `false`). Windows keeps `$Params` (the parsed JSON) as before. A secret parameter of a library script arrives here as its real value inside the signed job, is never stored in the job row, and is removed from the output the device reports.

Job life: `queued`, `running`, `succeeded`, `failed`, `timed_out`, `cancelled`, `expired`. Reasons: `result_lost`, `never_started`, `no_result_by_deadline`, `device_retired`, `cancelled_by_user`, `expired_before_run`, `late_result`. **A destructive job (every reboot, and any job flagged so) is offered at most once**: if its result never arrives it becomes `failed` / `result_lost` and is never re-sent; a late real result from the agent still replaces `result_lost`. A harmless job that was offered but never reported running may be offered again with `attempt + 1` after `job_ack_timeout_s`, up to `job_max_attempts`. A job that reported running is never re-offered: past its deadline it becomes `timed_out`.

### 3.4 `agent_update` (GET, device token)

`?arch=amd64|arm64&version=X.Y.Z`: the **unstamped** agent executable of the hosted release this very device is offered right now (its architecture, an active release and binary, its ring and rollout bucket, the stored SHA-256 matches), as a download. Anything else is a generic 404; a stored file that is missing or damaged is 503 `unavailable`. 60 per 60 s per device.

The offered release (in the check-in `update` manifest) is the highest release newer than the running version, whose `min_version` is not above it, in the device's ring (pilot devices also get stable releases), inside the deterministic rollout bucket (`crc32(device_id|version) % 100 < rollout_pct`, so raising the percentage only adds devices), and not reported failed by this device. The manifest is `{version, url, sha256, signature, min_version}`; the signature is over the lowercase hex SHA-256 **text**.

### 3.5 `agent_installer` (POST, enrollment token in the body or as a bearer token)

`{"token": "rvte1...", "arch": "amd64"|"arm64", "format": "exe"|"msi"}` as JSON or a form (body cap 4096 bytes; `format` is optional, defaults to `exe`, and any other value is 422): the stamped per-client installer (section 6). With `msi` the answer is a COPY of the published MSI template for the architecture with the same trailer appended (`RivetIT-Agent-<client>-<x64|arm64>.msi`); no published template is 409 `unavailable`. Every unusable token (malformed, unknown, wrong secret, revoked, expired, used up) is the same generic 404; no published binary or no https service URL is 409 `unavailable`. The download does not use up an enrollment use (enrolling does). Limits per 600 s from the database: per address 10 failures or 30 attempts, per token selector 30 downloads or 20 failures (429 + `Retry-After: 600`).

#### 3.2.4 Log collection offer (additive, Phase 8)

Everything here is optional on both sides; an agent that does not announce `logs:collect` gets the same response as before.

* **Announce.** The agent adds `logs:collect` to its `capabilities`.
* **Offer.** Only when the device announced it, the `logs` sub-switch is on, no load is being shed (`shed_level` 0) and at least one log source is configured for the device's platform, the response carries `"logs:collect"` in `features` and `config.logs`:

```json
{"features": ["logs:collect"],
 "config": {"logs": {"version": "a1b2c3d4e5f60718", "interval_s": 60, "batch_max": 200, "max_message_bytes": 1024, "rate_per_min": 600,
   "sources": [
     {"kind": "eventlog", "channel": "System", "levels": ["critical", "error", "warning"], "event_ids": [7036], "contains": []},
     {"kind": "journal", "units": ["ssh.service"], "identifiers": [], "min_level": "warning", "contains": ["failed password"]}]}}}
```

  A device receives only the sources of its own platform (`eventlog` for Windows, `journal` for Linux). `version` changes exactly when something the agent is told changes. The agent collects only while the latest response offers the feature and carries `config.logs`; the moment a response does not, it stops reading (its saved bookmark is kept) and drops what it had not sent.
* **Sources.** Windows: `channel` (letters, digits, space and `_ . / % -`, at most 100), `levels` (`critical`, `error`, `warning`, `info`, `verbose`), `event_ids` (at most 50 numbers, 0 to 65535; empty means all), `contains` (at most 10 phrases of at most 80 characters, any may match, case-insensitive). Linux: `units` and `identifiers` (at most 20 names each, letters, digits and `: _ . @ -`), `min_level` (`critical`, `error`, `warning`, `info`, `debug`: that severity or worse), `contains` as above. No source ever carries a regular expression. Windows sources may also carry `"levels": []` (every level) and, for an agent that announced `logs:presets`, `aggregate` (3.2.7).

#### 3.2.5 MeshCentral agent report (additive, 1.0.0-rc.12)

Everything here is optional on both sides. The optional "also install the MeshCentral agent" feature (section 6) and the automatic mapping of a device to its Mesh node use one additive check-in member and one capability; an agent that predates them sends neither, and a server that predates them ignores both.

* **Announce.** The agent adds `mesh:nodeid` to its `capabilities`: it can read and report the local MeshCentral node id (it does nothing on a machine without a Mesh agent). `disable_remote` withdraws it.
* **Report.** `"mesh_agent": {"installed": true, "version": "1.1.10", "node_id": "node//<id>", "error": "..."}`. `installed` is required (a boolean); `version` (at most 60 characters), `node_id` and `error` (at most 300 characters, only while `installed` is false) are optional. The agent sends the member on the first check-in after it changes and then once a day, and never on a device that has no Mesh agent and never tried to install one. `node_id` is `node//` followed by the id (the agent adds the prefix to what `meshagent -nodeid` prints) in whatever spelling the agent found it: 96 hex characters or MeshCentral's 64-character URL form (since rc.18 the server converts both to `node//<64 characters>`; the field is unchanged on the wire). The server never fails a check-in over this member: anything it cannot use is ignored, and a node id that is neither a MeshCentral id (48 bytes as 64 base64 or 96 hex characters, `RivetCore\Rmm\Mesh\MeshNodeId`) nor, for compatibility, `node//` plus 16 to 100 characters of `A-Za-z0-9@$_-` is dropped.
* **What the server does with it.** It records the state (device detail `mesh_agent`), raises `rmm.mesh.agent_installed` the first time the agent is reported installed, and, only while MeshCentral is enabled, maps the node id to the device: no mapping yet means it is mapped (source `agent`); the same node changes nothing; a different node the agent set earlier is replaced; a different node an administrator set by hand is kept unless the `mesh_auto_map_overwrite` setting is on; a node already mapped to **another live device** is refused (`map_state` `conflict`, audit row, `rmm.mesh.node_conflict`), whatever the settings say; a node mapped to a retired or revoked device moves to the device that now reports it. `map_state` is `mapped`, `conflict`, `kept_manual` or null. The response to a check-in is not changed by any of this.

#### 3.2.6 Pushed MeshCentral agent install (additive, 1.0.0-rc.16)

At install time the Mesh options live only in the stamped installer payload (3.2.5, section 6), so an agent that enrolled without them, or one that has since updated itself, never installs a Mesh agent. Two ordinary signed job types fix that. They add no endpoint and change no existing member; an agent that predates them never announces the capabilities and is never offered the jobs.

| Type | Flag | Params | Destructive | Result document |
|---|---|---|---|---|
| `mesh_install` | `job:mesh_install` | `url`, `meshid`, `pin` (optional), `agent_ids`, `install_args` (both JSON text), `force` | no | `{v, ok, detail, installed, host, agent_id, sha256_head, node_id, exit_code, error, log}` |
| `mesh_uninstall` | `job:mesh_uninstall` | none | always (offered at most once) | `{v, ok, detail, installed, error, exit_code, log}` |

* **Capabilities.** The agent announces `job:mesh_install` and `job:mesh_uninstall` (the ordinary `job:<type>` list) and, unlike other job types, the server never assumes them for an agent that announced nothing. `disable_remote` withdraws both. Both run on Windows and Linux.
* **Parameters are the installer payload's Mesh block and nothing else.** `url`, `meshid`, `pin`, `agent_ids` and `install_args` are exactly the members of the payload's `mesh` object (section 6), taken by the server from the stored Mesh settings when the job is created; the two tables travel as JSON text because a job parameter is a scalar. The caller of the technician API supplies none of them, only `force`. The agent validates them with the SAME rules as the payload (https-only address without credentials or query, a 64-character URL-form or 32-128 hex group id, a 64 or 96 hex pin, `windows`/`linux` argument lists of simple arguments, agent ids 1 to 255) and refuses a job with an unknown member (`invalid_params`, nothing downloaded or run).
* **The device group id is not stored in the job row.** The id lets anyone who holds it download an agent that joins the group, so the server keeps `[redacted:secret]` in the job's `params_json`, seals the real value with the job's other secret parameters, puts it back only when the job is signed for the device (the signature covers the real value), wipes it when the job has a result, and scrubs it from reported output. The agent never writes it to its log, its saved state or the job result.
* **What the agent does.** The same routine as at install time: download `<url>/meshagents?id=<N>&meshid=<meshid>&installflags=0` over https, trusting the system roots or exactly the pinned certificate hash, with a 64 MiB cap and a content check, run it with the table's arguments in a private directory, remove the directory. A device that already has a Mesh agent answers `succeeded` / `already_installed` without downloading anything unless `force` is true. Whatever happens (wrong pin, HTTP 404, an oversize body, an installer that exits non-zero or does not start, a panic), the job ends with a result and the RivetIT agent is untouched: `failed` with `detail` `install_failed` and the reason in `error`, or `invalid_params`. On success the agent waits up to 20 s for the node id and puts it in the result, with the first 32 hex characters of the installer's SHA-256 (`sha256_head`; a full 64-character hash would be redacted by the server like any secret).
* **Two reports of the same outcome.** The job result, and the existing check-in member `mesh_agent` (3.2.5): the agent saves the outcome to its state, which makes the next check-in carry `installed`, `version`, `node_id` or `error`, and the server maps the node id exactly as for an installer-time install (`rmm.mesh.agent_installed` is raised the first time). A successful `mesh_uninstall` saves `installed: false`, so the next check-in tells the server to clear its record.
* **`mesh_uninstall`** runs the Mesh agent's own uninstaller (Windows `-fulluninstall`, Linux `-uninstall`; **unverified against a real MeshAgent**) and never touches the RivetIT agent. `succeeded` with `detail` `uninstalled`, or `not_installed` when there was nothing; `failed` / `uninstall_failed` when the uninstaller did not run or exited non-zero.
* **Unverified.** Like the install-time feature, the Linux agent ids, the install and uninstall arguments and the node-id locations were written from MeshCentral's documentation and are exercised only against a mock Mesh server (docs/rmm/REMOTE_AND_LOGS.md).

#### 3.2.7 Change monitoring offer (additive, 1.0.0-rc.22)

Everything here is optional on both sides; an agent that does not announce `change:monitor` gets the same response as before. Full model: [CHANGE_MONITOR.md](CHANGE_MONITOR.md).

* **Announce.** The agent adds `change:monitor` to its `capabilities` (and `logs:presets`, below).
* **Offer.** Only when the device announced `change:monitor`, the `change_monitor` sub-switch is on, the load-shedding level is below 2, at least one watch exists for the device's platform and the server holds a usable signing key, the response carries `"change:monitor"` in `features` and `config.change`, a SIGNED object:

```json
{"features": ["change:monitor"],
 "config": {"change": {"v": 1, "kind": "change_config", "platform": "windows", "interval_s": 300, "batch_max": 100, "rate_per_min": 120, "max_value_bytes": 256,
   "watches": [
     {"id": "win.startup", "kind": "registry", "paths": [{"key": "HKLM\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run", "depth": 0}, {"key": "HKU\\*\\Software\\Microsoft\\Windows\\CurrentVersion\\Run", "depth": 0}]},
     {"id": "win.local_admins", "kind": "group", "name": "Administrators"},
     {"id": "win.hosts", "kind": "file", "paths": [{"path": "C:\\Windows\\System32\\drivers\\etc\\hosts"}]},
     {"id": "win.tasks", "kind": "task"}],
   "version": "9f2c01aa77d3e5b1", "signature": "<base64 Ed25519>"}}}
```

  `version` is the first 16 hex characters of the SHA-256 of the signed message of the object without `version` and `signature`, so it changes exactly when something the agent is told changes. **The signature** is Ed25519 (the key the agent pinned at enrolment) over the domain line `rivetit-change-config-v1`, a line feed, then the canonical JSON of the object without its `signature` member; the domain line means a job or a check signature can never verify as a configuration and the reverse. The agent verifies it BEFORE it reads anything and refuses a configuration that does not verify (the last good one stays in force). A device receives only the watches of its own platform; the object is at most 49152 bytes (a larger one is not offered).
* **Watches.** `registry` (Windows): `paths` of `{key, depth 0..4, values?}`; a key starting `HKU\*\` is read once for every loaded user hive; `values` limits the read to those value names. `file`: `paths` of `{path, depth 0..3?, match?}`; a folder is read down to `depth` levels (at least one), `match` a name filter with `*` and `?`. `group` (Windows): `name`, the members of a local group (`net localgroup`). `task` (Windows): the scheduled task definitions. Watch ids are `win.<name>`, `lnx.<name>` (presets) or `custom.<name>`. The agent applies its own policy again to every path (the credential hives SAM and SECURITY and the LSA key parts that hold the boot key, shadow files, private keys and other credential stores are never read), clamps every number and ignores what it refuses.
* **Caps the agent applies whatever it is told.** interval 60 s to 24 h; at most 100 records per request; 10 to 2000 records per minute; values cut at 64 to 512 bytes; at most 60 watches, 20 paths per watch, 5000 items per watch; at most 500 changes per poll per watch and 2000 records waiting to be sent (what does not fit is counted in `dropped`).
* **Security log presets.** `config.logs` (3.2.4) may carry, for Windows, sources with `levels: []` (read every level: audit events have Level 0) and, only for an agent that announced `logs:presets`, a source with `aggregate: {window_s, min, by}`: the agent sums the events of that source up on the device and ships one entry per window and per combination of the `by` event fields, only when the count reaches `min`. An agent that did not announce `logs:presets` is never offered an aggregated source.

### 3.6 Remote-tool job types (Phase 6, additive)

Six job types, queued only by their own technician endpoints, only for a device that announced the matching capability flag (an agent that announced no capability at all is never assumed to have it, unlike the `job:<type>` rule), and only while the `remote_tools` (services, processes) or `filetransfer` (files) sub-switch is on. They are signed like every job; their `params` are flat, validated on the server against strict allow-lists and validated again by the agent, which turns them into an argument list and never into a command string. The result is the job report: `succeeded` with exit code 0, or `failed` with exit code 1, and `output` is one compact JSON document (at most `max_output_bytes`; lists are shortened with `"truncated": true`, never cut mid-text).

| Type | Flag | Params | Destructive | Result document |
|---|---|---|---|---|
| `svc_list` | `remote:services` | none | no | `{v, platform, truncated, count, services: [{name, display, state, startup}]}`; `state` running, stopped, starting, stopping, paused, failed, unknown; `startup` auto, manual, disabled, static, unknown |
| `svc_control` | `remote:services` | `name` (letters, digits and `_ . @ $ : -`, at most 200, first character a letter, digit or `_`), `action` (`start`, `stop`, `restart`, `enable`, `disable`, `manual`) | `stop`, `restart`, `disable` | `{v, name, action, before, after, startup, ok, detail}`; Linux runs `systemctl <verb> -- <unit>` with an argument list, Windows uses the service manager API |
| `proc_list` | `remote:processes` | `limit` (1 to 500, default 200), `sort` (`cpu`, `mem`) | no | `{v, sampled_ms, count, truncated, processes: [{pid, ppid, name, user, cpu_pct, mem_bytes, threads, started}]}`; no command lines (they can hold secrets) |
| `proc_kill` | `remote:processes` | `pid` (2 to 4194304), `name` (as listed), `force` | always | `{v, pid, name, signal, ok, detail}`; the agent re-reads the name at kill time (`name_mismatch`), refuses itself and its parent (`refused_self`), system processes (`refused_protected`) and kernel threads |
| `file_get` | `remote:files` | `transfer_id`, `token`, `path`, `max_bytes`, `allow`, `chunk` | no | `{v, transfer_id, bytes, sha256}` |
| `file_put` | `remote:files` | `transfer_id`, `token`, `path`, `size`, `sha256`, `overwrite`, `allow`, `mode` | always | `{v, transfer_id, path, bytes, sha256}` |

The agent also refuses (`failed`, a stable `detail`) to stop, restart or disable its own service (`refused_self`) and a short list of critical services (`refused_protected`). The server refuses the agent's own service names first (`422 protected`). A destructive job is offered at most once and never retried (3.3). A technician needs `rmm.remote.manage` for all of them except `file_put`, which needs `rmm.admin`.

**Path policy of `file_get` and `file_put`.** The path must be absolute (a Linux path starting with `/`; a Windows path with a drive letter, never a UNC or device path), free of `.`, `..`, empty parts, control characters and (Windows) alternate data streams, wildcards, trailing dots and 8.3 short names, and at most 1000 characters. `allow` is the administrator's list of folders for the device's platform, newline separated (each at least two levels deep: never a whole drive, `/etc`, `/root` or `/home`); the path, after the agent has resolved symbolic links, must lie inside one of them, compared by whole path elements (`/srv/data` never allows `/srv/data2`) and, on Windows, case-insensitively. An empty list refuses everything (`no_allowed_roots`). The never-transfer list always applies and wins over the allow list: Linux `/etc/shadow`, `/etc/gshadow`, `/etc/sudoers` and `/etc/sudoers.d`, `/etc/ssh/ssh_host_*`, `/proc`, `/sys`, `/dev`, `/run/secrets`, `/root/.ssh`, any `.ssh` or `.gnupg` folder, files named `id_rsa*`, `id_ed25519*`, `id_ecdsa*`, `id_dsa*`, `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.kdbx`, `*.keystore`, and the agent's own folders; Windows `<SystemRoot>\System32\config` (the SAM, SECURITY, SYSTEM and SOFTWARE hives), `\Windows\NTDS`, `ntds.dit`, `pagefile.sys`, `hiberfil.sys`, `swapfile.sys` and the agent's own `ProgramData` folder. A refusal by the agent is `failed` with `detail` `path_denied:<reason>`. `file_put` writes to a temporary file in the destination folder (never creating folders, never following a symbolic link), checks the byte count and the SHA-256 against the signed job, then renames it into place; any failure removes the temporary file and leaves the destination untouched.

### 3.6a Patch job types (Phase 4, additive)

Two job types, queued only by the patch endpoints and the scheduler, only for a device that announced the matching capability flag (never assumed for an agent that announced nothing), and only while the `patching` switch is on. Parameters are scalars only (the job parameter rules); `ids` is a comma list. Full model: [PATCHING.md](PATCHING.md).

| Type | Flag | Params | Destructive | Result document |
|---|---|---|---|---|
| `patch_scan` | `patch:scan` | `categories` (comma list of `security,critical,important,definition,feature,driver,optional,other`, empty for all), `max_items` (1 to 1000, default 500) | no | `{v, ok, manager, scanned_at, scan_state, wu_state, reboot_pending, last_install_at, truncated, total, updates: [{id, kb, title, kind, severity, size, reboot_required, released_at, eula_accepted, categories, current, version}]}`; `manager` `wua`, `apt`, `dnf`, `yum`, `zypper`; `kind` critical, security, important, optional, driver, other; failure `{v, ok: false, detail, message?}` with `detail` `no_package_manager`, `wu_unavailable`, `timeout`, `lock_held`, `parse_error`, `invalid_params`, `busy`, `scan_failed` |
| `patch_install` | `patch:install` | `ids` (at most 40 ids and 1000 characters; a Windows Update id in lower-case GUID form or a Linux package id), `mode` (`dry_run` default, `install`), `max_minutes` (1 to 240, default 60) | always | `{v, ok, mode, manager, results: [{id, title, result, code, detail, reboot_required}], reboot_required, truncated}`; `result` `would_install`, `installed`, `needs_reboot`, `failed`, `skipped`, `not_applicable`, `eula_required`, `not_found`, `locked` |

The agent validates every parameter again (an unknown key, an out-of-range number, an id that does not match the platform's syntax, a duplicate or more than 40 ids is refused as `invalid_params` before any command is built), re-scans before an install and installs only ids that are still applicable, never accepts an EULA, never removes a package, never restarts the machine and never breaks a package manager lock. The job is `succeeded` when every requested id ended `installed`, `would_install`, `needs_reboot`, `skipped` or `not_applicable`; it is `failed` (the document is still the full result) when any ended `failed`, `eula_required`, `not_found` or `locked`. The server keeps no other channel: results are applied from the job report, nothing rides on the check-in. `disable_patching` withdraws both capabilities on a machine.

### 3.6b Software job types (Phase 5, additive, 1.0.0-rc.21)

Two job types, queued only by the software endpoints and the scheduler, only for a device that announced the matching capability flag (never assumed for an agent that announced nothing), and only while the `software_deploy` switch is on. The job parameter rules apply (flat scalars of at most 1024 characters), so the detection rules travel as one JSON text. Full model: [SOFTWARE_DEPLOY.md](SOFTWARE_DEPLOY.md). The parameters are checked on both sides; `endpoint-agent/testdata/vectors/agent_software_deploy_vectors.json` holds accept/reject vectors both sides replay.

| Type | Flag | Params | Destructive | Result document |
|---|---|---|---|---|
| `software_detect` | `software:detect` | `rules`: a JSON text holding 1 to 25 objects `{key, kind, value, min}` (`key` `[a-z0-9][a-z0-9._-]{0,39}`, unique; `kind` `package` on Linux, `product_code` or `uninstall_name` on Windows; `value` a package name, an MSI product code or the exact display name; `min` empty or a version) | no | `{v, ok, items: [{key, installed, version, satisfied, detail?}]}`; failure `{v, ok: false, detail}` |
| `software_install` | `software:install` | `action` (`install`, `uninstall`), `mode` (`dry_run` default, `run`), `fmt` (`apt`, `dnf`, `msi`, `exe`); Linux: `pkg`, optional `version` (install only); Windows install: `url` (https, host name only, default port), `sha256` (64 lower-case hex), `size` (1 to 1073741824), `args` (exe only, no quotes or shell characters), optional `product_code`, `detect_kind`/`detect_value`/`detect_min` (required for an exe); Windows removal: `product_code` (msi only); `max_minutes` (1 to 120, default 30) | always | `{v, ok, action, mode, fmt, result, code, detail, version, reboot_required}`; `result` `would_install`, `would_uninstall`, `installed`, `uninstalled`, `already_installed`, `not_installed`, `needs_reboot`, `failed`, `hash_mismatch`, `download_failed`, `not_found`, `locked`, `verify_failed` |

What the agent guarantees: it validates every parameter again and refuses anything outside the table as `invalid_params` before it runs or downloads anything; it checks whether the package is already in the wanted state first and then does nothing; it downloads only over https from the host in the signed job (no redirect to another host or to http), reads at most `size` bytes, and runs the file only if its SHA-256 equals `sha256` (checked again just before it runs); it starts programs from an argument list, never through a shell; on Linux it uses only the repositories the machine already has configured (no URL, no file path, no option that skips signature checks) and a simulation that would remove another package refuses the job; it verifies by detection afterwards (`verify_failed` when the install did not do what was asked) and never restarts the machine. The job is `succeeded` for the results `would_*`, `installed`, `uninstalled`, `already_installed`, `not_installed` and `needs_reboot`, and `failed` (the document is still the full result) otherwise. `disable_software` in the agent's `config.json` withdraws both capabilities on a machine.

### 3.7 `agent_transfer` (GET and POST, device token plus transfer token; Phase 6)

Carries the bytes of `file_get` and `file_put`. Off (`503 feature_disabled`, `Retry-After: 3600`) while the `filetransfer` sub-switch is off. Needs the device credential AND the one-time `X-Transfer-Token` header (64 lower-case hex characters, from the signed job's `token`; never in the URL; the server keeps only its SHA-256), only for a transfer of this device, only before it expires (`transfer_ttl_s`, default 24 hours), only in its direction. At most 600 requests per 60 s per device. Chunks are at most 1 MiB.

* `POST ?transfer_id=<uuid>&offset=<n>` (raw body): a chunk of a download. The final chunk, which for an empty file is an empty one, adds `X-Transfer-Last: 1` and `X-Transfer-Sha256: <hex of the whole file>`. `200 {ok, received, complete}`. A chunk the server already holds is acknowledged without being stored twice; a gap or a different offset is `409 conflict` with `received` (resume there); past the size cap `413 too_large` (the transfer fails and the bytes are deleted); a final checksum that differs from the server's own is `422 checksum_mismatch` (same). The server hashes the stored file itself; it never trusts the agent's figure.
* `GET ?transfer_id=<uuid>&offset=<n>&length=<m>`: a range of an upload. The body is the raw bytes; `X-Transfer-Size` and `X-Transfer-Sha256` repeat the signed size and hash. `416` outside the file. The server counts the bytes it serves and stops at twice the file size.
* Anything else (another device, a wrong token, the other direction, an unknown id) is the same `404 not_found`.

### 3.8 `agent_logs` (POST, device token; Phase 8)

Body cap 262144 bytes: `{events: [{ts, level, source, event_id, message}], dropped, config_version}` with at most 200 events. `ts` is RFC 3339 (a clock more than five minutes ahead is clamped; an entry older than the retention is dropped), `level` is `critical`, `error`, `warning`, `info`, `debug` (or `verbose`, stored as `debug`), `source` at most 100 characters (a channel provider or a unit), `event_id` a whole number or null, `message` at most 1024 characters. `200 {ok, accepted, dropped, duplicates}`: the server drops an entry it cannot understand, or one beyond the device's per-minute budget (`logs_rate_per_min`, default 600), the device's row cap (`logs_device_max_rows`, default 20000) or the fleet cap (`logs_fleet_max_rows`, default 2000000), and counts what it dropped; an entry stored before (same time, level, source, event id, text) is a duplicate and is not stored twice, so resending a batch whose answer was lost is safe. Control characters in a message become spaces and credential-shaped text is redacted before it is stored. The agent advances its bookmark (journal cursor, Windows record id) only after a `200`. `503 feature_disabled` while the `logs` sub-switch is off; `503 unavailable` with a jittered `Retry-After` at load-shedding level 1 and up (log ingest is the first optional work given up); `429` above 30 requests per 60 s per device; `422` for a body that is not shaped like this. The new error codes of this phase are `protected`, `unsupported`, `path_denied` and `checksum_mismatch` (technician and transfer answers); `feature_disabled`, `not_configured`, `confirmation_required`, `too_large` and `conflict` already existed.

### 3.9 `agent_changes` (POST, device token; change monitoring, 1.0.0-rc.22)

Body cap 262144 bytes: `{changes: [{ts, kind, watch, change, target, item, old, new, summary, old_hash, new_hash, actor, process, redacted, truncated}], dropped, config_version, status?}` with at most 100 records. `ts` is the time the agent DETECTED the change (RFC 3339; a clock more than five minutes ahead is clamped; a record older than the retention is dropped), `kind` is `registry`, `file`, `group` or `task`, `watch` the id of a watch in the device's current configuration with the same kind, `change` is `added`, `modified` or `removed`. `target` is the registry key, the file path, `group:<name>` or `scheduled-tasks` (at most 300 characters) and must lie inside the watched paths and pass the server's watch policy; `item` is the value name, the group member or the task path (200); `old` and `new` are the values (512; secrets masked, a value whose NAME looks like a secret is never sent); `summary` for a file is `+N -M lines` and the permission change in words (200); `old_hash` and `new_hash` are 64 lower-case hex; `actor` and `process` are what Windows recorded for a registry change (blank when it did not); `redacted` and `truncated` are flags. Nothing is sent for the FIRST reading of a watch (the baseline). `status` is `{watches: [{id, state ("ok", "baseline", "partial", "error"), items, error}], baseline_at}`, sent when it changes and at least every six hours, so an empty `changes` list is a valid heartbeat.

`200 {ok, accepted, dropped, duplicates}`: the server drops a record it cannot understand, one for a watch the device was not told to read, one whose target is outside that watch or refused by the watch policy, and everything beyond the device's per-minute budget (`change_rate_per_min`, default 120), the device's row cap (`change_device_max_rows`, default 5000) or the fleet cap (`change_fleet_max_rows`, default 500000), and counts what it dropped; a record stored before (same time, watch, target, item, change, hashes and value) is a duplicate and is not stored twice, so resending a batch whose answer was lost is safe. Control characters become spaces and credential-shaped text is redacted again before storage. The agent removes records from its queue only after a `200`. `503 feature_disabled` while the `change_monitor` sub-switch is off; `503 unavailable` with a jittered `Retry-After` at load-shedding level 2 and up; `429` above 30 requests per 60 s per device; `422` for a body that is not shaped like this; `413` over the cap.

## 4. Signing

* **Canonical JSON** (what is signed): UTF-8; object keys sorted by their UTF-8 bytes, recursively; arrays in order; no whitespace; strings escape only `"`, `\`, `\b`, `\f`, `\n`, `\r`, `\t` and the other code points below U+0020 as lower-case `\u00xx`, everything else raw (including `/ < > &`, U+007F, U+2028, U+2029); **integers only** (floats are rejected in check definitions); `true`, `false`, `null`.
* **Ed25519** (libsodium detached). Keys are base64 of the 64-byte secret key and the 32-byte public key; signatures are standard padded base64. The key id is the first 16 hex characters of the SHA-256 of the **base64 text** of the public key.
* **What is signed:** a job: the canonical form of the job object **without** its `signature` member (`job_id, device_id, attempt, type, script, params, timeout_s, max_output_bytes, issued_at, expires_at`; the signature covers the attempt); a check definition: the canonical form of `{key, type, params, interval_s}` (an empty `params` is signed as `{}`); an update manifest: the lowercase hex SHA-256 text of the package; a change-monitoring configuration: the domain line `rivetit-change-config-v1`, a line feed, then the canonical form of the `config.change` object without its `signature` member (3.2.7).
* **Pinning.** An agent trusts the public key it received at enrollment, so rotating the instance key makes every enrolled agent refuse jobs and updates until it re-enrolls. The server never rotates or re-derives a key by itself; an administrator's "rotate signing key" says so and counts the devices affected.

## 5. Module switch and capacity (what an agent sees)

A switched-off module answers every device endpoint with `503 {"error":"The RMM service is disabled on this server.","code":"module_disabled"}` and `Retry-After: 3600`, before any database work (the edition's pre-bootstrap gate), including for a valid credential. Agents keep their credential and buffer, wait at least 15 minutes with jitter and retry. A sub-switch that is off (`jobs`, `updates`) answers `feature_disabled` the same way on that endpoint only. Load shedding (`LoadShedder`) answers 503 `unavailable` with a jittered `Retry-After` at its highest level. Data is never deleted by switching the module off; re-enabling resumes. An edition that does not give Core the module state (no gate, no kill switch) keeps the baseline answer of RivetIT: 403 `forbidden` for enroll, installer and a valid device credential, which is what the golden transcripts record in section 10.

## 6. Installer trailer

```
stamped_exe = <original exe bytes> || payload || footer
payload     = UTF-8 JSON object, at most 16384 bytes:
              {"version":1,"installer_id":"<uuid>","server_url":"https://host[/prefix]","enrollment_token":"rvte1.<selector>.<secret>",
               "department":"<client name>","ca_pem":null|"<PEM text>","created_at":"RFC3339 UTC","expires_at":"RFC3339 UTC"}
footer      = 52 bytes: uint32 big-endian payload length || 32-byte raw SHA-256 of the payload || 16 ASCII bytes "RIVETIT-EMBED-v1"
```

**Optional members (1.0.0-rc.12, additive; the version stays 1).** When an administrator ticked "also install the MeshCentral agent" the JSON object carries two more members **after** `expires_at`, and only then, so every payload that does not ask for it is byte for byte what it always was:

```
"install_mesh_agent":true,
"mesh":{"url":"https://mesh.example.com","meshid":"<device group id: MeshCentral's 64-character URL form (since rc.15) or 32-128 hex>","pin":"<SHA-384 (96) or SHA-256 (64) hex of the Mesh server certificate>",
        "agent_ids":{"linux/amd64":6,"windows/amd64":4,...},"install_args":{"linux":["-install"],"windows":["-fullinstall"]}}
```

`pin` is left out when the administrator set none (the agent then verifies the Mesh server with the system roots). `agent_ids` maps `GOOS/GOARCH` to the MeshCentral agent id for `<url>/meshagents?id=<N>&meshid=<meshid>&installflags=0`; the server always sends the full table (built-in plus the administrator's corrections), so a wrong id is corrected on the server without a new agent. The agent reads these two members leniently: a wrong type, a missing source or an unusable value never makes the installer unreadable and never fails the RivetIT install; it only records an error and reports it at the first check-in (3.2.5). An agent that does not know the members ignores them (proved by `agent_installer_mesh_vectors.json`, which every older reader decodes). The agent never ships a Mesh binary: it downloads it from the customer's own Mesh server over https, trusting either the system roots or exactly the pinned certificate hash, with a 64 MiB size cap, and runs it (`MeshAgent.exe -fullinstall` on Windows, `./meshagent -install` on Linux). **`meshid` (rc.15, additive).** The server now writes `meshid` in the form MeshCentral prints it in its download address: 64 characters of `[A-Za-z0-9@$_-]`, base64 of the 48-byte group hash with `@` for `+` and `$` for `/` (derived from the stored hex when the administrator gave hex). The agent sends it **unchanged** as `meshid=` (`@` and `$` literal, not percent-encoded, exactly as MeshCentral prints them) and also accepts the older hex form, from which it derives the same URL form when the hex is 96 characters (48 bytes); any other hex length is sent as it is. A reader of the older payload shape (hex) is unaffected: both shapes are in `agent_installer_mesh_vectors.json`, which also carries `group_forms` (hex and URL form of the same ids, both directions). An agent that predates rc.15 and receives the URL form records a Mesh error and the RivetIT install still succeeds. **Verified against a real MeshCentral (rc.15):** the address shape `<url>/meshagents?id=4&meshid=<URL form>&installflags=0` (id 4 = Windows x64). **Still unverified:** the Linux agent ids, the install arguments and where the node id can be read; see `docs/rmm/REMOTE_AND_LOGS.md`.

**The same trailer on an MSI (1.0.0-rc.16, additive).** A per-client Windows Installer package is a COPY of a published MSI template (an OLE compound file, signature `D0 CF 11 E0 A1 B1 1A E1`) with `payload || footer` appended after its last byte, in exactly the format above and with the same payload (including the optional Mesh members). The template is stored unmodified and is only read. The deferred custom action `RivetSetupStamped` runs `rivetit-agent.exe setup --silent --from-msi --payload-from "[OriginalDatabase]"`; `--payload-from` points the SAME reader at the `.msi` instead of at the agent's own executable. The reader looks only at the last 52 bytes, so it does not care what the container is. **Unverified on Windows:** that Windows Installer (`msiexec`) accepts a package with bytes after the end of the compound file, and that the copy it caches in `C:\Windows\Installer` keeps them (docs/rmm/MSI_VERIFY.md). An Authenticode signature on the template would be invalidated by appending the payload to the signed file; the design note is in docs/rmm/AGENT_BUILD.md. The MSI also accepts public properties (`SERVER`, `ENROLL_TOKEN` or `ENROLL_TOKEN_FILE`, `CA_PEM_FILE`) instead of a payload; those never reach the wire protocol.

The agent reads the last 52 bytes of its own executable, checks the magic, the length bound and the SHA-256, parses the JSON and installs and enrolls unattended. The base binary is stored unstamped; stamping happens per download (`Installer\InstallerStamp`). The SHA-256 detects truncation and corruption; it is not authentication: the payload carries the enrollment token, which is the secret, and its expiry is the token's expiry. The `department` key keeps its name (it is the client name).

## 7. Identifiers and storage names

Job id: UUID v4. Alert key `agent:<device_id>:<check_key>:<episode>` (and, for the Phase 3 storm-control summary alert, `agent:storm:<scope>:<window start>`); agent link key `rivetit:<device_id>`; integration `type='rivetit_agent'`. Hosted binaries are stored as `bin_<32 hex>.bin` and only that name pattern is ever turned into a path. Platforms: `windows`, `linux` (macOS is deferred).

## 8. Technician REST

`endpoint_devices` (list, detail, jobs, cancel, remote launch) is the technician side of the same module and is authenticated by the edition with a user API token; its routes, shapes and status codes are in [openapi-device.yaml](openapi-device.yaml) and implemented by `Http\TechnicianApi`. Phase 1 added software, tags, groups, check history and the live document; Phase 2 adds policies (`policies`, `{id}/policy`), the script library (`scripts`, versions, `runs`, `library_script_id` on a job), approvals (`approvals`), scheduled scripts (`schedules`) and custom fields (`fields`, `{id}/fields`); Phase 3 adds alerts, maintenance windows, escalation policies and thresholds; Phase 6 adds the service and process managers (`{id}/services`, `{id}/processes`), file transfer (`{id}/files`), the Mesh views (`{id}/remote/mesh`, `view` on `POST {id}/remote`), `remote/settings`; Phase 8 adds `logs` and `logs/rules`; 1.0.0-rc.16 adds `{id}/mesh/install` and `{id}/mesh/uninstall` (pushed Mesh agent, section 3.2.6), the bulk `mesh/install`, and `format` on the installer download. All additive.

## 9. Frozen constants

<!-- BEGIN GENERATED: constants -->
These are the values of `RivetCore\Rmm\RmmProtocol`; `tests/Unit/Rmm/FrozenConstantsTest.php` pins them.

**Integration identity**

| Constant | Value |
|---|---|
| `INTEGRATION_TYPE` | `rivetit_agent` |
| `DEFAULT_INTEGRATION_NAME` | `RivetIT Endpoint Agent` |
| `AGENT_KEY_PREFIX` | `rivetit:` |
| `ALERT_KEY_PREFIX` | `agent:` |

**Credentials**

| Constant | Value |
|---|---|
| `ENROLL_TOKEN_PREFIX` | `rvte1` |
| `ENROLL_TOKEN_SELECTOR_RE` | `/^[0-9a-f]{12}$/` |
| `ENROLL_TOKEN_SECRET_RE` | `/^[0-9a-f]{40}$/` |
| `ENROLL_MAX_USES` | `5000` |
| `RINGS` | `["pilot","stable"]` |
| `DEVICE_TOKEN_BEARER_RE` | `/^Bearer\s+([A-Za-z0-9]{64})$/` |
| `DEVICE_TOKEN_VALID_DAYS` | `365` |
| `SIGNING_KEY_ID_LENGTH` | `16` |

**Request bodies**

| Constant | Value |
|---|---|
| `ENROLL_MAX_BODY` | `16384` |
| `CHECKIN_MAX_BODY` | `1048576` |
| `JOBS_REPORT_MAX_BODY` | `262144` |
| `INSTALLER_MAX_BODY` | `4096` |

**Rate limits**

| Constant | Value |
|---|---|
| `RATE_CHECKIN` | `[40,60]` |
| `RATE_JOBS` | `[120,60]` |
| `RATE_UPDATE` | `[60,60]` |

**DB-backed limits, per IP hash**

| Constant | Value |
|---|---|
| `ENROLL_RATE_SALT` | `ea-enroll\|` |
| `ENROLL_RATE_WINDOW_S` | `600` |
| `ENROLL_RATE_MAX_FAILURES` | `10` |
| `ENROLL_RATE_MAX_ATTEMPTS` | `60` |
| `INSTALLER_RATE_SALT` | `ea-installer\|` |
| `INSTALLER_WINDOW_S` | `600` |
| `INSTALLER_IP_MAX_FAILURES` | `10` |
| `INSTALLER_IP_MAX_ATTEMPTS` | `30` |
| `INSTALLER_TOKEN_MAX_DOWNLOADS` | `30` |
| `INSTALLER_SELECTOR_MAX_FAILURES` | `20` |

**Job polling and jobs**

| Constant | Value |
|---|---|
| `JOBS_WAIT_MAX_S` | `5` |
| `JOBS_POLL_STEP_US` | `500000` |
| `JOBS_OFFER_LIMIT` | `5` |
| `JOB_MAX_SCRIPT_BYTES` | `102400` |
| `JOB_DEADLINE_GRACE_S` | `60` |
| `JOB_STATES` | `["queued","running","succeeded","failed","timed_out","cancelled","expired"]` |
| `JOB_FINAL_STATES` | `["succeeded","failed","timed_out","cancelled","expired"]` |
| `JOB_REPORTABLE_STATES` | `["running","succeeded","failed","timed_out","cancelled"]` |
| `JOB_REASONS` | `["result_lost","never_started","no_result_by_deadline","device_retired"]` |

**Check-in caps**

| Constant | Value |
|---|---|
| `CHECKIN_MAX_CHECKS` | `100` |
| `CHECKIN_MAX_BUFFERED` | `100` |
| `CHECKIN_MAX_DISKS` | `32` |
| `CHECKIN_MAX_INVENTORY_BYTES` | `65536` |
| `CHECKIN_FUTURE_SKEW_S` | `300` |
| `CHECK_KEY_RE` | `/^[A-Za-z0-9_.:-]{1,100}$/` |
| `CHECK_STATUSES` | `["ok","warn","fail","unknown"]` |

**Device identity**

| Constant | Value |
|---|---|
| `LINK_STATES` | `["linked","pending_approval","rejected"]` |
| `MAC_RE` | `/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/` |
| `NULL_MAC` | `00:00:00:00:00:00` |
| `INSTALL_ID_RE` | `/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i` |
| `MACHINE_GUID_RE` | `/^[A-Za-z0-9{}-]{1,64}$/` |
| `AGENT_VERSION_RE` | `/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?$/` |
| `JUNK_SERIALS` | `["","0","none","n/a","na","unknown","default string","to be filled by o.e.m.","system serial number","serial number","not specified","not applicable","xxxxxxxx","123456789","1234567890","default"]` |

**Binaries and updates**

| Constant | Value |
|---|---|
| `ARCHS` | `{"amd64":34404,"arm64":43620}` |
| `BINARY_VERSION_RE` | `/^\d{1,5}\.\d{1,5}\.\d{1,5}([-+][0-9A-Za-z.-]{1,20})?\z/` |
| `BINARY_STORAGE_NAME_RE` | `/^bin_[0-9a-f]{32}\.bin$/` |
| `BINARY_DEFAULT_MAX_BYTES` | `67108864` |
| `BINARY_MIN_BYTES` | `1024` |
| `DOWNLOAD_NAME_PREFIX` | `rivetit-agent-` |
| `INSTALLER_NAME_PREFIX` | `RivetIT-Agent-Setup-` |

**Installer trailer**

| Constant | Value |
|---|---|
| `EMBED_MAGIC` | `RIVETIT-EMBED-v1` |
| `EMBED_FOOTER_LEN` | `52` |
| `EMBED_MAX_PAYLOAD` | `16384` |
| `EMBED_PAYLOAD_VERSION` | `1` |

**MeshCentral**

| Constant | Value |
|---|---|
| `MESH_DEFAULT_ACCOUNT_TEMPLATE` | `rivetit-support` |
| `MESH_COOKIE_ACCESS` | `3` |
| `MESH_VIEWMODE` | `11` |
| `CAP_MESH_NODEID` | `mesh:nodeid` |
| `CHECKIN_MESH_AGENT` | `mesh_agent` |
| `EMBED_MEMBER_INSTALL_MESH` | `install_mesh_agent` |
| `EMBED_MEMBER_MESH` | `mesh` |

**Redaction**

| Constant | Value |
|---|---|
| `REDACTION_MASK` | `[REDACTED]` |

**HTTP**

| Constant | Value |
|---|---|
| `JSON_FLAGS` | `2098496` |
| `ERROR_CODES` | `["invalid_token","revoked","expired","forbidden","not_found","method_not_allowed","conflict","too_large","invalid","tls_required","rate_limited","internal","unavailable","token_in_url","confirmation_required","queued","cancelled","device_offline","unmapped","not_configured","device_retired","mesh_unavailable","disabled"]` |

**Platforms**

| Constant | Value |
|---|---|
| `DEFAULT_PLATFORM` | `windows` |
| `PLATFORMS` | `["windows","linux"]` |
<!-- END GENERATED: constants -->

## 10. Observed responses

<!-- BEGIN GENERATED: observed -->
Recorded from the golden transcripts (`tests/Fixtures/rmm/golden`): every status the server answered, with the error `code` and message.
The disabled-module answer (503 `module_disabled`) is not in the transcripts; it is specified in the section above.

| Endpoint | Method | Status | `code` | Message |
|---|---|---|---|---|
| `agent_checkin` | GET | 405 | `method_not_allowed` | Use POST. |
| `agent_checkin` | POST | 200 |  |  |
| `agent_checkin` | POST | 401 | `expired` | This device credential has expired. Re-enroll the agent. |
| `agent_checkin` | POST | 401 | `invalid_token` | Invalid device credential. |
| `agent_checkin` | POST | 401 | `revoked` | This device credential was revoked. |
| `agent_checkin` | POST | 413 | `too_large` | Request body too large. |
| `agent_checkin` | POST | 422 | `invalid` | A JSON object body is required. |
| `agent_checkin` | POST | 422 | `invalid` | agent_version is invalid |
| `agent_checkin` | POST | 422 | `invalid` | checks must be a list of at most 100 items |
| `agent_checkin` | POST | 422 | `invalid` | collected_at is in the future |
| `agent_checkin` | POST | 422 | `invalid` | collected_at must be an RFC 3339 timestamp |
| `agent_checkin` | POST | 422 | `invalid` | each check needs a valid key and a status of ok, warn, fail or unknown |
| `agent_checkin` | POST | 422 | `invalid` | inventory must be an object under 65536 bytes |
| `agent_checkin` | POST | 422 | `invalid` | seq must be a non-negative integer |
| `agent_checkin` | POST | 426 | `tls_required` | TLS is required. |
| `agent_checkin` | POST | 429 | `rate_limited` | Too many requests. |
| `agent_enroll` | GET | 405 | `method_not_allowed` | Use POST. |
| `agent_enroll` | GET | 426 | `tls_required` | TLS is required. |
| `agent_enroll` | POST | 201 |  |  |
| `agent_enroll` | POST | 401 | `expired` | This enrollment token has expired. |
| `agent_enroll` | POST | 401 | `invalid_token` | Invalid enrollment token. |
| `agent_enroll` | POST | 401 | `revoked` | This enrollment token was revoked. |
| `agent_enroll` | POST | 403 | `forbidden` | The endpoint agent service is disabled. |
| `agent_enroll` | POST | 403 | `forbidden` | This device was revoked. An administrator must allow re-enrollment first. |
| `agent_enroll` | POST | 403 | `forbidden` | This enrollment token has no uses left. |
| `agent_enroll` | POST | 409 | `conflict` | This install id is already bound to a different machine. |
| `agent_enroll` | POST | 413 | `too_large` | Request body too large. |
| `agent_enroll` | POST | 422 | `invalid` | A JSON object body is required. |
| `agent_enroll` | POST | 422 | `invalid` | device.arch is invalid |
| `agent_enroll` | POST | 422 | `invalid` | device.hostname is invalid |
| `agent_enroll` | POST | 422 | `invalid` | device.install_id is invalid |
| `agent_enroll` | POST | 422 | `invalid` | device.machine_guid is invalid |
| `agent_enroll` | POST | 422 | `invalid` | device.os is invalid |
| `agent_enroll` | POST | 422 | `invalid` | enrollment_token and device are required |
| `agent_enroll` | POST | 426 | `tls_required` | TLS is required. |
| `agent_enroll` | POST | 429 | `rate_limited` | Too many enrollment attempts. Try again later. |
| `agent_enroll` | PUT | 405 | `method_not_allowed` | Use POST. |
| `agent_installer` | GET | 405 | `method_not_allowed` | Use POST. |
| `agent_installer` | POST | 200 |  | binary download |
| `agent_installer` | POST | 400 | `token_in_url` | Send the token in the request body or an Authorization header, never in the URL. |
| `agent_installer` | POST | 403 | `forbidden` | The endpoint agent service is disabled. |
| `agent_installer` | POST | 404 | `not_found` | Not found. |
| `agent_installer` | POST | 409 | `unavailable` | No agent binary is published for arm64. Upload one under Agent binaries and make it current. |
| `agent_installer` | POST | 413 | `too_large` | Request body too large. |
| `agent_installer` | POST | 422 | `invalid` | token and arch (amd64 or arm64) are required. |
| `agent_installer` | POST | 426 | `tls_required` | TLS is required. |
| `agent_installer` | POST | 429 | `rate_limited` | Too many requests. Try again later. |
| `agent_jobs` | GET | 200 |  |  |
| `agent_jobs` | GET | 401 | `invalid_token` | Invalid device credential. |
| `agent_jobs` | GET | 426 | `tls_required` | TLS is required. |
| `agent_jobs` | GET | 429 | `rate_limited` | Too many requests. |
| `agent_jobs` | POST | 200 |  |  |
| `agent_jobs` | POST | 404 | `not_found` | Unknown job. |
| `agent_jobs` | POST | 409 | `conflict` | Job is already succeeded. |
| `agent_jobs` | POST | 409 | `conflict` | That attempt was never issued. |
| `agent_jobs` | POST | 413 | `too_large` | Request body too large. |
| `agent_jobs` | POST | 422 | `invalid` | job_id, attempt and state are required |
| `agent_jobs` | PUT | 405 | `method_not_allowed` | Use GET or POST. |
| `agent_update` | GET | 200 |  | binary download |
| `agent_update` | GET | 401 | `invalid_token` | Invalid device credential. |
| `agent_update` | GET | 404 | `not_found` | Not found. |
| `agent_update` | GET | 422 | `invalid` | arch (amd64 or arm64) and version are required. |
| `agent_update` | GET | 426 | `tls_required` | TLS is required. |
| `agent_update` | GET | 429 | `rate_limited` | Too many requests. |
| `agent_update` | POST | 405 | `method_not_allowed` | Use GET. |
<!-- END GENERATED: observed -->

## 11. Examples

<!-- BEGIN GENERATED: examples -->
Placeholders such as `<TOKEN#5>`, `<DEVICE:dev2>` and `<TS>` are the transcripts' masks for values that differ on every run (see `scripts/rmm-golden/README.md`); a `<SIG:ok:job>` is an Ed25519 signature that was verified against the test key.

#### Enrollment (the device matched an asset by serial number)

```http
POST /api/v1/agent_enroll
content-type: application/json

{
    "enrollment_token": "rvte1.a1a1a1a1a101.1111111111111111111111111111111111111111",
    "device": {
        "install_id": "00000000-0000-4000-8000-000000000002",
        "machine_guid": "a985e21143e883c4",
        "hostname": "GOLD-7A01",
        "os": "windows",
        "os_version": "Windows 11 23H2",
        "arch": "amd64",
        "serial": "GOLD-SER-LINK",
        "manufacturer": "Dell",
        "model": "Latitude 7440",
        "mac_addresses": [
            "AA-BB-CC-4E-00-01"
        ],
        "agent_version": "1.0.0"
    }
}

HTTP 201
cache-control: no-store
content-type: application/json

{
    "device_id": "<DEVICE:dev2>",
    "device_token": "<TOKEN#2>",
    "check_in_interval_s": 300,
    "server_time": "<TS>",
    "status": "linked",
    "matched_asset_id": "<ASSET:linkable>",
    "signing_public_key": "iMb1UVs5sfHsmc8uRi4z7vPgUMwAmKcMd2dCQj8E5BE=",
    "signing_key_id": "b2569fb66d0a09e4",
    "config": {
        "checks": [
            {
                "key": "disk_c",
                "type": "disk",
                "params": {
                    "mount": "C:",
                    "warn_pct": 85,
                    "fail_pct": 95
                },
                "interval_s": 300,
                "signature": "<SIG:ok:check>"
            },
            {
                "key": "pending_reboot",
                "type": "pending_reboot",
                "params": [],
                "interval_s": 3600,
                "signature": "<SIG:ok:check>"
            },
            {
                "key": "svc_eventlog",
                "type": "service",
                "params": {
                    "name": "EventLog",
                    "expect": "running"
                },
                "interval_s": 300,
                "signature": "<SIG:ok:check>"
            }
        ],
        "collect_interval_s": 60
    }
}
```

#### Check-in (inventory, metrics, checks; the response carries signed checks and a signed update manifest)

```http
POST /api/v1/agent_checkin
authorization: Bearer <TOKEN#5>
content-type: application/json

{
    "seq": 1,
    "collected_at": "{{ts:-20}}",
    "agent_version": "1.0.0",
    "metrics": {
        "cpu_pct": 12.5,
        "mem_pct": 40,
        "disk": [
            {
                "mount": "C:",
                "used_pct": 60.8
            }
        ],
        "net_rx_bps": 1000.5,
        "net_tx_bps": 500
    },
    "checks": [
        {
            "key": "disk_c",
            "status": "ok",
            "detail": "C: 61% used"
        },
        {
            "key": "svc_eventlog",
            "status": "ok",
            "detail": "running"
        }
    ],
    "buffered": [],
    "inventory": {
        "hostname": "GOLD-INV",
        "os": "windows",
        "os_version": "Windows 11 23H2",
        "manufacturer": "Dell",
        "model": "Latitude 7440",
        "serial": "GOLD-SER-LINK",
        "cpu": {
            "model": "Intel i7-1355U",
            "cores": 10
        },
        "memory_total_bytes": 17179869184,
        "disks": [
            {
                "mount": "C:",
                "total_bytes": 512000000000,
                "free_bytes": 200000000000,
                "fs": "NTFS"
            }
        ],
        "network": [
            {
                "name": "Ethernet",
                "mac": "AA-BB-CC-DD-EE-01",
                "ips": [
                    "10.0.0.5",
                    "not-an-ip"
                ]
            }
        ],
        "uptime_s": 7200,
        "logged_in_user": "<script>alert(1)</script>",
        "pending_reboot": false
    }
}

HTTP 200
cache-control: no-store
content-type: application/json

{
    "ok": true,
    "status": "linked",
    "matched_asset_id": "<ASSET:linkable>",
    "next_check_in_s": 300,
    "jobs_pending": 0,
    "config": {
        "checks": [
            {
                "key": "disk_c",
                "type": "disk",
                "params": {
                    "mount": "C:",
                    "warn_pct": 85,
                    "fail_pct": 95
                },
                "interval_s": 300,
                "signature": "<SIG:ok:check>"
            },
            {
                "key": "pending_reboot",
                "type": "pending_reboot",
                "params": [],
                "interval_s": 3600,
                "signature": "<SIG:ok:check>"
            },
            {
                "key": "svc_eventlog",
                "type": "service",
                "params": {
                    "name": "EventLog",
                    "expect": "running"
                },
                "interval_s": 300,
                "signature": "<SIG:ok:check>"
            }
        ],
        "collect_interval_s": 60
    },
    "update": {
        "version": "1.1.0",
        "url": "<BASE>/api/v1/agent_update?arch=amd64&version=1.1.0",
        "sha256": "7a758b8a61ffabf5ffe29becd570b2754bcedab6f39b79e235ee4fc3c00f3356",
        "signature": "<SIG:ok:manifest>",
        "min_version": "0.0.0"
    },
    "server_time": "<TS>",
    "signing_key_id": "b2569fb66d0a09e4"
}
```

#### Job offer (GET agent_jobs): a signed job

```http
GET /api/v1/agent_jobs
authorization: Bearer <TOKEN#5>

HTTP 200
cache-control: no-store
content-type: application/json

{
    "jobs": [
        {
            "job_id": "<UUID:job:j1>",
            "device_id": "<DEVICE:dev2>",
            "attempt": 1,
            "type": "powershell",
            "script": "Get-Date",
            "params": {
                "Name": "x",
                "Count": 3
            },
            "timeout_s": 120,
            "max_output_bytes": 65536,
            "issued_at": "<TS>",
            "expires_at": "<TS>",
            "signature": "<SIG:ok:job>"
        }
    ]
}
```

#### Job report: the agent started the job

```http
POST /api/v1/agent_jobs
authorization: Bearer <TOKEN#5>
content-type: application/json

{
    "job_id": "{{job:j1}}",
    "attempt": 1,
    "state": "running",
    "started_at": "{{ts:-2}}"
}

HTTP 200
cache-control: no-store
content-type: application/json

{
    "ok": true
}
```

#### Job report: the result (the server redacts secrets in the stored output)

```http
POST /api/v1/agent_jobs
authorization: Bearer <TOKEN#5>
content-type: application/json

{
    "job_id": "{{job:j1}}",
    "attempt": 1,
    "state": "succeeded",
    "exit_code": 0,
    "output": "ok\npassword=hunter2\nAuthorization: Bearer abcdefghijklmnop12345\ntoken: ababababababababababababababa...(312 characters)",
    "started_at": "{{ts:-2}}",
    "finished_at": "{{ts:-1}}"
}

HTTP 200
cache-control: no-store
content-type: application/json

{
    "ok": true
}
```

#### Hosted update download (the unstamped executable)

```http
GET /api/v1/agent_update?arch=amd64&version=1.1.0
authorization: Bearer <TOKEN#5>

HTTP 200
cache-control: no-store
content-disposition: attachment; filename="rivetit-agent-amd64.exe"
content-type: application/octet-stream
pragma: no-cache
x-accel-buffering: no
x-content-type-options: nosniff

(binary body) {
    "length": 12288,
    "sha256": "7a758b8a61ffabf5ffe29becd570b2754bcedab6f39b79e235ee4fc3c00f3356",
    "equals_published": "amd64@1.1.0"
}
```

#### Installer download (the stamped executable; the transcript records the parsed trailer)

```http
POST /api/v1/agent_installer
content-type: application/json

{
    "token": "rvte1.a6a6a6a6a606.6666666666666666666666666666666666666666",
    "arch": "amd64"
}

HTTP 200
cache-control: no-store
content-disposition: attachment; filename="RivetIT-Agent-Setup-golden-dept-a-x64.exe"
content-type: application/octet-stream
pragma: no-cache
x-accel-buffering: no
x-content-type-options: nosniff

(binary body) {
    "length": 8544,
    "magic": "RIVETIT-EMBED-v1",
    "footer_sha256_matches_payload": true,
    "payload_length": 300,
    "exe_prefix_equals_published": "amd64@1.0.0",
    "payload_keys": [
        "version",
        "installer_id",
        "server_url",
        "enrollment_token",
        "department",
        "ca_pem",
        "created_at",
        "expires_at"
    ],
    "payload": {
        "version": 1,
        "installer_id": "<UUID#1>",
        "server_url": "<BASE>",
        "enrollment_token": "rvte1.a6a6a6a6a606.6666666666666666666666666666666666666666",
        "department": "Golden Dept A",
        "ca_pem": null,
        "created_at": "<TS>",
        "expires_at": "<TS>"
    },
    "payload_enrollment_token_is_the_requested_one": true,
    "payload_department_is_the_expected_one": true
}
```

#### An error response

```http
POST /api/v1/agent_enroll
content-type: application/json

{
    "enrollment_token": "rvte1.a3a3a3a3a303.3333333333333333333333333333333333333333",
    "device": {
        "install_id": "00000000-0000-4000-8000-000000000901",
        "machine_guid": "470b150b658bfaec",
        "hostname": "GOLD-9E1D",
        "os": "windows",
        "os_version": "Windows 11 23H2",
        "arch": "amd64",
        "serial": "GOLD-E1",
        "manufacturer": "Dell",
        "model": "Latitude 7440",
        "mac_addresses": [
            "AA-BB-CC-D0-00-01"
        ],
        "agent_version": "1.0.0"
    }
}

HTTP 401
cache-control: no-store
content-type: application/json

{
    "error": "This enrollment token has expired.",
    "code": "expired"
}
```

#### A rate-limited response

```http
POST /api/v1/agent_enroll
content-type: application/json

{
    "enrollment_token": "rvte1.cccccccccccc.dddddddddddddddddddddddddddddddddddddddd",
    "device": {
        "install_id": "00000000-0000-4000-8000-000000000950",
        "machine_guid": "14ebb302cd9887db",
        "hostname": "GOLD-2C5E",
        "os": "windows",
        "os_version": "Windows 11 23H2",
        "arch": "amd64",
        "serial": "GOLD-RL",
        "manufacturer": "Dell",
        "model": "Latitude 7440",
        "mac_addresses": [
            "AA-BB-CC-D3-00-01"
        ],
        "agent_version": "1.0.0"
    }
}

HTTP 429
cache-control: no-store
content-type: application/json
retry-after: 600

{
    "error": "Too many enrollment attempts. Try again later.",
    "code": "rate_limited"
}
```
<!-- END GENERATED: examples -->

## 12. Vectors

<!-- BEGIN GENERATED: vectors -->
Both files live in `endpoint-agent/testdata/vectors/` and are read by the Go agent and by the PHP tests; `php scripts/endpoint-vectors.php --check` regenerates them and fails on any byte difference.

| File | SHA-256 |
|---|---|
| `agent_job_signing_vectors.json` | `9850daf57252afbfc7eee7f9531239b9f8e0e68fb491b56bff5682e94f85deb6` |
| `agent_installer_trailer_vectors.json` | `8a89e10349425d6370fcb9b56dbd25426b7116f24de5bb34f8b8d25055059304` |
| `agent_installer_mesh_vectors.json` | `39fb641e12abb3d10eac10132fc640991014c5dc994d8e8ca5f6e31ba2af61ea` |
| `agent_software_deploy_vectors.json` | `f52af62a9163a63b869d378e513bde81490c598aec7caae469ed58e2a3554ef2` |
| `agent_change_monitor_vectors.json` | `aa20e416e88f667681f169d26b38ea6dd53a94397a4d1d6012d6bc173df6a50e` |
| `agent_check_os_vectors.json` | `dfbf0dceb2cc9dbd06d392787d30a2399b26c12299e2f909e7b95b1d533dbaab` |

**Job signing** (`agent_job_signing_vectors.json`). Test key: SHA-256 of the ASCII string RivetIT-agent-TEST-seed (raw 32 bytes); public key `iMb1UVs5sfHsmc8uRi4z7vPgUMwAmKcMd2dCQj8E5BE=`.

Signed jobs (4):

- minimal reboot job (null script, empty params object)
- powershell job, unsorted input keys, nested params
- script with quotes, backslashes, control characters and unicode
- collect job with parameters of every scalar type

Canonical-form-only cases (4): keys sort by UTF-8 bytes; empty containers stay distinct; string escaping; integers and literals.

Also: one update-manifest signature (The signature is Ed25519 over the lowercase hex SHA-256 string of the package, as ASCII bytes.) and one check-definition signature (Check definitions are signed the same way as jobs (canonical JSON of the object without "signature").).

**Installer trailer** (`agent_installer_trailer_vectors.json`): footer 52 bytes, payload at most 16384 bytes.

Stamped vectors (7): basic; with_ca_and_unicode; one_byte_exe; empty_exe; magic_inside_body; minimal_payload; max_payload_16384.

Negative cases, every one of which must be rejected (14):

- too_short: shorter than the 52-byte footer
- empty_file: no bytes at all
- bad_magic: last byte of the magic changed
- bad_magic_case: magic compared case-sensitively
- bad_sha256: one bit of the stored hash flipped
- payload_corrupted: payload changed after hashing
- truncated_one_byte: file truncated by one byte (magic incomplete)
- length_zero: declared payload length 0
- length_exceeds_file: declared length larger than the file
- length_16385: declared length one over the 16384 bound, hash valid
- length_off_by_one_short: length does not match the hashed payload
- length_off_by_one_long: length does not match the hashed payload (includes one exe byte)
- payload_not_json: footer valid, payload is not JSON: the parser must refuse
- payload_json_array: footer valid, payload is a JSON array, not an object

**Optional Mesh members** (`agent_installer_mesh_vectors.json`, added in 1.0.0-rc.12; the two files above are unchanged): payloads with `install_mesh_agent` and `mesh`, which every reader must parse and an agent that predates them must still decode. Vectors (11): mesh_pinned_sha384; mesh_system_roots; mesh_table_override; mesh_not_requested; mesh_requested_without_source; mesh_wrong_types; mesh_bad_source; mesh_url_form_meshid; mesh_hex_meshid_derives_url_form; mesh_bad_meshid_forms; unknown_future_members.

**Software job parameters** (`agent_software_deploy_vectors.json`, added in 1.0.0-rc.21; the files above are unchanged): accept/reject vectors for the parameters of `software_install` (126 cases, 14 accepted) and `software_detect` (28 cases, 5 accepted). The agent must refuse every rejected one as `invalid_params` before it runs or downloads anything; the server builds only accepted ones. The PHP validators are checked against the labels by the generator and the Go agent replays the file in `internal/deploy`.

**Per-OS check targeting** (`agent_check_os_vectors.json`, added in 1.0.0-rc.24; the files above are unchanged): 4 signed check definitions, some carrying the optional `os` member (it is part of the signed object), and the keys delivered to a Windows and to a Linux device. The Go agent replays the file in `internal/agent`: every signature must verify over the object exactly as delivered, and dropping the checks of the other OS from the full list must leave exactly the delivered keys.

**Change monitoring** (`agent_change_monitor_vectors.json`, added in 1.0.0-rc.22; the files above are unchanged): the watch policy's answer for 47 registry keys and 96 file paths (plus 8 with an extra root), the secret-name rule for 30 value names, and one complete signed `config.change` object (domain line, canonical JSON, Ed25519 by the test key). The Go agent replays the file in `internal/change`: its policy must agree on every row, the signature must verify, and one changed character must break it.
<!-- END GENERATED: vectors -->
