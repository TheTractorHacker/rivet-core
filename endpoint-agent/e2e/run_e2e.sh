#!/usr/bin/env bash
# End-to-end harness for the Linux build of the RMM endpoint agent (runs as an ordinary user, without systemd).
# Nothing here needs Windows. Two modes:
#
#   1. Against a real RivetIT scratch server (the lead integrates this):
#        RIVETIT_E2E_SERVER_URL=https://scratch.example  RIVETIT_E2E_TOKEN=<enrollment token> \
#        [RIVETIT_E2E_CA=/path/ca.pem] [RIVETIT_E2E_SECONDS=90] ./e2e/run_e2e.sh
#      The agent enrolls, runs for N seconds, and the script checks the AGENT side
#      (status, seq progress, last check-in). Verify the SERVER side (asset linked,
#      metrics rows, alerts) in RivetIT afterwards; the script prints what to look for.
#      Jobs are NOT queued in this mode (the fake server is not involved).
#
#      REAL-SERVER PREREQUISITES
#        * The server must accept Linux devices (the RMM module with Linux support; RivetIT editions that
#          predate it need EA_ALLOW_NON_WINDOWS = true on scratch installs only). Plain http is refused by the agent; give an https URL (and RIVETIT_E2E_CA for a private CA).
#        * An unmatched device waits in pending_approval/ambiguous until an administrator approves it.
#          Either approve it by hand in RivetIT (Administration > Endpoint agent > approval queue)
#          while this script waits, or set E2E_APPROVE_CMD to a shell command that approves it. The
#          command runs once, after enrollment, with these exported variables:
#            E2E_STATE_DIR  agent state dir      E2E_DEVICE_ID  device id from enrollment
#            E2E_INSTALL_ID agent install id     E2E_SERVER_URL server URL
#          Example:  E2E_APPROVE_CMD='php /var/www/scratch/tools/approve_device.php "$E2E_DEVICE_ID"'
#        * The script waits up to E2E_APPROVE_WAIT seconds (default 120) for the status to become
#          linked, polling the agent at E2E_PENDING_INTERVAL seconds (default 10) instead of the
#          production 300 s low rate. E2E_MIN_INTERVAL (default 5) lowers the interval clamp so the
#          run produces several check-ins. Other knobs: RIVETIT_E2E_SECONDS (run time, default 90).
#
#      Stamped-installer check against the real server (optional): set RIVETIT_E2E_STAMPED_TOKEN to a SECOND,
#      unused enrollment token (the first is consumed by the plain enroll above). The script then builds a
#      TEST/DEV-only binary (-tags devtools), stamps it, runs `setup --silent --no-service` and checks
#      enrollment plus that the staged copy holds no token.
#
#   2. Self-contained (RIVETIT_E2E_SERVER_URL unset): starts e2e/fakeserver (a
#      contract-shaped stand-in), exercises enroll, check-ins (with the platform/capabilities block), a signed
#      shell job, a PowerShell job that must be answered unsupported_platform, and the stamped installer.
#      The fuller Linux scenario (systemd-less container, update swap, offline buffering, module_disabled,
#      install script) is e2e/run_linux_container_e2e.sh.
set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
export PATH="${GO_BIN_DIR:-$HOME/.local/go/bin}:/usr/local/go/bin:$PATH"
# Setup/install insist on root; this harness runs unprivileged with --no-service in directories it owns.
export RIVETIT_AGENT_ALLOW_NONROOT=1
WORK="${RIVETIT_E2E_WORK:-$ROOT/e2e/work}"
SECS="${RIVETIT_E2E_SECONDS:-90}"
rm -rf "$WORK"; mkdir -p "$WORK/state"; rm -f /tmp/e2e-foreign-ran

echo "== building linux test binary"
( cd "$ROOT" && go build -trimpath -o "$WORK/rivetit-agent" . && go build -o "$WORK/fakeserver" ./e2e/fakeserver )

FAKE_PID=""
cleanup() { [ -n "${AGENT_PID:-}" ] && kill "$AGENT_PID" 2>/dev/null || true; [ -n "$FAKE_PID" ] && kill "$FAKE_PID" 2>/dev/null || true; }
trap cleanup EXIT

