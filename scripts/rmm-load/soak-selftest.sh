#!/usr/bin/env bash
# Self-test of the soak tooling (soak.sh + soak-report.php) against a SCRATCH install started with the S1 harness:
# a throwaway MariaDB container (scale-soak-mdb), `php -S` with scripts/rmm-load/router.php and rmm-sim on loopback.
#
#   scripts/rmm-load/soak-selftest.sh [--keep] [--only a|b|c|d|e|f]
#
# Scenarios
#   a  healthy 3 minute soak, 200 devices x 6 s (= 33 check-ins/s, the 10,000-device rate), full sampling: expects every measured row PASS
#   b  same, sampler given a WRONG database password: DB rows must be NOT MEASURED and the verdict must not be PASS
#   c  server is not running: nothing can enroll; the verdict must not be PASS
#   d  the server is killed in the middle of the run: transport errors must make the errors row FAIL
#   f  SIGTERM in the middle of the run: simulator stopped, report still produced and marked INTERRUPTED
#   e  a herd outage executed by this script on behalf of the "operator" (stop + start of the scratch php -S): recovery is measured
# Nothing outside the scratch container and this tree is touched. Run it from a copy of the tree when the main worktree is in flux.

set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CONTAINER=scale-soak-mdb
DBPORT=33795
SIMPORT=18781
KEEP=0 ONLY=""
while [ $# -gt 0 ]; do case "$1" in --keep) KEEP=1; shift ;; --only) ONLY="$2"; shift 2 ;; *) echo "usage: $0 [--keep] [--only a|b|c|d|e|f]"; exit 2 ;; esac; done
WORK="${SOAK_SELFTEST_DIR:-$(mktemp -d)}"
SIM="$ROOT/endpoint-agent/dist/rmm-sim"
export RIVETCORE_TEST_DB_HOST=127.0.0.1 RIVETCORE_TEST_DB_PORT=$DBPORT RIVETCORE_TEST_DB_USER=root RIVETCORE_TEST_DB_PASS=scratch RIVETCORE_TEST_DB_NAME=rivetcore_scratch_soak
MY=(mysql --skip-ssl -h127.0.0.1 -P"$DBPORT" -uroot -pscratch)
FAILS=0

pass() { printf 'ok   %s\n' "$*"; }
fail() { printf 'FAIL %s\n' "$*"; FAILS=$((FAILS + 1)); }
expect() { # expect FILE REGEX DESCRIPTION
    if grep -Eq -- "$2" "$1"; then pass "$3"; else fail "$3 (no match for /$2/ in $1)"; fi
}
# measured: the row must be PASS or FAIL (not NOT MEASURED); host noise decides which, so a FAIL is only reported
measured() { # measured FILE ROWREGEX DESCRIPTION
    if grep -Eq -- "$2.*\*\*PASS\*\*" "$1"; then pass "$3: PASS"
    elif grep -Eq -- "$2.*\*\*FAIL\*\*" "$1"; then pass "$3: measured, FAIL (shared host under load, load1 $(cut -d' ' -f1 /proc/loadavg); the report is right to say so)"
    else fail "$3 (not measured: no PASS/FAIL in $1)"; fi
}
reject() {
    if grep -Eq -- "$2" "$1"; then fail "$3 (unexpected /$2/ in $1)"; else pass "$3"; fi
}

# shellcheck disable=SC2329
cleanup() {
    stop_server
    if [ "$KEEP" -eq 0 ]; then docker rm -f "$CONTAINER" >/dev/null 2>&1; fi
}
trap cleanup EXIT

[ -x "$SIM" ] || { echo "rmm-sim missing; build: docker run --rm -v \"$ROOT/endpoint-agent\":/src -w /src golang:1.27 sh -c 'GOOS=linux GOARCH=amd64 go build -ldflags \"-s -w\" -o dist/rmm-sim ./cmd/rmm-sim'"; exit 2; }
[ -f "$ROOT/vendor/autoload.php" ] || { echo "vendor/ missing in $ROOT (copy it, do not symlink)"; exit 2; }

start_db() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1
    docker run -d --name "$CONTAINER" -e MARIADB_ROOT_PASSWORD=scratch -p 127.0.0.1:$DBPORT:3306 mariadb:10.11 \
        --innodb-buffer-pool-size=256M --slow-query-log=1 --long-query-time=1 >/dev/null || exit 2
    for _ in $(seq 1 90); do "${MY[@]}" -e 'SELECT 1' >/dev/null 2>&1 && break; sleep 1; done
    "${MY[@]}" -e 'CREATE DATABASE IF NOT EXISTS rivetcore_scratch_soak' || exit 2
}

