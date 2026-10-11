# RMM scaling plan: 5,000 devices supported, 10,000 before the RMM leaves beta

Status: S1 to S8 BUILT in Core (2026-10-11), S9 PENDING. The load model below is the design's; the measurements that replace it are in [CAPACITY.md](CAPACITY.md) section 8 and, for the scale work, sections 13 to 16. Nothing here is a promise until the validation run (S9) passes on the reference hardware: the tooling for it is [SOAK_RUNBOOK.md](SOAK_RUNBOOK.md) and `scripts/rmm-load/soak.sh`.

## Goal

| Stage | Devices per install | What it means |
|---|---|---|
| Phase 0 (beta) | up to **5,000** supported, 500 suggested default cap on new installs | Capacity limits, queued ingest, load shedding and the simulator ship; the cap is raised by the admin |
| Leaving beta (GA) | **10,000** proven | The S1 to S9 work items below are done and the 10,000-device soak run passes on the reference hardware |
| After GA | beyond 10,000 | Only with an external metric store (S4) and a read replica; out of scope until there is demand |

Leaving beta is gated on the validation run, not on a date. "Handles 10,000" means the targets below are met by a simulated fleet, on the stated hardware, for 24 hours.

## Load model (to be replaced by measurements)

`check-ins per second = devices / check-in interval`; at the defaults (check-in every 300 s) and about 40 rows written per check-in:

| Devices | Check-ins/s | Rows written/s (defaults) | Raw samples per month (defaults) | Rows/s (recommended profile) | Raw per month (recommended) |
|---|---|---|---|---|---|
| 500 | 1.7 | 70 | 18 GB | 23 | 3.6 GB |
| 5,000 | 16.7 | 700 | 180 GB | 233 | 36 GB |
| **10,000** | **33.3** | **1,330** | **360 GB** | **470** | **72 GB** (about 20 GB steady with 7-day raw plus rollups) |

The request rate is modest. The costs that grow with the fleet are **writes per check-in**, **stored history**, **dashboard queries over the fleet** and the
**thundering herd** after an outage, when every device reconnects at once.

## Targets for the 10,000-device validation run

Reference hardware (documented, not a minimum): 8 vCPU, 16 GB RAM, NVMe, one tuned MariaDB 11 primary on the same host or a separate one, PHP-FPM, Redis.

| Target | Value |
|---|---|
| Sustained check-ins | 10,000 devices at a 300 s interval (33 check-ins/s) for 24 h with no errors other than injected ones |
| Check-in latency | p95 under 250 ms, p99 under 1 s at the server |
| Database | CPU under 60 % average, replication or flush lag within limits, no lock-wait timeouts in the check-in path |
| Backlog | Queued-ingest backlog stays under 2 minutes of work and drains after a burst |
| Thundering herd | After a 30-minute outage, all 10,000 devices are caught up within 15 minutes, with load shedding visible and no data loss for buffered samples |
| Pruning | Retention pruning never blocks check-ins (no statement over 1 s on the hot tables) |
| Dashboards | Fleet dashboard and device list render in under 2 s with 10,000 devices; device page graphs under 1 s |
| Disabled module | Zero queries when the module is off (already a Phase 0 test) |

## Work items (milestone "RMM Scale: 10,000 devices")

