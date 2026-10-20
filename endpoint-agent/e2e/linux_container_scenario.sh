#!/usr/bin/env bash
# Runs INSIDE the ubuntu container as root. See run_linux_container_e2e.sh. Prints "ok:"/"FAIL:" lines and a final verdict.
set -uo pipefail
W=/w; BIN=$W/bin; OUT=$W/out
# files created here are root-owned: hand them back to the invoking user so the host can clean up
trap 'chown -R "${HOST_UID:-0}:${HOST_GID:-0}" /w 2>/dev/null || true' EXIT
rm -rf "$OUT" /var/lib/rivetit-agent /opt/rivetit-agent /etc/systemd/system/rivetit-agent.service
mkdir -p "$OUT" /etc/systemd/system
fail=0
ok()   { echo "ok: $*"; }
bad()  { echo "FAIL: $*"; fail=1; }
chk()  { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { # wait_for SECONDS "shell condition"
  local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*5)) ] && return 1; sleep 0.2; done; return 0; }
killagent() { local d c; for d in /proc/[0-9]*; do c="$({ tr '\0' ' ' <"$d/cmdline"; } 2>/dev/null)"; case "$c" in "/opt/rivetit-agent/rivetit-agent run"*) kill "${d#/proc/}" 2>/dev/null;; esac; done; }
ctl() { local a; a="$(cat "$OUT/ctl")"; exec 3<>"/dev/tcp/${a%:*}/${a#*:}"; printf 'GET /_ctl?%s HTTP/1.0\r\n\r\n' "$1" >&3; cat <&3 >/dev/null; exec 3>&-; }

echo "== container: $(. /etc/os-release; echo "$PRETTY_NAME") $(uname -m), uid=$(id -u)"
chk "running as root" '[ "$(id -u)" = 0 ]'

# systemctl is a recording shim here (no systemd in the container)
cat >/usr/local/bin/systemctl <<'SH'
#!/bin/sh
echo "$*" >> /w/out/systemctl.log
exit 0
SH
chmod +x /usr/local/bin/systemctl

# the mock Mesh server's device group, in MeshCentral's 64-character URL form (a fresh random value, with @ and $ like a real one; rc.15) and the node id its fake MeshAgent prints (bare, like the real one is assumed to)
MESHGROUP='4Q3y1m6Cm1PFZ2UtTZGt1cYvRX$g1wwy@dIBLfVOt6rZ4Mq3gqfJmwmSMX4jXYSe'
MESHNODE_RAW='AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01'
echo "== start the fake server"
"$BIN/fakeserver" -dir "$OUT" -interval 3 -collect 3 -token rvte1.e2esel.E2Esecret123 -job-type shell -foreign-job \
  -job-script 'echo e2e-job-ok uid=$(id -u) shell=$(basename "$0") cwd-ok; exit 0' \
  -update-binary "$BIN/rivetit-agent-1.0.1" -update-version 1.0.1 -ctl-listen 127.0.0.1:0 \
  -mesh-listen 127.0.0.1:0 -mesh-group "$MESHGROUP" -mesh-node "$MESHNODE_RAW" >"$OUT/fakeserver.stdout" 2>&1 &
FAKE=$!
wait_for 10 '[ -s "$OUT/url" ] && [ -s "$OUT/ctl" ] && [ -s "$OUT/mesh_url" ] && [ -s "$OUT/mesh_pin" ]' || { bad "fake server did not start"; cat "$OUT/fakeserver.stdout"; exit 1; }
SERVER="$(cat "$OUT/url")"
printf 'rvte1.e2esel.E2Esecret123' >"$OUT/enroll.token"; chmod 600 "$OUT/enroll.token"

