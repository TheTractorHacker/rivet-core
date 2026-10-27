# RMM storage at scale: rollups, time partitions and retention

This is the storage half of the scale work (SCALING.md item S5, issue #85). It covers the daily metric rollup tier, the opt-in time partitioning of the history tables with its capability probe and its fallback, and the retention settings. Numbers and the load model are in [CAPACITY.md](CAPACITY.md).

Everything below describes the MariaDB / MySQL store, the default. The same tiers, partitions and retention exist on the optional PostgreSQL store with its own mechanics (day partitions or TimescaleDB chunks): [POSTGRES.md](POSTGRES.md).

Everything here is **off by default or free until used**: migration 0021 only adds empty tables; partitioning is `limits_json.partitioning = 0`; the rollup runs from `Housekeeping::run()` only when the edition passed a `DatabaseMetricSink`.

## 1. The tiers of the database metric sink

`DatabaseMetricSink` never stores raw samples (an install that wants raw points uses its own sink or [an external store](EXTERNAL_METRICS.md)). It keeps:

| Table | Rows | Written | Read for |
|---|---|---|---|
| `rmm_metric_latest` | one per (asset, key, instance) | every check-in (upsert) | the live pages |
| `rmm_metric_hourly` | one per (asset, key, instance, hour): samples, sum, min, max | every check-in (upsert) | `series()` / `peak()` over windows up to 7 days |
| `rmm_metric_daily` (new, migration 0021) | one per (asset, key, instance, UTC day) | the rollup job, from the hourly rows | `series()` / `peak()` over windows longer than 7 days |

`Storage\MetricRollup` (run by `Housekeeping::run()`, so by the edition's maintenance cron) rolls a day up only when it is complete **and** a grace period of two hours has passed (a device that buffered samples while offline can deliver a late hour), in slices of 500 assets under a time budget of 15 s per run. The watermark (`rmm_storage_state.metric_daily_rollup`, the first day NOT yet rolled up) moves only when a whole day is done. A slice is an idempotent upsert computed from the hourly rows, so a crash, a second run or a re-run gives the same rows. An hour that arrives after its day was rolled up stays in the hourly tier only (documented loss of at most a few samples in one daily average).

Readers (`RmmMetricReaderInterface`):

* `series(since, until)` over more than 7 days returns one point per day for the completed days (the daily tier) followed by hourly points for the days not yet rolled up. A 90 day graph is 90 rows per series instead of 2,160.
* `peak(since)` over more than 7 days combines the daily tier (day-granular: the first partial day is included whole) with the hourly rows since the watermark. It is exact for min, max, mean and sample count over whole days; `peak_at` is the start of the hour (or day) that held the maximum.
* Shorter windows are answered from the hourly tier exactly as before (hour-granular). Without a rollup watermark (nothing rolled up yet) every window uses the hourly tier.

## 2. Retention settings

All are `limits_json` keys (`RmmSettings::LIMIT_KEYS`), whole numbers, validated on save.

| Key | Default | Range | Meaning |
|---|---|---|---|
| `metric_hourly_days` | 14 | 1 to 400 | days of hourly rollups and latest readings. When it is not set, the retention given to the `DatabaseMetricSink` constructor is used, so an edition that passed its own number is not overridden by an upgrade |
| `metric_daily_days` | 400 | 1 to 3650 | days of daily rollups. At 10,000 devices and about 12 series each that is about 120,000 rows a day (about 17 MB), 7 GB in 400 days: lower it if you do not chart that far back |
| `check_history_days` | 7 | 0 to 365 | days of per-check history (0 records none) |
| `partitioning` | 0 | 0 or 1 | 1 converts the history tables to time partitions when the probe allows it, and keeps them |
| `partition_span_days` | 1 | 1 to 7 | days one partition spans (the daily table always uses at least 7) |
| `partition_ahead` | 7 | 1 to 60 | partitions kept created in front of today |

`RmmSettings::explicitLimits()` returns only what an administrator stored.

## 3. Time partitioning

Deleting millions of rows in batches is the one operation whose cost grows with the fleet. A partitioned table drops a whole partition instead: a metadata operation that takes milliseconds whatever the row count and leaves no purge backlog.

Partitioned tables: `rmm_metric_hourly` (`hour_start`), `rmm_metric_daily` (`day_start`), `endpoint_agent_check_history` (`reported_at`). `endpoint_agent_checkins` is **not** partitioned: its primary key `(device_id, seq)` is what makes check-ins idempotent, and a partitioned table would force the partition column into every unique key.

### 3.1 The capability probe

`$module->partitions()->probe()` returns, for the server and for each table, whether partitioning can be used and why not. It also writes the answer to `rmm_storage_state` and is what the capacity panel (`storage`) shows.

| Check | Fails when |
|---|---|
| server | MariaDB without the `partition` plugin active. MySQL 8 is native |
| privileges | the probe really creates a one-column scratch table, partitions it, adds a partition and drops it, then drops the table. An account without CREATE, ALTER or DROP fails here with the server's message |
| table | not InnoDB; has foreign keys or is referenced by one (a partitioned InnoDB table cannot); has a unique key that does not contain the partition column |
| warning | more than 5 million rows: the conversion copies the table, run it off hours |

Maintenance of a table that is **not** partitioned is the batched DELETE pruning (`Housekeeping::prune()`, 5,000 rows per statement with a pause, at most 200,000 rows per table per run), exactly as before. So a managed database that forbids ALTER, a table with a foreign key an edition added, or a server without the plugin simply keeps working the old way.

### 3.2 Conversion and layout

`partitioning = 1` makes `Housekeeping::run()` convert each convertible table the next time it runs; or call `$module->partitions()->convert('rmm_metric_hourly', $spanDays, $ahead)` by hand at a quiet moment. The statement is one `ALTER TABLE ... PARTITION BY RANGE COLUMNS(col)`:

* `p_old`: everything before the first named boundary (normally empty);
* one partition per span from the oldest existing row to `partition_ahead` spans from now, named `pYYYYMMDD` after its first day;
* `p_max` (`MAXVALUE`): the catch-all.

For `endpoint_agent_check_history` the primary key `(hist_id)` is extended to `(hist_id, reported_at)`; the id stays unique because it is AUTO_INCREMENT. The conversion rewrites the whole table (`ALGORITHM=COPY`): reads continue, but writes to that table wait until the copy ends. An empty or small table takes well under a second; a table of tens of millions of rows takes minutes, so convert it off hours (or let the first conversion happen on a new install, where the tables are empty).

### 3.3 Maintenance

Each `Housekeeping::run()` (every minute from the cron) calls `PartitionMaintenance::maintain()` for every partitioned table, whether or not `partitioning` is still on (switching it off stops creating new conversions, it never stops dropping expired partitions):

1. **create**: `REORGANIZE PARTITION p_max INTO (next partitions..., p_max)` until `partition_ahead` spans exist in front of now (at most 100 per statement; a long gap gets one catch-up partition);
2. **drop**: `DROP PARTITION` for every partition whose upper boundary is not after `now - retention`, oldest first;
3. **guard**: a partition is **not** dropped while its hours are not yet in the daily tier (the hourly table is guarded by the rollup watermark; the message is returned in the maintenance result). A stuck rollup therefore stops the drops instead of losing data.

Safety: a server-side named lock (`GET_LOCK('rivetcore_rmm_partitions', 0)`) keeps two cron runs from altering at once; every ALTER runs with `lock_wait_timeout = 3`, so it cannot queue behind a long transaction and stall every statement behind it in the metadata lock queue (a failed ALTER is reported and retried by the next run). A lagging maintenance is harmless: rows past the last boundary sit in `p_max` until the next run splits it.

### 3.4 Going back

`ALTER TABLE rmm_metric_hourly REMOVE PARTITIONING` returns a table to plain InnoDB (for `endpoint_agent_check_history` also `DROP PRIMARY KEY, ADD PRIMARY KEY (hist_id)` if you want the original key). Set `partitioning = 0` first; the batched pruning takes over by itself because the table is no longer partitioned.

## 4. What the panel shows

`CapacityReport::build()['storage']`: `partitioning_enabled`, `rollup_watermark`, `rollup_behind_days`, and per table `partitioned`, `partitions`, `retention_days`. `Housekeeping::run()` returns `rollup_days`, `rollup_behind_days`, `partitions_created`, `partitions_dropped`.
