# PostgreSQL for the RMM history (optional)

Status: phases 2 and 3 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89). The stores exist and are tested against MariaDB and against PostgreSQL 18, the only supported version (and TimescaleDB 2.31 on 18); phase 3 added the setting with its connection test (section 13), the guided migration in both directions with verification, cut-over, rollback and purge (sections 14 and 15), the capacity recommendation (section 16), and backup helpers (section 8). The settings card and the progress page are the editions' (phase 4). Design and decisions: [docs/design/rmm-data-store.md](../design/rmm-data-store.md).

**Nothing changes unless you opt in.** MariaDB / MySQL stays the default and behaves exactly as before; the PostgreSQL code is never loaded, and no connection is ever opened, while the data store is `mariadb`.

## 1. What moves, what stays

The twelve **hot** tables (metric latest/hourly/daily, check history, log events and their ledger, change records and their ledger, software, patch, deployment and hypervisor history) can live in PostgreSQL. Everything else (devices, jobs, policies, tokens, alerts, settings, inventory, the check state) stays in MariaDB, because it joins with the edition's tickets and assets and must change in the same transaction as them. Two databases, one product: the control database decides, PostgreSQL remembers.

Use it when the hot tables dominate the load: a large fleet, long retention, logs or change monitoring on. A fleet of a few hundred devices has no reason to leave MariaDB.

## 2. Requirements

| | |
|---|---|
| PostgreSQL | **18 is the only supported version, and the only one tested.** The code uses no 18-only syntax, so an older server may work (14 is the lowest the connection test accepts; below that it refuses), but that is unsupported and untested: the connection test adds the warning "This PostgreSQL version is not supported; Rivet is tested only on PostgreSQL 18" and shows the exact server version. Declarative partitioning with partitioned primary keys and `ON CONFLICT` on partitioned tables (11+) is what the schema needs. |
| TimescaleDB | optional, 2.x (tested 2.31.0 on PostgreSQL 18). Never required, never installed by Core. |
| PHP | the `pdo_pgsql` extension in the PHP that runs the web requests **and** the cron jobs (`php -m \| grep pdo_pgsql`). |
| Role | one role that owns one database (or one schema). No superuser. |

## 3. Install on the same server

The supported small setup: PostgreSQL next to MariaDB and PHP-FPM on one machine.

On Ubuntu 26.04 LTS the distribution package is PostgreSQL 18, the supported version:

```bash
sudo apt update
sudo apt install postgresql-18 postgresql-contrib php8.5-pgsql      # Ubuntu 26.04; php8.5-pgsql is the pdo_pgsql extension for PHP 8.5
psql --version                                                       # psql (PostgreSQL) 18.x
sudo systemctl reload php8.5-fpm                                     # PHP-FPM must reload to load the extension; also restart the cron PHP if it is a different binary
php -m | grep pdo_pgsql                                              # check the PHP that runs the web requests AND the cron jobs
sudo -u postgres psql <<'SQL'
CREATE ROLE rivet_rmm LOGIN PASSWORD 'generate-a-long-random-one' NOSUPERUSER NOCREATEDB NOCREATEROLE;
CREATE DATABASE rivet_rmm OWNER rivet_rmm ENCODING 'UTF8' LC_COLLATE 'C.UTF-8' LC_CTYPE 'C.UTF-8' TEMPLATE template0;
\c rivet_rmm
REVOKE ALL ON SCHEMA public FROM PUBLIC;
GRANT ALL ON SCHEMA public TO rivet_rmm;
SQL
```

On another distribution use the PostgreSQL project's apt or yum repository (`postgresql-18`) or an official image, e.g. `docker run -d --name rivet-pg -e POSTGRES_USER=rivet_rmm -e POSTGRES_PASSWORD=... -e POSTGRES_DB=rivet_rmm -p 127.0.0.1:5432:5432 postgres:18` (never `latest`: pin the major, and bind to loopback). The `postgres:18` image keeps its data under `/var/lib/postgresql` (a volume mounted there, not at `/var/lib/postgresql/data`); the TimescaleDB image is `timescale/timescaledb:2.31.0-pg18`. The Debian and Ubuntu packages create the cluster for you (`pg_lsclusters`), listening on port 5432.