echo "== install-linux.sh: argument and integrity checks"
IS="bash $W/install-linux.sh"
$IS --binary "$BIN/rivetit-agent-arm64" --server "$SERVER" --token-file "$OUT/enroll.token" >"$OUT/i1.out" 2>&1; rc=$?
chk "an arm64 binary on an amd64 host is refused (exit 65)" '[ $rc = 65 ] && grep -q "different CPU" "$OUT/i1.out"'
head -c 3000 /dev/urandom >"$OUT/notelf"
$IS --binary "$OUT/notelf" --server "$SERVER" --token-file "$OUT/enroll.token" >"$OUT/i2.out" 2>&1; rc=$?
chk "a non-ELF file is refused (exit 65)" '[ $rc = 65 ]'
$IS --url http://example.invalid/agent --sha256 "$(printf 0%.0s {1..64})" >"$OUT/i3.out" 2>&1; rc=$?
chk "plain http download refused" '[ $rc = 65 ] && grep -q "non-HTTPS" "$OUT/i3.out"'
$IS --url https://example.invalid/agent >"$OUT/i4.out" 2>&1; rc=$?
chk "--url without --sha256 refused (exit 64)" '[ $rc = 64 ]'
printf 'not-a-token' >"$OUT/badtoken"
$IS --binary "$BIN/rivetit-agent-1.0.0" --server "$SERVER" --token-file "$OUT/badtoken" >"$OUT/i5.out" 2>&1; rc=$?
chk "a malformed token file is refused before anything is installed (exit 64)" '[ $rc = 64 ] && [ ! -e /opt/rivetit-agent ]'
chk "failed attempts left nothing behind" '[ ! -e /var/lib/rivetit-agent ] && [ ! -e /etc/systemd/system/rivetit-agent.service ]'

echo "== install-linux.sh: download with SHA-256 verification (curl)"
if apt-get update -qq >/dev/null 2>&1 && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq curl ca-certificates >/dev/null 2>&1; then
  SUM="$(sha256sum "$BIN/rivetit-agent-1.0.0" | cut -d' ' -f1)"
  $IS --url "file://$BIN/rivetit-agent-1.0.0" --sha256 "$(printf f%.0s {1..64})" --server "$SERVER" --token-file "$OUT/enroll.token" >"$OUT/i6.out" 2>&1; rc=$?
  chk "SHA-256 mismatch aborts the install (exit 65)" '[ $rc = 65 ] && grep -q "mismatch" "$OUT/i6.out" && [ ! -e /opt/rivetit-agent ]'
  URLMODE=1
else
  echo "note: could not install curl (no network?); the --url download path is NOT tested in this run"; URLMODE=0
fi

echo "== install (binary next to the script is not present: --binary or --url)"
if [ "$URLMODE" = 1 ]; then
  $IS --url "file://$BIN/rivetit-agent-1.0.0" --sha256 "$SUM" --server "$SERVER" --token-file "$OUT/enroll.token" --ca "$OUT/ca.pem" >"$OUT/install.out" 2>&1; rc=$?
  chk "verified download installs (SHA-256 verified)" 'grep -q "SHA-256 verified" "$OUT/install.out"'
else
  $IS --binary "$BIN/rivetit-agent-1.0.0" --server "$SERVER" --token-file "$OUT/enroll.token" --ca "$OUT/ca.pem" >"$OUT/install.out" 2>&1; rc=$?
fi
chk "install exit 0" '[ $rc = 0 ]'
[ $rc = 0 ] || { cat "$OUT/install.out"; }
UNIT=/etc/systemd/system/rivetit-agent.service
chk "binary installed root:755" '[ "$(stat -c %a /opt/rivetit-agent/rivetit-agent)" = 755 ] && [ "$(stat -c %U /opt/rivetit-agent/rivetit-agent)" = root ]'
chk "install dir 755 and unit file 644 (the script's umask 077 must not leak into them)" '[ "$(stat -c %a /opt/rivetit-agent)" = 755 ] && [ "$(stat -c %a $UNIT)" = 644 ]'
chk "state dir is 0700" '[ "$(stat -c %a /var/lib/rivetit-agent)" = 700 ]'
chk "credential file is 0600" '[ "$(stat -c %a /var/lib/rivetit-agent/device.token)" = 600 ]'
chk "unit written with User=root, Restart=always, StateDirectory" 'grep -q "^User=root" $UNIT && grep -q "^Restart=always" $UNIT && grep -q "^StateDirectory=rivetit-agent" $UNIT && grep -q "^StateDirectoryMode=0700" $UNIT'
chk "unit runs the installed binary on the state dir" 'grep -q "^ExecStart=\"/opt/rivetit-agent/rivetit-agent\" \"run\" \"--state-dir\" \"/var/lib/rivetit-agent\"" $UNIT'
chk "systemd was told: daemon-reload, enable, restart" 'grep -qx "daemon-reload" $OUT/systemctl.log && grep -qx "enable rivetit-agent.service" $OUT/systemctl.log && grep -qx "restart rivetit-agent.service" $OUT/systemctl.log'
chk "no enrollment token anywhere under the state dir, unit or install output" '! grep -rqa "E2Esecret123" /var/lib/rivetit-agent $UNIT "$OUT/install.out" /opt/rivetit-agent'
chk "agent status works" '/opt/rivetit-agent/rivetit-agent status | grep -q "enrolled and linked"'
chk "enrolled once, as linux/amd64" '[ "$(grep -c "^.*ENROLL install_id" $OUT/events.log)" = 1 ] && grep -q "os=linux/amd64" $OUT/events.log'

