# RMM capacity, module switch and load control

This is the operations guide of the RMM module's "compute heavy" side: how the module is switched on and off at zero cost, which limits exist and
what they default to, how the server protects itself under load (shedding, queued ingest), how to read the capacity panel, how to size an install, and
the measurements taken so far. The plan and the targets (5,000 devices supported in beta, 10,000 proven before the RMM leaves beta) are in
[SCALING.md](SCALING.md); the model and the design are section 12 and 13 of `docs/design/endpoint-module-extraction.md`.

Status: Phase 0 (Phase 1 and Phase 3 additions in 11 and 8.4). The numbers in "Measured so far" come from a single scratch host and `php -S`, not from PHP-FPM on the reference hardware; they are a
floor and a regression baseline, not a capacity promise. The validation on the reference hardware (S9) is planned at the end of this file.

## 1. The module switch

The RMM is a module that can be enabled or disabled. It is **off by default for a new install** and **unchanged for an existing RivetIT install**: the
existing master column `endpoint_agent_settings.enabled` is the switch, the migration never touches it, and nothing is inferred from the rows (an admin
who switched the module off on purpose must not be switched back on by an upgrade).

| Level | Switch | Notes |
|---|---|---|
| Edition kill switch | `RmmModuleStateInterface::editionAllows()` | RivetMSP: `settings.config_core_rmm_enabled`. RivetIT: always true. Must not throw; false on any failure |
| Master | `endpoint_agent_settings.enabled` | Administration > RMM > "Enable RMM module". Disabling deletes nothing |
| Sub-switches | `endpoint_agent_settings.features_json` | `monitoring`, `metrics`, `jobs`, `remote`, `updates`, later more. `NULL` = the legacy defaults (monitoring, metrics, jobs, updates on; remote follows `mesh_enabled`) |

Effective state: `enabled = editionAllows() AND master`; a feature is active when `enabled` and its sub-switch is on. In code: `RmmModule::enabled()` and
`RmmModule::featureOn($name)` (state file first, database second, cached for the request).

### 1.1 What a disabled module costs

| Surface | While the master is off |
|---|---|
| Device REST (`agent_enroll`, `agent_checkin`, `agent_jobs`, `agent_update`, `agent_installer`) | `503`, `Retry-After: 3600`, `Cache-Control: no-store`, `Content-Type: application/json`, body `{"error":"The RMM service is disabled on this server.","code":"module_disabled"}`, answered by the **pre-bootstrap gate**: no database connection, no query, no Core class loaded |
| Technician REST (`endpoint_devices`) | not answered by the gate (that would tell an anonymous caller whether the module is on). The edition authenticates first (401), then `TechnicianApi` answers `404 {"error":"The endpoint agent is not enabled.","code":"disabled"}`, as RivetIT always did; the cost is the edition's normal token lookup |
| One sub-switch off | `agent_jobs` (jobs off) and `agent_update` (updates off) answer `503 feature_disabled` with `Retry-After: 3600`; the check-in response omits `update` and reports `jobs_pending: 0`; metrics off drops sample ingest; monitoring off skips check evaluation and the link health. Check-in itself keeps working so devices stay online for whatever remains |
| Cron | the edition's cron block reads the state file first and skips the autoload and every query when it says off |
| Job queue | `rmm.*` job handlers are registered but **release** the job (back to pending, no attempt spent, claimable again after 60 s) instead of failing it, so queued work survives a disable and is processed after the re-enable. Core's worker would otherwise dead-letter a job with no handler |
| Data | nothing is deleted or altered: devices, tokens, jobs, checks, binaries, the signing key and queued jobs all stay. The same device tokens work again after the re-enable |
| Link status | edition link rows are **not** flipped to offline while the module is off (that would fire `asset_offline` for every device because an admin flipped a switch). The first maintenance run after the re-enable computes true statuses |

Agents: an un-updated agent already treats `503` as transient and backs off to once per hour (`Retry-After` is a floor, capped at one hour); the current
agent has an explicit `module_disabled`/`feature_disabled` branch: it keeps its credential and buffer, does not count the answer as an error, and waits at least
15 minutes with full jitter. This is the only intentional wire-visible change of Phase 0 (a disabled server used to answer 403 after a database lookup).

### 1.2 The state file (the contract between Core and the edition)

Core mirrors the switch into a small JSON file so that a gate or a cron entry can answer **without opening the database**.

* Location: `<RmmModuleStateInterface::stateDirectory()>/rmm_state.json`. The directory must be writable by the web user and **shared by all web nodes**; with
  several nodes and no shared directory return `null` and accept the one primary-key SELECT per request. With `null` there is no file, no gate and no request-path
  shedding evaluation; everything else works.
* Writer: `RmmStateFile::write()`, called by `RmmSettings::set()` (and so by `update()`, `enable()`, `disable()`) after every write of `enabled`, `features_json`,
  `limits_json`, `shed_level`, `ingest_mode`, `mesh_enabled` or `max_devices`, and by the load shedder on every transition. It is atomic (temporary file in the
  same directory, then `rename`), creates the file with mode `0640`, never throws (an unwritable directory just returns false) and does not touch the file when
  nothing but the timestamp would change. **The edition must call `RmmModule::syncState()` when it changes ITS OWN kill switch** (RivetMSP: when
  `config_core_rmm_enabled` is saved), because Core cannot observe that flag.
* Reader: `RmmStateFile::read()` returns the validated state or `null`. The file is **data, never code**: it is read with `file_get_contents` and `json_decode`
  and never included (the design draft said a PHP `return [...]` file; JSON was chosen so that a damaged or tampered file cannot execute or print anything, at the
  cost of a small read instead of an opcache hit).
* **Fail-safe rule.** A missing, unreadable, empty, garbled, wrong-type or wrong-version file is **"unknown", never "off"**. Every reader proceeds on the normal path
  (the settings row), and the first such request rewrites the file. Only a well-formed version-1 file that says `enabled: false` turns anything away.
  `RmmStateFile::enabled($dir)` returns false only in that case. A stale file can delay a switch only until the writer runs, which is in the same request as the
  change; somebody editing the settings table behind Core's back must call `syncState()` (or save a setting).
* Schema v1: `{"v":1,"enabled":bool,"edition":bool,"master":bool,"features":{name:bool},"shed":0..3,"shed_at":int,"shed_retry":[min,max],"retry_after":3600,"ingest_mode":"sync|queued","limits":{name:int},"written_at":int}`.
  `enabled = edition AND master`. A change of the version number makes older readers answer "unknown", which is the safe direction.
