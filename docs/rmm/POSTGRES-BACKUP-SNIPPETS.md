# PostgreSQL store: backup, restore and update snippets for editions

(Phase 3 of [#89](https://github.com/TheTractorHacker/rivet-core/issues/89). To be merged into `docs/rmm/POSTGRES.md` section 8 and `UPGRADING.md`.)

Core cannot own an edition's `deploy/backup.sh`, `restore.sh` or in-app backup. It ships the pieces; the edition wires them. Nothing here applies while the data store is `mariadb`: the hot tables are in the control database and the existing backup already covers them.

## 1. Pieces Core ships

| Piece | Purpose |
|---|---|
| `scripts/rmm-postgres-backup.sh` | `pg_dump` custom format of the RMM database/schema to **STDOUT**. Refuses to run without `RIVETCORE_PG_DATABASE`, `_USER`, `_HOST`, `_PORT` (also `_SSLMODE`, default `prefer`, and `_SCHEMA`, default `public`). The password only through `PGPASSWORD` or `PGPASSFILE`, never an argument. Refuses to write to a terminal. |
| `scripts/rmm-postgres-restore.sh` | Reads that dump from **STDIN** into the RMM database. Refuses a target schema that holds tables unless `RMM_PG_RESTORE_CLEAN=1` (which drops the schema, so the RMM tables, first). Never touches MariaDB. |
| `RivetCore\Rmm\Store\BackupHook` | Tells the in-app backup whether a dump is needed and builds the exact command and environment (no password). |
| `scripts/rmm-store-verify.php` | Read-only reconciliation of the control database with the hot store after a restore. |

`RMM_PG_DUMP`, `RMM_PG_RESTORE`, `RMM_PG_PSQL` override the client binaries. The scripts need `pg_dump`/`pg_restore`/`psql` of PostgreSQL 14 or newer (the major version of the server or newer for `pg_dump`).

## 2. deploy/backup.sh (encrypted pipeline)

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

## 3. deploy/restore.sh

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

The target database and role must exist (`docs/rmm/POSTGRES.md` section 3). The restore recreates the schema, so the role needs `CREATE` on the database (for `public`, to own it). After the restore, `php scripts/rmm-postgres.php status --config=...` must report nothing pending.

## 4. In-app backup

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

## 5. Update ordering (code before migration is safe)

1. Deploy the new code. It runs against the old schema: PostgreSQL stores do not load while the setting is `mariadb`, and the MariaDB stores fall back for columns that migrations 0043/0044 add (code-before-migration safety, phase 2).
2. Run the MariaDB migrations (`CoreMigrations`, including 0044).
3. If (and only if) the PostgreSQL store is in use or about to be: `php vendor/rivet/rivet-core/scripts/rmm-postgres.php migrate --config=...`. It is idempotent and serialised by an advisory lock, and must run **after** step 2 and **before** the setting is switched to `postgres` (the switch service refuses to enable a store whose schema is behind).
4. Only then enable the switch (Administration > RMM > Data store) or leave it as is.

Rolling back code: the older release ignores the new tables and the `postgres` setting is read by it as an unknown value (it uses MariaDB); switch back to `mariadb` first when the older release should keep serving history.

## 6. What "restore" means when the store is PostgreSQL

Two databases, two dumps, not one snapshot (`docs/rmm/POSTGRES.md` section 8). The order is **control database first, then the RMM store, then reconcile**:

1. Restore the control database. It holds the setting (`data_store`), the sealed connection and every device id.
2. Restore the RMM PostgreSQL dump (section 3).
3. Run `php scripts/rmm-store-verify.php --bootstrap=FILE [--json]` (read-only; exit 0 clean, 1 findings, 2 usage/setup). It reports:
   * `orphan_rows`: hot rows of devices the control database does not know (the control dump is older than the hot dump): harmless, the pages do not show them; they age out with retention;
   * `ledger_drift` / `events_without_ledger`: the per-device ledgers (`endpoint_agent_log_state`, `endpoint_agent_change_state`) disagree with the rows (a dump taken mid-write): the housekeeping's next enforcement pass corrects the counters, or recompute them;
   * `rollup_no_watermark`, `rollup_daily_beyond_watermark`, `rollup_missing_days`: the daily rollup watermark contradicts the tiers (dumps from different moments): the days named are rolled up again or missing;
   * `hot_older_than_control`: the hot store is older than the newest check-in in the control database by `gap_seconds`: that much history was lost with the older dump;
   * `hot_store_unreachable`: the hot store could not be read (connection, role, schema missing).

An older control database with a newer RMM dump leaves history for devices the control database lacks (orphans); a newer control database with an older RMM dump leaves a gap. Neither corrupts anything; the report names them.