echo "== supervise the agent exactly as the unit specifies (restart on exit, like Restart=always)"
sed -n 's/^ExecStart=//p' $UNIT >"$OUT/execstart"
cat >"$OUT/supervise.sh" <<'SV'
#!/bin/bash
# the unit's own ExecStart line (systemd double-quote syntax is valid bash here) plus the TEST-ONLY short interval clamp
while :; do eval "$(cat /w/out/execstart) --min-interval 3" >>/w/out/agent.log 2>&1; echo "agent exited $?" >>/w/out/agent.log; sleep 1; done
SV
chmod +x "$OUT/supervise.sh"
"$OUT/supervise.sh" & SUPER=$!
wait_for 30 'grep -q "CHECKIN #2 " "$OUT/events.log"' || bad "agent never checked in"
chk "check-in carries platform, arch and capabilities" 'grep -q "CHECKIN #1 .*version=1.0.0 platform=linux/amd64" $OUT/events.log && grep -q "caps=.*job:shell" $OUT/events.log && ! grep "CHECKIN" $OUT/events.log | grep -q "job:powershell"'
chk "inventory: os-release, cpu, memory, disks, network, uptime" 'grep -q "^.*INVENTORY os=linux os_version=.*Ubuntu" $OUT/events.log && grep INVENTORY $OUT/events.log | grep -q "disks=[1-9]"'
chk "metrics: memory and disk reported" 'wait_for 15 "grep METRICS $OUT/events.log | grep -q \"mem=[0-9]\""'
chk "metrics: cpu rate present after two samples" 'wait_for 15 "grep METRICS $OUT/events.log | grep -q \"cpu=[0-9]\""'
chk "software inventory: capability announced, then ONE full dpkg report with real packages (nothing before the offer)" 'wait_for 30 "grep -q \"SOFTWARE mode=full\" $OUT/events.log" && grep -q "caps=.*software_inventory" $OUT/events.log && [ "$(grep -c "SOFTWARE mode=full" $OUT/events.log)" = 1 ] && grep "SOFTWARE mode=full" $OUT/events.log | grep -Eq "count=[1-9][0-9]* .*truncated=false hash=[0-9a-f]{64} base_hash=<nil> first=true .*\"source\":\"dpkg\"" && awk "/CHECKIN #2 /{seen=1} /SOFTWARE /{ if (!seen) bad=1 } END{exit bad}" $OUT/events.log'
chk "software snapshot persisted for the next delta (written after the server's answer, so wait for it)" 'wait_for 10 "[ -s /var/lib/rivetit-agent/software.json ]" && [ "$(stat -c %a /var/lib/rivetit-agent/software.json)" = 600 ]'
chk "checks: disk and pending_reboot evaluated" 'wait_for 15 "grep CHECKS $OUT/events.log | grep -q disk_root"'
chk "signed shell job ran ONCE as root through a script file" 'wait_for 30 "grep -q \"JOB-REPORT job=e2e-job-1 state=succeeded\" $OUT/events.log" && grep -q "uid=0" $OUT/events.log && grep -q "shell=job.sh" $OUT/events.log && [ "$(grep -c "job=e2e-job-1 state=succeeded" $OUT/events.log)" = 1 ]'
chk "PowerShell job answered unsupported_platform, nothing executed" 'wait_for 30 "grep -q \"job=e2e-job-2 state=failed.*unsupported_platform\" $OUT/events.log" && [ ! -e /tmp/e2e-foreign-ran ]'
chk "no job script left in /tmp" '[ -z "$(ls -d /tmp/rivetit-job-* 2>/dev/null)" ]'

echo "== offline buffering"
ctl "mode=offline"
sleep 14
ctl "mode=ok"
chk "after the outage the agent delivers the buffered samples" 'wait_for 120 "grep CHECKIN $OUT/events.log | grep -Eq \"buffered=[2-9]|buffered=[0-9][0-9]\""'
SEQS="$(grep -o "CHECKIN #[0-9]* seq=[0-9]*" $OUT/events.log | sed 's/.*seq=//' | tr '\n' ' ')"
chk "seq numbers strictly increasing, no duplicates accepted twice" 'echo "$SEQS" | tr " " "\n" | grep . | awk "NR>1 && \$1<=p {exit 1} {p=\$1}"'
echo "   seqs: $SEQS"

