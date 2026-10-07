# Endpoint agent extraction: Phase 0 baseline (task T1)

Produced by task T1 of [endpoint-module-extraction.md](endpoint-module-extraction.md) on 2026-10-07. Nothing here changes behaviour; it records what the RivetIT
endpoint agent does **today** so that T2 to T7 can prove they did not change it.

## 1. Baseline

| Item | Value |
|---|---|
| RivetIT commit | `c26957c0b2e98556dadf2623332bfde442e1f08c` (release `v26.10.26`, agent tag `agent-v0.1.0-beta.1`), reachable from `origin/beta` |
| `origin/beta` when T1 ran | `4ba51d3523cd73b6ea4777fac910a8e993c4356f`: one commit later, it only edits `endpoint-agent/README.md` (2 lines); no frozen path below differs, so the baseline stands |
| RivetCore used by the scratch install | `v1.0.0-rc.3` (what `composer.lock` pins at the baseline), taken from `git archive v1.0.0-rc.3` of `/home/sysadmin/rivet-core` |
| RivetIT DB version on the baseline | `2.6.146` (`includes/database_version.php`) |
| Server stack of the runs | MariaDB 11.8.6, PHP 8.5.11 CLI (`php -S`), Redis (throwaway, port 6360) |
| Scratch install | worktree copy without `.git/.claude/backups/uploads/node_modules/android/docs`, vendor copied read-only from the live tree, `vendor/rivet/rivet-core` replaced by rc.3, `composer dump-autoload --no-dev --optimize`, `scripts/setup_cli.php` from `scripts/` with `</dev/null`, `$config_https_only = FALSE`, `define('EA_ALLOW_INSECURE_HTTP', true)`, `scripts/update_cli.php --update_db` |

## 2. Hashes to hold constant

Gate for T3: the two vector files copied into Core must have **exactly** these sha256 values.

| File | sha256 |
|---|---|
| `tests/fixtures/agent_job_signing_vectors.json` | `9850daf57252afbfc7eee7f9531239b9f8e0e68fb491b56bff5682e94f85deb6` |
| `endpoint-agent/internal/jobs/testdata/agent_job_signing_vectors.json` (Go copy) | `9850daf57252afbfc7eee7f9531239b9f8e0e68fb491b56bff5682e94f85deb6` (identical) |
| `tests/fixtures/agent_installer_trailer_vectors.json` | `8a89e10349425d6370fcb9b56dbd25426b7116f24de5bb34f8b8d25055059304` |
| `endpoint-agent/internal/embed/testdata/agent_installer_trailer_vectors.json` (Go copy) | `8a89e10349425d6370fcb9b56dbd25426b7116f24de5bb34f8b8d25055059304` (identical) |
| `tests/fixtures/generate_agent_job_signing_vectors.php` | `683dc2512f353fada6a7b69f2aadfc113fc360eac70b937fe0e420790d92e139` |
| `tests/fixtures/generate_agent_installer_trailer_vectors.php` | `ef9908b20d8b2b3758433dda606a7f10ac89672ce23ef70855031f1083325c4d` |

The REST bridge files (the five device files plus the technician file and the shared include):

| File | sha256 |
|---|---|
| `api/v1/agent_enroll.php` | `1ce22c2a508a68d3279c708c8d2d23ce1c85031c1baee325f84cfad8cd351764` |
| `api/v1/agent_checkin.php` | `cd06909e3a98da16e7ae7174694500a128cc75421a83ab08e4353b559d0ff356` |
| `api/v1/agent_jobs.php` | `33ddea674f45a7d1abfab66ee3dec61154470b5057e943568d015378be74b94c` |
| `api/v1/agent_update.php` | `630a364137a1b987699fe951673b0e1892bd6587167898245b780225be1ce061` |
| `api/v1/agent_installer.php` | `ba75ce099781b4a4aef6fab6f8b941804919381447cd7d83d939b790418e5441` |
| `api/v1/endpoint_devices.php` (technician) | `50a324fe68aa4a5a498aac4ba65303014136c3fb452dabc53aef17fbcc601bd3` |
| `api/v1/includes/agent_device_api.php` | `10c77310bafada9394cd794a681775b59a59e48e3e7b5835789996ee835b5a80` |

