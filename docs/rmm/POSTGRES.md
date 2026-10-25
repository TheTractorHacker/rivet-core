# PostgreSQL for the RMM history (optional)

Status: phase 2 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89). The stores exist, are tested against MariaDB and against PostgreSQL 16 (and TimescaleDB 2.31), and an edition can already run them by passing a connection. What is **not** built yet (phase 3): the settings page and the connection test, the guided migration command, the capacity recommendation, and `deploy/backup.sh` coverage. Design and decisions: [docs/design/rmm-data-store.md](../design/rmm-data-store.md).

**Nothing changes unless you opt in.** MariaDB / MySQL stays the default and behaves exactly as before; the PostgreSQL code is never loaded, and no connection is ever opened, while the data store is `mariadb`.

## 1. What moves, what stays

The twelve **hot** tables (metric latest/hourly/daily, check history, log events and their ledger, change records and their ledger, software, patch, deployment and hypervisor history) can live in PostgreSQL. Everything else (devices, jobs, policies, tokens, alerts, settings, inventory, the check state) stays in MariaDB, because it joins with the edition's tickets and assets and must change in the same transaction as them. Two databases, one product: the control database decides, PostgreSQL remembers.

Use it when the hot tables dominate the load: a large fleet, long retention, logs or change monitoring on. A fleet of a few hundred devices has no reason to leave MariaDB.

## 2. Requirements

| | |
|---|---|
| PostgreSQL | 14 or newer (tested on 16). Declarative partitioning with partitioned primary keys and `ON CONFLICT` on partitioned tables (11+) is what the schema needs. |
| TimescaleDB | optional, 2.x (tested 2.31 on PostgreSQL 16). Never required, never installed by Core. |
| PHP | the `pdo_pgsql` extension in the PHP that runs the web requests **and** the cron jobs (`php -m \| grep pdo_pgsql`). |
| Role | one role that owns one database (or one schema). No superuser. |

## 3. Install on the same server

The supported small setup: PostgreSQL next to MariaDB and PHP-FPM on one machine.

```bash
sudo apt install postgresql postgresql-contrib php8.5-pgsql          # package names differ per distribution
sudo -u postgres psql <<'SQL'
CREATE ROLE rivet_rmm LOGIN PASSWORD 'generate-a-long-random-one' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE rivet_rmm OWNER rivet_rmm ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0;
\c rivet_rmm
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO rivet_rmm;
SQL
```

Optional, once, as the superuser, **only if you want TimescaleDB** (and have installed its package): `sudo -u postgres psql -d rivet_rmm -c 'CREATE EXTENSION IF NOT EXISTS timescaledb'`. Core detects the extension with `SELECT extname FROM pg_extension`; without it the native partitioned mode is used.

**Listen locally only.** In `postgresql.conf`: `listen_addresses = ''` (unix socket only) or `'localhost'`, and `unix_socket_directories = '/var/run/postgresql'`. In `pg_hba.conf`, one line for the role, nothing broader:

```
local   rivet_rmm   rivet_rmm                    scram-sha-256
host    rivet_rmm   rivet_rmm   127.0.0.1/32     scram-sha-256
```

Reload (`systemctl reload postgresql`). Use `password_encryption = scram-sha-256` (the default since PostgreSQL 14). PHP-FPM and the cron user connect over the socket: set the connection `host` to the socket directory (`/var/run/postgresql`) and, if the PHP user is not the role, keep the password; or create the role with the PHP user's name and use `peer` authentication (then no password is stored anywhere).

