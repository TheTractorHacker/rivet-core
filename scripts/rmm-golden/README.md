# rmm-golden: golden HTTP transcripts and schema dumps for the endpoint agent API

Baseline: RivetIT `origin/beta` at `c26957c0b` (see `docs/design/endpoint-baseline.md`). These tools record how the **current** RivetIT server answers
the device-facing API (and a few technician calls), so the Core `RivetCore\Rmm` implementation (task T4) and the RivetIT adoption (T7) can prove they
behave identically.

| File | Purpose |
|---|---|
| `golden.php` | The driver. `record` runs every scenario against a server and writes `tests/Fixtures/rmm/golden/*.json`. `replay` runs them again and compares with the stored files (exit 0 = identical, 1 = difference, 2 = usage/transport error). |
| `constants.php` | Fixed, public test fixtures: signing key (the test key of `agent_job_signing_vectors.json`), enrollment tokens, admin API token, two clients, two assets, fake PE binaries. Nothing secret. |
| `adapter-rivetit.php` | Edition adapter for a **scratch** RivetIT install: `reset` (wipe + seed), `hook` (state changes the HTTP API cannot do), `snapshot` (rows of `endpoint_agent_*`). Refuses a database whose name has no `scratch`. |
| `dump-schema.php` | Dumps the 10 `endpoint_agent_*` tables from `information_schema` as deterministic JSON plus `SHOW CREATE TABLE` text. |

The driver speaks **only HTTP** to the server under test and talks to the edition **only through the adapter** (`php adapter <install> <command> <json>` prints JSON).
To replay against a Core-backed server, write an adapter with the same three commands (`reset`, `hook`, `snapshot`) for that edition; every scenario, mask and
comparison is reused unchanged.

## Environment (scratch only)

* A fully migrated RivetIT scratch install (see "Building the scratch install" in `endpoint-baseline.md`): config.php points at a database containing `scratch`, defines
  `EA_ALLOW_INSECURE_HTTP = true`, `$config_https_only = FALSE`.
* `php -S 127.0.0.1:8680 tests/mobile_api_router.php` from that install (the **main** server), started with `RIVETIT_REDIS_PORT=<port>` of a throwaway Redis
  (`redis-server --port 6360 --save "" --appendonly no`). Without Redis the per-device 429 scenarios fail (the guard fails open by design).
* Optional second server on another port from a copy of the install whose config.php does **not** define `EA_ALLOW_INSECURE_HTTP`: the `--tls-base`. It produces the 426 transcripts.
* `ext-sodium`, `ext-curl`, `ext-mysqli`. The adapter needs the same `RIVETIT_REDIS_PORT` in its environment (it flushes the throwaway Redis between runs).

## Record and replay

```
export RIVETIT_REDIS_PORT=6360
B="--base=http://127.0.0.1:8680 --tls-base=http://127.0.0.1:8681 --install=/path/to/scratch-install"
php scripts/rmm-golden/golden.php record $B          # rewrites tests/Fixtures/rmm/golden/*.json (deletes the old *.json first)
php scripts/rmm-golden/golden.php replay $B          # exit 0 and "replay identical (10 files)"
php scripts/rmm-golden/golden.php replay $B --dir=/tmp/other-recording --ignore-key-order
```

`record` and `replay` both start with `adapter reset`, so any previous state is discarded (the scratch database is wiped of endpoint data, test assets, API tokens).
A run takes about 15 seconds (two deliberate 1.1 s sleeps keep job `created_at` seconds distinct; one 1 s long poll). Run it twice and `diff -r` the two outputs: they are identical.

`--ignore-key-order` compares objects as sets of keys (default: strict, JSON key order of the server is part of the golden contract).

## What a transcript file contains

`NN-name.json` = `{scenario, format, steps[], snapshots{}}`. A step is either

* an exchange: `{id, note?, request{method, path, base, headers, body}, response{status, headers[], body | body_binary | body_text}}`,
* a hook: `{id: "hook:<name>", hook, args}` (state change done through the adapter, e.g. `revoke_device`, `clear_attempts`, `set_setting`), or
* a burst: `{id, burst{requests, status_histogram, note}}` (N quiet requests used to fill a rate-limit bucket; the next step records the refused request in full).

Request bodies are stored as **templates**: `{{ts:-30}}` = now - 30 s, `{{dev:dev2}}`, `{{tok:dev2}}`, `{{job:j1}}` = values bound at run time from earlier responses.
Everything else in a request is a fixed fixture (enrollment tokens `rvte1.a1a1a1a1a101.1111...`, install ids `00000000-0000-4000-8000-...`).

## Masking rules (all deterministic; the same rules apply to responses, headers and snapshots)

