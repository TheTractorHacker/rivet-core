#!/usr/bin/env bash
# REAL-agent proof of the clock-skew handling (rc.23): the real agent binary (built with the agenttest tag, whose only extra is plain http and the test clock
# RIVETIT_TEST_CLOCK_OFFSET_S) runs on this machine as an ordinary user against `php -S` serving RivetCore\Rmm on a SCRATCH database, with a PC clock that is
# 5 minutes ahead, 20 minutes ahead (enrolled with a right clock, then refused by the 15 minute server window until the agent corrects itself), 20 minutes behind and 3 hours off (a time zone mix-up).
# Each case is a fresh scratch database and a fresh agent state directory. Shows: the device checks in, the server measured a small skew (the agent's stamps
# are server-aligned), the agent reported its own offset, and for the 20 minutes ahead case the first request was refused (a last refusal was recorded and then
# cleared by the accepted retry). No clock is changed on this machine.
#
#   RIVETCORE_TEST_DB_HOST/PORT/USER/PASS/NAME=... [AGENT_BIN=path built with -tags agenttest] e2e/run_clock_proof.sh
#
# Prints "ok:"/"FAIL:" lines and a final "CLOCK PROOF PASS" or "CLOCK PROOF FAIL". Needs docker (only to build the agent), php (mysqli), curl.
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
case "${RIVETCORE_TEST_DB_NAME:-}" in *scratch*) ;; *) echo "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database"; exit 2;; esac
freeport() { python3 -c "import socket;s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1])"; }
PHPPORT="${PHP_PORT:-$(freeport)}"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/rivetit-clockproof.XXXXXX")"
fail=0
ok()  { echo "ok: $*"; }
bad() { echo "FAIL: $*"; fail=1; }
chk() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*2)) ] && return 1; sleep 0.5; done; return 0; }
PHPPID=""; AGPID=""
cleanup() { [ -n "$AGPID" ] && kill "$AGPID" 2>/dev/null; [ -n "$PHPPID" ] && kill "$PHPPID" 2>/dev/null; rm -rf "$WORK"; }
trap cleanup EXIT
dbq() { php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "127.0.0.1", getenv("RIVETCORE_TEST_DB_USER") ?: "root", getenv("RIVETCORE_TEST_DB_PASS") ?: "", getenv("RIVETCORE_TEST_DB_NAME"), (int) (getenv("RIVETCORE_TEST_DB_PORT") ?: 3306)); $r = $m->query($argv[1]); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); echo $row[0] ?? ""; } elseif (!$r) { fwrite(STDERR, $m->error . "\n"); }' "$1"; }

echo "== the agent build (agenttest tag)"
if [ -z "${AGENT_BIN:-}" ]; then
  GOVER="$(sed -n 's/^go \([0-9.]*\).*/\1/p' "$ROOT/go.mod" | head -1)"
  mkdir -p "$WORK/gocache" "$WORK/gomod"
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e CGO_ENABLED=0 -e GOCACHE=/w/gocache -e GOMODCACHE=/w/gomod -v "$ROOT":/src -v "$WORK":/w -w /src "${GO_IMAGE:-golang:$GOVER}" \
    go build -trimpath -tags agenttest -ldflags "-X main.version=0.9.0-clockproof" -o /w/rivetit-agent . || { echo "cannot build the agent"; exit 2; }
  AGENT_BIN="$WORK/rivetit-agent"
fi
[ -x "$AGENT_BIN" ] || { echo "AGENT_BIN $AGENT_BIN not found"; exit 2; }
export RIVETIT_AGENT_ALLOW_NONROOT=1