CA_ARGS=()
if [ -z "${RIVETIT_E2E_SERVER_URL:-}" ]; then
  MODE=fake
  "$WORK/fakeserver" -dir "$WORK/fake" -interval 3 -collect 5 -job-type shell -foreign-job -job-script 'echo e2e-job-ok; exit 0' >"$WORK/fakeserver.out" 2>&1 &
  FAKE_PID=$!
  for _ in $(seq 1 50); do [ -s "$WORK/fake/url" ] && break; sleep 0.1; done
  SERVER="$(cat "$WORK/fake/url")"; TOKEN="E2E-ENROLL-TOKEN"; CA_ARGS=(--ca "$WORK/fake/ca.pem")
  SECS="${RIVETIT_E2E_SECONDS:-20}"
else
  MODE=real
  SERVER="$RIVETIT_E2E_SERVER_URL"; TOKEN="${RIVETIT_E2E_TOKEN:?RIVETIT_E2E_TOKEN is required}"
  [ -n "${RIVETIT_E2E_CA:-}" ] && CA_ARGS=(--ca "$RIVETIT_E2E_CA")
fi
echo "== mode=$MODE server=$SERVER"

# stamped_check SERVER TOKEN [CA_FILE]: stamp a copy of the (devtools) binary, run the unattended setup in
# Linux test mode and verify enrollment, idempotence and that no token reaches the staged copy.
stamped_check() {
  local srv="$1" tok="$2" ca="${3:-}" st="$WORK/stamped-state" code
  echo "== stamped installer: build dev stamper, stamp, setup --silent --no-service"
  ( cd "$ROOT" && go build -trimpath -tags devtools -o "$WORK/rivetit-agent-dev" . )
  local stamp_args=(--in "$WORK/rivetit-agent" --out "$WORK/installer-stamped" --server "$srv" --token "$tok" --department "E2E Dept" --ttl 1h)
  [ -n "$ca" ] && stamp_args+=(--ca "$ca")
  "$WORK/rivetit-agent-dev" stamp "${stamp_args[@]}" >/dev/null
  grep -aq "$tok" "$WORK/installer-stamped" && echo "ok: stamped file embeds the token (expected, documented)" || { echo "FAIL: stamp lost the token"; fail=1; }
  rm -rf "$st"
  set +e
  "$WORK/installer-stamped" setup --silent --no-service --state-dir "$st" --install-dir "$st/bin" >"$WORK/stamped-setup.out" 2>&1; code=$?
  set -e
  [ "$code" = 0 ] && echo "ok: setup --silent exit 0" || { echo "FAIL: setup exit $code"; cat "$WORK/stamped-setup.out"; fail=1; }
  [ -s "$st/device.token" ] && echo "ok: stamped setup enrolled (device token present)" || { echo "FAIL: no device token after setup"; fail=1; }
  [ -s "$st/install.log" ] && echo "ok: install.log written" || { echo "FAIL: no install.log"; fail=1; }
  if grep -arq -- "$tok" "$st" 2>/dev/null; then echo "FAIL: token found under the state dir ($st)"; fail=1; else echo "ok: no token anywhere under the state dir (staged copy, config, log)"; fi
  if grep -aq -- "$tok" "$WORK/stamped-setup.out"; then echo "FAIL: token printed by setup"; fail=1; else echo "ok: token not printed"; fi
  local staged="$st/bin/rivetit-agent" orig_size
  orig_size=$(stat -c %s "$WORK/rivetit-agent")
  if [ -x "$staged" ] && [ "$(stat -c %s "$staged")" = "$orig_size" ] && cmp -s "$staged" "$WORK/rivetit-agent"; then
    echo "ok: staged copy is byte-identical to the unstamped binary"
  else echo "FAIL: staged copy missing or differs from the unstamped binary"; fail=1; fi
  # idempotence: the same installer again keeps the device identity
  local id1 id2
  id1=$("$WORK/rivetit-agent" status --state-dir "$st" | sed -n 's/^install id: *//p')
  set +e; "$WORK/installer-stamped" setup --silent --no-service --state-dir "$st" --install-dir "$st/bin" >>"$WORK/stamped-setup.out" 2>&1; code=$?; set -e
  id2=$("$WORK/rivetit-agent" status --state-dir "$st" | sed -n 's/^install id: *//p')
  [ "$code" = 0 ] && [ -n "$id1" ] && [ "$id1" = "$id2" ] && echo "ok: second run is idempotent (install id kept)" || { echo "FAIL: rerun exit=$code id1=$id1 id2=$id2"; fail=1; }
  # an unstamped binary refuses with exit 2
  set +e; "$WORK/rivetit-agent" setup --silent --no-service --state-dir "$WORK/none" >/dev/null 2>&1; code=$?; set -e
  [ "$code" = 2 ] && echo "ok: unstamped exe -> setup exit 2" || { echo "FAIL: unstamped setup exit $code"; fail=1; }
}
fail=0