Optional, once, as the superuser, **only if you want TimescaleDB** (and have installed its package): `sudo -u postgres psql -d rivet_rmm -c 'CREATE EXTENSION IF NOT EXISTS timescaledb'`. Core detects the extension with `SELECT extname FROM pg_extension`; without it the native partitioned mode is used.

**Listen locally only.** In `postgresql.conf`: `listen_addresses = ''` (unix socket only) or `'localhost'`, and `unix_socket_directories = '/var/run/postgresql'`. In `pg_hba.conf`, one line for the role, nothing broader:

```
local   rivet_rmm   rivet_rmm                    scram-sha-256
host    rivet_rmm   rivet_rmm   127.0.0.1/32     scram-sha-256
```

Set `listen_addresses` before the first start of a cluster that other machines must reach; the packages default to `localhost`. Reload (`systemctl reload postgresql`) after `pg_hba.conf`; `listen_addresses` needs a restart. Use `password_encryption = scram-sha-256` (the default since PostgreSQL 14). PHP-FPM and the cron user connect over the socket: set the connection `host` to the socket directory (`/var/run/postgresql`) and, if the PHP user is not the role, keep the password; or create the role with the PHP user's name and use `peer` authentication (then no password is stored anywhere).

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

Changing the mode of an existing schema is not an in-place operation. To change it, move the history out and back with the migration command (section 14): migrate to MariaDB (section 14), create a new empty PostgreSQL database, run `scripts/rmm-postgres.php migrate --mode=...` in it, save it as the connection (retype the password: the database changed) and migrate to it.

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

The option accepts a `PostgresConfig` (opened with the shipped PDO adapter behind a circuit breaker), a `PostgresConnectionInterface` of your own, or a closure returning either. If the password is kept sealed in the edition's settings, `PostgresConfig::fromSealed($row, $secretBox)` decrypts `password_sealed` with the same `SecretBoxInterface` the module already uses for its other secrets; an unreadable value is an error, never an empty password. `DataStoreSwitch` (section 13) stores the connection that way and the module uses it when the edition passes no `postgres` option.

The keys of `PostgresConfig::fromArray()` (unknown keys are refused, so a typo cannot silently fall back to a default):

| Key | Default | Meaning |
|---|---|---|
| `database`, `user` | required | letters, digits, `_ . -` only |
| `password` | empty | never put it on a command line; it is hidden from dumps and traces |
| `host`, `port` | `127.0.0.1`, 5432 | a host name, an address, or the directory of the unix socket (starts with `/`) |
| `sslmode`, `sslrootcert` | `prefer`, none | use `verify-full` with a CA file when the connection leaves the machine |
| `schema` | `public` | the schema the tables live in |
| `connect_timeout` | 3 s | how long a new connection may take before the server counts as down |
| `socket_timeout_s` | 30 s | TCP keepalives and user timeout: a peer that stops answering is given up after about this long (not for a unix socket); 0 = the operating system's defaults |
| `statement_timeout_ms`, `lock_timeout_ms` | 30000, 5000 | per statement; 0 = no limit |
| `transaction_retries` | 2 | how often a deadlocked transaction is repeated |
| `breaker_open_s` | 15 s | after a connection failure a worker stops trying for this long (0 = no breaker) |
| `application_name` | `rivetcore-rmm` | shown in `pg_stat_activity` |

Without a `postgres` option the module uses the connection stored by the data store setting (section 13). Choose the store with `DataStoreSwitch` (section 13); `RmmSettings::update()` refuses `postgres` on purpose, and editing `endpoint_agent_settings.data_store` by hand skips the connection test. **Switching does not move existing rows** (section 13); to keep history, migrate (section 14).

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

**PostgreSQL 18 notes.** `initdb` enables **data checksums by default** on 18 (the official image does; on a packaged cluster check `SHOW data_checksums`): a small CPU cost on writes, and a page that fails its checksum is reported instead of silently read. Nothing to configure. Two consequences: a cluster created by an older major without checksums cannot be upgraded in place by `pg_upgrade` into an 18 cluster that has them (initialise the new cluster with `--no-data-checksums`, or move the history with a dump and restore through `rmm-postgres-backup.sh`: Core stores only the RMM history in this database); and `pg_checksums` can enable them offline on an old cluster. The new asynchronous I/O subsystem (`io_method`, default `worker`; `io_workers` default 3, `io_method = io_uring` needs a Linux kernel and build that support it) speeds up sequential scans and vacuum; the RMM's hot path is small point writes and indexed reads, so **keep the defaults**; raise `io_workers` (to 6 to 8) only if a large fleet shows scans waiting on I/O. Everything above (`shared_buffers`, `work_mem`, autovacuum, WAL) is not specific to 18.

