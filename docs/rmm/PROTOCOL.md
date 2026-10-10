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

Request: `{seq (integer, idempotency key per device), collected_at (RFC 3339, at most 300 s in the future), agent_version, inventory (object, at most 65536 bytes, or null), metrics {cpu_pct, mem_pct, disk[{mount, used_pct}], net_rx_bps, net_tx_bps}, checks [{key, status ("ok","warn","fail","unknown"), detail}] (at most 100), buffered [{collected_at, metrics, checks}] (at most 100), update_result (optional {version, state ("ok","failed","rolled_back"), detail})}`. Body cap 1 MiB. A missing metric produces no sample; it is never stored as 0. Duplicate `seq` is a normal 200 (the liveness is real, the data is not processed twice). At most 40 per 60 s per device (429).

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

### 3.3 `agent_jobs` (GET and POST, device token)

* `GET [?wait=0..5]`: the signed jobs this device may run now (at most 5). With `wait` the request long-polls (poll step 0.5 s). `{"jobs": [{job_id, device_id, attempt, type, script, params, timeout_s, max_output_bytes, issued_at, expires_at, signature}]}`. Up to 120 requests per 60 s per device.
* `POST` (body cap 262144 bytes): the report `{job_id, attempt, state ("running","succeeded","failed","timed_out","cancelled"), exit_code, output, started_at, finished_at}` answers `{"ok": true}`. An unknown job (or one of another device) is 404; an attempt that was never issued, or a second different final state, is 409 `conflict`; the same final state again is an idempotent 200. Output is redacted (credential-shaped text becomes `[REDACTED]`) and capped (`max_output_bytes`, `job_output_max_bytes`) before it is stored.

Job life: `queued`, `running`, `succeeded`, `failed`, `timed_out`, `cancelled`, `expired`. Reasons: `result_lost`, `never_started`, `no_result_by_deadline`, `device_retired`, `cancelled_by_user`, `expired_before_run`, `late_result`. **A destructive job (every reboot, and any job flagged so) is offered at most once**: if its result never arrives it becomes `failed` / `result_lost` and is never re-sent; a late real result from the agent still replaces `result_lost`. A harmless job that was offered but never reported running may be offered again with `attempt + 1` after `job_ack_timeout_s`, up to `job_max_attempts`. A job that reported running is never re-offered: past its deadline it becomes `timed_out`.

### 3.4 `agent_update` (GET, device token)

`?arch=amd64|arm64&version=X.Y.Z`: the **unstamped** agent executable of the hosted release this very device is offered right now (its architecture, an active release and binary, its ring and rollout bucket, the stored SHA-256 matches), as a download. Anything else is a generic 404; a stored file that is missing or damaged is 503 `unavailable`. 60 per 60 s per device.

The offered release (in the check-in `update` manifest) is the highest release newer than the running version, whose `min_version` is not above it, in the device's ring (pilot devices also get stable releases), inside the deterministic rollout bucket (`crc32(device_id|version) % 100 < rollout_pct`, so raising the percentage only adds devices), and not reported failed by this device. The manifest is `{version, url, sha256, signature, min_version}`; the signature is over the lowercase hex SHA-256 **text**.

### 3.5 `agent_installer` (POST, enrollment token in the body or as a bearer token)

`{"token": "rvte1...", "arch": "amd64"|"arm64"}` as JSON or a form (body cap 4096 bytes): the stamped per-client installer (section 6). Every unusable token (malformed, unknown, wrong secret, revoked, expired, used up) is the same generic 404; no published binary or no https service URL is 409 `unavailable`. The download does not use up an enrollment use (enrolling does). Limits per 600 s from the database: per address 10 failures or 30 attempts, per token selector 30 downloads or 20 failures (429 + `Retry-After: 600`).

## 4. Signing

* **Canonical JSON** (what is signed): UTF-8; object keys sorted by their UTF-8 bytes, recursively; arrays in order; no whitespace; strings escape only `"`, `\`, `\b`, `\f`, `\n`, `\r`, `\t` and the other code points below U+0020 as lower-case `\u00xx`, everything else raw (including `/ < > &`, U+007F, U+2028, U+2029); **integers only** (floats are rejected in check definitions); `true`, `false`, `null`.
* **Ed25519** (libsodium detached). Keys are base64 of the 64-byte secret key and the 32-byte public key; signatures are standard padded base64. The key id is the first 16 hex characters of the SHA-256 of the **base64 text** of the public key.
* **What is signed:** a job: the canonical form of the job object **without** its `signature` member (`job_id, device_id, attempt, type, script, params, timeout_s, max_output_bytes, issued_at, expires_at`; the signature covers the attempt); a check definition: the canonical form of `{key, type, params, interval_s}` (an empty `params` is signed as `{}`); an update manifest: the lowercase hex SHA-256 text of the package.
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

The agent reads the last 52 bytes of its own executable, checks the magic, the length bound and the SHA-256, parses the JSON and installs and enrolls unattended. The base binary is stored unstamped; stamping happens per download (`Installer\InstallerStamp`). The SHA-256 detects truncation and corruption; it is not authentication: the payload carries the enrollment token, which is the secret, and its expiry is the token's expiry. The `department` key keeps its name (it is the client name).

## 7. Identifiers and storage names

Job id: UUID v4. Alert key `agent:<device_id>:<check_key>:<episode>`; agent link key `rivetit:<device_id>`; integration `type='rivetit_agent'`. Hosted binaries are stored as `bin_<32 hex>.bin` and only that name pattern is ever turned into a path. Platforms: `windows`, `linux` (macOS is deferred).

## 8. Technician REST

`endpoint_devices` (list, detail, jobs, cancel, remote launch) is the technician side of the same module and is authenticated by the edition with a user API token; its routes, shapes and status codes are in [openapi-device.yaml](openapi-device.yaml) and implemented by `Http\TechnicianApi`.

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
<!-- END GENERATED: vectors -->