Other anchors: git tree of `endpoint-agent/` at the baseline `13e946bbf4d69fa348d09a88367ac0243cef80fb` (94 tracked files);
`db.sql` lines 8007 to 8240 (endpoint tables block, header comment says "DB 2.6.145" but it holds the 2.6.146 shape) sha256 `0c414ac3a7af337b53f5aae9991e51de96b0999581a40e7329668be389dee003`;
`admin/database_updates.php` lines 10116 to 10365 (the two blocks that create and then extend the tables) sha256 `68ece6cf4e7f20735d869d9610a174e33d68a3143caa53042f4618733f92ed33`.

## 3. Paths to freeze (no edit before T7, and in T7 only the way the design describes)

Until the owner tags Core rc.4 and T7 runs, these paths of the **RivetIT** repo must not change. After T7 the golden transcripts and the 7 test files must still pass **unchanged**.

* `src/EndpointAgent/**` (20 classes), `api/v1/agent_{enroll,checkin,jobs,update,installer}.php`, `api/v1/endpoint_devices.php`, `api/v1/includes/agent_device_api.php`
* `api/v1/index.php` lines that route the device endpoints above the Bearer parsing (the `if ($resource === 'agent_enroll' || ...)` block)
* `endpoint-agent/**` (Go agent, 94 files), `.github/workflows/endpoint-agent.yml`
* `tests/endpoint_agent_*.php` (7 suites + `endpoint_agent_lib.php`), `tests/fixtures/agent_*vectors*.json` and their generators, `tests/mock/mock_meshcentral.php`, `tests/load/agent_ingest_load.php`, `tests/mobile_api_router.php` (used by the harness)
* `db.sql` lines 8007 to 8240; `admin/database_updates.php` blocks gated `== '2.6.144'` (creates the tables, sets 2.6.145) and `== '2.6.145'` (sets 2.6.146); `includes/database_version.php` until the new `2.6.147` step is added
* `admin/settings_endpoint_agent.php`, `admin/post/settings_endpoint_agent.php`, `agent/rmm_agent_device.php`, `agent/post/rmm_agent.php`, `scripts/endpoint_agent_publish.php`, `docs/ENDPOINT_AGENT.md`, `docs/ENDPOINT_AGENT_BUILD.md`
* Wire constants (never change): the list in design section 4 (caps, rate limits, token/credential formats, canonical JSON, `RIVETIT-EMBED-v1`, `rivetit_agent`)

Per-file sha256 at the baseline:

