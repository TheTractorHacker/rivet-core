# Performance baselines (issue #42)

Measured with `php bench/run.php` (plain PHP CLI, no extra dependencies). Read the caveats first: **these numbers come from a shared,
heavily loaded development machine, not from production-like hardware**, and the database numbers in particular varied by up to 4x
between two runs of identical code. Use them for orders of magnitude and for spotting regressions, not as capacity promises.

## Environment

| | |
|---|---|
| Date | 2026-10-06 |
| CPU | Intel Xeon Gold 6130 @ 2.10 GHz, 14 logical CPUs visible, 62 GB RAM |
| OS | Linux 7.0.0-38-generic (Ubuntu), local disk shared with other work |
| PHP | 8.5.11 CLI (NTS), opcache and JIT at their CLI defaults (off) |
| Database | MariaDB 11.8.6 (Ubuntu package, default InnoDB settings: `innodb_flush_log_at_trx_commit=1`), TCP 127.0.0.1, a scratch database |
| Redis | 8.0.5 throwaway server on 127.0.0.1, no persistence, no password |
| Load | load average 7 to 18 throughout (other agents' test suites, Docker builds, an `apt` install ran at the same time) |
| Method | each case run 3 times, **median run by throughput** reported; `--scale=1`; latencies are per call |

Caveats that matter:

- **Autocommit writes are bound by the disk's fsync**, not by PHP: a single-row `INSERT` per operation measured 186 events/s on one run and 47 on the
  next while a Docker build used the disk. On a production database with a battery-backed write cache or `innodb_flush_log_at_trx_commit=2` expect
  one to two orders of magnitude more. The numbers below are the **second** run (more contended); the first, partial run (killed when it was clear
  the later cases would take too long) is quoted where it differs.
- Pure CPU cases (signing, URL policy, date ranges, compliance rendering) are far less sensitive to load but still ran on a busy CPU.
- Redis cases include a TCP round trip to a local server; against a remote server the round trip dominates.
- The webhook delivery numbers use a fake transport: they measure building, signing and logging a delivery, not the network.
- Nothing here was run on MySQL 8.x or MariaDB 10.11 (CI runs the test matrix there, not the benchmark).

## Results

| Case | What it measures | Throughput |
|---|---|---|
| `audit.write` | `AuditService::log()`, one autocommit INSERT each | 47 events/s (186 on the quieter first run) |
| `audit.reader.page1` | `AuditReader::page()` first page (COUNT + 50 rows), 100,000-row table | 54 pages/s |
| `audit.reader.pageDeep` | the same at 75% depth (OFFSET 74,950) | 16 pages/s |
| `audit.reader.filterType` | event-type group + actor filter | 109 pages/s |
| `audit.reader.search` | free-text `LIKE '%...%'` search (cannot use an index) | 10 pages/s |
| `audit.reader.exportChunked` | `iterate()` keyset chunks of 1000, 50,000 rows | 282,000 rows/s |
| `jobs.enqueue` | `JobQueue::enqueue()`, autocommit | 5 jobs/s on the contended run (18 on the quieter first run) |
| `jobs.claim` | `claim(10)` until drained, 2,000 pending jobs (1 conditional UPDATE per candidate) | 10 jobs/s (a candidate is claimed by its own UPDATE; this is fsync bound) |
| `jobs.lifecycle` | enqueue + claim(1) + markCompleted, one at a time | 55 jobs/s |
| `redis.lock.acquireRelease` | `LockManager::acquire()` + `release()` | 1,463 cycles/s (about 0.7 ms each) |
| `redis.cronguard.contended` | `CronGuard::acquire()` while another holder has the lock | 3,106 attempts/s |
| `redis.cronguard.acquire` | `CronGuard::acquire()` on a free lock | 3,431 /s |
| `redis.ratelimit.hit` | `RateLimiter::hit()` (one Lua call) | 3,038 hits/s |
| `redis.failopen.lockWhenDown` | `LockManager::acquire()` with no Redis (fail open) | 520,000 /s |
| `webhook.signatureV2` | HMAC-SHA256 of a 2 KB body | 92,700 /s |
| `webhook.deliverTo.fakeTransport` | build, sign and log one delivery (INSERT into `webhook_deliveries`) | 14 /s contended, 116 /s with the Slack format (same code path, the disk was quieter then) |
| `retention.plan` | dry-run COUNTs over 200,000 audit rows (3 tables) | 21 plans/s |
| `retention.prune.audit` | `prune()` deleting 200,000 old audit rows in batches of 5,000 | 43,000 rows/s (about 4.6 s) |
| `retention.prune.nothingToDo` | `prune()` when nothing is old enough, 100,000 rows kept | 10 prunes/s |
| `compliance.assess` | 40 checks + 25 manual items, 3 frameworks | 3,771 /s |
| `compliance.report.html` | printable HTML report | 4,684 /s |
| `compliance.report.csv` | CSV report | 4,119 /s |
| `urlpolicy.vet.publicHost` | vet a public URL (resolver stubbed) | 55,900 /s |
| `urlpolicy.vet.rejected` | vet a URL that resolves to a private address | 518,000 /s |
| `urlpolicy.vet.allowedNetwork` | private address admitted by `allowedNetworks` | 121,600 /s |
| `daterange.resolve` | every preset, DST time zone, plus `sqlBounds()` | 80,600 /s |
| `daterange.resolveCustom` | custom range plus `previous()` and `toQuery()` | 36,500 /s |

## What the numbers say

- **The audit reader is fine for pages, poor for deep OFFSETs and searches.** Page 1 and filtered pages stay in the tens of milliseconds at 100,000 rows;
  an OFFSET of 75% depth costs about 3.5x page 1 and grows linearly with the table, and `search` (a `LIKE '%x%'` over five columns) is a full scan.
  Editions should not offer "jump to the last page" on a table past a few hundred thousand rows; `iterate()` (keyset) is the right path for exports and is not the bottleneck.
- **Retention is not the bottleneck either.** Deleting 200,000 rows took about 4.6 s in 5,000-row batches. The `DELETE ... WHERE created_at < ?` has no index starting with
  `created_at` (the audit indexes are `(event_type, created_at)`, `(entity_type, entity_id)`, `(actor_user_id)`), so each batch scans from the primary key until it has enough old rows; that is
  cheap while old rows are at the start and degrades when most rows are recent. `prune()` with nothing to delete costs three index-less range checks (about 100 ms at 100,000 rows).
  See SECURITY-REVIEW-2.md, RC-SR2-03.
- **Queue throughput is limited by commits, not logic.** One autocommit statement per job step; batching enqueues in a transaction is the cheapest improvement an edition can make.
- **Redis helpers cost about a millisecond or less each** against a local server and cost nothing measurable when Redis is unavailable (fail open path).

## Reproduce and regression thresholds

```bash
export RIVETCORE_BENCH_DB_HOST=127.0.0.1 RIVETCORE_BENCH_DB_NAME=rivetcore_scratch_bench RIVETCORE_BENCH_DB_USER=... RIVETCORE_BENCH_DB_PASS=...
export RIVETCORE_BENCH_REDIS_PORT=6391            # optional: a THROWAWAY Redis; without it the Redis cases are skipped
php bench/run.php --runs=3 --json=bench-result.json
php bench/run.php --runs=3 --check=bench/thresholds.json     # exit 1 and a REGRESSION line per case below its minimum
php bench/run.php --only=audit,jobs --scale=0.2              # a subset, smaller data sets
```

The database name must contain `scratch`, `bench` or `test`: the harness truncates the Core tables in it. `bench/thresholds.json` holds a
generous minimum per case (about a quarter of the contended database numbers, a fifth of the CPU ones). The `bench` CI job runs weekly and on demand
(`workflow_dispatch`), is `continue-on-error`, and uploads the JSON as an artifact: a miss is a prompt to look, not a merge gate.
Re-measure on quiet hardware (and a second database server) before quoting any of these figures externally.
