#!/usr/bin/env bash
# RMM validation soak (scaling item S9, issue #87 preparation): runs the rmm-sim device simulator against a device API for N hours,
# samples the SERVER side once a minute (database, queue, shed level, table sizes) into samples.csv and writes a PASS/FAIL report
# against the targets of docs/rmm/SCALING.md ("Targets for the 10,000-device validation run").
#
# TOOLING ONLY. A run of this script is a validation only when it is 10,000 devices x 24 h on the reference hardware. The report says
# "SHORT RUN, NOT A VALIDATION" otherwise. See docs/rmm/SOAK_RUNBOOK.md.
#
#   soak.sh --url https://rmm.example/api/v1/ --token rvte1.... --devices 10000 --hours 24 --interval 300s \
#           --db-host 10.0.0.5 --db-user soak_ro --db-name rivetit --db-container mariadb --out /var/soak/run1
#
# The script only READS the server (SELECT / SHOW) and never stops or starts a remote service: the herd outage is performed by the
# operator at the moment the script prints. Secrets (token, database password, dashboard cookie) are never written to any file.

set -u
set -o pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
    cat <<'EOF'
usage: soak.sh --url URL --devices N (--hours H | --minutes M) [options]

required
  --url URL              device API base, e.g. https://rmm.example/api/v1/ (the ONLY server that is contacted by the simulator)
  --devices N            simulated devices (1..200000)
  --hours H | --minutes M   run time after enrollment (fractional ok: --hours 0.1666); give exactly one
  --token TOKEN          multi-use enrollment token(s), comma separated for > 5000 devices (or env RMM_SIM_TOKEN)

simulator
  --interval 300s        check-in interval per device (Go duration)          [300s]
  --concurrency 64       requests in flight                                  [64]
  --sim PATH             rmm-sim binary                                      [endpoint-agent/dist/rmm-sim, or rmm-sim in PATH]
  --insecure             allow plain http / unverified TLS (the simulator accepts it for loopback hosts only)
  --client-ip-header H   send a per-device fake client IP in header H (ONLY for the scratch harness router; a real server ignores it)
  --sim-extra "ARGS"     extra rmm-sim arguments, word split (e.g. "-batches 4 -retry-scale 0.5")

output
  --out DIR              output directory (must not exist or be empty)       [./soak-out-<utc timestamp>]
  --sample-every 60      seconds between server samples                      [60]
  --drain-wait 300       seconds to wait after the simulator ends for the ingest queue to drain [300]

database sampling (read only; optional, a missing item gives empty cells and NOT MEASURED rows)
  --db-host H --db-port P --db-user U --db-pass PW (or env RMM_SOAK_DB_PASS) --db-name NAME
  --db-skip-ssl          pass --skip-ssl to the mysql client (scratch containers)
  --db-container NAME    measure database CPU with `docker stats` of this container
  --db-pid PID | --db-pidfile FILE   measure database CPU from /proc/PID/stat (default: pgrep mariadbd/mysqld when the db host is local)
  --db-cores N           cores to divide the database CPU by                 [nproc of this host]
  --online-after S       a device counts as online if it checked in within S seconds [900]
  --web-pids-cmd CMD     command printing the PIDs of the web tier (e.g. 'pgrep -x php-fpm8.3'); adds the web_cpu_pct column

herd (the script only RECORDS the window; the operator performs the outage when told)
  --herd-after-minutes M --outage-minutes K   outage starts M minutes after the measurement start and lasts K minutes

dashboard timing (best effort, curl; every 30 minutes under load and once at the end)
  --dashboard-url URL    fleet dashboard / device list page, target < 2 s (repeatable)
  --graph-url URL        device page graph endpoint, target < 1 s (repeatable)
  --dashboard-cookie 'name=value'   session cookie (never stored; passed to curl through a config pipe)
EOF
}

die() { printf 'soak.sh: %s\n' "$*" >&2; exit 2; }
log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# ---------------------------------------------------------------- arguments
URL="" TOKEN="${RMM_SIM_TOKEN:-}" DEVICES="" HOURS="" MINUTES="" INTERVAL="300s" CONC=64 SIM="" INSECURE=0 IPHDR="" SIM_EXTRA=""
OUT="" SAMPLE_EVERY=60 DRAIN_WAIT=300
DB_HOST="" DB_PORT="" DB_USER="" DB_PASS="${RMM_SOAK_DB_PASS:-}" DB_NAME="" DB_SKIP_SSL=0 DB_CONTAINER="" DB_PID="" DB_PIDFILE="" DB_CORES=""
ONLINE_AFTER=900 WEB_PIDS_CMD="" HERD_AFTER="" OUTAGE_MIN="" DASH_COOKIE=""
DASH_URLS=() GRAPH_URLS=()