PHP domain classes
| Path | sha256 |
|---|---|
| `src/EndpointAgent/Actions.php` | `f6deb8cb31acf09098afbb879aa4dbc7923229283632bc775e5ece83d116c23a` |
| `src/EndpointAgent/ApiError.php` | `300a2d0d0606505905aee87b0361d93c8e3c51c4d6d11fa6fffcd7e330ae1148` |
| `src/EndpointAgent/Authz.php` | `740482a53e8ea18e9bf4123adfe91b68dedae95ef8e573e7eb5813547c0f0b77` |
| `src/EndpointAgent/Binaries.php` | `a87452214d826f645866e22bdad7c76a8d1413f457cd883b32b09463fa0beb64` |
| `src/EndpointAgent/Checkin.php` | `12afe98ae127ae3dc2762a1879c7f9a411768e1128619e765fe3413e575309b4` |
| `src/EndpointAgent/Checks.php` | `63b7772f274bf021f91c609fca5b95f220e1f660d4c705d5328b241b4390651a` |
| `src/EndpointAgent/Config.php` | `e3446cf1afce59c37a8dab410304e993664e2ff0e87232351690f3fb2dc03a4c` |
| `src/EndpointAgent/Db.php` | `506f48257712d5bd3775d34c8218be4b2465abbaeaf8262998bb9758dbe48ee2` |
| `src/EndpointAgent/Devices.php` | `c3937ab4b4224e3f288b9fce121859ca687194dd1eb33becb917d976eacd5a86` |
| `src/EndpointAgent/Enrollment.php` | `261218bc020002de266433efb56c9369b7b37b21fd926073d5a0f8a53c465fbc` |
| `src/EndpointAgent/Installer.php` | `73cc353cd3322271ad1349d5bf8f38269197f6b1abfd45e9c494598e95592b04` |
| `src/EndpointAgent/InstallerStamp.php` | `c8d98490a9c83e0425c80d488554eb25d805d4a7ac3b5f8143650c5793686b19` |
| `src/EndpointAgent/Jobs.php` | `88f3efba5bd7e1d128c4ccb4139243b1e803ff746906879a79d02fdf2a4c31e6` |
| `src/EndpointAgent/Link.php` | `9c5f6a265bb81d3e64cce1ef32cce8f35110e871f824832d92abad82a4ada834` |
| `src/EndpointAgent/Maintenance.php` | `34c299cebfc02446365a0c29d94108be47fbdfe5db46afafb6e6167d95cf02af` |
| `src/EndpointAgent/Mesh.php` | `17d60bd73732d38a3c7310e2838106fedebb5747229f781e1d9ed5c0cb21adb7` |
| `src/EndpointAgent/Redactor.php` | `e203688e098ae016492cc0a66ee783fb132691b1217e5b3aad2aa55c51fd70bd` |
| `src/EndpointAgent/Signer.php` | `c88e7bf9e8af6edde017a5e93a906d32e160be3d1d1f4f23792b15d2fe62e19b` |
| `src/EndpointAgent/Updates.php` | `13e1e3ebe8671c65c84cdb9d999a3de88694ae2da2b55d530842aee955d3be27` |
| `src/EndpointAgent/View.php` | `a93bc89ec38ecd011a8ed16c581f06a7f8f2d1662efbb69c066a913d67cb3f7b` |

Tests, mocks, harness
| Path | sha256 |
|---|---|
| `tests/endpoint_agent_authz.php` | `bad7c301ee53bc5977005a40e2aa401cb09f744b49bbb9cef53258a5fa0aea53` |
| `tests/endpoint_agent_checkin.php` | `7715e419a08846d1ce697b68e0de8f23f776aaa6a3bc1740feca158de779ac5e` |
| `tests/endpoint_agent_deploy_http.php` | `dafe6f1040dec28b0170774ec84e546080411cd3258f9de11ac69276f6442fcd` |
| `tests/endpoint_agent_deploy_unit.php` | `d5aac32654e7968c525917a44b8f4ef7f326ec62e7c3eb442f8a0f371a28d83d` |
| `tests/endpoint_agent_enroll.php` | `bd475c59d973aa900f9f1065385a513a0530f208eedccb1c27f9081414b7985e` |
| `tests/endpoint_agent_jobs.php` | `4cf0f123ffad564872cc50b770f030e70a72d6822a404e5f193e72004fbae14b` |
| `tests/endpoint_agent_lib.php` | `973bd6235657011a17313a67ab96aad96b7637d93beefa3802827c7b95155c91` |
| `tests/endpoint_agent_migration.php` | `9beed4aac025e171a4cb109e242003935f653e9c52a549469f38f429cb8559eb` |
| `tests/mock/mock_meshcentral.php` | `c0d6beb88b392f509fa4db002930dce60831980463d4a9365c7b6ae256b4dfc3` |
| `tests/load/agent_ingest_load.php` | `5e30c2c361c30c35c3c144f933b93bf23b1175cea237b5edaf8da6faa06054d9` |
| `tests/mobile_api_router.php` | `781807f033dc401a00031bf85990f97db23a1edcf9c16904f858b2930be7da0c` |