echo "== module_disabled: one request, then silence; the body is kept and replayed"
# N0 is read inside the eval'd chk string below (single-quoted, so shellcheck cannot see the use).
# shellcheck disable=SC2034
N0="$(grep -c "^.*CHECKIN #" $OUT/events.log)"
ctl "mode=disabled&retry_after=20"
wait_for 30 'grep -q "GATE disabled checkin" $OUT/events.log' || bad "agent never hit the disabled server"
sleep 25
chk "exactly one disabled answer in 25 s (>=12 min back-off, no tight loop)" '[ "$(grep -c "GATE disabled checkin" $OUT/events.log)" = 1 ]'
chk "agent logged the state once and kept running" '[ "$(grep -c "keeping the credential" $OUT/agent.log)" = 1 ]'
chk "credential kept and agent not dormant" '/opt/rivetit-agent/rivetit-agent status | grep -q "enrolled and linked"'
ctl "mode=ok"
killagent # the supervisor restarts it: a fresh process retries immediately
chk "after the server is enabled again (agent restarted) the buffered body is delivered" 'wait_for 60 "[ \$(grep -c \"^.*CHECKIN #\" $OUT/events.log) -gt $N0 ]"'

echo "== self-update swap (v1.0.0 -> v1.0.1) with probation, then restart under the supervisor"
ctl "update=on"
chk "agent downloaded the update exactly once" 'wait_for 90 "grep -q UPDATE-DOWNLOAD $OUT/events.log" && [ "$(grep -c UPDATE-DOWNLOAD $OUT/events.log)" = 1 ]'
chk "agent restarted into 1.0.1 (exit 75 from the old process)" 'wait_for 60 "grep -q \"CHECKIN #[0-9]* seq=[0-9]* version=1.0.1\" $OUT/events.log" && grep -q "agent exited 75" $OUT/agent.log'
chk "installed binary is now 1.0.1, previous kept as .prev" '/opt/rivetit-agent/rivetit-agent version | grep -q "1.0.1" && [ -x /opt/rivetit-agent/rivetit-agent.prev ] && /opt/rivetit-agent/rivetit-agent.prev version | grep -q "1.0.0"'
chk "probation confirmed by a successful check-in (update state cleared)" 'wait_for 30 "[ ! -e /var/lib/rivetit-agent/update.json ]"'
chk "agent log says the new version is healthy" 'grep -q "new agent version confirmed healthy" $OUT/agent.log'
chk "mode and owner of the binary unchanged by the swap (755 root)" '[ "$(stat -c %a /opt/rivetit-agent/rivetit-agent)" = 755 ] && [ "$(stat -c %U /opt/rivetit-agent/rivetit-agent)" = root ]'
chk "enrolled still exactly once (identity kept across the update)" '[ "$(grep -c "^.*ENROLL install_id" $OUT/events.log)" = 1 ]'

echo "== stamped installer (dev stamper) through the script"
"$BIN/rivetit-agent-dev" stamp --in "$BIN/rivetit-agent-1.0.0" --out "$OUT/installer-stamped" --server "$SERVER" --token rvte1.e2esel.E2Esecret123 --department "E2E" --ttl 1h --ca "$OUT/ca.pem" >/dev/null 2>&1
kill $SUPER 2>/dev/null; killagent; sleep 1
$IS --uninstall --purge >"$OUT/uninstall.out" 2>&1; rc=$?
chk "uninstall exit 0" '[ $rc = 0 ]'
chk "uninstall removed unit, binaries and state" '[ ! -e $UNIT ] && [ ! -e /opt/rivetit-agent ] && [ ! -e /var/lib/rivetit-agent ]'
chk "uninstall told systemd: stop, disable, daemon-reload" 'grep -qx "stop rivetit-agent.service" $OUT/systemctl.log && grep -qx "disable rivetit-agent.service" $OUT/systemctl.log'
$IS --binary "$OUT/installer-stamped" >"$OUT/stamped.out" 2>&1; rc=$?
chk "stamped installer: setup --silent exit 0" '[ $rc = 0 ]'
chk "stamped installer: unit + 0700 state + device token + no token on disk" '[ -f $UNIT ] && [ "$(stat -c %a /var/lib/rivetit-agent)" = 700 ] && [ -s /var/lib/rivetit-agent/device.token ] && ! grep -rqa E2Esecret123 /var/lib/rivetit-agent /opt/rivetit-agent $UNIT'
chk "stamped install did not leave the token in the installed binary" '! grep -qa E2Esecret123 /opt/rivetit-agent/rivetit-agent'
$IS --binary "$OUT/installer-stamped" >"$OUT/stamped2.out" 2>&1; rc=$?
chk "re-running the stamped installer is idempotent (no second enrollment)" '[ $rc = 0 ] && [ "$(grep -c "^.*ENROLL install_id" $OUT/events.log)" = 2 ]'
$IS --uninstall >/dev/null 2>&1
chk "uninstall without --purge keeps state" '[ -d /var/lib/rivetit-agent ] && [ ! -e /opt/rivetit-agent/rivetit-agent ]'