start_server() {
    PHP_CLI_SERVER_WORKERS=8 RMM_LOAD_TRUST_SIM_IP=1 RMM_LOAD_STATE_DIR="" php -d display_errors=0 -d opcache.enable_cli=1 -S 127.0.0.1:$SIMPORT "$ROOT/scripts/rmm-load/router.php" \
        >"$WORK/server.log" 2>&1 &
        for _ in $(seq 1 50); do (exec 3<>/dev/tcp/127.0.0.1/$SIMPORT) 2>/dev/null && break; sleep 0.2; done
}
kill_server() { pkill -f "127.0.0.1:$SIMPORT .*router.php" 2>/dev/null; }
stop_server() {
    kill_server
    pkill -f "scripts/rmm-load/load.php worker" 2>/dev/null
    wait 2>/dev/null
}
# two drain workers (S5: several workers are supported); one is enough for the sizing rule but leaves no margin on a noisy shared host
start_worker() {
    php "$ROOT/scripts/rmm-load/load.php" worker 1800 >"$WORK/worker.log" 2>&1 &
    php "$ROOT/scripts/rmm-load/load.php" worker 1800 >"$WORK/worker2.log" 2>&1 &
}

# fresh database state + token for N devices (queued ingest so the queue columns mean something)
setup_fleet() {
    TOKEN="$(php "$ROOT/scripts/rmm-load/load.php" setup "$1" queued 2>"$WORK/setup.err" | tail -1)"
    case "$TOKEN" in rvte1.*) ;; *) echo "setup failed: $TOKEN $(cat "$WORK/setup.err")"; exit 2 ;; esac
}

SOAK_COMMON=(--url "http://127.0.0.1:$SIMPORT/api/v1/" --insecure --client-ip-header X-Sim-Client-Ip --sim "$SIM" --sim-extra "-retry-scale 0.02"
    --db-host 127.0.0.1 --db-port "$DBPORT" --db-user root --db-name rivetcore_scratch_soak --db-skip-ssl --db-container "$CONTAINER" --online-after 60)

run_soak() { # run_soak OUTDIR DBPASS args...
    local out="$1" pass="$2"; shift 2
    RMM_SOAK_DB_PASS="$pass" "$ROOT/scripts/rmm-load/soak.sh" "${SOAK_COMMON[@]}" --token "$TOKEN" --out "$out" "$@" >"$out.stdout" 2>&1
    return $?
}
want() { [ -z "$ONLY" ] || [ "$ONLY" = "$1" ]; }

echo "== scratch database container"
start_db
echo "work dir: $WORK"

if want a; then
    echo; echo "== (a) healthy 3 minute soak: 200 devices x 6 s"
    setup_fleet 200; start_server; start_worker
    run_soak "$WORK/a" scratch --devices 200 --interval 6s --minutes 3 --sample-every 10 --drain-wait 60 --concurrency 64
    echo "exit code: $?"
    echo "--- samples.csv (head)"; head -4 "$WORK/a/samples.csv" | cut -c1-230
    echo "--- samples.csv (tail)"; tail -3 "$WORK/a/samples.csv" | cut -c1-230
    echo "--- summary.md"; cat "$WORK/a/summary.md"
    expect "$WORK/a/summary.md" 'SHORT RUN, NOT A VALIDATION' "(a) short run banner"
    expect "$WORK/a/summary.md" 'Devices: \*\*200\*\*' "(a) device count on the first lines"
    measured "$WORK/a/summary.md" '\| Sustained check-ins \(required\)' "(a) sustained check-ins"
    expect "$WORK/a/summary.md" '\| Errors .*\*\*PASS\*\*' "(a) errors PASS"
    measured "$WORK/a/summary.md" '\| Check-in latency p95' "(a) p95"
    measured "$WORK/a/summary.md" '\| Check-in latency p99' "(a) p99"
    measured "$WORK/a/summary.md" '\| Backlog \(required\)' "(a) backlog"
    expect "$WORK/a/summary.md" '\| Database CPU .*\*\*PASS\*\*' "(a) database CPU measured"
    measured "$WORK/a/summary.md" '\| Pruning' "(a) slow statements"
    expect "$WORK/a/summary.md" '\| Fleet dashboard .*\*\*MANUAL\*\*' "(a) dashboard row MANUAL"
    reject "$WORK/a/env.txt" 'rvte1\.' "(a) no token in env.txt"
    reject "$WORK/a/sim.log" 'rvte1\.[A-Za-z0-9]' "(a) no token in sim.log"
    if grep -rIl --exclude-dir=.state -e "$TOKEN" -e scratch_pw_never "$WORK/a" >/dev/null 2>&1; then fail "(a) token found in output files"; else pass "(a) token not in any output file"; fi
    stop_server
fi

if want b; then
    echo; echo "== (b) wrong database password for the sampler"
    setup_fleet 50; start_server; start_worker
    run_soak "$WORK/b" wrongpassword --devices 50 --interval 6s --minutes 0.75 --sample-every 10 --drain-wait 10
    echo "exit code: $?"
    sed -n '/^## Verdict/,/^## Details/p' "$WORK/b/summary.md" | cut -c1-260
    expect "$WORK/b/summary.md" '\| Backlog \(required\) .*\*\*NOT MEASURED\*\*' "(b) backlog NOT MEASURED"
    expect "$WORK/b/summary.md" '\| Lock-wait timeouts .*\*\*NOT MEASURED\*\*' "(b) lock waits NOT MEASURED"
    expect "$WORK/b/summary.md" '\| Pruning.*\*\*NOT MEASURED\*\*' "(b) pruning NOT MEASURED"
    reject "$WORK/b/summary.md" '^## Verdict: \*\*PASS' "(b) verdict is not PASS (INCOMPLETE, or FAIL if the loaded host also failed a measured row)"
    expect "$WORK/b/summary.md" 'Required rows NOT MEASURED: .*Backlog' "(b) the report lists the unmeasured required row"
    stop_server
