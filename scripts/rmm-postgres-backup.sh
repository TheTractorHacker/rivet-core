#!/usr/bin/env bash
# Dump the PostgreSQL RMM data store (docs/rmm/POSTGRES.md, #89) in pg_dump custom format to STDOUT, so the caller can wrap it
# (for example `| age -r KEY > rmm-pg.dump.age`). Only the RMM database/schema is dumped; MariaDB is never touched.
#
# Connection: explicit environment only (the script refuses to guess):
#   RIVETCORE_PG_DATABASE, RIVETCORE_PG_USER, RIVETCORE_PG_HOST, RIVETCORE_PG_PORT   required
#   RIVETCORE_PG_SSLMODE (default: prefer), RIVETCORE_PG_SCHEMA (default: public)
# The password is read by libpq from PGPASSWORD or PGPASSFILE; it is never an argument and never printed.
# RMM_PG_DUMP overrides the pg_dump binary. Status goes to STDERR; exit 0 only when the dump completed.
set -euo pipefail

die() { printf 'rmm-postgres-backup: %s\n' "$1" >&2; exit 2; }

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
if [ -t 1 ]; then die "refusing to write a binary dump to a terminal; redirect or pipe STDOUT"; fi

dump=${RMM_PG_DUMP:-pg_dump}
command -v "$dump" >/dev/null 2>&1 || die "$dump not found on PATH"

"$dump" --format=custom --compress=6 --no-owner --no-privileges --schema="$schema" \
    --host="$host" --port="$port" --username="$user" --no-password --dbname="$db"
printf 'rmm-postgres-backup: dump of database %s schema %s finished\n' "$db" "$schema" >&2