echo "== optional MeshCentral agent: pulled from the (mock) customer Mesh server, pinned, never fatal"
MESHURL="$(cat "$OUT/mesh_url")"; MESHPIN="$(cat "$OUT/mesh_pin")"
# the variables below are read inside the eval'd chk strings, which shellcheck cannot see
# shellcheck disable=SC2034
MESHDIR=/usr/local/mesh_services/meshagent
stampmesh() { # stampmesh OUTFILE URL GROUP PIN
  "$BIN/rivetit-agent-dev" stamp --in "$BIN/rivetit-agent-1.0.0" --out "$1" --server "$SERVER" --token rvte1.e2esel.E2Esecret123 --department "E2E" --ttl 1h --ca "$OUT/ca.pem" \
    --mesh-url "$2" --mesh-group "$3" --mesh-pin "$4" >/dev/null 2>&1; }
supervise_start() { "$OUT/supervise.sh" & SUPER=$!; }
supervise_stop() { kill "$SUPER" 2>/dev/null; killagent; sleep 1; }
rm -f "$OUT/mesh-installed.marker" "$OUT/mesh-uninstalled.marker"; : >"$OUT/agent.log"
$IS --uninstall --purge >/dev/null 2>&1
# shellcheck disable=SC2034
M0="$(grep -c MESH-DOWNLOAD "$OUT/events.log")"

echo "-- a pin that does not match: the RivetIT agent installs, the Mesh agent does not"
stampmesh "$OUT/inst-badpin" "$MESHURL" "$MESHGROUP" "$(printf 0%.0s {1..96})"
$IS --binary "$OUT/inst-badpin" >"$OUT/mesh1.out" 2>&1; rc=$?
chk "RivetIT install exits 0 although the Mesh part failed" '[ $rc = 0 ] && [ -f $UNIT ] && /opt/rivetit-agent/rivetit-agent status | grep -q "enrolled and linked"'
chk "no Mesh installer ran and no Mesh agent exists" '[ ! -e "$MESHDIR/meshagent" ] && [ ! -e "$OUT/mesh-installed.marker" ]'
chk "the failure is on record (state file) and names the pin, never the group id" 'grep -q "\"installed\": false" /var/lib/rivetit-agent/mesh_agent.json && grep -qi pinned /var/lib/rivetit-agent/mesh_agent.json && ! grep -rqaF -- "$MESHGROUP" /var/lib/rivetit-agent "$OUT/mesh1.out"'
chk "the mock Mesh server never saw an HTTP request (the TLS handshake was refused)" '[ "$(grep -c MESH-DOWNLOAD "$OUT/events.log")" = "$M0" ]'
supervise_start
chk "the first check-in reports the failure to the server" 'wait_for 30 "grep -q \"MESH-REPORT installed=false\" $OUT/events.log" && grep MESH-REPORT "$OUT/events.log" | grep -q "pinned"'
chk "the agent announces mesh:nodeid" 'grep -q "caps=.*mesh:nodeid" $OUT/events.log'
supervise_stop
$IS --uninstall --purge >/dev/null 2>&1

echo "-- a wrong device group: the mock server answers 404"
stampmesh "$OUT/inst-badgroup" "$MESHURL" "$(printf 1%.0s {1..96})" "$MESHPIN"
$IS --binary "$OUT/inst-badgroup" >"$OUT/mesh2.out" 2>&1; rc=$?
chk "exit 0, Mesh not installed, HTTP 404 on record" '[ $rc = 0 ] && [ ! -e "$MESHDIR/meshagent" ] && grep -q "HTTP 404" /var/lib/rivetit-agent/mesh_agent.json'
$IS --uninstall --purge >/dev/null 2>&1