A="$WORK/rivetit-agent"
echo "== enroll"
printf '%s' "$TOKEN" >"$WORK/token"; chmod 600 "$WORK/token"
"$A" enroll --state-dir "$WORK/state" --server "$SERVER" "${CA_ARGS[@]}" --token-file "$WORK/token"
rm -f "$WORK/token"

echo "== run for ${SECS}s"
RUN_ARGS=(--state-dir "$WORK/state" --no-update)
if [ "$MODE" = real ]; then
  RUN_ARGS+=(--pending-interval "${E2E_PENDING_INTERVAL:-10}" --min-interval "${E2E_MIN_INTERVAL:-5}")
else
  RUN_ARGS+=(--min-interval 3)
fi
"$A" run "${RUN_ARGS[@]}" >"$WORK/agent.out" 2>&1 &
AGENT_PID=$!
if [ "$MODE" = real ]; then
  export E2E_STATE_DIR="$WORK/state" E2E_SERVER_URL="$SERVER"
  E2E_DEVICE_ID="$("$A" status --state-dir "$WORK/state" | sed -n 's/^device id: *//p')"; export E2E_DEVICE_ID
  E2E_INSTALL_ID="$("$A" status --state-dir "$WORK/state" | sed -n 's/^install id: *//p')"; export E2E_INSTALL_ID
  if [ -n "${E2E_APPROVE_CMD:-}" ]; then
    echo "== approving device $E2E_DEVICE_ID via E2E_APPROVE_CMD"
    bash -c "$E2E_APPROVE_CMD" || { echo "FAIL: E2E_APPROVE_CMD failed"; exit 1; }
  else
    echo "== if the device is pending, approve it in RivetIT now (waiting up to ${E2E_APPROVE_WAIT:-120}s)"
  fi
  waited=0
  until "$A" status --state-dir "$WORK/state" | grep -q "enrolled and linked"; do
    [ "$waited" -ge "${E2E_APPROVE_WAIT:-120}" ] && { echo "FAIL: device never became linked (still pending/ambiguous: approve it, or set E2E_APPROVE_CMD)"; exit 1; }
    sleep 2; waited=$((waited+2))
  done
  echo "ok: device linked after ${waited}s"
fi
sleep "$SECS"
kill "$AGENT_PID"; wait "$AGENT_PID" 2>/dev/null || true; AGENT_PID=""

echo "== status"
"$A" status --state-dir "$WORK/state" | tee "$WORK/status.txt"

check() { if ! grep -q "$1" "$WORK/status.txt"; then echo "FAIL: status lacks '$1'"; fail=1; else echo "ok: $2"; fi; }
check "device id:   .\+" "device id assigned"
check "last check-in: 20" "agent checked in"
seq=$(sed -n 's/^check-in seq: //p' "$WORK/status.txt")
[ "${seq:-0}" -ge 2 ] && echo "ok: seq advanced to $seq" || { echo "FAIL: seq=$seq"; fail=1; }
if grep -q "REVOKED" "$WORK/agent.out"; then echo "note: agent reported revocation"; fi
perm=$(stat -c %a "$WORK/state/device.token"); [ "$perm" = 600 ] && echo "ok: device.token is 0600" || { echo "FAIL: token perms $perm"; fail=1; }

