# Performance baselines

Roadmap item for `1.0.0-rc` (#42). These are regression tripwires, not marketing numbers: they exist so that a change which makes a
hot path ten times slower is noticed. Reproduce with [scripts/bench.php](../scripts/bench.php).

## How to run

```bash
# A scratch database only: the script DROPS EVERY TABLE in it and refuses to run unless the name contains "scratch".
RIVETCORE_TEST_DB_HOST=127.0.0.1 RIVETCORE_TEST_DB_PORT=3306 \
RIVETCORE_TEST_DB_NAME=rivetcore_scratch_bench RIVETCORE_TEST_DB_USER=... RIVETCORE_TEST_DB_PASS=... \
RIVETCORE_TEST_REDIS_PORT=6390 \
php -d memory_limit=1G scripts/bench.php          # add --json for machine-readable output
```

- Without `RIVETCORE_TEST_DB_NAME` it prints a notice and exits 0. Redis rows are skipped without `RIVETCORE_TEST_REDIS_PORT`
  (point it at a throwaway server: `redis-server --port 6390 --save "" --appendonly no`).
- It needs the dev install (it reuses `tests/Support/MysqliDatabase`, a plain mysqli adapter).
- Iteration counts are fixed in the script so runs are comparable. Each iteration is timed individually; the table shows the
  median and p95 per call and the overall throughput. Rows with one iteration are single operations (the whole migration run).
- The script seeds 100,000 `audit_events` rows (a third older than a year, the rest spread over the last 300 days) with multi-row
  inserts, then measures the read side and retention on them.

## Environment of the recorded run (2026-10-06)

| | |
|---|---|
| CPU | Intel Xeon Gold 6130 @ 2.10 GHz, 14 logical CPUs, 61 GiB RAM (a shared development server) |
| PHP | 8.5.11 CLI, opcache off for CLI |
| Database | MariaDB 11.8.6, a private throwaway instance (default `innodb_flush_log_at_trx_commit=1`, 256 MiB buffer pool, no binary log) on a local socket/TCP port |
| Redis | 8.0.5, a throwaway instance on port 6390, no persistence |
| Load | the host was busy with other work: load average between 17 and 25 during the runs |
| Schema | all 13 Core migrations, including `0013_retention_indexes` (indexes on `created_at`) as it exists in the tree after 0.21.0 |

Honest caveats: the host was heavily loaded, so absolute numbers are pessimistic and noisy. Run to run, medians moved by up to about
30 percent (for example `AuditService::log` 214 to 281 microseconds across three runs; 225 in the table below). A first attempt on the
server's shared MariaDB was abandoned because other scratch databases' DDL made every statement queue ("Opening tables" waits of
tens of seconds), which says nothing about Core. Treat the table as an order of magnitude, and re-record it on a quiet machine
(and on the CI runners) before the release candidate.

## Results

| Benchmark | Iterations | Median (us) | p95 (us) | Ops/s |
|---|---:|---:|---:|---:|
| MigrationRunner::run, empty database (13 migrations) | 1 | 39069 | 39069 | 26 |
| MigrationRunner::run, already current (no-op) | 1 | 998 | 998 | 1002 |
| AuditService::log (one INSERT, metadata and summary) | 2000 | 225 | 423 | 3887 |
| AuditReader::page, unfiltered, page 1 (100,000 rows) | 100 | 19053 | 32490 | 47 |
| AuditReader::page, unfiltered, page 1000 (OFFSET 49950) | 50 | 46893 | 72471 | 20 |
| AuditReader::page, filtered by event type | 100 | 21233 | 38865 | 42 |
| RetentionService::plan (90 day horizon, 100,000 audit rows) | 30 | 23503 | 36837 | 40 |
| JobQueue::enqueue | 1000 | 288 | 535 | 3078 |
| JobQueue::claim (limit 1) | 500 | 1874 | 4435 | 456 |
| JobQueue::markCompleted | 480 | 429 | 739 | 2167 |
| JobWorker::run, trivial handler (mean per job over 1000 jobs, claim + run + complete) | 1000 | 2035 | 2035 | 491 |
| PayloadFormatter::format json | 3000 | 1.4 | 1.8 | 624765 |
| PayloadFormatter::format form | 3000 | 11.3 | 18.3 | 84896 |
| PayloadFormatter::format slack | 3000 | 21.0 | 52.2 | 43368 |
| PayloadFormatter::format slack_attachments | 3000 | 27.7 | 41.3 | 34397 |
| PayloadFormatter::format teams | 3000 | 27.0 | 63.2 | 33133 |
| PayloadFormatter::format discord | 3000 | 33.3 | 81.2 | 26011 |
| PayloadFormatter::format ntfy | 3000 | 15.3 | 30.2 | 56677 |
| PayloadFormatter::format gotify | 3000 | 28.7 | 40.9 | 33713 |
| PayloadFormatter::format telegram | 3000 | 21.1 | 27.1 | 46948 |
| PayloadFormatter::format matrix | 3000 | 20.9 | 26.6 | 47202 |
| PayloadFormatter::format matrix_hookshot | 3000 | 21.0 | 27.5 | 47393 |
| PayloadFormatter::format apprise | 3000 | 16.2 | 23.7 | 59040 |
| PayloadFormatter::format template | 3000 | 27.9 | 37.5 | 33937 |
| PayloadTemplate::render (4 placeholders, json) | 5000 | 20.3 | 25.0 | 49283 |
| EventCatalog::search (113 events) | 5000 | 181 | 204 | 5594 |
| DateRange::resolve + sqlBounds | 20000 | 11.0 | 13.1 | 88523 |
| LockManager::acquire + release (Redis) | 3000 | 234 | 451 | 3969 |
| RateLimiter::hit (Redis) | 3000 | 101 | 213 | 8526 |

What the numbers say:

- Writes are one fsync-bound InnoDB statement: audit, enqueue and complete are a few hundred microseconds on a busy host. The audit
  write is cheap enough to stay on the request path; the edition can still wrap it in try/catch.
- `AuditReader::page` is dominated by `SELECT COUNT(*)` over the whole table (about 19 ms at 100,000 rows), because the page
  needs a total. It grows linearly with the table. Deep pages cost more again (`OFFSET`, 47 ms for page 1000). If an edition shows the
  trail on a busy installation with millions of rows, use filters (event type, date range) or keyset paging through `iterate()`
  rather than deep offsets, or retain less (Retention).
- `RetentionService::plan` counts rows with `created_at < ?`; with migration 0013's index it is a range scan on the index. `prune()` deletes in
  batches of 5,000 so it never holds a long lock; its cost is proportional to the rows removed.
- Queue claim is a SELECT plus one conditional UPDATE per candidate, so claim cost scales with the batch size; the worker's end-to-end
  cost per trivial job (about 2 ms) is claim plus complete, with the handler free.
- Formatting a webhook body takes tens of microseconds, so formatting is never the bottleneck of a delivery (the network is).
- The Redis helpers were measured with one connection kept open (as the editions' providers do); a provider that builds a new
  Predis client per call (as `tests/Support/TestRedis` does) adds a connection per operation, about 0.5 ms locally.

## Proposed regression thresholds (budgets)

The budgets are about 5 to 10 times the recorded median so that they trip on a real regression, not on a noisy runner. They are
asserted on medians only, over the iteration counts in the script, on a CI runner of at least 2 vCPUs with a database service container.
When `bench.php` grows a `--check` mode, these are the numbers it enforces; until then a person compares.

| Operation | Recorded median | Budget (median) |
|---|---:|---:|
| `AuditService::log` | 0.23 ms | < 1.5 ms |
| `AuditReader::page`, page 1, 100k rows | 19 ms | < 100 ms |
| `AuditReader::page`, page 1000, 100k rows | 47 ms | < 250 ms |
| `JobQueue::enqueue` | 0.29 ms | < 2 ms |
| `JobQueue::claim` (limit 1) | 1.9 ms | < 10 ms |
| `JobQueue::markCompleted` | 0.43 ms | < 3 ms |
| `JobWorker::run`, per trivial job | 2.0 ms | < 10 ms |
| Any `PayloadFormatter::format` | up to 0.033 ms | < 0.5 ms |
| `PayloadTemplate::render` | 0.020 ms | < 0.3 ms |
| `EventCatalog::search` | 0.18 ms | < 2 ms |
| `DateRange::resolve` + `sqlBounds` | 0.011 ms | < 0.2 ms |
| `MigrationRunner::run`, empty database (all migrations) | 39 ms | < 2 s |
| `MigrationRunner::run`, already current | 1 ms | < 25 ms |
| `RetentionService::plan`, 100k rows | 23 ms | < 150 ms |
| `LockManager::acquire` + `release` | 0.23 ms | < 2 ms |
| `RateLimiter::hit` | 0.10 ms | < 1 ms |

A change that exceeds a budget needs an explanation in the pull request (and a new budget in this file if the cost is accepted).

## What is not measured

- Concurrency: contention between several workers, lock waits under many writers, and many simultaneous readers.
- Tables much larger than 100,000 rows, and the cost of `prune()` itself (it is proportional to rows deleted).
- Real network delivery of webhooks, the converters (DOCX and PDF parsing), and the compliance assessor (all of it depends on edition checks).
- MySQL 8 and the older MariaDB; only MariaDB 11 was used. The CI matrix covers correctness on the others, not speed.
- Cold caches, other PHP versions and opcache settings.