UI, scripts, docs, workflow
| Path | sha256 |
|---|---|
| `docs/ENDPOINT_AGENT.md` | `e9003e46f9762f464596d973b70e97d3522a6347cf6fbaee5208a9bcc9631f02` |
| `docs/ENDPOINT_AGENT_BUILD.md` | `256437660caec4882a2cd577f74daab942791a9623b69e9be55fed2c01e3b341` |
| `.github/workflows/endpoint-agent.yml` | `c6d5f8010c9223f3992b37cb794f7a63af6a436dd934a7822e097f0d29cbc8fc` |
| `scripts/endpoint_agent_publish.php` | `2289e22933d6f01db11a86ec5695d1bfb35487ca87d5a7715a37061f674567a5` |
| `admin/settings_endpoint_agent.php` | `a705840371275c3b87239a3259c341cb75c3c078c3920410a821073702beff78` |
| `admin/post/settings_endpoint_agent.php` | `7741f7fbc937569d8c4e79060b4408ccc60f3ed72603b283050ce10d507145da` |
| `agent/rmm_agent_device.php` | `9ec7bc3557384dd1d2154caba93289d747d77bfa7ab9f7ff5e2053afe52d5d25` |
| `agent/post/rmm_agent.php` | `dad17a71facf5826892aa8186d2a2abd49c0098785cd9483354b794f9fb5d90c` |

## 4. The 7 existing RivetIT suites on the baseline

Run on the scratch install of section 1 against scratch database `scratch_rmmt1` with `RIVETIT_TEST_DB=1 RIVETIT_TEST_DB_NAME=scratch_rmmt1 RIVETIT_TEST_DB_USER=... RIVETIT_TEST_DB_PASS=... RIVETIT_REDIS_PORT=6360`, one after another (each wipes the endpoint tables and starts its own `php -S` servers).

| Suite | Result |
|---|---|
| `tests/endpoint_agent_migration.php` | 63 passed, 0 failed |
| `tests/endpoint_agent_deploy_unit.php` | 99 passed, 0 failed |
| `tests/endpoint_agent_enroll.php` | 64 passed, 0 failed |
| `tests/endpoint_agent_checkin.php` | 78 passed, 0 failed |
| `tests/endpoint_agent_jobs.php` | 75 passed, 0 failed |
| `tests/endpoint_agent_authz.php` | 151 passed, 0 failed |
| `tests/endpoint_agent_deploy_http.php` | 163 passed, 0 failed |

693 assertions, all green, exit code 0 for every suite. Requirements found while running them: a scratch database with `scratch` in its name and the user's credentials in `RIVETIT_TEST_DB_*`; a `config.php` pointing at the same database and defining `EA_ALLOW_INSECURE_HTTP`; a Redis on `RIVETIT_REDIS_PORT` for the rate-limit parts (the guard fails open without it); `ext-sodium`; the suites are destructive for the endpoint tables and the test users/assets (`ea_reset()`), so they must never see a live database. `endpoint_agent_migration.php` replays the updater (`scripts/update_cli.php --update_db`) from 2.6.144 and 2.6.145 on the same database and needs the install directory to be writable.

## 5. Golden transcripts

`tests/Fixtures/rmm/golden/*.json`, recorded by `scripts/rmm-golden/golden.php` (how to record/replay and the complete masking table: `scripts/rmm-golden/README.md`).
Recorded twice into separate directories: byte-identical; replayed twice against the live scratch server: "replay identical (10 files)" both times (about 18 s per run).

Scenario coverage requested by T1 and where it is: enroll, re-enroll, reinstall by machine_guid and by serial, install-id conflict, scope mismatch (`04`); check-in with duplicate seq, buffered samples, check debounce and alerts, signed update manifest (`05`); job offer, report, idempotent replay, redaction, truncation, cancel (`06`); hosted update download and its 404s (`07`); installer download and its 404s (`08`); 401 / 403 / 405 / 409 / 413 / 422 / 426 / 429 across all of them (`01` to `09`).

