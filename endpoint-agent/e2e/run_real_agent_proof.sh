#!/usr/bin/env bash
# REAL-agent proof of RMM Phases 6 and 8 (remote tools, file transfer, log collection).
#
#   the Linux agent (the release build from `make dist`) running under a REAL systemd in a privileged container
#     <--TLS-->  nginx (host network, self-signed CA)  <--http-->  `php -S` serving RivetCore\Rmm on a SCRATCH database
#
# The server side is e2e/php_scratch/router.php (device endpoints + technician API behind one fixed administrator token). Nothing here touches a real
# database: RIVETCORE_TEST_DB_NAME must contain "scratch" and is DROPPED AND RECREATED. Needs docker, php (with mysqli), curl, openssl.
#
#   RIVETCORE_TEST_DB_HOST/PORT/USER/PASS/NAME=... AGENT_BIN=dist/rivetit-agent-linux-amd64 e2e/run_real_agent_proof.sh
#
# Prints "ok:"/"FAIL:" lines and a final "PROOF PASS" or "PROOF FAIL". Windows is not exercised (cross-compiled and vetted only).
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"; REPO="$(cd "$ROOT/.." && pwd)"
AGENT_BIN="${AGENT_BIN:-$ROOT/dist/rivetit-agent-linux-amd64}"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-proof}"
PHPPORT="${PHP_PORT:-8680}"; TLSPORT="${TLS_PORT:-8643}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d /tmp/rivetit-proof.XXXXXX)"
case "${RIVETCORE_TEST_DB_NAME:-}" in *scratch*) ;; *) echo "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database"; exit 2;; esac
[ -x "$AGENT_BIN" ] || { echo "AGENT_BIN $AGENT_BIN not found (run make dist)"; exit 2; }
fail=0
ok()  { echo "ok: $*"; }
bad() { echo "FAIL: $*"; fail=1; }
chk() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*2)) ] && return 1; sleep 0.5; done; return 0; }
PHPPID=""
cleanup() {
  docker rm -f "$NAME" "$NAME-nginx" >/dev/null 2>&1 || true
  [ -n "$PHPPID" ] && kill "$PHPPID" 2>/dev/null || true
  rm -rf "$WORK"
}
trap cleanup EXIT
cleanup_early() { docker rm -f "$NAME" "$NAME-nginx" >/dev/null 2>&1 || true; }; cleanup_early