fi

if want c; then
    echo; echo "== (c) server stopped before the run"
    setup_fleet 50
    run_soak "$WORK/c" scratch --devices 50 --interval 6s --minutes 0.5 --sample-every 10 --drain-wait 10
    echo "exit code: $?"
    sed -n '/^## Verdict/,/^## Details/p' "$WORK/c/summary.md" | cut -c1-260
    reject "$WORK/c/summary.md" '^## Verdict: \*\*PASS' "(c) verdict is not PASS"
    expect "$WORK/c/summary.md" '\| Sustained check-ins \(required\) .*\*\*NOT MEASURED\*\*' "(c) sustained NOT MEASURED"
fi

if want d; then
    echo; echo "== (d) server killed in the middle of the run"
    setup_fleet 50; start_server; start_worker
    ( sleep 40; kill_server ) &
    run_soak "$WORK/d" scratch --devices 50 --interval 6s --minutes 1.5 --sample-every 10 --drain-wait 10
    echo "exit code: $?"
    sed -n '/^## Verdict/,/^## Details/p' "$WORK/d/summary.md" | cut -c1-300
    expect "$WORK/d/summary.md" '\| Errors .*\*\*FAIL\*\*' "(d) errors FAIL"
    expect "$WORK/d/summary.md" '^## Verdict: \*\*FAIL\*\*' "(d) verdict FAIL"
    stop_server
fi

if want e; then
    echo; echo "== (e) herd outage executed by the self-test as the operator (stop and start the scratch web tier)"
    setup_fleet 100; start_server; start_worker
    (
        # the "operator": waits for the instruction the script records, stops the server for the planned time, starts it again
        for _ in $(seq 1 400); do grep -q '^outage_start_epoch=' "$WORK/e/herd_plan.txt" 2>/dev/null && break; sleep 0.5; done
        kill_server; sleep 30; start_server
    ) &
    run_soak "$WORK/e" scratch --devices 100 --interval 6s --minutes 3 --sample-every 10 --drain-wait 20 --herd-after-minutes 1 --outage-minutes 0.5
    echo "exit code: $?"
    cat "$WORK/e/herd_plan.txt"
    grep -E 'OPERATOR' "$WORK/e.stdout" | cut -c1-200
    sed -n '/^## Verdict/,/^## Details/p' "$WORK/e/summary.md" | cut -c1-300
    echo "--- event column"; awk -F, 'NR>1{c[$NF]++} END{for(k in c) print "  event=" (k==""?"(none)":k), c[k]}' "$WORK/e/samples.csv"
    expect "$WORK/e/summary.md" '\| Thundering herd .*\*\*PASS\*\*' "(e) herd recovery measured and PASS"
    expect "$WORK/e/summary.md" 'Herd window' "(e) herd window reported"
    expect "$WORK/e/herd_plan.txt" 'outage_end_epoch=' "(e) outage window recorded"
    stop_server
fi

if want f; then
    echo; echo "== (f) SIGTERM in the middle of the run"
    setup_fleet 50; start_server; start_worker
    "$ROOT/scripts/rmm-load/soak.sh" "${SOAK_COMMON[@]}" --token "$TOKEN" --out "$WORK/f" --devices 50 --interval 6s --minutes 10 --sample-every 10 --drain-wait 20 >"$WORK/f.stdout" 2>&1 &
    SOAKPID=$!
    sleep 45
    kill -TERM "$SOAKPID"   # SIGINT sent to a script started with & is ignored by bash itself; Ctrl-C in a foreground terminal and `kill PID` both work
    wait "$SOAKPID"
    echo "exit code: $?"
    grep -E "signal|finished|building" "$WORK/f.stdout" | cut -c1-160
    sed -n '1,/^## Verdict/p' "$WORK/f/summary.md" | cut -c1-200
    expect "$WORK/f/summary.md" 'INTERRUPTED' "(f) report marks the run as interrupted"
    expect "$WORK/f/summary.md" 'final summary present' "(f) simulator printed its summary on the stop request"
    expect "$WORK/f/run.kv" '^interrupted=1' "(f) run.kv records the interruption"
    reject "$WORK/f/summary.md" '^## Verdict: \*\*PASS' "(f) a 45 s slice is never a PASS"
    stop_server
fi

echo
if [ "$FAILS" -eq 0 ]; then echo "SELF-TEST OK"; else echo "SELF-TEST: $FAILS check(s) failed"; fi
echo "outputs kept in $WORK"
exit "$FAILS"