Masking rules, short form (the table with every key is in the README): ids and device tokens become stable aliases in order of first appearance (`<DEVICE:dev2>`, `<TOKEN#3>`, `<ASSET:linkable>`, `<UUID#7>`); RFC 3339 and database datetimes become `<TS>` / `<DT>`; Ed25519 signatures are **verified** against the fixed test public key and replaced by `<SIG:ok:job|check|manifest>` (the run fails if one does not verify); `Date`, `Host`, `X-Powered-By` and JSON `Content-Length` header values are masked, all other header names and values are kept exactly and stored sorted; downloads are reduced to length, hash (not for installers, which embed a per-download id and time) and a comparison against the published fake binary, plus the parsed installer trailer; strings over 2000 characters are replaced by their length and hash.
The instance signing key is the committed test key (seed `SHA-256("RivetIT-agent-TEST-seed")`, public key `iMb1UVs5sfHsmc8uRi4z7vPgUMwAmKcMd2dCQj8E5BE=`), installed into the scratch database by the adapter, so every signature that does not depend on time is itself stable.

## 6. Schema dumps

| Artifact | What |
|---|---|
| `tests/Fixtures/rmm/schema/endpoint_tables_final.json` | `information_schema` (engine, collation, columns with type / nullability / default / extra / charset / collation / key, indexes with uniqueness and column order and sub-part, constraints) of the 10 tables at DB 2.6.146 |
| `tests/Fixtures/rmm/schema/ddl/*.sql` | `SHOW CREATE TABLE` of the same 10 tables (AUTO_INCREMENT counters removed) |
| `tests/Fixtures/rmm/schema/endpoint_tables_2_6_145.json`, `ddl-2_6_145/*.sql` | the schema after only the `2.6.145` step: **9 tables** (no `endpoint_agent_binaries`), `endpoint_agent_releases` without `arch`/`binary_id` and with `uniq_version_ring`, `endpoint_agent_settings` without `ca_pem` |

Reproducibility: the final dump is byte-identical when taken from (a) a database freshly installed from `db.sql` and brought to 2.6.146 by the updater, (b) the same database after dropping the 10 tables, resetting the version to 2.6.143 and letting the updater recreate them (2.6.144 block, then 2.6.145 block), and (c) the database the 7 suites had just used. The 2.6.145-only dump was produced by (b) with the 2.6.145 -> 2.6.146 block disabled in a throwaway copy of `admin/database_updates.php` (the copy lives in `/tmp`, the repo file is untouched). Regenerate with `scripts/rmm-golden/dump-schema.php`.

Why (b): a fresh `scripts/setup_cli.php` install loads `db.sql`, which already contains the endpoint tables in their final shape but records DB version `2.6.100`; the updater then walks 2.6.100 -> 2.6.146 and the table steps are no-ops. The historical 2.6.145 shape therefore cannot come from a fresh install without dropping the tables first.

## 7. Deviations from the design assumptions