A **separate database host** is recommended for large fleets (the 10,000 device target of #89): set `host` to it, require TLS (`sslmode` `verify-full`, `sslrootcert` the CA file) and restrict `pg_hba.conf` to the web hosts' addresses.

## 4. Create the schema

The schema is created and upgraded by its own migrator, separate from the MariaDB migrations. It is never run implicitly (DDL at request time would be a hazard):

```bash
cat > /etc/rivetcore/rmm-postgres.json <<'JSON'
{ "database": "rivet_rmm", "user": "rivet_rmm", "password": "...", "host": "/var/run/postgresql", "sslmode": "disable" }
JSON
chmod 600 /etc/rivetcore/rmm-postgres.json && chown www-data /etc/rivetcore/rmm-postgres.json   # readable by the service account only
php vendor/rivet/rivet-core/scripts/rmm-postgres.php migrate --config=/etc/rivetcore/rmm-postgres.json
php vendor/rivet/rivet-core/scripts/rmm-postgres.php status  --config=/etc/rivetcore/rmm-postgres.json
```

`migrate` is idempotent (a second run changes nothing) and safe to run from two machines at once (a session advisory lock; one applies, the other waits and finds nothing to do). It keeps its own ledger, `rivet_core_pg_migrations`, and its settings in `rivet_core_pg_meta`. The first run decides the **storage mode** and records it:

* `native`: the three big time series (`rmm_metric_hourly`, `rmm_metric_daily`, `endpoint_agent_check_history`) are range-partitioned tables that start with a single DEFAULT partition. Nothing to maintain until you switch partitioning on (section 7).
* `timescale`: the same three are hypertables with one-day chunks. Chosen by `--mode=auto` (the default) when the extension is installed in the database, or forced with `--mode=timescale` (refused with a clear message if the extension is missing).

Changing the mode of an existing schema is not an in-place operation; it is a migration to a new database (phase 3's command).

## 5. Tell the edition about it

Core does not store PostgreSQL credentials. **The edition passes the connection** to the module as the `postgres` option, from wherever it already keeps secrets:

```php
use RivetCore\Rmm\Store\Postgres\PostgresConfig;

$module = new RmmModule(/* ... as before ... */, options: [
    // A closure is called once, on first use, and only while the data store is "postgres":
    'postgres' => static fn () => PostgresConfig::fromArray([
        'database' => 'rivet_rmm', 'user' => 'rivet_rmm', 'password' => getenv('RIVET_PG_PASSWORD') ?: '',
        'host' => '/var/run/postgresql', 'sslmode' => 'disable',
    ]),
]);
```

The option accepts a `PostgresConfig` (opened with the shipped PDO adapter behind a circuit breaker), a `PostgresConnectionInterface` of your own, or a closure returning either. If the password is kept sealed in the edition's settings, `PostgresConfig::fromSealed($row, $secretBox)` decrypts `password_sealed` with the same `SecretBoxInterface` the module already uses for its other secrets; an unreadable value is an error, never an empty password. Phase 3 adds the settings page that stores it that way.

Then set the store. The settings API refuses `postgres` until phase 3 ships its connection test; until then an administrator with database access sets it by hand (`UPDATE endpoint_agent_settings SET data_store = 'postgres'`), **after** the schema exists. Switching does **not** move existing rows: history written so far stays in MariaDB and is no longer shown; use a fresh install, or wait for the migration command. Switching back is the same statement.

## 6. Size it next to MariaDB and PHP-FPM

On one box, the three compete for RAM. PostgreSQL's defaults are tiny, MariaDB's `innodb_buffer_pool_size` is usually generous. A starting point for a 16 GB machine that also runs MariaDB and PHP-FPM (adjust, then watch the cache hit ratio):

| Setting | Starting value | Why |
|---|---|---|
| `shared_buffers` | 1 to 2 GB (about 10 to 15 % of RAM) | PostgreSQL also uses the OS cache; do not give it a quarter of the box when MariaDB's buffer pool is already large |
| `effective_cache_size` | 4 to 6 GB | a planner hint only, nothing is allocated; what the OS cache will realistically hold for PostgreSQL |
| `work_mem` | 8 to 16 MB | per sort or hash **per query**; the hot queries are small, and a big value times many workers is how a box runs out of memory |
| `maintenance_work_mem` | 256 MB | vacuum, index and partition builds |
| `max_connections` | PHP-FPM `pm.max_children` + cron jobs + 10 | every PHP-FPM worker that touches a hot store holds one connection while it works; a pooler in **session** mode is fine, in transaction mode it is not (the session settings below are per session) |
| `autovacuum` | on (default); `autovacuum_vacuum_scale_factor = 0.05` for the ledger tables if they churn | the two ledgers (`endpoint_agent_log_state`, `endpoint_agent_change_state`) are updated on every batch |
| `synchronous_commit` | `on` (default) | `off` is a legitimate speed-up for history data (a crash loses at most the last fraction of a second, never corrupts), but it is your decision, not ours |
| `wal` / checkpoints | `max_wal_size = 4GB`, `checkpoint_timeout = 15min` | fewer checkpoint spikes under constant small inserts |

Put the PostgreSQL data directory and MariaDB's on storage with enough IOPS for both; the hot tables are write-heavy. The connection opened by Core sets, per session: `TIME ZONE 'UTC'`, `statement_timeout` (30 s), `lock_timeout` (5 s), `idle_in_transaction_session_timeout` (60 s), `search_path` and `application_name = rivetcore-rmm` (so `pg_stat_activity` shows who is who). All are keys of `PostgresConfig`.

## 7. Retention and partitions

Row-level retention (batched deletes by age) works out of the box, exactly like MariaDB. Time partitions make retention a metadata operation. They follow the same administrator limits as on MariaDB (`partitioning`, `partition_span_days`, `partition_ahead`, `metric_hourly_days`, `metric_daily_days`, `check_history_days`):

* **native mode:** with `partitioning = 1` (or once partitions exist) the housekeeping job creates the next `partition_ahead` day partitions (moving rows that arrived in the DEFAULT partition into them first) and drops every partition that is wholly past retention, and, for the hourly tier, only after the daily rollup covered it. A run that is blocked (lock timeout) is reported and retried; a lagging maintenance only means rows wait in the DEFAULT partition. A year-long gap is bridged with one wide catch-up partition, never hundreds of statements.
* **TimescaleDB mode:** with `partitioning = 1` the job also enables compression (chunks older than 7 days) and drops whole chunks past retention, with the same rollup guard. The daily tier stays an ordinary table written by the same idempotent rollup as everywhere else; a continuous aggregate was **not** used on purpose (section "Equivalence" of the design note).

`DEFAULT` partitions and hypertable chunks are listed by the capacity panel (`storage.tables`).

## 8. Backups and restore

PostgreSQL is a second database: **back up both**. Until phase 3 teaches `deploy/backup.sh`, the in-app backup and `restore.sh` about it, take a separate dump on the same schedule, to the same encrypted destination:

```bash
# daily, as the postgres user or the role (the role needs no superuser rights to dump its own database)
pg_dump --format=custom --compress=6 --no-owner --file=/var/backups/rivet/rmm-$(date +%F).dump rivet_rmm
```

`pg_dump` covers the whole RMM store (all twelve tables, the rollup state, the ledgers, the migration ledger). Restore order: restore the MariaDB control database first, then `createdb` + `pg_restore --no-owner --role=rivet_rmm --dbname=rivet_rmm file.dump`, then `php scripts/rmm-postgres.php status` (must say nothing pending). The two dumps are not one snapshot: the hot data is append-only and keyed by ids that the control database owns (device, asset, client), so an older PostgreSQL dump with a newer control database loses history for the gap and nothing else; an older control database with a newer PostgreSQL dump leaves history of devices the control database does not know yet, which the pages simply do not show. For point-in-time recovery use WAL archiving (`archive_mode`, `pg_basebackup`); that is the standard PostgreSQL procedure and Core does nothing special for it.

## 9. Monitoring

* **Capacity panel** (Administration > RMM > Performance and capacity): the new `data_store` block says where the hot tables live and what could not be written, and a warning `data_store` appears while faults are recorded. `RmmModule::dataStoreHealth()` returns the same figures; `clearDataStoreFaults()` acknowledges them once the cause is fixed.
* **From PostgreSQL:** `SELECT application_name, state, count(*) FROM pg_stat_activity WHERE datname = 'rivet_rmm' GROUP BY 1, 2;` (connections), `SELECT relname, n_live_tup, n_dead_tup, last_autovacuum FROM pg_stat_user_tables ORDER BY n_live_tup DESC LIMIT 20;` (table sizes and vacuum), `SELECT pg_size_pretty(pg_total_relation_size(c.oid)), c.relname FROM pg_class c WHERE relname LIKE 'rmm\_%' OR relname LIKE 'endpoint\_agent\_%' ORDER BY pg_total_relation_size(c.oid) DESC LIMIT 20;`, and the partitions: `SELECT inhrelid::regclass FROM pg_inherits WHERE inhparent = 'rmm_metric_hourly'::regclass;`.
* **Alert on:** the capacity warning, `pg_stat_activity` connections near `max_connections`, a growing DEFAULT partition (`SELECT count(*) FROM rmm_metric_hourly_default` should stay near 0 once partitioning is on), the disk of the data directory, and the age of the oldest unvacuumed ledger row.

## 10. When PostgreSQL is down, or not configured

A check-in must never fail because the history store is gone. What happens (the design note has the table with the reasons):

| What the device sent | Result |
|---|---|
| Check-in, metrics, check results, software inventory, job results (patch and deployment history), hypervisor reports | **Accepted (200).** The control-database part (device state, check state, alerts, jobs) is written as always. The history part is **dropped and counted**: `rmm_hot_store_faults` in the control database (per store: writes dropped, failures, first and last time, last error), one `error_log` line a minute, and the `data_store` warning in the capacity panel. |
| Log and change batches | **503 `unavailable` with `Retry-After` 60 to 180 s.** The protocol's existing load-shedding answer: the agent keeps its batch and sends it again. Nothing is lost, nothing is counted as dropped. |
| Reads (log search, change search and timeline, software, patch and deployment history, device history) | The page gets `HotStoreUnavailableException` with a message naming the cause. The **metric** readers (dials and graphs) answer "no data" instead, because every device page asks for them; the fault is counted. |
| Housekeeping (retention, rollup, partitions) | Reports nothing done and counts the fault; the next run catches up. |

An outage costs **one** connect timeout (default 3 s) per PHP process per 15 s window: after a connection failure the circuit breaker opens, shared by all workers of the host through a tiny file in the temp directory, and every call fails at once until the window is over. If the edition passes no connection at all while the setting says `postgres`, the stores behave the same and the message says exactly that; nothing is ever written to MariaDB in its place, because it would be invisible to the store the administrator chose.

## 11. Security notes

* The role has no superuser and owns only its database. It can `CREATE TABLE` in its schema (the migrator and the partition maintenance need it) and nothing else.
* Every value goes to the server as a bound parameter of a server-side prepared statement; no SQL is built from device or user text. Table and partition names are generated by Core and checked against a strict pattern before a DDL statement uses them.
* Error messages carry the server's primary message only: never the `DETAIL` line (it quotes the failing row), a bound value, or the password. `PostgresConfig` hides the password from `var_dump`, `print_r`, `var_export`, `json_encode`, serialization and stack traces.
* Use `sslmode=verify-full` for any connection that leaves the machine; a unix socket needs none.
* Rotating the password: change it in PostgreSQL and in the edition's configuration; the open sessions end at the next request, there is nothing else to restart.

## 12. Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `The PHP extension pdo_pgsql is not installed` | Install `php-pgsql` for the **same** PHP (FPM and CLI are separate packages/ini files) and reload FPM. |
| `no PostgreSQL connection was configured` | The setting is `postgres` but the module got no `postgres` option. Pass it (section 5) or set the data store back to `mariadb`. |
| `The PostgreSQL schema is missing` / SQLSTATE `42P01` | Run the migrator (section 4) as the role that owns the database. |
| `password authentication failed` | Role password or `pg_hba.conf` line; the message never shows the password, check `pg_hba.conf` and `log_connections`. |
| `not trying again for N more seconds` | The circuit breaker is open after a failed connect. Fix the server; it closes by itself after the window, or delete `rivetcore-pg-breaker-*` in the temp directory. |
| Statement timeout (SQLSTATE `57014`) | A query ran longer than `statement_timeout_ms`; check `pg_stat_activity` for a long transaction or an index that is missing (`\d+ table`). |
| Rows pile up in `*_default` | Partitioning is off, or the maintenance cannot get its lock (`lock_timeout` reported in the capacity panel); look for a long-running transaction. |