| Value | Becomes | Notes |
|---|---|---|
| `device_id` and `id` of a device object, `owned_by_device_id`, snapshot `device_id` | `<DEVICE:name>` when the scenario bound a name (`dev1`, `dev2`...), else `<DEVICE#n>` | n = order of first appearance in the run |
| `device_token` (value) and any Bearer value the scenario sent | `<TOKEN#n>` | every occurrence of a known device token inside any string is replaced too |
| `asset_id`, `matched_asset_id` | `<ASSET:linkable>` / `<ASSET:otherdept>` for seeded assets, else `<ASSET#n>` | |
| `alert_id` | `<ALERT#n>` | |
| `created_by` and other user ids | `<USER#n>` | the scratch admin id differs per install |
| UUIDs other than the fixed test ids | `<UUID#n>`, job ids bound by the scenario `<UUID:job:j1>` | |
| any RFC 3339 string (`2026-10-07T12:00:00Z`) | `<TS>` | anywhere inside a string |
| any `YYYY-MM-DD HH:MM:SS` | `<DT>` | database datetimes in snapshots and technician output |
| `signature` of a check definition, job object or update manifest | `<SIG:ok:check>` / `<SIG:ok:job>` / `<SIG:ok:manifest>` | **verified**, not compared: Ed25519 with the fixed test public key over the protocol's canonical JSON (re-implemented independently in `golden.php`) or, for the manifest, over the lowercase hex SHA-256. A signature that does not verify becomes `<SIG:INVALID:...>` and fails the run. |
| `age_s` | `<AGE>` | |
| base URL of the main / TLS server | `<BASE>` / `<TLSBASE>` | |
| strings longer than 2000 characters | `<STRING len=N sha256=...>` | e.g. the truncated 64 KiB job output |
| snapshot columns `token_hash`, `signing_private_key_enc`, `storage_name`, `integration_id` | `<TOKEN_HASH>`, `<SIGNING_PRIVATE_KEY_ENC>`, `<STORAGE_NAME>`, `<INTEGRATION_ID>` | |
| snapshot auto-increment ids (`token_id`, `release_id`, `binary_id`, ...) | `<TOKENROW#n>`, `<ID#n>`, `<BINARY#n>` | |
| snapshot `*_json` columns | decoded and masked as JSON | |

Response headers are stored as a **sorted** list of `name: value` (lower-case names). `date` -> `<DATE>`, `host` -> `<HOST>`, `x-powered-by` -> `PHP/<VERSION>`,
`content-length` -> `<LEN>` on JSON bodies (kept exact on downloads). Headers that exist only because of the transport or the RivetIT front controller are listed in
`_meta.json` (`transport_headers_ignored_by_core_comparison`, `edition_headers_not_produced_by_core`): a Core `DeviceApi` comparison must ignore the first list and expect the
edition bridge to add the second (`access-control-*` are added by `api/v1/index.php` for JSON responses only; binary downloads do not carry them).

Binary downloads are not stored: `body_binary` holds the length, the SHA-256 (omitted for installers, whose payload carries a per-download id and time), the comparison with the published
fake binary (`equals_published`, `exe_prefix_equals_published`) and, for installers, the parsed trailer (magic, footer hash check, payload keys and masked payload).

Snapshots (`snapshots{}` in each file) are masked row dumps of `endpoint_agent_*` after key points; they pin side effects (checks debounce, alerts open/resolve, token use counts, job states, enroll-attempt reasons).

## Scenario files

| File | Covers |
|---|---|
| `01-disabled.json` | module off: 403 on enroll/installer, 405 before the switch check, 401 before the switch check on device endpoints |
| `02-tls.json` | 426 on all five device endpoints over plain http; `X-Forwarded-Proto: https` from loopback passes, `http` does not |
| `03-enroll-errors.json` | 405/413/422, token garbage/unknown/wrong secret/expired/revoked/exhausted, device field validation, attempt accounting |
| `04-enroll-flows.json` | new unmatched, linked by serial, re-enroll, reinstall (machine_guid / serial), install-id identity conflict, scope mismatch, department-blind reinstall, junk serial, `auto_create`, revoked device, technician list/detail |
| `05-checkin.json` | auth errors, validation, expired credential, inventory, duplicate seq, buffered samples, checks debounce and alert open/resolve, signed update manifest, 413 over 1 MiB |
| `06-jobs.json` | technician submit, signed offer, ack window, report running/final, idempotent replay, 404/409/413, secret redaction, truncation, cancel, long poll |
| `07-update.json` | hosted update download and every generic 404 / 422 |
| `08-installer.json` | stamped installer (json / form / bearer), per-department payload, 409/405/400/422/413, generic 404s |
| `09-rate-limits.json` | enrollment and installer failure budgets (database), per-device check-in / jobs / update buckets (Redis), `Retry-After` values |

## Schema dumps