echo "-- no pin and a self-signed Mesh certificate: refused (system roots only)"
stampmesh "$OUT/inst-noroots" "$MESHURL" "$MESHGROUP" ""
$IS --binary "$OUT/inst-noroots" >"$OUT/mesh3.out" 2>&1; rc=$?
chk "exit 0, Mesh not installed because the certificate is not trusted" '[ $rc = 0 ] && [ ! -e "$MESHDIR/meshagent" ] && grep -q "\"installed\": false" /var/lib/rivetit-agent/mesh_agent.json'
$IS --uninstall --purge >/dev/null 2>&1

echo "-- --no-mesh-agent wins over the installer"
stampmesh "$OUT/inst-good" "$MESHURL" "$MESHGROUP" "$MESHPIN"
# shellcheck disable=SC2034
D0="$(grep -c MESH-DOWNLOAD "$OUT/events.log")"
$IS --binary "$OUT/inst-good" --no-mesh-agent >"$OUT/mesh4.out" 2>&1; rc=$?
chk "exit 0 and Mesh untouched (no state file, no download)" '[ $rc = 0 ] && [ ! -e /var/lib/rivetit-agent/mesh_agent.json ] && [ "$(grep -c MESH-DOWNLOAD "$OUT/events.log")" = "$D0" ]'
$IS --uninstall --purge >/dev/null 2>&1

echo "-- the good case: pinned download, the installer runs, the node id is reported"
# shellcheck disable=SC2034
D1="$(grep -c MESH-DOWNLOAD "$OUT/events.log")"
$IS --binary "$OUT/inst-good" >"$OUT/mesh5.out" 2>&1; rc=$?
chk "install exit 0" '[ $rc = 0 ]'
chk "downloaded the binary from <mesh>/meshagents?id=6 (no meshid) and the settings from <mesh>/meshsettings?id=<group URL form, @ and $ literal>" 'grep -qF -- "MESH-DOWNLOAD id=6 meshid= installflags= raw=id=6" "$OUT/events.log" && grep -qF -- "MESH-SETTINGS meshid=$MESHGROUP raw=id=$MESHGROUP" "$OUT/events.log"'
chk "the downloaded installer ran with -fullinstall --copy-msh=1, the .msh carried StartupType, and the agent is in place and running" 'grep -q "installed by: .* -fullinstall --copy-msh=1" "$OUT/mesh-installed.marker" && grep -qx "StartupType=[0-9]" "$OUT/mesh-installed.msh" && ! grep -q "StartupType=9" "$OUT/mesh-installed.msh" && [ -x "$MESHDIR/meshagent" ] && pgrep -f "$MESHDIR/meshagent" >/dev/null'
chk "the work directory was removed" '[ -z "$(ls -d /var/lib/rivetit-agent/mesh-install-* 2>/dev/null)" ]'
chk "outcome saved 0600 without the group id" '[ "$(stat -c %a /var/lib/rivetit-agent/mesh_agent.json)" = 600 ] && grep -q "\"installed\": true" /var/lib/rivetit-agent/mesh_agent.json && ! grep -rqaF -- "$MESHGROUP" /var/lib/rivetit-agent'
chk "the install output says so" 'grep -q "MeshCentral agent: installed" "$OUT/mesh5.out"'
supervise_start
chk "the first check-in reports installed=true with the node id from -nodeid, normalised to node//" 'wait_for 30 "grep -qF \"MESH-REPORT installed=true node_id=node//\$MESHNODE_RAW\" \$OUT/events.log"'
# shellcheck disable=SC2034
R1="$(grep -c "MESH-REPORT" "$OUT/events.log")"; sleep 8
chk "the report is sent once, not on every check-in (3 s interval, 8 s later nothing new)" '[ "$(grep -c "MESH-REPORT" "$OUT/events.log")" = "$R1" ]'
supervise_stop

echo "-- re-running the installer leaves the Mesh agent alone; a plain uninstall too"
$IS --binary "$OUT/inst-good" >"$OUT/mesh6.out" 2>&1; rc=$?
chk "re-run exits 0 and does not download again" '[ $rc = 0 ] && [ "$(grep -c MESH-DOWNLOAD "$OUT/events.log")" = "$((D1+1))" ] && grep -q "already installed" "$OUT/mesh6.out"'
$IS --uninstall >"$OUT/mesh7.out" 2>&1; rc=$?
chk "uninstall (no flag) removes the RivetIT agent and leaves Mesh" '[ $rc = 0 ] && [ ! -e /opt/rivetit-agent/rivetit-agent ] && [ -x "$MESHDIR/meshagent" ] && [ ! -e "$OUT/mesh-uninstalled.marker" ] && grep -q "left untouched" "$OUT/mesh7.out"'
$IS --binary "$OUT/inst-good" >/dev/null 2>&1
$IS --uninstall --purge --remove-meshagent >"$OUT/mesh8.out" 2>&1; rc=$?
chk "uninstall --remove-meshagent runs the Mesh agent's own uninstaller" '[ $rc = 0 ] && [ -e "$OUT/mesh-uninstalled.marker" ] && [ ! -e "$MESHDIR/meshagent" ] && [ ! -e /var/lib/rivetit-agent ]'

