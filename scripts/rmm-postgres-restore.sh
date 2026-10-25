#!/usr/bin/env bash
# Restore a dump made by rmm-postgres-backup.sh from STDIN into the PostgreSQL RMM database (docs/rmm/POSTGRES.md, #89).
# Example: `age -d -i KEY rmm-pg.dump.age | RIVETCORE_PG_DATABASE=... scripts/rmm-postgres-restore.sh`.
# Restore the MariaDB control database FIRST, then this, then run scripts/rmm-store-verify.php. MariaDB is never touched.
#
# Connection: RIVETCORE_PG_DATABASE, _USER, _HOST, _PORT required; RIVETCORE_PG_SSLMODE (default prefer), RIVETCORE_PG_SCHEMA (default public).
# Password only through PGPASSWORD / PGPASSFILE. The target must be EMPTY (no tables in the schema); RMM_PG_RESTORE_CLEAN=1 drops the
# schema (and so the existing RMM tables) first. RMM_PG_RESTORE and RMM_PG_PSQL override the binaries.
set -euo pipefail

die() { printf 'rmm-postgres-restore: %s\n' "$1" >&2; exit 2; }

for v in RIVETCORE_PG_DATABASE RIVETCORE_PG_USER RIVETCORE_PG_HOST RIVETCORE_PG_PORT; do
    [ -n "${!v:-}" ] || die "$v is not set (explicit connection settings are required)"
done
db=$RIVETCORE_PG_DATABASE
user=$RIVETCORE_PG_USER
host=$RIVETCORE_PG_HOST
port=$RIVETCORE_PG_PORT
schema=${RIVETCORE_PG_SCHEMA:-public}
export PGSSLMODE=${RIVETCORE_PG_SSLMODE:-prefer}
[[ $db =~ ^[A-Za-z0-9_.-]{1,63}$ ]] || die "invalid database name"
[[ $user =~ ^[A-Za-z0-9_.-]{1,63}$ ]] || die "invalid user name"
[[ $port =~ ^[0-9]{1,5}$ ]] || die "invalid port"
[[ $schema =~ ^[a-z_][a-z0-9_]{0,62}$ ]] || die "invalid schema name"
[[ $host =~ ^[A-Za-z0-9_.:/-]{1,255}$ ]] || die "invalid host"
if [ -t 0 ]; then die "expected the dump on STDIN (pipe it in)"; fi

restore=${RMM_PG_RESTORE:-pg_restore}
psql=${RMM_PG_PSQL:-psql}
command -v "$restore" >/dev/null 2>&1 || die "$restore not found on PATH"
command -v "$psql" >/dev/null 2>&1 || die "$psql not found on PATH"

# The schema name was validated above, so it is safe inside the literal.
tables=$("$psql" --host="$host" --port="$port" --username="$user" --no-password --dbname="$db" --tuples-only --no-align --set=ON_ERROR_STOP=1 <<SQL
SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = '$schema' AND c.relkind IN ('r','p') AND NOT c.relispartition
SQL
) || die "cannot query the target database"
tables=${tables//[[:space:]]/}
# The dump carries CREATE SCHEMA, so the schema is dropped first: when it is empty plainly, otherwise (RMM_PG_RESTORE_CLEAN=1) with CASCADE, which deletes the
# existing RMM tables. (pg_restore --clean cannot drop partitions of partitioned tables, so it is not used.) Without CASCADE the drop refuses a non-empty schema.
cascade=""
if [ "$tables" != "0" ]; then
    [ "${RMM_PG_RESTORE_CLEAN:-}" = "1" ] || die "the target schema is not empty ($tables tables); set RMM_PG_RESTORE_CLEAN=1 to replace it"
    cascade=" CASCADE"
fi
"$psql" --host="$host" --port="$port" --username="$user" --no-password --dbname="$db" --quiet --set=ON_ERROR_STOP=1 --command="DROP SCHEMA IF EXISTS \"$schema\"$cascade" </dev/null \
    || die "cannot drop the schema $schema (extension objects in it, or the role does not own it?)"

"$restore" --no-owner --no-privileges --exit-on-error \
    --host="$host" --port="$port" --username="$user" --no-password --dbname="$db"
printf 'rmm-postgres-restore: restored into database %s schema %s\n' "$db" "$schema" >&2