Put the PostgreSQL data directory and MariaDB's on storage with enough IOPS for both; the hot tables are write-heavy. The connection opened by Core sets, per session: `TIME ZONE 'UTC'`, `statement_timeout` (30 s), `lock_timeout` (5 s), `idle_in_transaction_session_timeout` (60 s), `search_path` and `application_name = rivetcore-rmm` (so `pg_stat_activity` shows who is who). All are keys of `PostgresConfig`.

## 7. Retention and partitions

Row-level retention (batched deletes by age) works out of the box, exactly like MariaDB. Time partitions make retention a metadata operation. They follow the same administrator limits as on MariaDB (`partitioning`, `partition_span_days`, `partition_ahead`, `metric_hourly_days`, `metric_daily_days`, `check_history_days`):

* **native mode:** with `partitioning = 1` (or once partitions exist) the housekeeping job creates the next `partition_ahead` day partitions (moving rows that arrived in the DEFAULT partition into them first) and drops every partition that is wholly past retention, and, for the hourly tier, only after the daily rollup covered it. A run that is blocked (lock timeout) is reported and retried; a lagging maintenance only means rows wait in the DEFAULT partition. A year-long gap is bridged with one wide catch-up partition, never hundreds of statements.
* **TimescaleDB mode:** with `partitioning = 1` the job also enables compression (chunks older than 7 days) and drops whole chunks past retention, with the same rollup guard. The daily tier stays an ordinary table written by the same idempotent rollup as everywhere else; a continuous aggregate was **not** used on purpose (section "Equivalence" of the design note).

`DEFAULT` partitions and hypertable chunks are listed by the capacity panel (`storage.tables`).

## 8. Backups and restore

PostgreSQL is a second database: **back up both**, in the same run and to the same encrypted destination. `pg_dump` covers the whole RMM store (all hot tables, the rollup state, the ledgers, the migration ledger). For point-in-time recovery use WAL archiving (`archive_mode`, `pg_basebackup`); that is the standard PostgreSQL procedure and Core does nothing special for it.

Core cannot own an edition's `deploy/backup.sh`, `restore.sh` or in-app backup. It ships the pieces; the edition wires them. Nothing here applies while the data store is `mariadb`: the hot tables are in the control database and the existing backup already covers them.

### 8.1 Pieces Core ships

| Piece | Purpose |
|---|---|
| `scripts/rmm-postgres-backup.sh` | `pg_dump` custom format of the RMM database/schema to **STDOUT**. Refuses to run without `RIVETCORE_PG_DATABASE`, `_USER`, `_HOST`, `_PORT` (also `_SSLMODE`, default `prefer`, and `_SCHEMA`, default `public`). The password only through `PGPASSWORD` or `PGPASSFILE`, never an argument. Refuses to write to a terminal. |
| `scripts/rmm-postgres-restore.sh` | Reads that dump from **STDIN** into the RMM database. Refuses a target schema that holds tables unless `RMM_PG_RESTORE_CLEAN=1` (which drops the schema, so the RMM tables, first). Never touches MariaDB. |
| `RivetCore\Rmm\Store\BackupHook` | Tells the in-app backup whether a dump is needed and builds the exact command and environment (no password). |
| `scripts/rmm-store-verify.php` | Read-only reconciliation of the control database with the hot store after a restore. |

`RMM_PG_DUMP`, `RMM_PG_RESTORE`, `RMM_PG_PSQL` override the client binaries. The scripts need `pg_dump`/`pg_restore`/`psql` of PostgreSQL 18 (for `pg_dump` the major version of the server or newer).

### 8.2 deploy/backup.sh (encrypted pipeline)