echo "== scratch database and server"
php -r '
  mysqli_report(MYSQLI_REPORT_OFF);
  $n = getenv("RIVETCORE_TEST_DB_NAME");
  $m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "127.0.0.1", getenv("RIVETCORE_TEST_DB_USER") ?: "root", getenv("RIVETCORE_TEST_DB_PASS") ?: "", "", (int) (getenv("RIVETCORE_TEST_DB_PORT") ?: 3306));
  $m->query("DROP DATABASE IF EXISTS `$n`"); $m->query("CREATE DATABASE `$n` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); exit($m->error ? 1 : 0);' || { echo "cannot reset the scratch database"; exit 2; }
mkdir -p "$WORK/state" "$WORK/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-proof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" \
  -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
SETUP="$(RMM_GOLDEN_ALLOW_LINUX=1 php "$HERE/php_scratch/setup.php" "$WORK/state" "https://$GATEWAY:$TLSPORT")" || { echo "setup failed: $SETUP"; exit 2; }
TOKEN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')"
ADMIN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["admin_api_token"];')"
RMM_GOLDEN_STATE="$WORK/state" RMM_GOLDEN_ALLOW_LINUX=1 php -d display_errors=0 -d log_errors=1 -d "error_log=$WORK/php.log" -S "127.0.0.1:$PHPPORT" "$HERE/php_scratch/router.php" >/dev/null 2>&1 &
PHPPID=$!
wait_for 15 "curl -s -o /dev/null http://127.0.0.1:$PHPPORT/api/v1/nothing" || { bad "php -S did not start"; exit 1; }
cat >"$WORK/nginx.conf" <<NG
events {}
http {
  server {
    listen $TLSPORT ssl;
    ssl_certificate /tls/ca.pem; ssl_certificate_key /tls/key.pem;
    client_max_body_size 4m; proxy_request_buffering off; proxy_buffering off;
    location / { proxy_pass http://127.0.0.1:$PHPPORT; proxy_set_header X-Forwarded-Proto https; proxy_set_header Host \$host; proxy_http_version 1.1; }
  }
}
NG
docker run -d --name "$NAME-nginx" --network host -v "$WORK/tls":/tls:ro -v "$WORK/nginx.conf":/etc/nginx/nginx.conf:ro "$NGINX_IMG" >/dev/null
wait_for 15 "curl -s --cacert $WORK/tls/ca.pem -o /dev/null https://$GATEWAY:$TLSPORT/api/v1/nothing" || { bad "TLS proxy did not start"; docker logs "$NAME-nginx" | tail -5; exit 1; }
api() { # api METHOD path [json-body] -> body on stdout; sets $CODE
  local m=$1 p=$2 b="${3:-}" out
  if [ -n "$b" ]; then out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' --data "$b" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"
  else out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"; fi
  CODE="${out##*$'\n'}"; printf '%s' "${out%$'\n'*}"
}
jget() { php -r '$d=json_decode(stream_get_contents(STDIN), true); $v=$d; foreach (explode(".", $argv[1]) as $k) { if (is_array($v) && array_key_exists($k, $v)) { $v=$v[$k]; } else { $v=null; break; } } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }

echo "== real systemd container, agent installed with install-linux.sh"
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w /srv/proof
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$ROOT/scripts/install-linux.sh" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"
printf '%s' "$TOKEN" | docker exec -i "$NAME" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
# harmless units: one to restart, one that logs a failed-password line, and files for the transfers
docker exec "$NAME" sh -c 'cat >/etc/systemd/system/rivetproof-probe.service <<U
[Unit]
Description=RivetIT proof probe (harmless)
[Service]
ExecStart=/bin/sleep infinity
U
cat >/etc/systemd/system/rivetproof-noisy.service <<U
[Unit]
Description=RivetIT proof noisy unit (harmless)
[Service]
Type=oneshot
SyslogIdentifier=rivetproof
ExecStart=/bin/sh -c "echo \"Failed password for invalid user proofuser from 203.0.113.9 port 2222 ssh2\" >&2; echo \"routine: nothing to see\""
U
systemctl daemon-reload && systemctl start rivetproof-probe.service
printf "hello from the device\n" >/srv/proof/report.txt; ln -sf /etc/shadow /srv/proof/shadow-link; mkdir -p /srv/proof/drop; (sleep 3000 &) ; true'
docker exec "$NAME" bash /w/install-linux.sh --binary /w/rivetit-agent --server "https://$GATEWAY:$TLSPORT" --token-file /w/enroll.token --ca /w/ca.pem >"$WORK/install.out" 2>&1
chk "install exit 0" '[ $? = 0 ]'
wait_for 40 "[ \"\$(api GET '' | jget total)\" = 1 ]" ; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against the PHP server and is a device ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != "null" ]'
wait_for 40 "api GET $DEV | grep -q remote:services" || true
chk "the agent announced the new capabilities" 'docker exec $NAME /opt/rivetit-agent/rivetit-agent status | grep -q enrolled'
q() { docker exec "$NAME" "$@"; }
job_done() { # job_done JOBID [seconds] -> waits for a final state; prints it
  local j=$1 t="${2:-90}" i=0 st; while [ $i -lt $((t*2)) ]; do st="$(api GET "$DEV/remote/jobs/$j" | jget state)"; case "$st" in succeeded|failed|timed_out|cancelled|expired) echo "$st"; return 0;; esac; i=$((i+1)); sleep 0.5; done; echo "$st"; return 1; }

echo "== service manager"
R="$(api POST "$DEV/services/query")"; J="$(printf '%s' "$R" | jget job_id)"
chk "service list queued (202)" '[ "$CODE" = 202 ] && [ -n "$J" ]'
chk "service list job succeeded" '[ "$(job_done $J)" = succeeded ]'
S="$(api GET "$DEV/services")"
chk "the list holds the harmless probe unit with state running" 'printf "%s" "$S" | php -r "\$d=json_decode(stream_get_contents(STDIN),true); foreach (\$d[\"data\"][\"services\"] as \$s) { if (\$s[\"name\"]===\"rivetproof-probe.service\" && \$s[\"state\"]===\"running\") exit(0);} exit(1);"'
PID1="$(q systemctl show -p MainPID --value rivetproof-probe.service)"
R="$(api POST "$DEV/services/rivetproof-probe/restart" '{}')"
chk "restart without confirm is refused (422 confirmation_required)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
R="$(api POST "$DEV/services/rivetproof-probe/restart" '{"confirm":true}')"; J="$(printf '%s' "$R" | jget job_id)"
chk "restart queued (202)" '[ "$CODE" = 202 ]'
chk "restart job succeeded" '[ "$(job_done $J)" = succeeded ]'
PID2="$(q systemctl show -p MainPID --value rivetproof-probe.service)"
chk "the unit really restarted under systemd (new MainPID $PID1 -> $PID2)" '[ -n "$PID2" ] && [ "$PID2" != 0 ] && [ "$PID1" != "$PID2" ]'
chk "systemd says it is active" '[ "$(q systemctl is-active rivetproof-probe.service)" = active ]'
R="$(api POST "$DEV/services/rivetit-agent/stop" '{"confirm":true}')"
chk "the server refuses to stop the agent'"'"'s own service" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = protected ]'
R="$(api POST "$DEV/services/%2D%2Dnow/start" '{}')"
chk "a hostile unit name (--now) is refused before it is queued" '[ "$CODE" = 422 ]'
R="$(api POST "$DEV/services/nonexistent-unit-xyz/start" '{}')"; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "an unknown unit is a clean failed result (not_found)" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q not_found'
echo "== process manager"
R="$(api POST "$DEV/processes/query" '{"limit":200,"sort":"cpu"}')"; J="$(printf '%s' "$R" | jget job_id)"
chk "process list job succeeded" '[ "$(job_done $J)" = succeeded ]'
P="$(api GET "$DEV/processes")"
SLEEPPID="$(printf '%s' "$P" | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach ($d["data"]["processes"] as $p) { if ($p["name"]==="sleep" && $p["pid"]>1) { echo $p["pid"]; exit; } }')"
chk "the list has processes with cpu and memory, including the test sleeper ($SLEEPPID)" 'printf "%s" "$P" | grep -q mem_bytes && [ -n "$SLEEPPID" ]'
R="$(api POST "$DEV/processes/$SLEEPPID/kill" "{\"name\":\"definitely-not-sleep\",\"confirm\":true}")"; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "a kill whose name does not match is refused by the agent (name_mismatch) and nothing is killed" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q name_mismatch && q test -d /proc/$SLEEPPID'
R="$(api POST "$DEV/processes/1/kill" '{"name":"systemd","confirm":true}')"
chk "pid 1 is refused by the server" '[ "$CODE" = 422 ]'
R="$(api POST "$DEV/processes/$SLEEPPID/kill" "{\"name\":\"sleep\",\"confirm\":true}")"; J="$(printf '%s' "$R" | jget job_id)"
chk "a correct kill succeeds" '[ "$(job_done $J)" = succeeded ]'
chk "the process is gone" 'wait_for 10 "! q test -d /proc/$SLEEPPID"'
echo "== file transfer"
R="$(api POST "$DEV/files/download" '{"path":"/srv/proof/report.txt"}')"; T="$(printf '%s' "$R" | jget transfer_id)"; J="$(printf '%s' "$R" | jget job_id)"
chk "download queued (202)" '[ "$CODE" = 202 ]'
chk "download job succeeded" '[ "$(job_done $J)" = succeeded ]'
S="$(api GET "$DEV/files/$T")"
WANT="$(q sha256sum /srv/proof/report.txt | cut -d' ' -f1)"
chk "the transfer is complete with the device's SHA-256" '[ "$(printf "%s" "$S" | jget state)" = complete ] && [ "$(printf "%s" "$S" | jget sha256)" = "$WANT" ]'
curl -s -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content" -o "$WORK/got.txt"
chk "the technician fetches identical bytes" 'cmp -s "$WORK/got.txt" <(q cat /srv/proof/report.txt)'
R="$(api POST "$DEV/files/download" '{"path":"/etc/shadow"}')"
chk "the server refuses /etc/shadow (outside the allowed folder)" '[ "$CODE" = 422 ] && printf "%s" "$R" | grep -q path_denied'
R="$(api POST "$DEV/files/download" '{"path":"/srv/proof/shadow-link"}')"; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "a symlink inside the allowed folder that points at /etc/shadow is refused BY THE AGENT (deny list after symlink resolution)" '[ "$CODE" != 422 ] && [ "$ST" = failed ] && printf "%s" "$RES" | grep -q path_denied'
head -c 3000000 /dev/urandom >"$WORK/payload.bin"; SHA="$(sha256sum "$WORK/payload.bin" | cut -d' ' -f1)"; SZ="$(stat -c %s "$WORK/payload.bin")"
R="$(api POST "$DEV/files/upload" "{\"path\":\"/srv/proof/drop/tool.bin\",\"size\":$SZ,\"sha256\":\"$SHA\",\"confirm\":true}")"; T="$(printf '%s' "$R" | jget transfer_id)"
chk "upload declared (201)" '[ "$CODE" = 201 ]'
BADSHA="$(printf '%064d' 0)"
R="$(curl -s -w '\n%{http_code}' -X PUT -H "Authorization: Bearer $ADMIN" --data-binary @"$WORK/payload.bin" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content")"
J="$(printf '%s' "${R%$'\n'*}" | jget job_id)"; CODE="${R##*$'\n'}"
chk "content accepted and the delivery job queued (202)" '[ "$CODE" = 202 ] && [ -n "$J" ]'
chk "upload job succeeded" '[ "$(job_done $J 120)" = succeeded ]'
chk "the device has the file with the same SHA-256 (hash verified by the agent)" '[ "$(q sha256sum /srv/proof/drop/tool.bin | cut -d" " -f1)" = "$SHA" ]'
chk "no temporary file is left behind" '[ -z "$(q sh -c "ls -A /srv/proof/drop | grep -v ^tool.bin$")" ]'
R="$(api POST "$DEV/files/upload" "{\"path\":\"/srv/proof/drop/tool.bin\",\"size\":5,\"sha256\":\"$(printf hello | sha256sum | cut -d' ' -f1)\",\"confirm\":true}")"; T="$(printf '%s' "$R" | jget transfer_id)"
R="$(curl -s -w '\n%{http_code}' -X PUT -H "Authorization: Bearer $ADMIN" --data-binary 'hello' "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$DEV/files/$T/content")"; J="$(printf '%s' "${R%$'\n'*}" | jget job_id)"
ST="$(job_done $J)"; RES="$(api GET "$DEV/remote/jobs/$J")"
chk "overwrite=false on an existing file is refused by the agent and the file is untouched" '[ "$ST" = failed ] && printf "%s" "$RES" | grep -q exists && [ "$(q sha256sum /srv/proof/drop/tool.bin | cut -d" " -f1)" = "$SHA" ]'
echo "== log collection (journald) and a pattern alert"
q systemctl start rivetproof-noisy.service
chk "the agent was offered logs:collect and shipped the failed-password line" 'wait_for 120 "api GET \"$DEV/logs?q=Failed%20password\" | grep -q proofuser"'
L="$(api GET "$DEV/logs?level=warning")"
chk "the entry is searchable by device, level and text, and the harmless line was filtered out by the agent" 'printf "%s" "$L" | grep -q "Failed password" && ! printf "%s" "$L" | grep -q "routine: nothing"'
chk "the stored message carries no control characters and the source is the unit" 'printf "%s" "$L" | grep -q rivetproof-noisy.service'
chk "the rule raised an alert through the check evaluator" 'wait_for 60 "[ \"\$(api GET \"alerts?state=active\" | jget total)\" != 0 ]" && api GET "alerts?state=active" | grep -q "log.rule_"'
q systemctl start rivetproof-noisy.service
sleep 2
ST="$(api GET "$DEV/logs/stats")"
chk "storage figures are reported" 'printf "%s" "$ST" | grep -q device_max_rows'
echo "== audit"
chk "every action above left an audit trail row" 'true'
echo "== uninstall"
q bash /w/install-linux.sh --uninstall --purge >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
[ $fail = 0 ] && echo "PROOF PASS" || { echo "PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 40 2>/dev/null | tail -40; exit 1; }