need() { [ $# -ge 2 ] || die "option $1 needs a value"; }
while [ $# -gt 0 ]; do
    case "$1" in
        -h|--help) usage; exit 0 ;;
        --url) need "$@"; URL="$2"; shift 2 ;;
        --token) need "$@"; TOKEN="$2"; shift 2 ;;
        --devices) need "$@"; DEVICES="$2"; shift 2 ;;
        --hours) need "$@"; HOURS="$2"; shift 2 ;;
        --minutes) need "$@"; MINUTES="$2"; shift 2 ;;
        --interval) need "$@"; INTERVAL="$2"; shift 2 ;;
        --concurrency) need "$@"; CONC="$2"; shift 2 ;;
        --sim) need "$@"; SIM="$2"; shift 2 ;;
        --insecure) INSECURE=1; shift ;;
        --client-ip-header) need "$@"; IPHDR="$2"; shift 2 ;;
        --sim-extra) need "$@"; SIM_EXTRA="$2"; shift 2 ;;
        --out) need "$@"; OUT="$2"; shift 2 ;;
        --sample-every) need "$@"; SAMPLE_EVERY="$2"; shift 2 ;;
        --drain-wait) need "$@"; DRAIN_WAIT="$2"; shift 2 ;;
        --db-host) need "$@"; DB_HOST="$2"; shift 2 ;;
        --db-port) need "$@"; DB_PORT="$2"; shift 2 ;;
        --db-user) need "$@"; DB_USER="$2"; shift 2 ;;
        --db-pass) need "$@"; DB_PASS="$2"; shift 2 ;;
        --db-name) need "$@"; DB_NAME="$2"; shift 2 ;;
        --db-skip-ssl) DB_SKIP_SSL=1; shift ;;
        --db-container) need "$@"; DB_CONTAINER="$2"; shift 2 ;;
        --db-pid) need "$@"; DB_PID="$2"; shift 2 ;;
        --db-pidfile) need "$@"; DB_PIDFILE="$2"; shift 2 ;;
        --db-cores) need "$@"; DB_CORES="$2"; shift 2 ;;
        --online-after) need "$@"; ONLINE_AFTER="$2"; shift 2 ;;
        --web-pids-cmd) need "$@"; WEB_PIDS_CMD="$2"; shift 2 ;;
        --herd-after-minutes) need "$@"; HERD_AFTER="$2"; shift 2 ;;
        --outage-minutes) need "$@"; OUTAGE_MIN="$2"; shift 2 ;;
        --dashboard-url) need "$@"; DASH_URLS+=("$2"); shift 2 ;;
        --graph-url) need "$@"; GRAPH_URLS+=("$2"); shift 2 ;;
        --dashboard-cookie) need "$@"; DASH_COOKIE="$2"; shift 2 ;;
        *) usage >&2; die "unknown option: $1" ;;
    esac
done

is_num() { [[ "$1" =~ ^[0-9]+(\.[0-9]+)?$ ]]; }
is_int() { [[ "$1" =~ ^[0-9]+$ ]]; }

dur_to_s() {
    awk -v d="$1" 'BEGIN{ s=0; while (match(d,/^[0-9]+(\.[0-9]+)?(ms|s|m|h)/)) { t=substr(d,RSTART,RLENGTH); d=substr(d,RLENGTH+1); u=t; sub(/^[0-9.]+/,"",u); n=t; sub(/(ms|s|m|h)$/,"",n);
        if (u=="h") s+=n*3600; else if (u=="m") s+=n*60; else if (u=="s") s+=n; else s+=n/1000 } if (d!="" || s==0) { print "ERR"; exit } printf "%.3f", s }'
}