Run the RMM dump in the same run, to the same encrypted destination as the control database dump. Only when the setting says `postgres` (read it from the control database, or from the edition's config). Placeholders in `<>`:

```bash
# --- RMM PostgreSQL store (skip when endpoint_agent_settings.data_store = 'mariadb') ---
if [ "$(mysql -N -e "SELECT data_store FROM endpoint_agent_settings WHERE id = 1" "$DB_NAME")" = "postgres" ]; then
    export RIVETCORE_PG_DATABASE='<database>' RIVETCORE_PG_USER='<role>' RIVETCORE_PG_HOST='<host or /var/run/postgresql>' \
           RIVETCORE_PG_PORT='5432' RIVETCORE_PG_SSLMODE='<disable|verify-full>' RIVETCORE_PG_SCHEMA='public'
    export PGPASSFILE='<path to a 0600 .pgpass owned by the backup user>'      # or PGPASSWORD from the secret store; never a command-line argument
    "$APP_DIR/vendor/rivet/rivet-core/scripts/rmm-postgres-backup.sh" \
        | age -r '<age recipient public key>' > "$BACKUP_DIR/rmm-pg-$STAMP.dump.age"
    # gpg variant:  ... | gpg --batch --encrypt --recipient '<key id>' > "$BACKUP_DIR/rmm-pg-$STAMP.dump.gpg"
fi
```

`set -o pipefail` must be on in `backup.sh`: a failing `pg_dump` must fail the run, not leave an empty encrypted file that looks valid. Keep the dump and the control-database dump in the same retention set.

### 8.3 deploy/restore.sh

```bash
# 1. restore the MariaDB control database first (as today)
# 2. the RMM store, only if the restored control database says postgres:
if [ "$(mysql -N -e "SELECT data_store FROM endpoint_agent_settings WHERE id = 1" "$DB_NAME")" = "postgres" ]; then
    export RIVETCORE_PG_DATABASE='<database>' RIVETCORE_PG_USER='<role>' RIVETCORE_PG_HOST='<host>' RIVETCORE_PG_PORT='5432' RIVETCORE_PG_SSLMODE='<mode>'
    export PGPASSFILE='<.pgpass>'
    age -d -i '<age identity file>' "$BACKUP_DIR/rmm-pg-$STAMP.dump.age" \
        | "$APP_DIR/vendor/rivet/rivet-core/scripts/rmm-postgres-restore.sh"          # add RMM_PG_RESTORE_CLEAN=1 to replace an existing schema
    # 3. check the two against each other
    php "$APP_DIR/vendor/rivet/rivet-core/scripts/rmm-store-verify.php" --bootstrap='<edition bootstrap file>' || echo "review the findings above before opening the site"
fi
```

The target database and role must exist (section 3). The restore recreates the schema, so the role needs `CREATE` on the database (for `public`, to own it). After the restore, `php scripts/rmm-postgres.php status --config=...` must report nothing pending.

### 8.4 In-app backup

```php
use RivetCore\Rmm\Store\BackupHook;

$kind   = $module->settings()->dataStore();
$config = $kind === 'postgres' ? $postgresConfigFromSettings : null;     // PostgresConfig built from the sealed connection (password not needed here)
$d = BackupHook::describe($kind, $config);   // ['needs_dump' => bool, 'connection' => host/port/database/user/schema/sslmode, 'env' => [...], ...]
if ($d['needs_dump']) {
    $c = BackupHook::command($kind, $config, __DIR__ . '/vendor/rivet/rivet-core');   // ['argv' => [...], 'string' => '...', 'env' => [...]]
    // run $c['argv'] with $c['env'] plus PGPASSWORD (unsealed by the edition for this process only), capture STDOUT as "rmm-postgres.dump" inside the backup archive
}
```

Record the descriptor (without a password) in the archive's manifest so a restore knows a second database is part of it. The in-app restore feeds that member to `scripts/rmm-postgres-restore.sh` on STDIN.

### 8.5 Update ordering (code before migration is safe)

1. Deploy the new code. It runs against the old schema: PostgreSQL stores do not load while the setting is `mariadb`, and the MariaDB stores fall back for columns that migrations 0043/0044 add (code-before-migration safety, phase 2).
2. Run the MariaDB migrations (`CoreMigrations`, including 0044).
3. If (and only if) the PostgreSQL store is in use or about to be: `php vendor/rivet/rivet-core/scripts/rmm-postgres.php migrate --config=...`. It is idempotent and serialised by an advisory lock, and must run **after** step 2 and **before** the setting is switched to `postgres` (`DataStoreSwitch::switchTo()` also runs the PostgreSQL migrator itself, idempotently, after its connection test).
4. Only then enable the switch (Administration > RMM > Data store) or leave it as is.

Rolling back code: the older release ignores the new tables and the `postgres` setting is read by it as an unknown value (it uses MariaDB); switch back to `mariadb` first when the older release should keep serving history.

### 8.6 What "restore" means when the store is PostgreSQL

Two databases, two dumps, not one snapshot (this section). The order is **control database first, then the RMM store, then reconcile**:

1. Restore the control database. It holds the setting (`data_store`), the sealed connection and every device id.
2. Restore the RMM PostgreSQL dump (section 3).
3. Run `php scripts/rmm-store-verify.php --bootstrap=FILE [--json]` (read-only; exit 0 clean, 1 findings, 2 usage/setup). It reports:
   * `orphan_rows`: hot rows of devices the control database does not know (the control dump is older than the hot dump): harmless, the pages do not show them; they age out with retention;
   * `ledger_drift` / `events_without_ledger`: the per-device ledgers (`endpoint_agent_log_state`, `endpoint_agent_change_state`) disagree with the rows (a dump taken mid-write): the housekeeping's next enforcement pass corrects the counters, or recompute them;
   * `rollup_no_watermark`, `rollup_daily_beyond_watermark`, `rollup_missing_days`: the daily rollup watermark contradicts the tiers (dumps from different moments): the days named are rolled up again or missing;
   * `hot_older_than_control`: the hot store is older than the newest check-in in the control database by `gap_seconds`: that much history was lost with the older dump;
   * `hot_store_unreachable`: the hot store could not be read (connection, role, schema missing).

An older control database with a newer RMM dump leaves history for devices the control database lacks (orphans); a newer control database with an older RMM dump leaves a gap. Neither corrupts anything; the report names them.

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

History writes of a check-in are **held until the control database transaction has committed** (the check-in holds the device row locked from its first statement to the commit, and PostgreSQL's latency or a connect timeout must not be spent inside that lock) and are dropped if it rolls back. An outage costs **one** connect timeout (default 3 s) per PHP process per 15 s window: after a connection failure the circuit breaker opens, shared by all workers of the host through a tiny file in the temp directory, and every call fails at once until the window is over. If the edition passes no connection at all while the setting says `postgres`, the stores behave the same and the message says exactly that; nothing is ever written to MariaDB in its place, because it would be invisible to the store the administrator chose.

## 11. Security notes

* The role has no superuser and owns only its database. It can `CREATE TABLE` in its schema (the migrator and the partition maintenance need it) and nothing else.
* Every value goes to the server as a bound parameter of a server-side prepared statement; no SQL is built from device or user text. Table and partition names are generated by Core and checked against a strict pattern before a DDL statement uses them.
* Error messages carry the server's primary message only: never the `DETAIL` line (it quotes the failing row), a bound value, or the password. `PostgresConfig` hides the password from `var_dump`, `print_r`, `var_export`, `json_encode`, serialization and stack traces.
* Use `sslmode=verify-full` for any connection that leaves the machine; a unix socket needs none.
* The stored password (section 13) is sealed with the SecretBox, kept in its own table, and is only reused for the target it was saved for (host, port, user, database unchanged; verified TLS for a remote host). The connection test, the migration, the size report and the scripts read the target from the stored settings only, never from a request or a command line combined with the stored password.
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

## 13. Choose the store in the settings

Everything below is a service the edition's settings card calls (`$module->dataStoreSwitch()`); Core ships no page.

1. **Save the connection**: `saveConnection(['host' => ..., 'port' => 5432, 'database' => ..., 'user' => ..., 'password' => ..., 'sslmode' => ..., 'schema' => 'public'])`. The password is sealed with the edition's SecretBox and kept in its own table (`rmm_data_store_connection`); it is never shown again, logged, audited or returned. Leave the password blank to keep the stored one **for the same host, port, user and database**; changing any of them requires typing the password again, and reusing a stored password for a remote host needs `sslmode` `verify-full` or `verify-ca` (so a hijacked admin session cannot make the server send the stored secret to another machine).
2. **Test connection**: `testConnection()` (or `testConnection($formValues)` for values not saved yet). It checks the PHP extension, the connection, server version (14 or newer is required; the exact version is shown, and below 18 a prominent, non-blocking warning says the version is not supported), a UTC session, the schema, that the role can create objects in it (tried in a rolled-back transaction), warns about a superuser role, and reports TimescaleDB and the schema state. Fix what it names; the message never contains the password.
3. **Switch**: `switchTo('postgres', ['acknowledge_no_data_move' => true])` tests again, creates or upgrades the PostgreSQL schema and writes the setting, all or nothing. The card must show `DataStoreSwitch::NO_DATA_MOVE_NOTICE` next to the button and require the acknowledgement.

**What the administrator sees after switching without migrating:** the RMM pages read only the new store, so logs, change records, software/patch/deployment history, check history and metric graphs start empty; the old history is still in the old store and is **not shown** (a merged view of two stores was rejected because paging, totals and per-device caps cannot be merged safely). `status()['left_behind']` names what stayed behind; the card should show it with a "Migrate data" button. Switching back to MariaDB (`switchTo('mariadb')`, always allowed) shows the old history again and hides what PostgreSQL received meanwhile, until a migration moves it.

## 14. Migrate the history

`scripts/rmm-store-migrate.php` (or `$module->storeMigrator()->migrate([...], $progress)` from a background job; never inside a web request). It needs the edition's small bootstrap file (`RivetCore\Rmm\Store\Cli\CliBootstrap`: control database, clock, SecretBox, audit) and uses the stored connection.

```bash
M="php vendor/rivet/rivet-core/scripts/rmm-store-migrate.php --bootstrap=/etc/rivetit/rmm-store-bootstrap.php"
$M --from=mariadb --to=postgres --dry-run          # rows and batches per table; the target must be empty
$M --from=mariadb --to=postgres --sample=50        # copy, dual-write, verify, cut over (JSON lines on stdout)
$M --from=mariadb --to=postgres --resume           # continue a stopped run
$M --status
```

What happens, why it is safe, and what is paused: [design note section 13.4](../design/rmm-data-store.md). In short: tables are copied in keyset pages with their ids and a checkpoint; **retention and rollups are paused** until the cut-over; a short **dual-write window** sends new writes to both stores (primary first); the tail is reconciled; the ledgers are rebuilt from the copied rows; row counts and a checksum sample (`--sample=all` for every device) are compared; only a clean verification cuts over, in one transaction. A failed verification leaves everything as it was (dual-write still on, nothing lost): fix the cause and `--resume`, or `--abort`. Throttling: `--batch`, `--pause-ms`, and the command backs off while the load shedder is active; the limits `rmm_store_migrate_batch` and `rmm_store_migrate_pause_ms` are the defaults. The same command runs the other way (`--from=postgres --to=mariadb`).

The progress is one JSON object per line; the last line is `{"summary": ...}`. Exit status 0 done, 1 failed or refused, 2 usage.

## 15. Roll back, purge, abort

* `--rollback` goes back to the previous store within the rollback period (limit `rmm_store_rollback_days`, default 7 days; `--force` after it while the old store still has its data). It first copies back what the new store received since the cut-over (same dual-write and verification), so nothing recorded meanwhile is lost, then flips the setting. The new store is kept.
* `--purge-old --yes` deletes the history from the store that is **not** active. It is explicit and never automatic; inside the rollback period it also needs `--force` (it removes the way back). A migration into a store that is not empty is refused: purge it first.
* `--abort` stops a run, turns dual-write off and ends the retention pause; rows already copied stay in the target until purged.

Disk: until you purge, the old store keeps its data and costs its disk.

## 16. When to move: the capacity recommendation

The capacity read model (`$module->capacity()->build()`) carries `store_sizes` (rows and bytes of the hot tables of the active store) and `data_store_recommendation`. While the store is MariaDB the recommendation says **"MariaDB is comfortable up to X devices. You have N. Consider PostgreSQL."** when `N >= 0.8 * X`, or when the hot tables exceed `rmm_pg_recommend_hot_mb` (default 51,200 MiB; 0 turns the size trigger off). `X` is `rmm_pg_recommend_devices` (a limit in `limits_json`, default 5000 until the phase 5 load test sets it from measurements). The panel also shows an estimated growth per day. It is advice only: nothing switches by itself.