# case <label> <clock offset seconds> <expected: first request refused yes|no>
run_case() {
  local label=$1 off=$2 refused=$3
  echo "== $label (PC clock $off s from real time)"
  php -r 'mysqli_report(MYSQLI_REPORT_OFF); $n = getenv("RIVETCORE_TEST_DB_NAME"); $m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "127.0.0.1", getenv("RIVETCORE_TEST_DB_USER") ?: "root", getenv("RIVETCORE_TEST_DB_PASS") ?: "", "", (int) (getenv("RIVETCORE_TEST_DB_PORT") ?: 3306));
    $m->query("DROP DATABASE IF EXISTS `$n`"); $m->query("CREATE DATABASE `$n` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); exit($m->error ? 1 : 0);' || { echo "cannot reset the scratch database"; exit 2; }
  rm -rf "$WORK/state" "$WORK/agent"; mkdir -p "$WORK/state" "$WORK/agent"
  local SETUP TOKEN
  SETUP="$(RMM_GOLDEN_ALLOW_LINUX=1 php "$HERE/php_scratch/setup.php" "$WORK/state" "https://127.0.0.1:$PHPPORT")" || { echo "setup failed: $SETUP"; exit 2; }
  TOKEN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')"
  [ -n "$PHPPID" ] && kill "$PHPPID" 2>/dev/null && wait "$PHPPID" 2>/dev/null
  RMM_PROOF_INSECURE=1 RMM_GOLDEN_ALLOW_LINUX=1 PHP_CLI_SERVER_WORKERS=4 RMM_GOLDEN_STATE="$WORK/state" php -d display_errors=0 -d log_errors=1 -d "error_log=$WORK/php.log" -S "127.0.0.1:$PHPPORT" "$HERE/php_scratch/router.php" >/dev/null 2>&1 &
  PHPPID=$!
  wait_for 15 "curl -s -o /dev/null http://127.0.0.1:$PHPPORT/api/v1/nothing" || { bad "php -S did not start"; exit 1; }
  # A "refused" case enrols while the PC clock is still right (so no estimate is stored) and runs after it went wrong: the server's first answer is the refusal.
  local enr=$off; [ "$refused" = yes ] && enr=0
  RIVETIT_TEST_CLOCK_OFFSET_S=$enr "$AGENT_BIN" enroll --state-dir "$WORK/agent" --server "http://127.0.0.1:$PHPPORT" --token "$TOKEN" >"$WORK/enroll.out" 2>&1
  chk "$label: enrolled" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_devices")" = 1 ]'
  RIVETIT_TEST_CLOCK_OFFSET_S=$off "$AGENT_BIN" run --state-dir "$WORK/agent" --no-update --min-interval 2 --pending-interval 2 >"$WORK/agent.out" 2>&1 &
  AGPID=$!
  chk "$label: the device checked in" 'wait_for 60 "[ -n \"\$(dbq \"SELECT last_checkin_at FROM endpoint_agent_devices\")\" ]"'
  sleep 6   # a couple more check-ins so the smoothed estimate settles
  local SK OFFS
  SK="$(dbq "SELECT clock_skew_s FROM endpoint_agent_devices")"; OFFS="$(dbq "SELECT clock_offset_s FROM endpoint_agent_devices")"
  echo "   server measured skew_s=$SK, agent reported clock_offset_s=$OFFS"
  chk "$label: the stamps are server-aligned (|skew| under 30 s)" '[ -n "$SK" ] && [ "${SK#-}" -lt 30 ]'
  chk "$label: the agent reported its own offset (about ${off} negated)" '[ -n "$OFFS" ] && [ "$(( OFFS + off ))" -lt 30 ] && [ "$(( OFFS + off ))" -gt -30 ]'
  chk "$label: no refusal is left on the device" '[ -z "$(dbq "SELECT last_reject_reason FROM endpoint_agent_devices")" ]'
  if [ "$refused" = yes ]; then
    chk "$label: the server refused the first check-in and the agent retried once with corrected stamps" 'grep -q "corrected the stamps and retrying once" "$WORK/agent.out"'
    chk "$label: the agent logged the clock warning" 'grep -q "times are being corrected" "$WORK/agent.out"'
  fi
  kill "$AGPID" 2>/dev/null; wait "$AGPID" 2>/dev/null; AGPID=""
}
run_case "5 minutes ahead" 300 no
run_case "20 minutes ahead" 1200 yes
run_case "20 minutes behind" -1200 no
run_case "3 hours ahead (time zone mix-up)" 10800 yes
[ $fail = 0 ] && echo "CLOCK PROOF PASS" || { echo "CLOCK PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; tail -20 "$WORK/agent.out" 2>/dev/null; exit 1; }