`php scripts/rmm-golden/dump-schema.php <scratch-db> <out.json> [<ddl-dir>]` with `RMM_DB_USER`/`RMM_DB_PASS` (`RMM_DB_HOST` optional). Output is sorted, contains no row data and no
`AUTO_INCREMENT=` counters. `tests/Fixtures/rmm/schema/endpoint_tables_final.json` + `ddl/*.sql` = DB 2.6.146 (fresh `db.sql` install and 2.6.145 -> 2.6.146 upgrade produce
byte-identical dumps); `endpoint_tables_2_6_145.json` + `ddl-2_6_145/*.sql` = after only the `2.6.145` step (9 tables: `endpoint_agent_binaries` does not exist yet).

## Replay against RivetCore (task T4)

`replay-core.php` replays the same transcripts through `RivetCore\Rmm\Http\DeviceApi` over a scratch database, with Core's in-memory reference adapters as the
edition. Nothing is edited in `golden.php`: the Core side only supplies the three things it expects.

| File | Role |
|---|---|
| `replay-core.php` | Starts two `php -S` servers on free **four-digit** loopback ports (the stamped installer carries the server URL, so the recorded installer length depends on the port having four digits), runs `golden.php` against them with `--adapter=adapter-core.php`, stops them. Needs `RIVETCORE_TEST_DB_*` (name must contain `scratch`) and `RIVETCORE_TEST_REDIS_PORT` (throwaway Redis, rate limits go through Core's `Redis\RateLimiter`). |
| `core-router.php` | The router: plays the edition's front controller and bridge files (trusted-proxy TLS decision, `RmmRequest` from the superglobals, `SapiEmitter`, the edition's CORS headers, which the transcripts show on downloads too). The technician endpoints go through the real `RmmModule::technicianApi()` behind a stub access policy (`tests/Support/AllowUsersPolicy.php`; the shared test token is administrator #2). |
| `adapter-core.php` | `reset` / `hook` / `snapshot` for Core: seeds clients, assets, tokens, the fixed signing key and the hosted binaries; snapshots leave out the five migration 0016 module-switch columns of `endpoint_agent_settings`, which RivetIT 2.6.146 does not have. |

```
export RIVETCORE_TEST_DB_NAME=rivetcore_scratch_x RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=... RIVETCORE_TEST_REDIS_PORT=6362
php scripts/rmm-golden/replay-core.php replay        # "replay identical (10 files)"
vendor/bin/phpunit tests/Integration/Rmm/GoldenReplayTest.php
```

The replay runs the module in its compatibility mode: no `RmmModuleStateInterface` is handed to `DeviceApi`, so a switched-off service answers 403 exactly as
transcript `01-disabled.json` records. The new 503 `module_disabled` answer is covered by `tests/Integration/Rmm/DeviceApiTest.php`.


## Intentional deltas from the original recordings (rc.7, CORE-1)

The transcripts were recorded from the original RivetIT code. The CORE-1 security fix (an enrollment token only ever matches, reuses or takes over devices of its own client) changes
exactly one recorded exchange, so five files were re-recorded with `replay-core.php record` (the other five files are byte-identical to the original
recordings, and `replay` over them still proves the original wire protocol). To keep that auditable:

* `tests/Fixtures/rmm/golden-original/` holds the **pre-fix** recordings of those five files (`04`, `05`, `06`, `08`, `09`). It is a sibling directory on purpose: `golden.php replay --dir=...tests/Fixtures/rmm/golden` (RivetIT's replay does this) never sees it.
* `golden-original/expected-deltas.json` lists every exchange (`step:<id>`) and snapshot table (`snapshot:<name>:<table>`) that differs, each mapped to a documented reason.
* `tests/Unit/Rmm/GoldenDeltaTest.php` (no database) fails if the set of differing exchanges/tables is not exactly the declared one, so a new unintended behaviour change cannot hide in a re-recording.

Changed exchange: `04-enroll-flows` step `serial-of-known-device-with-other-department-token`. A Department B token presents the machine_guid and serial of a Department A device. Original: 201 reusing the
Department A device (reinstall, enroll_count 2). Now: 201 with a NEW device of client 2, `pending_approval`, `match_reason = cross_client_identity`; the Department A device is untouched. Status, headers and
body keys are the same. Every other difference (the `<DEVICE#n>` renumbering in `junk-serial-is-ignored`, the extra device in `technician-list` (total 7), the device rows in the snapshots of `04`, `05`, `06`, `09`,
and one more `enrolled` / one fewer `reinstalled` attempt in `04` and `08`) is a knock-on of that one exchange.

To re-record after a future deliberate delta: `php scripts/rmm-golden/replay-core.php record --dir=<tmp>`, inspect every difference, copy over only the files you can explain, keep the old copy in `golden-original/`, and extend `expected-deltas.json`.