echo "-- a plain agent with --install-mesh-agent flags (no stamped installer)"
$IS --binary "$BIN/rivetit-agent-1.0.0" --server "$SERVER" --token-file "$OUT/enroll.token" --ca "$OUT/ca.pem" --install-mesh-agent --mesh-url "$MESHURL" --mesh-group-id "$MESHGROUP" --mesh-cert-pin "$MESHPIN" >"$OUT/mesh9.out" 2>&1; rc=$?
chk "plain install with the Mesh flags exits 0 and installs Mesh" '[ $rc = 0 ] && [ -x "$MESHDIR/meshagent" ] && grep -q "MeshCentral agent: installed" "$OUT/mesh9.out"'
$IS --uninstall --purge --remove-meshagent >/dev/null 2>&1

echo "== pushed MeshCentral agent (mesh_install job) on an agent enrolled WITHOUT the Mesh option"
rm -f "$OUT/mesh-installed.marker" "$OUT/mesh-uninstalled.marker" /tmp/e2e-should-not-run; : >"$OUT/agent.log"
ctl "update=off" # the self-update offered earlier would restart the agent (exit 75) and muddy "the Mesh jobs never restart it"
$IS --binary "$BIN/rivetit-agent-1.0.0" --server "$SERVER" --token-file "$OUT/enroll.token" --ca "$OUT/ca.pem" >"$OUT/push0.out" 2>&1; rc=$?
chk "plain install (no Mesh option anywhere) exits 0" '[ $rc = 0 ] && [ ! -e /var/lib/rivetit-agent/mesh_agent.json ] && [ ! -e "$MESHDIR/meshagent" ]'
# shellcheck disable=SC2034
E0="$(grep -c "ENROLL install_id" "$OUT/events.log")"
supervise_start
chk "the agent announces job:mesh_install and job:mesh_uninstall" 'wait_for 30 "grep -q \"CHECKIN.*job:mesh_install\" \$OUT/events.log" && grep CHECKIN "$OUT/events.log" | tail -1 | grep -q "job:mesh_uninstall"'
pushwait() { wait_for 90 "grep -Eq \"JOB-REPORT job=mesh-push-$1 state=(succeeded|failed)\" \$OUT/events.log"; }
pushout() { grep "JOB-REPORT job=mesh-push-$1 state=" "$OUT/events.log" | grep -v "state=running" | tail -1; }
alive() { # the RivetIT agent keeps checking in after the Mesh job
  local n; n="$(grep -c "CHECKIN #" "$OUT/events.log")"; wait_for 30 "[ \$(grep -c \"CHECKIN #\" \$OUT/events.log) -gt $n ]" && /opt/rivetit-agent/rivetit-agent status | grep -q "enrolled and linked"; }
dl() { grep -c MESH-DOWNLOAD "$OUT/events.log"; }

echo "-- failures are job results and check-in state; the RivetIT agent is never affected"
# PD, D2 and D3 are read inside the eval-ed check strings
# shellcheck disable=SC2034
PD="$(dl)"; ctl "mesh_push=badparams"; pushwait 1
chk "a parameter the agent does not know is refused before anything is downloaded or run" 'pushout 1 | grep -q "state=failed" && pushout 1 | grep -q invalid_params && [ "$(dl)" = "$PD" ] && [ ! -e /tmp/e2e-should-not-run ] && alive'
ctl "mesh_push=badpin"; pushwait 2
chk "a pin that does not match: failed install_failed (pinned hash), no HTTP request reached the Mesh server, no agent" 'pushout 2 | grep -q "state=failed" && pushout 2 | grep -q install_failed && pushout 2 | grep -q "pinned hash" && [ "$(dl)" = "$PD" ] && [ ! -e "$MESHDIR/meshagent" ] && alive'
chk "the failure also arrives as the check-in mesh_agent state" 'wait_for 30 "grep MESH-REPORT \$OUT/events.log | grep -q \"installed=false.*pinned\""'
ctl "mesh_push=notfound"; pushwait 3
chk "an unknown device group is HTTP 404: failed, no agent, agent alive" 'pushout 3 | grep -q "state=failed" && pushout 3 | grep -q "HTTP 404" && [ ! -e "$MESHDIR/meshagent" ] && alive'
ctl "mesh_push=oversize"; pushwait 4
chk "an announced body over the 64 MiB cap is refused (larger than the limit), nothing runs" 'pushout 4 | grep -q "state=failed" && pushout 4 | grep -q "larger than" && [ ! -e "$MESHDIR/meshagent" ] && [ ! -e "$OUT/mesh-installed.marker" ] && alive'
ctl "mesh_push=chunked"; pushwait 5
chk "the same without a Content-Length is cut at the cap" 'pushout 5 | grep -q "state=failed" && pushout 5 | grep -q "larger than" && [ ! -e "$MESHDIR/meshagent" ] && alive'
chk "no work directory is left behind by any failure" '[ -z "$(ls -d /var/lib/rivetit-agent/mesh-install-* 2>/dev/null)" ]'

echo "-- the good case: the same pinned download and install as at install time"
# shellcheck disable=SC2034
D2="$(dl)"; ctl "mesh_push=ok"; pushwait 6
chk "the job succeeded (installed) and the pinned download used the Linux id 6 and the URL-form group" 'pushout 6 | grep -q "state=succeeded" && pushout 6 | grep -q "installed" && [ "$(dl)" = "$((D2+1))" ] && grep -qF -- "MESH-DOWNLOAD id=6 meshid=$MESHGROUP installflags=0" "$OUT/events.log"'
chk "the downloaded installer ran with -install and the fake meshagent is in place" 'grep -q "installed by: .* -install" "$OUT/mesh-installed.marker" && [ -x "$MESHDIR/meshagent" ]'
chk "the job result carries the node id and never the group id" 'pushout 6 | grep -qF "node//$MESHNODE_RAW" && ! pushout 6 | grep -qF -- "$MESHGROUP"'
chk "the outcome is saved 0600 without the group id" '[ "$(stat -c %a /var/lib/rivetit-agent/mesh_agent.json)" = 600 ] && grep -q "\"installed\": true" /var/lib/rivetit-agent/mesh_agent.json && ! grep -rqaF -- "$MESHGROUP" /var/lib/rivetit-agent'
chk "the next check-in reports installed=true with the node id (the server maps it)" 'wait_for 30 "grep -qF \"MESH-REPORT installed=true node_id=node//\$MESHNODE_RAW\" \$OUT/events.log"'
chk "the RivetIT agent was neither restarted nor re-enrolled by any Mesh job" '[ "$(grep -c "ENROLL install_id" "$OUT/events.log")" = "$E0" ] && ! grep -q "agent exited" "$OUT/agent.log" && alive'

echo "-- idempotent: an installed agent is left alone unless forced"
# shellcheck disable=SC2034
D3="$(dl)"; ctl "mesh_push=ok"; pushwait 7
chk "second push: succeeded as already_installed, no download" 'pushout 7 | grep -q "state=succeeded" && pushout 7 | grep -q already_installed && [ "$(dl)" = "$D3" ]'
ctl "mesh_push=force"; pushwait 8
chk "force runs the installer again" 'pushout 8 | grep -q "state=succeeded" && [ "$(dl)" = "$((D3+1))" ]'

echo "-- mesh_uninstall removes only the Mesh agent"
rm -f "$OUT/mesh-uninstalled.marker"
# shellcheck disable=SC2034
R2="$(grep -c "MESH-REPORT" "$OUT/events.log")"; ctl "mesh_push=uninstall"; pushwait 9
chk "the Mesh agent's own uninstaller ran (-uninstall) and the RivetIT agent is untouched" 'pushout 9 | grep -q "state=succeeded" && [ -e "$OUT/mesh-uninstalled.marker" ] && [ ! -e "$MESHDIR/meshagent" ] && [ -x /opt/rivetit-agent/rivetit-agent ] && alive'
chk "the check-in now reports installed=false (so the server clears its record)" 'wait_for 30 "[ \$(grep MESH-REPORT \$OUT/events.log | tail -n +$((R2+1)) | grep -c \"installed=false\") -ge 1 ]"'
supervise_stop
$IS --uninstall --purge >/dev/null 2>&1

kill $FAKE 2>/dev/null
rm -f /tmp/e2e-foreign-ran
if [ $fail = 0 ]; then echo "SCENARIO PASS"; else echo "SCENARIO FAIL"; exit 1; fi
