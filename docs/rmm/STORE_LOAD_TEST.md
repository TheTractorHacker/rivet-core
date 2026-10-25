# Load-testing the RMM data store (MariaDB or PostgreSQL)

Phase 3 prepares, and phase 5 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89) runs, the load test that sets the default `rmm_pg_recommend_devices`. This page is the harness only. **The 5,000 and 10,000 device runs are not done here**; only a 200 device, 60 second smoke per store was run, on a shared and noisy development machine.

## What it runs

`scripts/rmm-store-load.php` wraps the existing S1 load rig (`scripts/rmm-load/run.php`: scratch database, `php -S` with N workers, the `rmm-sim` Go simulator) and adds the choice of store. The simulator, the device protocol and the golden replay are untouched; with no `RMM_LOAD_STORE` the old scripts behave as before.

* `--store=mariadb`: the hot tables on the control database (the default install).
* `--store=postgres`: the schema is created by the PostgreSQL migrator, emptied, the `data_store` setting is set by hand and the module receives the connection from `RIVETCORE_TEST_PG_*` (the `postgres` module option). The simulated edition uses the real `DatabaseMetricSink`/`PostgresMetricStore` so metrics reach the hot store.

Both databases are **scratch** (names must contain `scratch`) and are emptied. Build the simulator once: `cd endpoint-agent && make rmm-sim` (output `endpoint-agent/dist/rmm-sim`).

```bash
export RIVETCORE_TEST_DB_HOST=127.0.0.1 RIVETCORE_TEST_DB_NAME=rivet_scratch_load RIVETCORE_TEST_DB_USER=root RIVETCORE_TEST_DB_PASS=...
export RIVETCORE_TEST_PG_HOST=127.0.0.1 RIVETCORE_TEST_PG_NAME=rivet_scratch_load RIVETCORE_TEST_PG_USER=rivet RIVETCORE_TEST_PG_PASS=...
export RIVETCORE_TEST_REDIS_PORT=6379            # optional: the per-IP rate limiter
# PHP needs pdo_pgsql and mysqli (the development host has no pdo_pgsql: use the Docker image of docs/rmm/POSTGRES.md)
scripts/rmm-load/docker-sampler.sh /tmp/ds.jsonl 5 mariadb-container pg-container &   # on the HOST, optional: CPU and disk of the containers
php scripts/rmm-store-load.php --store=postgres --devices=200 --duration=60 --docker-stats-file=/tmp/ds.jsonl --out=/tmp/pg.json
```

Options: `--devices`, `--duration` (seconds, at least 10), `--interval` (check-in interval of the simulated agents, default `6s`), `--workers`, `--concurrency`, `--mode=sync|queued`. Run the two stores back to back, same options, and compare only those two documents.

## The JSON

| Key | Meaning |
|---|---|
| `store`, `versions` | the store under test and the server versions (MariaDB, PostgreSQL, TimescaleDB when installed) |
| `throughput` | check-ins accepted in the measured window, per second; requests per second as the simulator counts them |
| `latency_ms` | mean, p50, p95, p99, max of **all** requests of the simulator (it does not split by request type); measured at the client |
| `errors` | error percentage and the HTTP status counts |
| `statements.control_database_per_checkin` | the MariaDB server's own `Questions` delta divided by accepted check-ins: on a `postgres` run this is only the control-database part |
| `statements.postgres_per_checkin` / `_per_s` | transactions and tuples from `pg_stat_database`. Statements themselves are not counted (that needs `pg_stat_statements`) |
| `hot_rows_after` | rows in metric latest/hourly and check history, and for PostgreSQL the bytes of the hot tables |
| `cpu_disk` | `host_cpu_busy_pct` and `host_disk_mib_written` come from `/proc` of the machine that runs PHP and include everything else that runs on it. `database_containers` (mean/max CPU in cores, peak memory, block I/O counters) comes from the `docker stats` samples when `--docker-stats-file` is given |
| `host_load_avg_1m` | the host load at the start and the end: if it is high, the run is not comparable |
| `integrity` | duplicate sequence numbers and device sequence mismatches (must be 0) |

Everything depends on the machine, the container runtime and what else runs. A smoke is **not a capacity figure**.

## Plan for phase 5 (not run)

1. A quiet machine (or two) with the storage the target installs will have; PostgreSQL and MariaDB on separate data directories; TimescaleDB as a third variant.
2. For each store: 5,000 and then 10,000 simulated devices at the default 60 s check-in interval, at least 30 minutes after warm-up, `--mode=sync` and `--mode=queued`; the same hourly and daily rollup and retention jobs running (`partitioning` on and off).
3. Record the JSON above plus `pg_stat_activity`/`SHOW GLOBAL STATUS` over time; repeat three times and take the median, noting the variance.
4. Decide `rmm_pg_recommend_devices` from the device count at which MariaDB's p95 or CPU leaves its comfortable range (for example p95 above 500 ms or the database above 70 % of a core budget), keeping a margin.