| # | Item | What | Why |
|---|---|---|---|
| S1 | Load simulator and benchmark harness | `rmm-sim` enrolls N fake devices and checks in at a configured rate with realistic payloads; reports latency, errors, DB statements/s; baseline at 500, 5,000 and 10,000 | Replace the model with numbers and find the real bottleneck first |
| S2 | Cheaper write path | Queued ingest through Core's JobQueue (web request stays tiny), multi-row batched inserts, skip unchanged inventory by hash, `config_version` ETag so bundles are not resent, agent-side aggregation (send min/avg/max per interval instead of every raw point), check results stored on state change only | Fewer rows and statements per check-in |
| S3 | Storage model | Time-partitioned sample tables (drop a partition instead of DELETE), 5-minute / hourly / daily rollups, retention tiers, index and row-size review | Pruning and storage stay flat as the fleet grows |
| S4 | Metric store abstraction | `RmmMetricStoreInterface` with the MySQL implementation as default and a documented driver contract for an external time-series store (TimescaleDB, ClickHouse, VictoriaMetrics). Built only if S1 shows MySQL cannot meet the targets; the interface exists from the start so it is not a redesign | Future proofing without forcing a second database on everyone |
| S5 | Compute and workers | Several job workers with Redis locks and per-device sharding, PHP-FPM and opcache sizing guide, connection handling (ProxySQL guidance), stateless web nodes behind a load balancer with one primary DB and an optional read replica for dashboards | Horizontal headroom |
| S6 | Read path | Fleet summaries precomputed by a worker into a summary table with Redis caching, indexed and paginated device lists, graph queries served from rollups for long ranges | Dashboards stay fast at 10,000 devices |
| S7 | Backpressure and herd control | Jittered intervals and random start delay (agent), 429/503 with `Retry-After`, staged load-shedding levels (drop optional samples, then lengthen intervals, then refuse new work), staged re-enrollment | Survive outages and restarts |
| S8 | Operations | Performance and capacity panel with lag/backlog/queue-depth alerts, a sizing calculator, MariaDB tuning guide, runbook, `docs/rmm/CAPACITY.md` | Admins can see and plan |
| S9 | Validation | 24-hour soak with 10,000 simulated devices on the reference hardware plus the thundering-herd test; results published in `docs/rmm/CAPACITY.md`; failures become bugs before GA | The gate for leaving beta |

S1 (baseline) and S7/S2 (the cheap wins) started in Phase 0 with the module switch and capacity controls (task T9). The rest is built; S4 stays optional and off.

### Where each item stands (2026-10-11)

The work-item names in the table above are the design's. The GitHub issues number them differently (issue numbers in brackets); this is the map and the state.

| Design item | Issue | State | Where |
|---|---|---|---|
| S1 simulator and harness | #79 S1 | done (Phase 0) | `endpoint-agent/cmd/rmm-sim`, `scripts/rmm-load/` |
| S2 cheaper write path, part 1 (queued ingest, batched inserts, `config_version`, backpressure) and S7 herd/backpressure basics | #80 S2, #85 S7 | done (Phase 0) | CAPACITY.md sections 3, 4 |
| S2 cheaper write path, part 2 | #81 S3 | **done**: 27.2 to 15.6 statements per check-in (14.8 with the opt-in `check_touch_s`), response bytes unchanged | CAPACITY.md 13 |
| S4 optional metric store abstraction | #82 S4 | **done, off by default**: `ExternalMetricStoreInterface`, VictoriaMetrics reference adapter, bounded outbox | [EXTERNAL_METRICS.md](EXTERNAL_METRICS.md) |
| S3 storage model: partitions, rollups, retention | #83 S5 | **done**: daily tier, opt-in probed time partitions with DELETE fallback, retention limits | [STORAGE.md](STORAGE.md) |
| S5 compute and workers | #84 S6 | **done**: SKIP LOCKED claiming with lease fallback, per-queue limit, stale-lease reclaim, autoscaling hint | CAPACITY.md 14 |
| S6 read path and S7 herd control | #86 S8 | **done**: stale-while-revalidate cache, warm start, per-client token bucket, measured herd | CAPACITY.md 15, 16 |
| S8 operations (panel, runbook) | part of each | panel gained `autoscale`, `storage`, `herd`, `external_store` | CAPACITY.md 5 |
| S9 validation (24 h, 10,000 devices) | #87 S9 | **PENDING**: tooling and runbook built, a short run recorded; the 24 h run on reference hardware has not been done | [SOAK_RUNBOOK.md](SOAK_RUNBOOK.md) |

## Why not PostgreSQL

Core stays on MariaDB/MySQL (ADR-001, ADR-008): every self-hosted install already has it, and the load above is within what a tuned MariaDB handles when the write path is made cheap (S2, S3). A second database engine would double the operational burden. The one place a different engine can pay off is the metric history at very large fleets, which is why S4 defines a store interface instead of changing the main database.