if [ "$MODE" = fake ]; then
  grep -q 'JOB-REPORT job=e2e-job-1 state=succeeded' "$WORK/fakeserver.out" && grep -q 'e2e-job-ok' "$WORK/fakeserver.out" \
    && echo "ok: signed job verified, executed once and reported" || { echo "FAIL: job result missing"; fail=1; }
  n=$(grep -c 'JOB-REPORT job=e2e-job-1 state=succeeded' "$WORK/fakeserver.out"); [ "$n" = 1 ] && echo "ok: exactly one terminal report" || { echo "FAIL: $n terminal reports"; fail=1; }
  grep -q 'JOB-REPORT job=e2e-job-2 state=failed.*unsupported_platform' "$WORK/fakeserver.out" && [ ! -e /tmp/e2e-foreign-ran ] \
    && echo "ok: foreign (powershell) job answered unsupported_platform and never ran" || { echo "FAIL: foreign job not rejected cleanly"; fail=1; }
  grep -q 'CHECKIN #1 .*platform=linux/' "$WORK/fakeserver.out" && grep -q 'caps=\[.*job:shell' "$WORK/fakeserver.out" \
    && echo "ok: check-in carries platform/arch/capabilities" || { echo "FAIL: platform block missing from the check-in"; fail=1; }
  grep -q '^.*INVENTORY os=linux' "$WORK/fakeserver.out" && echo "ok: Linux inventory uploaded" || { echo "FAIL: no inventory"; fail=1; }
  # software inventory (PROTOCOL.md 3.2.1): the fake server offers the feature once the agent announced it; the
  # agent then sends exactly one FULL report (nothing changes afterwards, so no further one within the run)
  if command -v dpkg-query >/dev/null 2>&1 || command -v rpm >/dev/null 2>&1; then
    grep -q 'CHECKIN #1 .*caps=\[.*software_inventory' "$WORK/fakeserver.out" \
      && echo "ok: the agent announces the software_inventory capability" || { echo "FAIL: capability software_inventory not announced"; fail=1; }
    grep -q 'SOFTWARE mode=full count=[1-9][0-9]* .*truncated=false hash=[0-9a-f]\{64\} base_hash=<nil> first=true' "$WORK/fakeserver.out" \
      && echo "ok: the fake server received a full software report" || { echo "FAIL: no full software report"; fail=1; }
    [ "$(grep -c 'SOFTWARE mode=' "$WORK/fakeserver.out")" = 1 ] && echo "ok: exactly one software report (unchanged list is not re-sent)" || { echo "FAIL: software report count"; fail=1; }
    awk '/CHECKIN #2 /{seen=1} /SOFTWARE /{ if (!seen) bad=1 } END{exit bad}' "$WORK/fakeserver.out" \
      && echo "ok: no software before the offer was seen (check-in 1 carries none)" || { echo "FAIL: software before the first offer"; fail=1; }
    [ -s "$WORK/state/software.json" ] && [ "$(stat -c %a "$WORK/state/software.json")" = 600 ] && echo "ok: acknowledged snapshot persisted (software.json, 0600)" || { echo "FAIL: no software.json"; fail=1; }
  else
    echo "note: neither dpkg-query nor rpm on this host: the software inventory assertions are skipped"
  fi
  grep CHECKIN "$WORK/fakeserver.out" | head -5
  STOK="rvte1.e2esel.E2Esecret123"
  "$WORK/fakeserver" -dir "$WORK/fake2" -interval 3 -collect 5 -token "$STOK" >"$WORK/fakeserver2.out" 2>&1 &
  FAKE2_PID=$!
  for _ in $(seq 1 50); do [ -s "$WORK/fake2/url" ] && break; sleep 0.1; done
  stamped_check "$(cat "$WORK/fake2/url")" "$STOK" "$WORK/fake2/ca.pem"
  grep -q "ENROLL" "$WORK/fakeserver2.out" && echo "ok: fake server saw exactly the stamped enrollment" || { echo "FAIL: fake server saw no enrollment"; fail=1; }
  [ "$(grep -c ENROLL "$WORK/fakeserver2.out")" = 1 ] && echo "ok: one enrollment across two setup runs" || { echo "FAIL: enrollment count"; fail=1; }
  kill "$FAKE2_PID" 2>/dev/null || true
else
  cat <<MSG
== server-side verification (manual, in RivetIT):
   - the endpoint appears (device id above) and is linked/pending/ambiguous as expected
   - metrics/inventory rows arrived and last-seen is current
   - revoke the device in RivetIT, wait one check-in: agent.out must log 'REVOKED' and status must say DORMANT
   agent log: $WORK/agent.out
MSG
  if [ -n "${RIVETIT_E2E_STAMPED_TOKEN:-}" ]; then
    stamped_check "$SERVER" "$RIVETIT_E2E_STAMPED_TOKEN" "${RIVETIT_E2E_CA:-}"
    echo "   stamped-installer enrollment: verify the second device/install in RivetIT too (department 'E2E Dept')"
  fi
fi
[ $fail = 0 ] && echo "E2E PASS" || { echo "E2E FAIL"; exit 1; }