* `shed_at` is when the current shed level was set or last re-confirmed; the gate ignores a level older than **180 s**, so a stalled evaluator can never wedge
  the fleet in refusal.

### 1.3 The gate (what an edition adds)

Copy `docs/rmm/templates/rmm_gate.php` to the edition's `api/v1/rmm_gate.php` and make it the **first** statement of the REST entry point, before `config.php`,
before any database connection, before Composer's autoloader:

```php
define('RMM_GATE_STATE_DIR', '/var/lib/yourapp/rmm');   // the directory returned by stateDirectory()
require __DIR__ . '/rmm_gate.php';
// ... then config.php, db.php, routing as before
```

The gate matches the path against the five device endpoints (with or without `.php`, trailing slash or query string), reads the state file and
either answers and exits (module off: the exact 503 of section 1.1; shed level 3 on `agent_checkin` only: `503 {"code":"unavailable"}` with a jittered
`Retry-After` from the file's shed window) or returns and lets the request run. It never emits CORS headers (device agents are not browsers). Its reader mirrors
`RmmStateFile::read()`; `ModuleSwitchTest` keeps the two in step with a corpus of damaged files.

Cron: `if (!RmmStateFile::enabled($stateDir)) { /* skip: no autoload, no query */ }` before `require vendor/autoload.php`. Navigation and pages: call
`RmmModule::enabled()` (a file read). Nothing in an edition needs to load a Core class to know the module is off.

Proof of zero cost: `ModuleSwitchTest::testTheGateAnswersTheDisabledModuleWithTheExact503AndZeroDatabaseWork` serves the gate from a real `php -S`, sends more than
80 requests of every method and path shape and compares the database server's own `Connections` and `Com_select` counters before and after (delta zero); a canary
request with the module on proves the counters can see a violation. It needs a database server that is idle apart from the test, i.e. a private scratch server
(the test reports itself incomplete instead of guessing on a busy shared one). A second proof is in-process: with a valid state file a fresh module answers
`enabled()` and `featureOn()` with zero statements on a counting `DatabaseInterface`.

## 2. Limits and their defaults

Limits live in `endpoint_agent_settings.limits_json` (a JSON object of whole numbers; unknown keys and out-of-range values refuse the whole save, nothing is written)
and in a few columns. Defaults below are what an unset key means; `RmmSettings::limits()` fills them in.

| Setting | Where | Default | Range | Meaning |
|---|---|---|---|---|
| `max_devices` | column | 0 (unlimited; 500 suggested on new installs) | 0 to 1,000,000 | Enrollment refuses beyond it: `403 device_limit`. Revoked and retired devices do not count |
| `check_in_interval_s` | column | 300 | 60 to 3600 | Clamped on save; the server tells agents (`next_check_in_s`). Agents add +/-10 % jitter and a random start delay |
| `collect_interval_s` | column | 60 existing installs, 300 recommended | 30 to 3600 | How often the agent samples; the check-in carries the batches in between (buffer cap 100) |
| `retention_days` | column | 30 existing, 7 recommended | 1 to 400 | Retention of the Core-owned `endpoint_agent_*` rows; the edition's metric tables follow their own retention |
| `ingest_mode` | column | `sync` | `sync`, `queued` | See section 4 |
| `max_checkins_per_min` | limits | 0 (off) | 0 to 1,000,000 | Global check-in counter (the edition's rate limiter closure). Over it: `503` with `Retry-After` between `retry_after_min_s` and `retry_after_max_s`. Suggested 4 x devices / interval x 60 |
| `retry_after_min_s`, `retry_after_max_s` | limits | 30, 120 | 1 to 3600 | Jitter window of the over-the-limit 503 |
| `shed_retry_min_s`, `shed_retry_max_s` | limits | 60, 300 | 1 to 3600 | Jitter window of a level-3 refusal |
| `shed_backlog_l1/l2/l3` | limits | 500, 2000, 8000 | 0 to 10,000,000 | Pending `rmm.ingest` jobs that raise shed level 1, 2, 3 (0: that level never triggers on this signal) |
| `shed_db_ms_l1/l2/l3` | limits | 100, 300, 1000 | 0 to 60,000 | Database probe latency in milliseconds (median of three primary-key SELECTs) |
| `shed_rate_per_min` | limits | 0 (off) | 0 to 10,000,000 | Check-ins accepted in the last minute: level 1 at 1x, level 2 at 2x, level 3 at 4x this number |
| per-device rate limits | frozen | check-in 40/60 s, jobs 120/60 s, update 60/60 s | | Part of the wire contract |
| body caps | frozen | check-in 1 MiB, enroll 16 KiB, job report 256 KiB, installer 4 KiB | | Part of the wire contract |

Thresholds of a family must not decrease from level 1 to level 3 (zeros are ignored in that check).

### 2.1 Profiles and "Reduce load" presets

| Profile | Check-in | Collect | Raw retention | Features | For |
|---|---|---|---|---|---|
| **Defaults** | 300 s | 60 s | 30 days | `NULL` (legacy) | what an existing install has today |
| **Recommended** | 300 s | 300 s | 7 days | monitoring, metrics, jobs, updates | what a new install gets |
| **Light** | 600 s | 600 s | 7 days | monitoring and updates only | monitoring-only fleets, small hardware |

`CapacityReport::profiles()` returns each as a patch for `RmmSettings::update()`. A preset is a **patch, never applied automatically**; the panel shows it with the
before and after projection and asks for confirmation. `CapacityReport::presets()` is a pure function of the current settings: *Lengthen check-in to 600 s*, *Collect
every 300 s*, *Metrics off (monitoring only)*, *Raw retention 7 days*, *Enable queued ingest*, *Cap check-ins per minute at 4x the steady rate*. A preset that would change
nothing comes back with `applicable = false`. Presets change settings only; they never delete data. Note what the model says honestly: lengthening only the check-in interval
halves the requests and the statements but, with the collect interval unchanged, each check-in then carries twice the sample batches, so the sample rows barely drop; the
collect interval and the raw retention are what shrink storage.

## 3. Load shedding

`Capacity\LoadShedder` watches three cheap signals and sets a level 0 to 3, stored in `endpoint_agent_settings.shed_level` (and mirrored into the state file):

| Signal | Source | Cost |
|---|---|---|
| Queue backlog | pending `rmm.ingest` jobs in `integration_jobs` | one grouped COUNT |
| Database latency | median of three `SELECT id FROM endpoint_agent_settings WHERE id = 1` | three trivial queries |
| Check-in rate | rows of `endpoint_agent_checkins` received in the last 60 s (`idx_received`) | one indexed COUNT |

| Level | What the server does | Never shed |
|---|---|---|
| 0 | normal | |
| 1 | **drop optional samples**: buffered backlog samples older than 15 minutes are acknowledged but not ingested; **log ingest stops** (`agent_logs` answers `503 unavailable` with a jittered `Retry-After`, and the check-in no longer offers `logs:collect`, so agents stop reading and keep their bookmarks) | enrollment, job reports, revocation, file transfer |
| 2 | **lengthen intervals**: `next_check_in_s` is doubled (capped at 2 x 3600) in every check-in response; agents obey it | enrollment, job reports, revocation |
| 3 | **refuse new work**: `agent_checkin` answers `503 unavailable` with a jittered `Retry-After` (`shed_retry_min_s` to `shed_retry_max_s`) before any other work, at the gate when there is one; agents keep their buffer and retry | enrollment, job reports, revocation, `agent_jobs`, `agent_update` |

Escalation is immediate (the level jumps straight to the raw level the signals call for). **Hysteresis**: recovery steps down **one level after two consecutive
evaluations** whose raw level is below the current one, so a flapping signal cannot oscillate the fleet and a herd returning after an outage is let in gradually
(L3 to L0 takes six healthy evaluations). The streak is kept in `rmm_shed.json` beside the state file; without a state directory each healthy evaluation steps down.
Every transition is audited (`RMM Load Shed Level Changed`).

Evaluation: the maintenance cron calls it through `Housekeeping::run()` (result key `shed_level`), and the device request path calls `LoadShedder::tick()` before the
level-3 check, which evaluates at most once per 10 s (a non-blocking lock in the state directory keeps concurrent requests from evaluating together). A level-3
refusal therefore cannot persist longer than ten seconds after the load is gone, as long as devices keep calling.

The rate signal measures check-ins **accepted** in the last minute, so a level-3 refusal empties its own window: expect a sawtooth (shed, quiet, recover one level per
two evaluations, shed again) if the threshold is below the steady rate. Set `shed_rate_per_min` above the steady rate you want to protect (for example 3x), or
leave it at 0 and let the backlog and database signals decide.

## 4. Queued ingest

`ingest_mode = queued` (default `sync`) moves the heavy part of a check-in off the web request. The request validates, stores the idempotent `(device_id, seq)` row and
the cheap device-state writes, and enqueues **one** `rmm.ingest` job on Core's `JobQueue` (priority -10, below interactive jobs) **in the same transaction as the
check-in row**, so a duplicate delivery can never enqueue twice and a rolled-back request leaves no job. The response is the same shape as in sync mode. A payload above
60,000 bytes (a very large inventory) is processed inline.

The job does what the inline path does, in this order: inventory apply and the edition's asset blanks, sample building, link health, check evaluation, and **last** the
sink write. Two ways to run it (they can be combined):

* `$module->registerHandlers($jobWorker)` registers the handler on Core's generic `JobWorker` (one job per call; time budget 120 s per job).
* `$module->ingestQueue()->drain()` claims up to **50** due `rmm.ingest` jobs and delivers the samples of all of them in **one** sink call (batch merging). Run it from the
  edition's cron in a short loop (every few seconds is fine; one pass of 50 check-ins takes tens of milliseconds).

Retries are safe: every step before the sink is idempotent, a failed sink call stores nothing and the job is retried with the queue's backoff (1, 5, 30, 120 minutes;
5 attempts, then dead letter, which the capacity panel counts and warns about). The one residual window is a worker that dies after the sink returned and before the job
was marked completed: that check-in's samples can be stored twice (identical points), and a replayed check result advances its debounce counter once more, which can at worst
open an alert one check-in early. Jobs of a device that was revoked meanwhile finish as skipped.

Queued mode costs one extra row write and a few statements per check-in, and is only worth it above roughly 1,000 devices or on slow disks (see the measurements: it
shortens the request by about a quarter and moves the bursty writes to the worker). The backlog (`IngestQueue::backlog()`: pending, running, dead letters, oldest
pending age, average ingest latency over the last 200 jobs) is what the shedder and the panel read.

## 5. The capacity panel (`RmmModule::capacity()->build()`)

The data of Administration > RMM > "Performance and capacity"; the edition renders it. Read-only; the presets are the only thing that changes settings and only after confirmation.

| Block | Fields | How to read it |
|---|---|---|
| `devices` | total, active, online, offline, stale, never, revoked, retired, pending_approval | active = not revoked and not retired. Online: checked in within `offline_after_s`; offline: later; stale: later than `stale_after_s` or never |
| `checkins` | last_minute, last_hour, per_minute_limit | the live rate, from `endpoint_agent_checkins` |
| `shed` | level, signals, evaluated_at, thresholds | what the shedder saw at its last evaluation |
| `queue` | pending, running, dead_letter, oldest_pending_age_s, avg_latency_s | a healthy queue has `oldest_pending_age_s` under a few seconds; above 300 s the worker is not keeping up |
| `tables` | rows, data and index MB of every `endpoint_agent_*` table and of `integration_jobs` | information_schema estimates, not counts. The edition's metric tables are the big ones and are listed by the edition |
| `settings`, `projection` | the model of 13.2 filled with the live settings | projected check-ins/s, rows/s, statements/s, workers busy (average and peak), raw storage per month and steady state at the current retention |
| `warnings` | device limit at 80 %, collect interval under 60 s with more than 200 devices, queue age over 5 min, dead letters, shed level above 0, Redis recommended above 1,000 devices (only when the edition says Redis is absent), raw retention above 14 days with more than 500 devices, projected storage above the budget, queued ingest recommended above 1,000 devices | |
| `presets` | id, label, applicable, patch, before, after | each patch is a valid `RmmSettings::update()` input |

## 6. Sizing calculator

Inputs: devices D, check-in interval C (s), collect interval K (s), metrics on or off, raw retention R (days).

```
check-ins per second   = D / C
batches per check-in   = max(1, round(C / K))
sample rows per check-in = batches x 7                      (0 with metrics off; about 7 per batch: cpu, memory, 1 to 3 disks, rx, tx)
rows per check-in      = 7 + sample rows                    (check-in row, device updates, link; the model's base)
statements per check-in = 12 + (metrics on ? 7 + batches : 0)
rows/s = check-ins/s x rows per check-in        statements/s = check-ins/s x statements per check-in
PHP workers busy       = check-ins/s x 0.15 s   (peak: x 3, jitter and restarts)
raw storage per month  = sample rows x check-ins/s x 86,400 x 30 x 120 bytes
steady raw storage     = sample rows x check-ins/s x 86,400 x R x 120 bytes
```

| Devices | Profile | Check-ins/s | Rows/s | Statements/s | PHP workers (peak) | Raw per month | Steady at retention |
|---|---|---|---|---|---|---|---|
| 500 | Defaults | 1.7 | 70 | 40 | 0.25 (0.75) | 18 GB | 18 GB (30 d) |
| 5,000 | Defaults | 16.7 | 700 | 400 | 2.5 (7.5) | 181 GB | 181 GB (30 d) |
| 5,000 | Recommended | 16.7 | 233 | 333 | 2.5 (7.5) | 36 GB | 8.5 GB (7 d) |
| 10,000 | Recommended | 33.3 | 467 | 667 | 5 (15) | 73 GB | 17 GB (7 d) |
| 5,000 | Light | 8.3 | 58 | 100 | 1.25 (3.75) | 0 | 0 |

The constants (7 rows per batch, 12 base statements, 120 bytes per row, 0.15 s per request) are the design's assumptions; section 8 compares them with measurements.
`CapacityReport::project()` is exactly this and is unit-tested against the design's table.

## 7. Tuning notes

* **The collect interval and the raw retention dominate storage and write rate.** Halving the collect interval doubles the sample rows; the request rate is modest (17/s at 5,000
  devices). Prefer the Recommended profile and raise resolution deliberately.
* **Queued ingest** above ~1,000 devices; run `drain()` from cron every few seconds, or register the handler on the generic worker. Watch `queue.oldest_pending_age_s`.
* **Redis** (Core's `RateLimiter`, `CronGuard`) for the global check-in counter and the maintenance lock above ~1,000 devices; the module works identically without it.
* **PHP-FPM**: workers busy is about check-ins/s x 0.15 s at peak x 3; size `pm.max_children` above the peak with headroom, keep opcache on. A `Retry-After` from shedding
  is the safety net, not the plan.
* **MariaDB**: `innodb_buffer_pool_size` large enough for the hot tables (`endpoint_agent_devices`, the edition's metric indexes); `innodb_flush_log_at_trx_commit=1` costs
  one fsync per transaction, so a slow disk shows up as check-in latency before anything else (queued ingest moves the sample writes out of the request); keep
  `innodb_lock_wait_timeout` at the default: check-ins of different devices never contend, a duplicate of one device serialises on its row.
* **Pruning** is batched (`DELETE ... LIMIT 5000` with a 20 ms pause, at most 200,000 rows per table per run), so retention never blocks check-ins.
* **After an outage** every device reconnects at once: agents back off with full jitter and honour `Retry-After`; level 3 plus a wide `shed_retry_min_s`/`shed_retry_max_s`
  window spreads the herd. Raise the window rather than the thresholds if the herd is the problem.

## 8. Measured so far (S1 baseline)

**First measurements (single scratch host, `php -S`: not representative of PHP-FPM).** Everything below ran on one 14-vCPU, 60 GB Linux VM that also runs other work, with
a private MariaDB 11.8 (512 MB buffer pool, default `innodb_flush_log_at_trx_commit=1`, local socket), PHP 8.5 CLI's built-in server with 8 worker processes, a throwaway Redis for the per-device
rate limits, and the simulator on the same machine. The "edition" is `tests/Support/LoadEdition.php`: database-backed stand-ins for assets, links, alerts and a
metric sink, so an edition's link UPDATE and one multi-row sample INSERT per check-in are in the numbers. Simulated devices send the protocol's realistic check-in (about 1.5
KB, 5 sample batches, 3 checks; the first check-in carries the inventory). Reproduce with `scripts/rmm-load/run.php` (section 9).

| Run | Requests | Req/s | p50 / p95 / p99 / max (ms) | Non-200 | Statements/s | Statements per check-in | Rows inserted per check-in | CPU cores: database / PHP workers |
|---|---|---|---|---|---|---|---|---|
| sync, 200 devices x 6 s (= 33 check-ins/s, the 10,000-device rate) | 3984 | 33.2 | 20.6 / 25.2 / 33.1 / 45 | 0.00 % | 877 | 26.4 | 31.2 | - / - |
| queued, 200 devices x 6 s | 3999 | 33.3 | 15.7 / 18.0 / 25.2 / 59 | 0.00 % | 994 | 29.9 | 31.3 | - / - |
| sync, 500 devices x 300 s (rc scenario, real interval) | 553 | 1.7 | 25.2 / 30.9 / 35.4 / 39 | 0.00 % | 56 | 33.3 | 35.5 | 0.024 / 0.019 |
| sync, 500 devices x 100 s (3x peak) | 642 | 4.9 | 23.6 / 30.9 / 40.5 / 94 | 0.00 % | 159 | 32.3 | 34.9 | 0.065 / 0.052 |
| sync, 500 devices, herd (first check-in within 10 s) | 997 | 13.3 | 20.6 / 27.0 / 37.9 / 80 | 0.00 % | 399 | 30.1 | 33.5 | 0.147 / 0.116 |
| sync, 5,000 devices x 300 s (real interval) | 5484 | 16.6 | 23.6 / 27.0 / 30.9 / 57 | 0.00 % | 553 | 33.3 | 35.6 | 0.203 / 0.159 |
| queued, 5,000 devices x 300 s | 5506 | 16.7 | 15.7 / 18.0 / 20.6 / 87 | 0.00 % | 617 | 37.0 | 35.5 | 0.217 / 0.122 |
| sync, 10,000 devices x 300 s (real interval, 33 check-ins/s) | 10970 | 33.2 | 22.1 / 27.0 / 33.1 / 61 | 0.00 % | 1107 | 33.3 | 35.6 | 0.4 / 0.302 |
| queued, 10,000 devices x 300 s | 11007 | 33.4 | 14.7 / 18.0 / 22.1 / 118 | 0.00 % | 1225 | 36.7 | 35.5 | 0.401 / 0.227 |

**Shedding triggers when the limits are lowered.** 200 devices x 6 s (33 check-ins/s) with `shed_rate_per_min = 400` (level 1 at 400, level 2 at 800, level 3 at 1,600 check-ins per minute), `shed_retry_min_s/max_s = 2/4` and a state directory. Shed level sampled every 5 s: `[0, 0, 0, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3, 3]`. The rate window fills during the first minute, the level climbs 0, 1, 2, 3 as it does, and from the moment level 3 is reached 1069 check-ins were answered `503 unavailable` (p50 about 1.5 ms against about 20 ms for an accepted one, because they are refused before any work), while 1654 were accepted. No sequence number was duplicated or lost for an accepted check-in (`duplicate_seq = 0`, `device_seq_mismatch = 0`); devices kept the refused body and retried it with the same `seq`. Because the rate signal counts accepted check-ins, level 3 empties its own window and the level then steps down one level per two evaluations (see section 3).

**Queued against sync.** At the same 33 check-ins/s the request p95 fell from 25.2 ms to 18.0 ms and p99 from 33.1 to 25.2 ms. The total work per check-in rose from 26.4 to 29.9 statements (the queue's own enqueue, claim and completion), all of it now outside the request. The backlog was empty 0.1 s after the run ended, the average enqueue-to-completion time was 0.2 s, and the batch worker merged the samples of up to 50 check-ins per sink call. Rows and sample counts equal the sync run's (`IngestQueueTest` proves the equality of the resulting state).

Notes on reading the table. The two accelerated runs (200 devices every 6 s) are the steady-state runs: about twenty check-ins per device, so only 5 % carry the inventory; their per-check-in
numbers are the ones to compare with the model. In the real-interval runs of 5,000 and 10,000 devices almost every request is a device's FIRST check-in (the window is only one interval
long), which carries the inventory (about 10 extra sample rows and the inventory apply), so their per-check-in figures are a little higher and show the cost of a cold fleet, for example
right after an enrollment wave. "Rows inserted per check-in" counts the check-in row plus the sample rows; row updates are in the model comparison below. CPU is the user plus system time of
the database server and of the PHP worker processes over the measured window, in cores (not captured in the first two runs). "Non-200" was zero in every run except the deliberate shedding run.
The 1-minute host load average at the start of the runs was between 0.4 and 1.7 (the machine was otherwise quiet), except 3.4 at the start of the 10,000-device queued run (the previous run was still tearing down).

**Zero-cost proof under load.** `php -S` with 8 workers serving only the gate template against a disabled state file, 20,000 requests (every device endpoint, 8 concurrent keep-alive connections): all answered `503 module_disabled`, about
1,200 requests/s (limited by the Python client on the same host, not by the server), p50 6.3 ms and p99 14.5 ms measured at the client, and the private database server's `Connections` and `Com_select` counters did not move
(delta zero; a connection counter of +1 in the check was the second `mariadb` client itself).

**Pruning finding.** In queued mode each `rmm.ingest` job row carries its payload: 2.7 KB per check-in. The 10,000-device queued run left 9,296 finished jobs (23.5 MB) after five minutes, i.e. about 7.7 GB a day at 33 check-ins/s if
they were kept; Core's `JobQueue::purgeCompleted()` cannot go below one day. `IngestQueue::pruneCompleted()` therefore deletes finished `rmm.ingest` jobs after 10 minutes, in batches of 5,000, from `Housekeeping::run()` (added after
these runs; `IngestQueueTest` covers it).


### 8.1 Model against measurement

| Quantity | Model (design 13.1 and 13.2) | Measured (steady-state sync run, 3,984 check-ins) | Difference |
|---|---|---|---|
| Statements per check-in (5 batches, 3 checks) | 24 | 26.4 (11.2 SELECT, 2.2 INSERT, 9.1 UPDATE, the rest BEGIN/COMMIT) | +10 % |
| Sample rows per check-in | 35 (5 batches x 7) | 30.2 (the simulated device reports 6 samples per batch: cpu, memory, two disks, rx, tx) | -14 % |
| Rows written per check-in (inserted + updated) | about 42 | 31.2 inserted + 6.0 updated = 37.2 | -11 % |
| Statements per second at 33.3 check-ins/s | 800 | 877 | +10 % |
| Average PHP workers busy at 33.3 check-ins/s | 5 (0.15 s per request) | 0.64 (mean request 19.3 ms, loopback, warm cache) | the model is 8x pessimistic here; keep it for FPM sizing until S9 |
| PHP CPU per request | n/a | 9.1 ms (0.30 cores at 33.2/s) | |
| Database CPU per check-in | n/a | 12 ms (0.40 cores at 33.2/s) | |
| Bytes per sample row | 120 | 156 for the stand-in table (data plus one index, InnoDB); `endpoint_agent_checkins` 191, `endpoint_agent_devices` 818 per device | +30 % for the stand-in; recalibrate on RivetIT's real Metrics tables in S9 |

Statements and rows per check-in are inside the 25 % of the acceptance criterion (13.7). The model's storage constant was NOT changed (it is the design's assumption and `CapacityTest` pins the model to the design's table); the measured
156 bytes per row is for a one-index stand-in table, so treat 120 to 160 bytes as the planning range until the edition's real tables are measured.

Against the release-candidate targets of 13.7 (on this host, with the caveats above): 500 devices at the real 300 s interval, p95 30.9 ms and p99 35.4 ms (targets 250 and 600 ms), no errors, database CPU 0.024 cores (target under 15 % of one core); 3x peak (4.9 requests/s), p95 30.9 ms (target 400 ms);
herd of 500 devices within 10 s, zero 503, p99 37.9 ms, back to normal immediately (target at most 1 % 503 and recovery in 2 minutes). The GA targets (5,000 devices at 300 s, p95 under 400 ms, error rate under 0.1 %, ingest queue age under 30 s in queued mode) are met in the same sense by the 5,000-device runs
(p95 27.0 ms sync and 18.0 ms queued, no errors, backlog empty 0.2 s after the run). A 24-hour soak and the 30-minute-outage thundering herd at 5,000 devices remain for S9.

### 8.2 What this does not show

* PHP-FPM, opcache preloading, a separate database host, real network latency and TLS: the numbers are a floor for a loopback `php -S`.
* Hours of soak: the longest run is about five minutes; table growth, purge behaviour under load and memory are untested here (S9).
* The edition's real Metrics tables and indexes (RivetIT) are heavier than the stand-in sample table.
* Dashboards and read paths at 10,000 devices (S6).

### 8.3 Plan for the reference hardware (S9)

1. Hardware as in SCALING.md (8 vCPU, 16 GB, NVMe, MariaDB 11 tuned, PHP-FPM, Redis); install the RivetIT edition build, not the harness.
2. Re-run section 9's scenarios with the real edition's sink and Metrics tables: 500 and 5,000 devices at the real 300 s, then 10,000 devices for 24 hours, with
   `--herd` after a 30-minute outage, queued mode on, and `drain()` from cron.
3. Record statements and rows per check-in against section 6, the p95/p99 and error rate, database CPU, replication lag if any, backlog and its drain time, shed level
   transitions, and the size of every table every hour; correct the model constants here before release.
4. Disabled module: 2,000 requests/s of mixed agent calls against a disabled install, `Connections` and `Com_select` deltas must be zero (the unit test in section 1.3 already
   proves it for the gate; this proves it under load in FPM).
5. Publish the results in this file with the host description.

### 8.4 Phase 3: what the alerting switch costs (statements per check-in, before and after)

Measured with the S1 harness (`scripts/rmm-load/run.php --mode=sync --devices=200 --interval=6s --duration=60s --workers=8 --concurrency=32`, 33.2 check-ins/s, steady state), a private MySQL 8.4 in a container, PHP 8.5 CLI `php -S` with 8 workers, the simulator and the database on the same shared host (1-minute load average 3 to 7 from other work). The statement counts are the database server's own `Questions` counters divided by the accepted check-ins, so they do not depend on host load; the latencies do, and are given only where the host was quiet. `-value-check` makes the simulator send a fourth check, `cpu_load`, with a measured `value` (5 to 95), checked on the server by a `cpu` definition.

| Run | Statements per check-in | SELECT | INSERT | UPDATE | p50 / p95 ms | Notes |
|---|---|---|---|---|---|---|
| A. Before: origin/main (8a7c88e), 3 checks | 27.0 | 11.3 | 2.4 | 9.2 | 28.9 / 43.4 | the baseline of section 8 |
| B. Phase 3 code, `alerting` off, 3 checks | 27.0 | 11.3 | 2.4 | 9.2 | 28.9 / 40.5 | identical: nothing of Phase 3 runs |
| C. Phase 3 code, `alerting` on, 3 checks | 27.0 | 11.3 | 2.4 | 9.2 | (host busy) | identical: a healthy check-in adds no statement; Phase 3 tables are read only when a result is bad or an alert is open |
| D. `alerting` off, 4 checks (`cpu_load` reports a value) | 30.1 | 13.3 | 2.7 | 10.1 | (host busy) | what one more plain check costs today: 3.1 statements |
| E. `alerting` on, 4 checks, `cpu_load` with thresholds (warn above 80, 3 samples, hysteresis) and flap detection (window 10) | 32.3 | 14.5 | 3.5 | 10.2 | 33.1 / 46.4 | a run of 10 check-ins per device never leaves the flap warm-up (the window fills one sample at a time, one write each), so this is the worst case |
| F. as E without flap detection | 31.6 | 14.5 | 2.9 | 10.2 | 33.1 / 43.4 | thresholds only |

Reading it. A threshold or flap check adds ONE batched read of its evaluation rows to a check-in (`rmm_check_eval`, one statement however many such checks the device has) and a write only when its state changes (a tier, the duration counters, the flap history; the last reading is refreshed at most every 15 minutes). `AlertingTest::testASteadyThresholdCheckCostsOneReadAndNoWrite` pins that: after the flap window is full, a check-in with a threshold check costs exactly the plain check plus one statement. Alert opens and resolves add one INSERT or UPDATE of Core's alert record and, when a policy matches, no more (notices are sent by housekeeping, never in the request); maintenance windows, parents and storm control are consulted only when a bad result is about to open an alert (a window-list read once per request, a parent lookup, two bounded `COUNT` reads), and a `suppress` window is looked up only for a bad result or an open alert. Housekeeping adds an indexed read of the due escalations, the open windows and the storm summaries once per run, bounded to 200 notices. All of it sits inside the existing shed levels (level 3 refuses the check-in before any of it), and the queued path carries the checks unchanged inside the `rmm.ingest` job.

What this does NOT show: PHP-FPM, the reference hardware, a fleet that is mostly bad (an alert storm is capped by storm control, defaults 100 per client and 500 in total per 10 minutes), or the Windows-only checks on a Windows host. Re-measure at S9 with the Phase 3 checks on.

## 9. The simulator and the harness

`endpoint-agent/cmd/rmm-sim` (Go, standard library only; `make sim` in `endpoint-agent/`, built in the golang container if there is no Go on the host) enrolls N devices with one multi-use token
(`max_uses >= devices`) and checks them in with realistic protocol payloads.

```
rmm-sim -url http://127.0.0.1:8700/api/v1/ -token rvte1.... -devices 500 -interval 300s -duration 10m -concurrency 64 -insecure [-herd] [-json]
```

| Flag | Meaning |
|---|---|
| `-url`, `-token` | base URL of the device API (`agent_enroll` and `agent_checkin` are appended), the multi-use enrollment token (or `RMM_SIM_TOKEN`) |
| `-devices`, `-interval`, `-duration` | fleet size, check-in interval per device (+/-10 % jitter, random first offset), run time after enrollment |
| `-concurrency` | requests in flight, bounded |
| `-insecure` | allow plain http and unverified TLS, **only when the host is loopback**; against any other host the simulator refuses to start |
| `-herd` | every first check-in falls in the first 10 s (the thundering herd after a restart) |
| `-batches` | buffered sample batches per check-in (default 4: five batches with the current one) |
| `-retry-scale` | scales server `Retry-After` and the retry backoff (use below 1 when the interval is accelerated) |
| `-follow-server-interval` | obey `next_check_in_s` like the real agent |
| `-client-ip-header` | send a distinct fake client address per device in that header (only for a harness that honours it; the enrollment limiter counts per IP) |
| `-json`, `-report`, `-enroll-only`, `-seed`, `-timeout`, `-disabled-floor` | JSON summary line, progress period (default 10 s), enrollment only, determinism, request timeout, minimum wait after `module_disabled` |
| `-value-check` | add a fourth check, `cpu_load`, that reports a measured `value` (Phase 3: exercises the threshold and flap path; `run.php --value-check`) |

It prints a progress line every 10 s (request rate, p50/p95/p99/max of the period, in flight, status counts) and at the end the totals, the latency distribution and the
status histogram. Honouring the server: `503`/`429`/transport errors keep the in-flight body (same `seq`) and retry after `max(Retry-After, full-jitter backoff)`;
`module_disabled` waits at least the disabled floor.

The server side of a measurement is `scripts/rmm-load/`: `router.php` (a minimal edition front controller with the gate in front), `load.php` (setup, worker, stats,
counters) and `run.php` (starts the server and the queued worker, runs the simulator, reads the database server's own counters and the CPU time of the database and the
PHP workers, prints one JSON document; `--poll-shed` records the shed level every 5 s). Scratch databases only: the name must contain `scratch`, and a private database
server gives exact counters (a shared server's global counters are noisy).

## 10. Where each part lives

| Piece | Where |
|---|---|
| State file writer and reader | `src/Rmm/RmmStateFile.php`, `src/Rmm/RmmState.php` |
| Gate template | `docs/rmm/templates/rmm_gate.php` |
| Load shedder, capacity report and presets, queued ingest | `src/Rmm/Capacity/{LoadShedder,CapacityReport,IngestQueue}.php` |
| Hooks in the device path | `CheckinService::applyWork()` and the queue hook, `DeviceApi` (shedder tick, level-3 refusal), `Housekeeping` (evaluation), `RmmSettings` (state listener, shed thresholds), `RmmModule` wiring |
| Simulator | `endpoint-agent/cmd/rmm-sim/` |
| Harness and measurement scripts | `scripts/rmm-load/`, `tests/Support/LoadEdition.php` |
| Tests | `tests/Integration/Rmm/{ModuleSwitch,LoadShedder,Capacity,IngestQueue}Test.php`, `endpoint-agent/cmd/rmm-sim/sim_test.go` |

## 11. Phase 1 write paths (software inventory, check history, the database metric sink)

Nothing here runs unless it is switched on or used: the software inventory needs the `inventory_software` sub-switch (and an agent that announced
`software_inventory`), the check history is recorded by the check evaluator that already runs, and the metric sink tables are only written when an
edition passes `Support\DatabaseMetricSink` to the module. Retention is pruned by `Housekeeping::run()` in batches of 5,000 rows with the usual pause.

### 11.1 Limits

| Key | Default | Range | Meaning |
|---|---|---|---|
| `check_history_days` | 7 | 0 to 365 | days of per-check history kept; 0 stops recording (rows younger than a day are still pruned only after a day) |
| `check_history_gap_s` | 3600 | 60 to 86,400 | an unchanged status is stored at most this often per check; every change of status is always stored |
| `software_history_days` | 365 | 1 to 3,650 | days the software change log, and software that was removed, are kept; software that is still installed is never pruned |
| `DatabaseMetricSink` retention | 14 days | constructor argument | hourly rollups and latest readings older than this are deleted by `Housekeeping` |

### 11.2 Model

| What | Rows | Written when |
|---|---|---|
| Check history | per check: one row per status change plus one per gap while steady, so about 24 a day per check at the default; 3 default checks and 7 days is about 500 rows a device | by the check evaluator, one multi-row insert per check-in (200 rows per statement) |
| Software, current | one row per installed item (a Windows machine 150 to 400, a Linux server 300 to 2,000) plus the removed ones until the retention passes | the first report inserts them; afterwards only changed items are written |
| Software, history | one row per install, upgrade, downgrade and removal | on a change; the first report of a device is a baseline and writes none |
| Metric sink | per device about 6 hourly rows an hour for one disk (cpu, memory, disk used, disk free, receive, transmit), so about 2,000 rows in 14 days, and about 12 latest rows | one multi-row upsert per table per 200 rows, however many check-ins the batch merges |
| `rmm_device_state` | one row per device that announced capabilities, or whose presence or software hash is tracked | when the announcement changes; a presence write only while an event bus is wired |

At 5,000 devices that is about 2.5 million check-history rows, 1 to 2 million current software rows for Windows fleets (up to about 7 million for Linux servers with
2,000 packages each) and about 10 million metric rollup rows at the default retention. Plan roughly 100 to 150 bytes a row including indexes.

### 11.3 Measured (a scratch host, MariaDB 11.8 on the same machine, PHP CLI, one device, not the reference hardware)

Cost of one check-in that carries a software report, including the HTTP-layer work and the transaction:

| Items | First (baseline) full list | Unchanged full list (the daily one) | Full list with 20 changed items |
|---|---|---|---|
| 200 | 23 ms, 26 statements | 18 ms, 23 statements | 14 ms, 25 statements |
| 1,000 | 61 ms, 29 statements | 13 ms, 23 statements | 19 ms, 25 statements |
| 3,000 | 157 ms, 37 statements | 57 ms, 23 statements | 35 ms, 25 statements |

The statement count is nearly flat because rows go in 250 at a time. A delta of a handful of items costs the base check-in plus the state read, a hash check
and a few statements. With one full list a day per device, 10,000 devices send about 10,000 reports a day; with the default 5-minute check-in the other
~2.9 million check-ins carry no software block at all. `DatabaseMetricSink::ingest()` stored 1,750 samples (250 devices, 7 metrics each) in about 70 ms in two multi-row
statements per 200 rows, the same again as an upsert in about 80 ms.

What this does not show: contention with a full check-in load on one table, PHP-FPM, or a network round trip; the S9 validation on the reference hardware is where
those belong. A queued-ingest install applies the software block in the worker (a work item over 60,000 bytes is processed inline, so a big baseline report is).
Load shedding level 1 and above acknowledges a software report without applying it and asks the device to send a full list again later (at most every 15 minutes).

## 12. Phase 2 paths (policies, scheduled scripts, bulk runs, approvals)

Nothing here runs unless it is switched on: the `policies` sub-switch adds one query to a check-in, the `scripts` sub-switch lets `Housekeeping` run the
scheduler, the approval clean-up and the secret wipe. With both off a check-in costs the same statements as before and the cron run does no extra work.

### 12.1 Limits

| Key | Default | Range | Meaning |
|---|---|---|---|
| `approval_bulk_threshold` | 0 (never) | 0 to 100,000 | a run or a schedule on MORE devices than this needs a second person; a script flagged `requires_approval` always does |
| `approval_expiry_h` | 48 | 1 to 720 | hours a pending request waits before it lapses |
| `schedule_batch` | 200 | 10 to 5,000 | most jobs one pass of the scheduler queues for one run; level 1 of load shedding halves it (not below 10), level 2 quarters it, level 3 pauses it |
| `bulk_run_max` | 1,000 | 1 to 100,000 | most devices one technician run (or one approved run) reaches; more is 422 `too_many` |

### 12.2 Model

| What | Cost | Where |
|---|---|---|
| Effective policy at check-in | ONE extra statement per check-in (the assignments that reach the device joined to their policies, with the group and tag membership as sub-selects), however many policies or assignments exist; the device's stored capabilities are read only when the check-in did not announce them. No work at all while the `policies` switch is off | `Policy\PolicyStore::layersFor()` |
| Resolution and signing | in PHP, per check-in: the checks are signed again with the instance key when a policy applies (about 0.1 ms per check with libsodium), exactly as the global list always was | `Policy\EffectiveConfig`, `RmmSettings::signCheckList()` |
| Library jobs | one `rmm_job_extra` row per library job (a sidecar of the job: script, version, schedule, approval, idempotency key), pruned with the jobs; sealed secrets are wiped when the job is final | `Job\JobExtras` |
| Scheduler | one `rmm_schedule_runs` row per due slot; per device one job plus its sidecar (or a marker row when the device was deliberately skipped) so a slot is never queued twice. About 4 statements per device, one transaction per page of devices (a commit per job would cost a log flush each) | `Scripts\ScheduleRunner` |
| Offer filtering | `JobService::offer()` reads the device's stored capabilities once per offer that finds queued jobs | `Job\JobService` |

Plan for the scheduler: a fleet of N devices on one schedule is N jobs per slot, so a daily schedule on 5,000 devices adds 5,000 rows to `endpoint_agent_jobs` and
5,000 to `rmm_job_extra` a day (about 150 bytes a row with indexes each), kept for `job_retention_days` (180): about 1.5 million rows. Use `jitter_s` so the
devices do not all fetch their job in the same minute.

### 12.3 Measured (a scratch host, MariaDB 11 in Docker on the same machine, PHP CLI, not the reference hardware)

| Measurement | Result |
|---|---|
| Statements of one check-in, policies off / on with no assignment / on with five assigned policies | 16 / 17 / 17 |
| One scheduler pass, `schedule_batch` 200, a 5,000-device schedule | 200 jobs in about 0.4 s, about 810 statements (4 per device); 5,000 jobs in 25 passes, 11 s |
| The same before the per-page transaction | 200 jobs in about 6 s (the commit of each job dominated) |

What this does not show: the scheduler running next to a full check-in load, PHP-FPM, or the cost of group-membership sub-selects on a fleet with many groups (the
assignment query is by scope and index; a device in many groups checks each assigned group only). The S9 validation on the reference hardware is where those belong.
The default batch is deliberately small: with the cron every minute one due run queues 200 jobs a pass, so a 5,000-device schedule takes about 25 minutes to reach every device (use `jitter_s` and an `expires_s` window that covers it, or raise `schedule_batch`; a pass of 1,000 jobs takes about two seconds on the measured host). Up to 50 due schedules and 50 open runs are worked per pass.

## 13. Phase 6 and 8 paths (file transfer and log collection)

Both are off by default and cost nothing while off: a check-in costs the same number of statements as before (`LogsTest::testCostOfACheckinAndOfABatch` pins it), no table is read, and the housekeeping additions are three indexed reads on empty tables.

| Path | Cost | Bound |
|---|---|---|
| Check-in with `logs` on | at most two more statements, only for an agent that announced `logs:collect` (the remote-settings row, read once per request) | none above one object in the response (a few hundred bytes) |
| `agent_logs` batch | 11 statements for a 200-entry batch measured on MySQL 8.4 (device authentication, settings, the device's counter row locked, the fleet figure, two multi-row `INSERT IGNORE`, the counter update); ten rules that match nothing add none (matching is in memory over the batch) and a rule with a count above one adds one bounded `COUNT` per matching rule | 200 entries and 256 KiB per request, 30 requests per 60 s per device, `logs_rate_per_min` entries per minute per device (default 600), the device and fleet row caps |
| Log storage | about 330 bytes per entry for a typical 70-character message (data and indexes, measured on 99,000 rows); up to about 4.5 KB for a 1024-character one. The settings view shows 600 bytes per entry as its planning figure | `logs_device_max_rows` (20,000) and `logs_fleet_max_rows` (2,000,000): at the defaults the fleet cap is about 1.2 GB planned and 0.7 GB for typical messages; retention 7 days |
| Log pruning | batches of 2000 rows, each in its own transaction with the counter update, at most 100,000 rows per `Housekeeping::run()` pass, an optional pause between batches | the time of the pass is bounded by the row cap; a backlog is worked off over several passes |
| Log search | an index range on `(device_id, occurred_at)` for a device and on `occurred_at` for the fleet; text and source filters are substring matches inside that window | a fleet search spans at most 7 days, a device search 90; `limit` at most 500, `offset` at most 100,000; the total is counted up to 10,001 |
| Shedding | level 1 and above: the check-in stops offering `logs:collect` and `agent_logs` answers `503 unavailable` with the jittered shed `Retry-After`; agents keep their bookmark | file transfer and job reports are never shed |
| File transfer | one ledger row, one stored file, and per chunk (at most 1 MiB) one row lock and an append; the SHA-256 of the whole file is computed once, when the last chunk arrives | `transfer_max_bytes` (50 MiB default, 512 MiB hard), `transfer_store_max_bytes` (1 GiB), 600 requests per 60 s per device, expiry `transfer_ttl_s` (24 h) after which housekeeping deletes the bytes |

PHP-FPM note for file transfer: a chunk is read into memory (at most 1 MiB, the request body cap of the endpoint), so a worker holds a few MiB at most; the 50 MiB default file is 50 requests. Put the transfer folder on a disk that can absorb the budget. Not measured: log ingest and prune at fleet scale on PHP-FPM (the S9 validation is where that belongs).