1. **2.6.145 has 9 tables, not 10.** `endpoint_agent_binaries` first appears in the step that produces 2.6.146 (block gated `== '2.6.145'`), together with `endpoint_agent_settings.ca_pem`, `endpoint_agent_releases.arch`, `.binary_id` and the `uniq_version_ring_arch` index. Design 3.1 describes migration 0015 as only adding those columns/index; an install that stopped at 2.6.145 also lacks the **table**. Migration 0014 (`CREATE TABLE IF NOT EXISTS` for all 10) covers it, but `SchemaDiffTest` schema C (the "2.6.145 upgraded" fixture) must be built from the 9-table dump above, not by retyping, and its expectation "C has 10 tables after 0014+0015" is correct only because 0014 creates the 10th.
2. **Step naming.** In `admin/database_updates.php` the block gated `== '2.6.144'` is what the design calls "step 2.6.145" (it sets the version to 2.6.145), and the block gated `== '2.6.145'` is "step 2.6.146".
3. **Fresh installs.** `db.sql` carries the final tables but version 2.6.100, so on a fresh install the two table steps never create anything; both paths end byte-identical (section 6).
4. **Endpoint list is as designed**: five device files, one technician file, shared include; 20 classes in `src/EndpointAgent/`, 94 files in `endpoint-agent/`, 7 suites plus the harness. Extra thing worth knowing: technician `POST /endpoint_devices/{id}/jobs` answers `201 {"job_id","state":"queued"}`, cancel answers `200 {"ok":true}`.
5. **Header sets** (exact, from the transcripts). JSON responses of the device endpoints: `access-control-allow-headers/-methods/-origin` (added by `api/v1/index.php`), `cache-control: no-store`, `content-type: application/json`, plus `connection`, `date`, `host`, `x-powered-by` from `php -S`; 405 adds `allow`; 429 adds `retry-after` (`600` for enrollment and installer, `60` for the per-device buckets). Binary downloads (update, installer) carry **no** `access-control-*`; they send `cache-control: no-store`, `content-disposition: attachment; filename="..."`, `content-length`, `content-type: application/octet-stream`, `pragma: no-cache`, `x-accel-buffering: no`, `x-content-type-options: nosniff`. Technician responses (`api_response`) have the CORS headers but **no** `cache-control`. Core's `SapiEmitter` must reproduce the device sets; the CORS headers stay the edition's job.
6. **Authentication order.** Device endpoints authenticate before the module-switch check: an unknown bearer gets 401 `invalid_token` even when the service is disabled, a valid one gets 403 `forbidden` (`Devices::authenticate`); enroll and installer check the switch (403) after the method check (405) and before the body.
7. **Small behaviours the transcripts now pin**: an empty JSON array body to `agent_enroll` is 422 "enrollment_token and device are required" (not "A JSON object body is required"); the reinstall lookup is by `machine_guid`, then `serial`, and **ignores the token's department** (a Dept B token with the serial of a Dept A device returns the Dept A device and credential); `status: linked` is only returned for a match inside the token's department (scope mismatch -> `pending_approval`, `match_reason scope_mismatch` visible in the technician detail); a duplicate check-in `seq` returns the full normal 200 body.
8. **Rate limits**: enrollment and installer budgets are database-backed (reproduced with the DB alone); the per-device check-in (40/60 s), jobs (120/60 s) and update (60/60 s) buckets are Redis-backed through `rivetRateLimit()` and **fail open** when Redis is down. The existing 7 suites never assert the per-device 429s; the golden transcripts do (`09`), so a Core `DeviceApi` with a rate-limit closure is now testable against them. They need a Redis during recording and replay.
9. **Concurrent edits seen under `/home/sysadmin/rivet-core`** while T1 ran (other tasks writing `src/Rmm/*`, `endpoint-agent/`, `docs/rmm/`, `tests/Fixtures/rmm/schema/ddl-reference/`, tracked-file edits): T1 wrote only the paths listed in section 8 and did not touch those.
10. No Go toolchain was needed or used (T1 is PHP only).

## 8. Files written by T1

* `docs/design/endpoint-baseline.md` (this file)
* `tests/Fixtures/rmm/golden/{_meta,01-disabled,02-tls,03-enroll-errors,04-enroll-flows,05-checkin,06-jobs,07-update,08-installer,09-rate-limits}.json`
* `tests/Fixtures/rmm/schema/endpoint_tables_final.json`, `endpoint_tables_2_6_145.json`, `ddl/*.sql` (10), `ddl-2_6_145/*.sql` (9)
* `scripts/rmm-golden/{golden.php,adapter-rivetit.php,constants.php,dump-schema.php,README.md}`
