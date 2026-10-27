#!/usr/bin/env bash
# Backup/restore round trip of scripts/rmm-postgres-{backup,restore}.sh against a SCRATCH PostgreSQL, using the postgres docker image for the client tools.
# Needs: docker, a PostgreSQL reachable at $RIVETCORE_TEST_PG_HOST:$RIVETCORE_TEST_PG_PORT with role $RIVETCORE_TEST_PG_USER ($RIVETCORE_TEST_PG_PASS) that may use the
# databases $RIVETCORE_TEST_PG_NAME (source, must contain "scratch") and ${RIVETCORE_TEST_PG_NAME}_restore (empty target). Skips (exit 0) without them.
set -euo pipefail
cd "$(dirname "$0")/../.."
src=${RIVETCORE_TEST_PG_NAME:-}
if ! command -v docker >/dev/null 2>&1 || [ -z "$src" ] || [[ $src != *scratch* ]]; then echo "SKIP: docker or a scratch RIVETCORE_TEST_PG_NAME missing"; exit 0; fi
host=${RIVETCORE_TEST_PG_HOST:-127.0.0.1}; port=${RIVETCORE_TEST_PG_PORT:-5432}; user=${RIVETCORE_TEST_PG_USER:-rivet}
export PGPASSWORD=${RIVETCORE_TEST_PG_PASS:-}
img=${RMM_TEST_PG_IMAGE:-postgres:18}
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
for t in pg_dump pg_restore psql; do
    printf '#!/usr/bin/env bash\nexec docker run --rm -i --network host -e PGPASSWORD -e PGSSLMODE %s %s "$@"\n' "$img" "$t" > "$tmp/$t"; chmod +x "$tmp/$t"
done
export RMM_PG_DUMP=$tmp/pg_dump RMM_PG_RESTORE=$tmp/pg_restore RMM_PG_PSQL=$tmp/psql
sql() { "$tmp/psql" -h "$host" -p "$port" -U "$user" -w -d "$1" -tA -v ON_ERROR_STOP=1 -c "$2"; }
fail() { echo "FAIL: $1" >&2; exit 1; }
base=(RIVETCORE_PG_USER="$user" RIVETCORE_PG_HOST="$host" RIVETCORE_PG_PORT="$port" RIVETCORE_PG_SSLMODE=disable)
counts() { # row counts of every user table, sorted
    sql "$1" "SELECT string_agg(relname || '=' || (xpath('/row/c/text()', query_to_xml('select count(*) as c from ' || quote_ident(relname), false, true, '')))[1]::text, ',' ORDER BY relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p') AND NOT c.relispartition"
}

# source: schema + a few rows
sql "$src" "DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public" >/dev/null
cat > "$tmp/mini.sql" <<'SQL'
CREATE TABLE rmm_metric_latest (asset_id int, metric_key text, v double precision, PRIMARY KEY (asset_id, metric_key));
CREATE TABLE endpoint_agent_log_events (event_id bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY, device_id int NOT NULL, message text);
CREATE TABLE endpoint_agent_check_history (hist_id bigint, reported_at timestamptz NOT NULL, device_id int, PRIMARY KEY (hist_id, reported_at)) PARTITION BY RANGE (reported_at);
CREATE TABLE endpoint_agent_check_history_default PARTITION OF endpoint_agent_check_history DEFAULT;
INSERT INTO rmm_metric_latest SELECT i, 'cpu', i * 0.5 FROM generate_series(1, 250) i;
INSERT INTO endpoint_agent_log_events (device_id, message) SELECT i % 7, 'm' || i FROM generate_series(1, 1234) i;
INSERT INTO endpoint_agent_check_history SELECT i, now(), i % 5 FROM generate_series(1, 500) i;
SQL
"$tmp/psql" -h "$host" -p "$port" -U "$user" -w -d "$src" -v ON_ERROR_STOP=1 -q < "$tmp/mini.sql"

# refuses without explicit connection env
if env -i PATH="$PATH" RMM_PG_DUMP="$RMM_PG_DUMP" scripts/rmm-postgres-backup.sh >/dev/null 2>"$tmp/err"; then fail "backup ran without a connection"; fi
grep -q 'RIVETCORE_PG_DATABASE is not set' "$tmp/err" || fail "missing-env message"
# the password is never on a command line
if grep -q "${PGPASSWORD:-@@none@@}" scripts/rmm-postgres-backup.sh scripts/rmm-postgres-restore.sh; then fail "password in script"; fi

env "${base[@]}" RIVETCORE_PG_DATABASE="$src" scripts/rmm-postgres-backup.sh > "$tmp/dump" 2>"$tmp/err" || fail "backup"
[ -s "$tmp/dump" ] || fail "empty dump"
head -c5 "$tmp/dump" | grep -q PGDMP || fail "not a custom-format dump"
if grep -q "${PGPASSWORD:-@@none@@}" "$tmp/err"; then fail "password printed"; fi

dst=${src}_restore
sql "$dst" "DROP SCHEMA IF EXISTS public CASCADE; CREATE SCHEMA public" >/dev/null
env "${base[@]}" RIVETCORE_PG_DATABASE="$dst" scripts/rmm-postgres-restore.sh < "$tmp/dump" 2>"$tmp/err" || { cat "$tmp/err" >&2; fail "restore into an empty database"; }
a=$(counts "$src"); b=$(counts "$dst")
[ -n "$a" ] && [ "$a" = "$b" ] || fail "row counts differ: [$a] vs [$b]"
echo "counts equal: $a"
# a non-empty target is refused, then replaced with RMM_PG_RESTORE_CLEAN=1
if env "${base[@]}" RIVETCORE_PG_DATABASE="$dst" scripts/rmm-postgres-restore.sh < "$tmp/dump" 2>"$tmp/err"; then fail "restore into a non-empty target was not refused"; fi
grep -q 'RMM_PG_RESTORE_CLEAN' "$tmp/err" || fail "refusal message"
sql "$dst" "DELETE FROM rmm_metric_latest" >/dev/null
env "${base[@]}" RMM_PG_RESTORE_CLEAN=1 RIVETCORE_PG_DATABASE="$dst" scripts/rmm-postgres-restore.sh < "$tmp/dump" 2>"$tmp/err" || { cat "$tmp/err" >&2; fail "clean restore"; }
[ "$(counts "$dst")" = "$a" ] || fail "counts after clean restore"
echo "PASS"