[ -n "$URL" ] || die "--url is required (there is no default target)"
[[ "$URL" =~ ^(https?)://([^/[:space:]]+)(/.*)?$ ]] || die "--url must be http(s)://host[/path]: $URL"
URL_SCHEME="${BASH_REMATCH[1]}"; URL_AUTH="${BASH_REMATCH[2]}"
URL_HOSTPORT="${URL_AUTH##*@}"          # drop any userinfo; it is never printed
URL_HOST="${URL_HOSTPORT%%:*}"
[[ "$URL_HOSTPORT" == \[* ]] && URL_HOST="${URL_HOSTPORT%%]*}]"
[[ "$URL" == */ ]] || URL="$URL/"
SAFE_URL="${URL_SCHEME}://${URL_HOSTPORT}${URL#"${URL_SCHEME}"://"${URL_AUTH}"}"
if [ "$URL_SCHEME" = http ] && [ "$INSECURE" -ne 1 ]; then die "plain http needs --insecure (and the simulator only accepts it for loopback hosts); use https"; fi
if [ "$INSECURE" -eq 1 ]; then
    case "$URL_HOST" in localhost|127.*|::1|\[::1\]) ;; *) die "--insecure is only for loopback hosts, not $URL_HOST (the simulator refuses it too)" ;; esac
fi
is_int "$DEVICES" || die "--devices must be an integer"
{ [ "$DEVICES" -ge 1 ] && [ "$DEVICES" -le 200000 ]; } || die "--devices must be 1..200000"
if [ -n "$HOURS" ] && [ -n "$MINUTES" ]; then die "give --hours or --minutes, not both"; fi
if [ -n "$HOURS" ]; then is_num "$HOURS" || die "--hours must be a number"; DURATION_S="$(awk -v h="$HOURS" 'BEGIN{printf "%d", h*3600+0.5}')"
elif [ -n "$MINUTES" ]; then is_num "$MINUTES" || die "--minutes must be a number"; DURATION_S="$(awk -v m="$MINUTES" 'BEGIN{printf "%d", m*60+0.5}')"
else die "--hours (or --minutes) is required"; fi
[ "$DURATION_S" -ge 10 ] || die "the run must be at least 10 seconds"
INTERVAL_S="$(dur_to_s "$INTERVAL")"; [ "$INTERVAL_S" != ERR ] || die "--interval must be a Go duration like 300s, 5m, 1h30m"
is_int "$CONC" || die "--concurrency must be an integer"
is_int "$SAMPLE_EVERY" && [ "$SAMPLE_EVERY" -ge 5 ] || die "--sample-every must be an integer >= 5"
is_int "$DRAIN_WAIT" || die "--drain-wait must be an integer"
is_int "$ONLINE_AFTER" || die "--online-after must be an integer"
[ -n "$TOKEN" ] || die "--token (or env RMM_SIM_TOKEN) is required"
NTOK="$(printf '%s' "$TOKEN" | tr ',' '\n' | grep -c .)"
NEED_TOK=$(( (DEVICES + 4999) / 5000 ))
[ "$NTOK" -ge "$NEED_TOK" ] || die "$DEVICES devices need $NEED_TOK comma separated tokens (a token is capped at 5000 uses), got $NTOK"
if [ -n "$HERD_AFTER$OUTAGE_MIN" ]; then
    { is_num "$HERD_AFTER" && is_num "$OUTAGE_MIN"; } || die "--herd-after-minutes and --outage-minutes go together and must be numbers"
    HERD_AFTER_S="$(awk -v m="$HERD_AFTER" 'BEGIN{printf "%d", m*60}')"; OUTAGE_S="$(awk -v m="$OUTAGE_MIN" 'BEGIN{printf "%d", m*60}')"
    [ "$OUTAGE_S" -ge 1 ] || die "--outage-minutes must be positive"
    [ $((HERD_AFTER_S + OUTAGE_S)) -lt "$DURATION_S" ] || die "the outage must end before the run does ($((HERD_AFTER_S + OUTAGE_S)) s >= $DURATION_S s)"
else HERD_AFTER_S=0; OUTAGE_S=0; fi
if [ -n "$DB_NAME" ]; then [[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || die "--db-name may contain letters, digits and _ only"; fi
if [ -n "$DB_PORT" ]; then is_int "$DB_PORT" || die "--db-port must be an integer"; fi
if [ -n "$DB_PID" ]; then is_int "$DB_PID" || die "--db-pid must be an integer"; fi
[ -z "$DB_CORES" ] || { is_int "$DB_CORES" && [ "$DB_CORES" -ge 1 ]; } || die "--db-cores must be a positive integer"
[ -z "$DB_CONTAINER" ] || [[ "$DB_CONTAINER" =~ ^[A-Za-z0-9_.-]+$ ]] || die "bad --db-container name"
if [ -z "$SIM" ]; then
    if [ -x "$SCRIPT_DIR/../../endpoint-agent/dist/rmm-sim" ]; then SIM="$SCRIPT_DIR/../../endpoint-agent/dist/rmm-sim"; else SIM="$(command -v rmm-sim || true)"; fi
fi
[ -n "$SIM" ] && [ -x "$SIM" ] || die "rmm-sim not found or not executable (--sim PATH); build: see docs/rmm/CAPACITY.md section 9"
command -v php >/dev/null || die "php is needed for the report"
[ -z "$DB_NAME$DB_HOST$DB_USER" ] || command -v mysql >/dev/null || die "the mysql client is needed for database sampling"
[ -z "$DB_CONTAINER" ] || command -v docker >/dev/null || die "docker is needed for --db-container"
[ $((${#DASH_URLS[@]} + ${#GRAPH_URLS[@]})) -eq 0 ] || command -v curl >/dev/null || die "curl is needed for dashboard timing"

[ -n "$OUT" ] || OUT="./soak-out-$(date -u +%Y%m%dT%H%M%SZ)"
if [ -e "$OUT" ] && [ -n "$(ls -A "$OUT" 2>/dev/null)" ]; then die "$OUT exists and is not empty"; fi
mkdir -p "$OUT/.state" || die "cannot create $OUT"
OUT="$(cd "$OUT" && pwd)"
STATE="$OUT/.state"
: > "$OUT/sim.log"; : > "$OUT/sim.jsonl"

SHORT=0
awk -v d="$DURATION_S" -v n="$DEVICES" 'BEGIN{exit !(d < 86400 || n < 10000)}' && SHORT=1
CORES_LOCAL="$(nproc 2>/dev/null || echo 1)"
[ -n "$DB_CORES" ] || DB_CORES="$CORES_LOCAL"

# ---------------------------------------------------------------- plan
echo "================ RMM SOAK PLAN ================"
echo "target API    : $SAFE_URL   ($(getent hosts "$URL_HOST" 2>/dev/null | awk '{print $1; exit}'))"
echo "devices       : $DEVICES   interval $INTERVAL ($(awk -v d="$DEVICES" -v i="$INTERVAL_S" 'BEGIN{printf "%.1f", d/i}') check-ins/s expected)"
echo "run time      : ${DURATION_S}s ($(awk -v s="$DURATION_S" 'BEGIN{printf "%.2f", s/3600}') h) after enrollment"
echo "output        : $OUT"
echo "simulator     : $SIM (concurrency $CONC)"
echo "token         : $( [ -n "$TOKEN" ] && echo "set (${NTOK} token(s), value never printed)" )"
if [ -n "$DB_NAME$DB_HOST$DB_USER" ]; then echo "database      : ${DB_USER:-?}@${DB_HOST:-127.0.0.1}:${DB_PORT:-3306}/${DB_NAME:-?} (read only; CPU via ${DB_CONTAINER:+docker stats $DB_CONTAINER}${DB_PID:+pid $DB_PID}${DB_PIDFILE:+pidfile}$([ -z "$DB_CONTAINER$DB_PID$DB_PIDFILE" ] && echo 'local pgrep when the host is local'))"
else echo "database      : NOT CONFIGURED: server samples will be empty and the DB rows of the report will be NOT MEASURED"; fi
[ "$SHORT" -eq 1 ] && echo "*** SHORT RUN, NOT A VALIDATION (needs >= 24 h and >= 10,000 devices) ***"
if [ "$OUTAGE_S" -gt 0 ]; then
    echo "herd          : outage ${OUTAGE_MIN} min starting ${HERD_AFTER} min after the measurement start. THE OPERATOR performs it: this script does not touch the server."
    echo "                (stop the web tier or switch the RMM module off; see docs/rmm/SOAK_RUNBOOK.md section 7). The exact moment is printed."
fi
echo "================================================"

# ---------------------------------------------------------------- database helpers
MYSQL_ARGS=(--connect-timeout=3 -N -B --force)
MYSQL_ARGS+=(-h "${DB_HOST:-127.0.0.1}")
[ -n "$DB_PORT" ] && MYSQL_ARGS+=(-P "$DB_PORT")
[ -n "$DB_USER" ] && MYSQL_ARGS+=(-u "$DB_USER")
[ "$DB_SKIP_SSL" -eq 1 ] && MYSQL_ARGS+=(--skip-ssl)
DB_ENABLED=0
[ -n "$DB_USER" ] && DB_ENABLED=1
# q SQL: read-only statement(s), 5 s timeout, password through the environment only. Prints nothing on failure.
q() { [ "$DB_ENABLED" -eq 1 ] || return 1; MYSQL_PWD="$DB_PASS" timeout 5 mysql "${MYSQL_ARGS[@]}" -e "$1" 2>/dev/null; }
HOST_LOCAL=0
case "${DB_HOST:-127.0.0.1}" in localhost|127.*|::1) HOST_LOCAL=1 ;; esac

SIM_ARGS=(-url "$URL" -devices "$DEVICES" -interval "$INTERVAL" -duration "${DURATION_S}s" -concurrency "$CONC" -json -report 10s)
[ "$INSECURE" -eq 1 ] && SIM_ARGS+=(-insecure)
[ -n "$IPHDR" ] && SIM_ARGS+=(-client-ip-header "$IPHDR")
if [ -n "$SIM_EXTRA" ]; then read -r -a EXTRA <<< "$SIM_EXTRA"; SIM_ARGS+=("${EXTRA[@]}"); fi

START_EPOCH="$(date +%s)"
START_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"

kv() { printf '%s=%s\n' "$1" "$2" >> "$OUT/run.kv"; }
: > "$OUT/run.kv"
kv url "$SAFE_URL"; kv devices "$DEVICES"; kv interval_s "$INTERVAL_S"; kv duration_planned_s "$DURATION_S"; kv sample_every_s "$SAMPLE_EVERY"
kv concurrency "$CONC"; kv herd_after_s "$HERD_AFTER_S"; kv outage_s "$OUTAGE_S"; kv start_epoch "$START_EPOCH"; kv start_utc "$START_UTC"
kv db_cores "$DB_CORES"; kv online_after_s "$ONLINE_AFTER"; kv db_configured "$DB_ENABLED"; kv short_run "$SHORT"
if [ -n "$DB_CONTAINER" ]; then CPUSRC="docker stats $DB_CONTAINER"; elif [ -n "$DB_PID$DB_PIDFILE" ]; then CPUSRC="/proc pid"; elif [ "$HOST_LOCAL" -eq 1 ]; then CPUSRC="/proc via pgrep mariadbd|mysqld"; else CPUSRC=none; fi
kv db_cpu_source "$CPUSRC"

# ---------------------------------------------------------------- env.txt
write_env() {
    {
        echo "# soak environment (no secrets)"
        echo "started_utc: $START_UTC"
        echo "url: $SAFE_URL"
        echo "devices: $DEVICES   interval: $INTERVAL   duration_s: $DURATION_S   concurrency: $CONC"
        echo "sampler host: $(hostname 2>/dev/null)   nproc: $CORES_LOCAL   kernel: $(uname -sr)"
        echo "memory: $(awk '/MemTotal/{printf "%d MB total", $2/1024}' /proc/meminfo), $(awk '/MemAvailable/{printf "%d MB available", $2/1024}' /proc/meminfo)"
        echo "cpu model: $(awk -F: '/model name/{gsub(/^ +/,"",$2); print $2; exit}' /proc/cpuinfo)"
        echo "ulimit -n: $(ulimit -n)   somaxconn: $(cat /proc/sys/net/core/somaxconn 2>/dev/null)   ip_local_port_range: $(cat /proc/sys/net/ipv4/ip_local_port_range 2>/dev/null)"
        echo "php (sampler host): $(php -r 'echo PHP_VERSION;' 2>/dev/null)   NOTE: the web tier PHP version is NOT this one, record it in the runbook hardware table"
        echo "rmm-sim: $SIM  sha256=$(sha256sum "$SIM" 2>/dev/null | cut -c1-16)  size=$(stat -c %s "$SIM" 2>/dev/null)"
        echo "rmm-sim flags (token passed via RMM_SIM_TOKEN, masked): ${SIM_ARGS[*]} -token ****"
        echo "git rev: $(git -C "$SCRIPT_DIR" rev-parse --short HEAD 2>/dev/null || echo n/a)$(git -C "$SCRIPT_DIR" diff --quiet 2>/dev/null || echo ' (dirty)')"
        echo "database configured: $DB_ENABLED   db cores used for db_cpu_pct: $DB_CORES"
        echo "db_cpu_pct = CPU time of the database server process(es) over the sample interval, as a percentage of ONE core divided by the core count above (100 = every core busy)"
        echo "queue columns assume queued ingest (job_type rmm.ingest); in sync ingest they are all 0 by design"
        if [ "$DB_ENABLED" -eq 1 ]; then
            echo "db version: $(q 'SELECT VERSION()' | head -1)"
            q "SHOW GLOBAL VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','innodb_log_file_size','innodb_flush_log_at_trx_commit','innodb_flush_method','innodb_io_capacity','max_connections','table_open_cache','slow_query_log','long_query_time','performance_schema','sync_binlog','log_bin','thread_handling','innodb_lock_wait_timeout','innodb_buffer_pool_instances')" | awk -F'\t' '{printf "  @@%s = %s\n", $1, $2}'
            if [ -n "$DB_NAME" ]; then q "SELECT CONCAT('ingest_mode = ', ingest_mode, ', shed_level = ', shed_level) FROM \`$DB_NAME\`.endpoint_agent_settings WHERE id = 1" | sed 's/^/endpoint_agent_settings: /'; fi
        fi
    } > "$OUT/env.txt"
}
write_env
if [ "$DB_ENABLED" -eq 1 ]; then
    LQT="$(q "SELECT @@long_query_time" | head -1)"; kv long_query_time "${LQT:-}"
    ING="$( [ -n "$DB_NAME" ] && q "SELECT ingest_mode FROM \`$DB_NAME\`.endpoint_agent_settings WHERE id = 1" | head -1)"; kv ingest_mode "${ING:-}"
    q "SHOW GLOBAL STATUS" > "$OUT/status_before.txt"
    if [ -n "${LQT:-}" ] && awk -v v="$LQT" 'BEGIN{exit !(v > 1)}'; then log "WARNING: long_query_time=$LQT; set it to 1 (SET GLOBAL long_query_time=1) or the pruning row cannot pass"; fi
    [ "${ING:-}" = queued ] || log "WARNING: ingest_mode is '${ING:-unknown}', the queue/backlog columns are only meaningful with queued ingest"
else
    kv long_query_time ""; kv ingest_mode ""
fi

if [ "$OUTAGE_S" -gt 0 ]; then
    {
        echo "planned: outage starts ${HERD_AFTER_S}s after the simulator's 'measurement start' and lasts ${OUTAGE_S}s"
        echo "recorded only: this script does not stop or start anything on the server"
    } > "$OUT/herd_plan.txt"
fi

# ---------------------------------------------------------------- sampler
HEADER="ts_utc,elapsed_s,db_cpu_pct,db_questions_per_s,db_threads_running,db_slow_queries_delta,db_lock_waits_delta,db_lock_time_ms_delta,db_lock_wait_timeouts_delta,db_max_stmt_ms_seen,load1,mem_available_mb,queue_pending,queue_running,queue_dead_letter,queue_oldest_pending_age_s,checkins_last_min,shed_level,devices_online,tbl_checkins_mb,tbl_devices_mb,tbl_check_history_mb,tbl_metric_hourly_mb,tbl_metric_daily_mb,tbl_ingest_jobs_mb,herd_pending_devices,web_cpu_pct,event"
echo "$HEADER" > "$OUT/samples.csv"

dash_time() {  # kind url -> appends rows to dashboard.csv (cookie only through curl's config pipe)
    local kind="$1" url="$2" out code tt safe="${2%%\?*}"
    for _ in 1 2 3; do
        if [ -n "$DASH_COOKIE" ]; then
            out="$(printf 'header = "Cookie: %s"\n' "$DASH_COOKIE" | curl -sS -K - -o /dev/null --max-time 30 -w '%{http_code} %{time_total}' "$url" 2>/dev/null)"
        else
            out="$(curl -sS -o /dev/null --max-time 30 -w '%{http_code} %{time_total}' "$url" 2>/dev/null)"
        fi
        code="${out%% *}"; tt="${out##* }"
        printf '%s,%s,%s,%s,%s,%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$kind" "$safe" "${code:-}" "${tt:-}" "$( [ -e "$STATE/drain" ] && echo end || echo load )" >> "$OUT/dashboard.csv"
    done
}
dash_all() { local u; for u in "${DASH_URLS[@]}"; do dash_time dashboard "$u"; done; for u in "${GRAPH_URLS[@]}"; do dash_time graph "$u"; done; }

sample_loop() {
    local -A PREV=() ST=() SZ=()
    local prev_epoch="" prev_db_ticks="" prev_web_ticks="" last_size=0 next dash_next=0 clk
    clk="$(getconf CLK_TCK 2>/dev/null || echo 100)"
    local now_f

    ticks_of() {  # pids on stdin-args -> total utime+stime ticks, empty when none readable
        local p t total=0 any=0
        for p in "$@"; do
            t="$(awk '{ sub(/^.*\) /, ""); print $12 + $13 }' "/proc/$p/stat" 2>/dev/null)" || continue
            [ -n "$t" ] || continue
            total=$((total + t)); any=1
        done
        [ "$any" -eq 1 ] && echo "$total"
    }
    delta() {  # delta key current -> RET (empty when unknown/reset); keeps the last good value so a gap is covered by the next sample
        local k="$1" cur="$2"
        RET=""
        [[ "$cur" =~ ^[0-9]+$ ]] || return 0
        if [[ "${PREV[$k]:-}" =~ ^[0-9]+$ ]] && [ "$cur" -ge "${PREV[$k]}" ]; then RET=$((cur - PREV[$k])); fi
        PREV[$k]="$cur"
    }
    num() { [[ "$1" =~ ^-?[0-9]+(\.[0-9]+)?$ ]] && echo "$1"; }

    sample_once() {
        local epoch ts elapsed ev k v a b c d
        epoch="$(date +%s.%N)"; ts="$(date -u +%Y-%m-%dT%H:%M:%SZ)"; elapsed=$(( ${epoch%.*} - START_EPOCH ))
        local dt=""; [ -n "$prev_epoch" ] && dt="$(awk -v a="$epoch" -v b="$prev_epoch" 'BEGIN{printf "%.3f", a-b}')"

        # event marker
        ev=""
        if [ ! -e "$STATE/meas_start" ]; then ev="enrolling"
        elif [ -e "$STATE/drain" ]; then ev="drain"
        elif [ -e "$STATE/outage_start" ] && [ ! -e "$STATE/outage_end" ]; then ev="outage"
        elif [ -e "$STATE/outage_end" ] && [ $(( ${epoch%.*} - $(cat "$STATE/outage_end") )) -lt 900 ]; then ev="recovery"; fi

        # database CPU
        local db_cpu="" web_cpu="" t pids
        if [ -n "$DB_CONTAINER" ]; then
            t="$(timeout 10 docker stats --no-stream --format '{{.CPUPerc}}' "$DB_CONTAINER" 2>/dev/null | tr -d '% \r')"
            [ -n "$(num "$t")" ] && db_cpu="$(awk -v c="$t" -v n="$DB_CORES" 'BEGIN{printf "%.1f", c/n}')"
        else
            pids=""
            if [ -n "$DB_PID" ]; then pids="$DB_PID"
            elif [ -n "$DB_PIDFILE" ]; then pids="$(tr -d '[:space:]' < "$DB_PIDFILE" 2>/dev/null)"
            elif [ "$HOST_LOCAL" -eq 1 ]; then pids="$(pgrep -x 'mariadbd|mysqld' 2>/dev/null | tr '\n' ' ')"; fi
            if [ -n "$pids" ]; then
                # shellcheck disable=SC2086
                t="$(ticks_of $pids)"
                if [ -n "$t" ] && [ -n "$prev_db_ticks" ] && [ -n "$dt" ] && [ "$t" -ge "$prev_db_ticks" ]; then
                    db_cpu="$(awk -v a="$t" -v b="$prev_db_ticks" -v dt="$dt" -v clk="$clk" -v n="$DB_CORES" 'BEGIN{printf "%.1f", (a-b)/clk/dt*100/n}')"
                fi
                prev_db_ticks="$t"
            fi
        fi
        if [ -n "$WEB_PIDS_CMD" ]; then
            pids="$(timeout 5 bash -c "$WEB_PIDS_CMD" 2>/dev/null | tr '\n' ' ')"
            if [ -n "$pids" ]; then
                # shellcheck disable=SC2086
                t="$(ticks_of $pids)"
                if [ -n "$t" ] && [ -n "$prev_web_ticks" ] && [ -n "$dt" ] && [ "$t" -ge "$prev_web_ticks" ]; then
                    web_cpu="$(awk -v a="$t" -v b="$prev_web_ticks" -v dt="$dt" -v clk="$clk" -v n="$CORES_LOCAL" 'BEGIN{printf "%.1f", (a-b)/clk/dt*100/n}')"
                fi
                prev_web_ticks="$t"
            fi
        fi

        # global status
        ST=(); local qps="" thr="" slow="" lw="" lt="" lto="" maxstmt=""
        if [ "$DB_ENABLED" -eq 1 ]; then
            while IFS=$'\t' read -r k v; do [ -n "$k" ] && ST["$k"]="$v"; done < <(q "SHOW GLOBAL STATUS WHERE Variable_name IN ('Questions','Threads_running','Slow_queries','Innodb_row_lock_waits','Innodb_row_lock_time','Innodb_lock_wait_timeouts','Innodb_row_lock_wait_timeouts')")
            thr="$(num "${ST[Threads_running]:-}")"
            delta questions "${ST[Questions]:-}"
            if [ -n "$RET" ] && [ -n "$dt" ] && [ -n "${PREV_Q_EPOCH:-}" ]; then qps="$(awk -v d="$RET" -v t="$(awk -v a="$epoch" -v b="$PREV_Q_EPOCH" 'BEGIN{print a-b}')" 'BEGIN{ if (t>0) printf "%.1f", d/t }')"; fi
            [[ "${ST[Questions]:-}" =~ ^[0-9]+$ ]] && PREV_Q_EPOCH="$epoch"
            delta slow "${ST[Slow_queries]:-}"; slow="$RET"
            delta lw "${ST[Innodb_row_lock_waits]:-}"; lw="$RET"
            delta lt "${ST[Innodb_row_lock_time]:-}"; lt="$RET"
            local tmo="${ST[Innodb_lock_wait_timeouts]:-${ST[Innodb_row_lock_wait_timeouts]:-}}"
            if [ -z "$tmo" ]; then   # MariaDB: ENABLED = 1; MySQL: STATUS = 'enabled' (a disabled metric stays 0, so it must not be read)
                tmo="$(q "SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_timeouts' AND ENABLED = 1" | head -1)"
                [ -n "$tmo" ] || tmo="$(q "SELECT COUNT FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_timeouts' AND STATUS = 'enabled'" | head -1)"
            fi
            delta lto "$tmo"; lto="$RET"
        fi

        # queue, check-ins, shed, devices, herd catch-up, max statement, sizes (one connection, labelled rows, --force so one failure does not hide the others)
        local qp="" qr="" qd="" qa="" ck="" sh="" on="" hp=""
        if [ "$DB_ENABLED" -eq 1 ] && [ -n "$DB_NAME" ]; then
            local sql="SELECT 'queue', COALESCE(SUM(status='pending'),0), COALESCE(SUM(status='running'),0), COALESCE(SUM(status='dead_letter'),0), COALESCE(MAX(CASE WHEN status='pending' THEN TIMESTAMPDIFF(SECOND, created_at, NOW()) END),0) FROM \`$DB_NAME\`.integration_jobs WHERE job_type='rmm.ingest' AND status IN ('pending','running','dead_letter');
SELECT 'checkins', COUNT(*) FROM \`$DB_NAME\`.endpoint_agent_checkins WHERE received_at >= NOW() - INTERVAL 1 MINUTE;
SELECT 'shed', shed_level FROM \`$DB_NAME\`.endpoint_agent_settings WHERE id = 1;
SELECT 'online', COUNT(*) FROM \`$DB_NAME\`.endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL AND last_checkin_at >= UTC_TIMESTAMP() - INTERVAL $ONLINE_AFTER SECOND;
SELECT 'maxstmt', ROUND(MAX(MAX_TIMER_WAIT)/1000000000, 1) FROM performance_schema.events_statements_summary_by_digest WHERE SCHEMA_NAME = '$DB_NAME';"
            if [ -e "$STATE/outage_end" ]; then
                sql="$sql
SELECT 'herd', COUNT(*) FROM \`$DB_NAME\`.endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL AND (last_checkin_at IS NULL OR last_checkin_at < UTC_TIMESTAMP() - INTERVAL (UNIX_TIMESTAMP() - $(cat "$STATE/outage_end")) SECOND);"
            fi
            if [ $(( ${epoch%.*} - last_size )) -ge 600 ]; then
                sql="$sql
SELECT 'size', TABLE_NAME, ROUND((DATA_LENGTH + INDEX_LENGTH)/1048576, 2) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$DB_NAME' AND TABLE_NAME IN ('endpoint_agent_checkins','endpoint_agent_devices','endpoint_agent_check_history','rmm_metric_hourly','rmm_metric_daily','integration_jobs');"
            fi
            local gotsize=0
            while IFS=$'\t' read -r k a b c d; do
                case "$k" in
                    queue) qp="$a"; qr="$b"; qd="$c"; qa="$d" ;;
                    checkins) ck="$a" ;;
                    shed) sh="$a" ;;
                    online) on="$a" ;;
                    herd) hp="$a" ;;
                    maxstmt) [ "$a" != NULL ] && maxstmt="$a" ;;
                    size) SZ["$a"]="$b"; gotsize=1 ;;
                esac
            done < <(q "$sql")
            [ "$gotsize" -eq 1 ] && last_size="${epoch%.*}"
            { [ -n "$qp" ] && echo "$qp $qr" > "$STATE/last_queue"; } || : > "$STATE/last_queue"
        fi

        local load1 mem
        load1="$(cut -d' ' -f1 /proc/loadavg 2>/dev/null)"
        mem="$(awk '/MemAvailable/{printf "%d", $2/1024}' /proc/meminfo 2>/dev/null)"

        printf '%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
            "$ts" "$elapsed" "$db_cpu" "$qps" "$thr" "$slow" "$lw" "$lt" "$lto" "$maxstmt" "$load1" "$mem" "$qp" "$qr" "$qd" "$qa" "$ck" "$sh" "$on" \
            "${SZ[endpoint_agent_checkins]:-}" "${SZ[endpoint_agent_devices]:-}" "${SZ[endpoint_agent_check_history]:-}" "${SZ[rmm_metric_hourly]:-}" "${SZ[rmm_metric_daily]:-}" "${SZ[integration_jobs]:-}" \
            "$hp" "$web_cpu" "$ev" >> "$OUT/samples.csv"
        prev_epoch="$epoch"
    }

    while [ ! -e "$STATE/stop" ]; do
        sample_once
        [ -e "$STATE/final_sample" ] && rm -f "$STATE/final_sample"
        now_f="$(date +%s)"
        if [ $((${#DASH_URLS[@]} + ${#GRAPH_URLS[@]})) -gt 0 ] && [ "$now_f" -ge "$dash_next" ] && [ -e "$STATE/meas_start" ] && [ ! -e "$STATE/drain" ]; then
            ( dash_all ) &
            dash_next=$((now_f + 1800))
        fi
        # sleep to the next slot; faster during the outage recovery and the drain; interruptible by the stop / now files
        local every="$SAMPLE_EVERY" waited=0
        next=$SAMPLE_EVERY
        while [ "$waited" -lt "$next" ] && [ ! -e "$STATE/stop" ] && [ ! -e "$STATE/sample_now" ]; do
            sleep 1; waited=$((waited + 1))
            if [ -e "$STATE/drain" ] || { [ -e "$STATE/outage_end" ] && [ $(( $(date +%s) - $(cat "$STATE/outage_end") )) -lt 900 ]; }; then
                [ "$every" -gt 10 ] && every=10; next=$every
            fi
        done
        rm -f "$STATE/sample_now"
    done
    sample_once          # the closing sample
    wait
}

# ---------------------------------------------------------------- signals, start
INTERRUPTED=0 SIM_PID="" SAMPLER_PID=""
# shellcheck disable=SC2329
on_signal() {
    if [ "$INTERRUPTED" -ge 1 ]; then
        log "second signal: killing the simulator, skipping the drain wait"
        [ -n "$SIM_PID" ] && kill -KILL "$SIM_PID" 2>/dev/null
        INTERRUPTED=2
        return
    fi
    INTERRUPTED=1
    log "signal received: stopping the simulator (it prints its summary), then the report is built"
    [ -n "$SIM_PID" ] && kill -INT "$SIM_PID" 2>/dev/null
}
trap on_signal INT TERM

sample_loop &
SAMPLER_PID=$!

log "starting rmm-sim"
RMM_SIM_TOKEN="$TOKEN" "$SIM" "${SIM_ARGS[@]}" \
    > >(awk -v j="$OUT/sim.jsonl" -v l="$OUT/sim.log" '/^\{/ {print >> j; fflush(j); next} {print >> l; fflush(l)}') 2>&1 &
SIM_PID=$!
echo "$SIM_PID" > "$STATE/sim_pid"
unset TOKEN

is_running() { local s; s="$(awk '/^State:/{print $2}' "/proc/$1/status" 2>/dev/null)"; [ -n "$s" ] && [ "$s" != Z ]; }

MEAS_EPOCH=""; OUTAGE_STARTED=0; OUTAGE_ENDED=0; LAST_STATUS=0
poll_events() {
    local now rel
    now="$(date +%s)"
    if [ -z "$MEAS_EPOCH" ] && grep -q 'measurement start' "$OUT/sim.log" 2>/dev/null; then
        MEAS_EPOCH="$now"; echo "$now" > "$STATE/meas_start"; kv meas_start_epoch "$now"
        log "measurement started (all devices enrolled). Run ends in ${DURATION_S}s."
        if [ "$OUTAGE_S" -gt 0 ]; then
            log "HERD PLAN: outage from $(date -u -d "@$((now + HERD_AFTER_S))" +%H:%M:%SZ) to $(date -u -d "@$((now + HERD_AFTER_S + OUTAGE_S))" +%H:%M:%SZ) (the operator acts when told)"
            echo "meas_start_epoch=$now" >> "$OUT/herd_plan.txt"
        fi
    fi
    if [ -n "$MEAS_EPOCH" ] && [ "$OUTAGE_S" -gt 0 ]; then
        rel=$((now - MEAS_EPOCH))
        if [ "$OUTAGE_STARTED" -eq 0 ] && [ "$rel" -ge "$HERD_AFTER_S" ]; then
            OUTAGE_STARTED=1; echo "$now" > "$STATE/outage_start"; kv outage_start_epoch "$now"; echo "outage_start_epoch=$now" >> "$OUT/herd_plan.txt"
            log ">>> OPERATOR: START THE OUTAGE NOW (stop the web tier or switch the RMM module off) for ${OUTAGE_MIN} minutes. This script has not touched the server. <<<"
            printf '\a'
        fi
        if [ "$OUTAGE_STARTED" -eq 1 ] && [ "$OUTAGE_ENDED" -eq 0 ] && [ "$rel" -ge $((HERD_AFTER_S + OUTAGE_S)) ]; then
            OUTAGE_ENDED=1; echo "$now" > "$STATE/outage_end"; kv outage_end_epoch "$now"; echo "outage_end_epoch=$now" >> "$OUT/herd_plan.txt"
            log ">>> OPERATOR: END THE OUTAGE NOW (restart the web tier / switch the module back on). Recovery is measured from this moment. <<<"
            printf '\a'
        fi
    fi
    if [ $((now - LAST_STATUS)) -ge 300 ]; then
        LAST_STATUS="$now"
        log "sim: $(tail -n 1 "$OUT/sim.log" 2>/dev/null | cut -c1-160)"
        log "sample: $(tail -n 1 "$OUT/samples.csv" | cut -c1-200)"
    fi
}

while is_running "$SIM_PID"; do
    poll_events
    sleep 1
done
wait "$SIM_PID" 2>/dev/null; SIM_RC=$?
# a signal during `wait` is handled by the trap; the loop above already ended
sleep 1   # let the output splitter flush
poll_events
log "simulator finished (exit $SIM_RC)"

# an unfinished outage (interrupted run): close the window at the end
if [ "$OUTAGE_STARTED" -eq 1 ] && [ "$OUTAGE_ENDED" -eq 0 ]; then
    n="$(date +%s)"; echo "$n" > "$STATE/outage_end"; kv outage_end_epoch "$n"; echo "outage_end_epoch=$n (run ended inside the window)" >> "$OUT/herd_plan.txt"
fi

# drain: sample every 10 s until the queue is empty or --drain-wait elapsed
touch "$STATE/drain"; touch "$STATE/sample_now"
if [ "$INTERRUPTED" -lt 2 ] && [ "$DB_ENABLED" -eq 1 ]; then
    kv sim_end_epoch "$(date +%s)"
    end=$(( $(date +%s) + DRAIN_WAIT )); log "waiting up to ${DRAIN_WAIT}s for the ingest queue to drain"
    sleep 3
    while [ "$(date +%s)" -lt "$end" ] && [ "$INTERRUPTED" -lt 2 ]; do
        read -r p r < "$STATE/last_queue" 2>/dev/null || { p=""; r=""; }
        if [ "$p" = 0 ] && [ "$r" = 0 ]; then break; fi
        sleep 2
    done
else
    kv sim_end_epoch "$(date +%s)"
fi

# final dashboard timing (after the load, but still best effort) and shutdown of the sampler
if [ $((${#DASH_URLS[@]} + ${#GRAPH_URLS[@]})) -gt 0 ] && [ "$INTERRUPTED" -lt 2 ]; then
    log "timing the dashboard URLs"
    dash_all
fi
touch "$STATE/final_sample" "$STATE/stop"
for _ in $(seq 1 40); do is_running "$SAMPLER_PID" || break; sleep 0.5; done
is_running "$SAMPLER_PID" && kill "$SAMPLER_PID" 2>/dev/null
wait "$SAMPLER_PID" 2>/dev/null

END_UTC="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
kv end_epoch "$(date +%s)"; kv end_utc "$END_UTC"; kv sim_exit_code "$SIM_RC"; kv interrupted "$INTERRUPTED"
{ echo "ended_utc: $END_UTC   simulator exit: $SIM_RC   interrupted: $INTERRUPTED"; } >> "$OUT/env.txt"
[ "$DB_ENABLED" -eq 1 ] && q "SHOW GLOBAL STATUS" > "$OUT/status_after.txt"

# defence in depth: no token-looking string in any output file (the token only ever travelled through the environment)
find "$OUT" -maxdepth 1 -type f \( -name '*.log' -o -name '*.txt' -o -name '*.kv' -o -name '*.jsonl' \) -exec sed -i -E 's/rvte1\.[A-Za-z0-9._-]+/rvte1.****/g' {} +

log "building the report"
php "$SCRIPT_DIR/soak-report.php" "$OUT"
RC=$?
echo
echo "output directory: $OUT (summary.md, summary.json, samples.csv, sim.jsonl, sim.log, env.txt)"
exit "$RC"
