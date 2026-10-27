#!/usr/bin/env bash
# REAL-agent proof of RMM Phase 4 (patch management) on Linux.
#
#   the Linux agent (this tree's build) running under a REAL systemd in a privileged ubuntu:24.04 container, with a real apt and a LOCAL repository
#   (file://, two versions of a harmless dummy package) in place of the Ubuntu archive
#     <--TLS-->  nginx (host network, self-signed CA)  <--http-->  `php -S` serving RivetCore\Rmm on a SCRATCH database
#
# Shows: scan finds the update (security origin detected from the suite name), the server stores it, a technician approves it, a dry run lists it and changes
# nothing, a real install upgrades ONLY the approved package, history is written and compliance clears; a lock held by another apt, a package that is no longer
# applicable, an unknown package and hostile ids forged around the server are reported (or refused) without harm; the agent never restarts the machine.
# The scheduler (a housekeeping pass) queues the scan and the install by itself once a policy allows it.
#
# The server side is e2e/php_scratch (router.php, setup.php with RMM_PROOF_PATCHING=1, housekeeping.php). Nothing here touches a real database:
# RIVETCORE_TEST_DB_NAME must contain "scratch" and is DROPPED AND RECREATED. Needs docker, php (mysqli), curl, openssl.
#
#   RIVETCORE_TEST_DB_HOST/PORT/USER/PASS/NAME=... [AGENT_BIN=path] e2e/run_patch_proof.sh
#
# Prints "ok:"/"FAIL:" lines and a final "PATCH PROOF PASS" or "PATCH PROOF FAIL". Windows is NOT exercised here (see docs/rmm/WINDOWS_VERIFICATION.md).
# shellcheck disable=SC2034  # variables are read inside the eval-ed check strings
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-patchproof}"
freeport() { python3 -c "import socket;s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1])"; }
PHPPORT="${PHP_PORT:-$(freeport)}"; TLSPORT="${TLS_PORT:-$(freeport)}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d /tmp/rivetit-patchproof.XXXXXX)"
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
    go build -trimpath -ldflags "-s -w -X main.version=0.8.0-proof -X main.commit=patchproof" -o /w/rivetit-agent . || { echo "cannot build the agent"; exit 2; }
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
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-patchproof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" \
  -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
export RMM_PROOF_PATCHING=1 RMM_GOLDEN_ALLOW_LINUX=1
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

echo "== real systemd container, a LOCAL apt repository, agent installed with install-linux.sh"
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 60); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$ROOT/scripts/install-linux.sh" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"; docker cp "$HERE/patch_repo.sh" "$NAME:/w/patch_repo.sh"
printf '%s' "$TOKEN" | docker exec -i "$NAME" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
q() { docker exec "$NAME" "$@"; }
# no network: the Ubuntu archive is removed from apt, only the local repository is a source; its suite is called noble-security, like Ubuntu's security pocket
q sh -c 'rm -f /etc/apt/sources.list.d/*.sources /etc/apt/sources.list; echo "deb [trusted=yes] file:/srv/repo noble-security main" >/etc/apt/sources.list.d/proof.list'
repo() { q bash /w/patch_repo.sh /srv/repo noble-security "$@" && q apt-get update -qq >/dev/null 2>&1; }
repo rivetit-dummy=1.0 rivetit-other=1.0
q env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq rivetit-dummy rivetit-other >/dev/null 2>&1
ver() { q dpkg-query -W -f='${Version}' "$1" 2>/dev/null; }
chk "baseline: rivetit-dummy 1.0 and rivetit-other 1.0 are installed" '[ "$(ver rivetit-dummy)" = 1.0 ] && [ "$(ver rivetit-other)" = 1.0 ]'
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-other=1.0 rivetit-other=1.1
chk "apt sees both as upgradable (candidate 1.1)" 'q apt list --upgradable 2>/dev/null | grep -q "rivetit-dummy.*1.1" && q apt list --upgradable 2>/dev/null | grep -q "rivetit-other.*1.1"'
BOOT="$(q cat /proc/sys/kernel/random/boot_id)"
OTHERS_BEFORE="$(q sh -c "dpkg-query -W | grep -v '^rivetit-dummy' | sha256sum")"
q bash /w/install-linux.sh --binary /w/rivetit-agent --server "https://$GATEWAY:$TLSPORT" --token-file /w/enroll.token --ca /w/ca.pem >"$WORK/install.out" 2>&1
chk "install exit 0" '[ $? = 0 ]'
wait_for 40 "[ \"\$(api GET '' | jget total)\" = 1 ]"; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against the PHP server and is a device ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != "null" ]'
CAPS="$(wait_for 40 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q patch:scan" ; dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV")"
chk "the agent announced patch:scan, patch:install, job:patch_scan and job:patch_install" 'for c in patch:scan patch:install job:patch_scan job:patch_install; do printf "%s" "$CAPS" | grep -q "\"$c\"" || { echo "missing $c"; false; break; }; done'
jstate() { dbq "SELECT state FROM endpoint_agent_jobs WHERE job_id = '$1'"; }
job_done() { local j=$1 t="${2:-90}" i=0 st; while [ $i -lt $((t*2)) ]; do st="$(jstate $j)"; case "$st" in succeeded|failed|timed_out|cancelled|expired) echo "$st"; return 0;; esac; i=$((i+1)); sleep 0.5; done; echo "$st"; return 1; }
jout() { dbq "SELECT output FROM endpoint_agent_jobs WHERE job_id = '$1'"; }
status_of() { dbq "SELECT status FROM rmm_patch_available WHERE device_id = $DEV AND update_id = '$1'"; }
last_result() { dbq "SELECT CONCAT(result, '|', code) FROM rmm_patch_history WHERE device_id = $DEV AND update_id = '$1' ORDER BY id DESC LIMIT 1"; }

echo "== detect first: the scan"
A POST "$DEV/patches/scan"; J="$(printf '%s' "$R" | jget job_id)"
chk "a scan is queued (202)" '[ "$CODE" = 202 ] && [ -n "$J" ]'
A POST "$DEV/patches/scan"
chk "a second scan while one waits is not queued twice (200 already_queued)" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget skipped)" = already_queued ]'
chk "the scan job succeeded" '[ "$(job_done $J)" = succeeded ]'
A GET "$DEV/patches"
chk "the server lists both updates, kind security (the candidate comes from a *-security suite), manager apt" '[ "$(printf "%s" "$R" | jget total)" = 2 ] && [ "$(printf "%s" "$R" | jget items.0.kind)" = security ] && [ "$(printf "%s" "$R" | jget summary.scan.manager)" = apt ] && [ "$(printf "%s" "$R" | jget summary.scan.state)" = ok ]'
chk "versions are recorded (current 1.0, candidate 1.1) and nothing is approved by itself" 'printf "%s" "$R" | grep -q "\"current_version\":\"1.0\"" && printf "%s" "$R" | grep -q "\"version\":\"1.1\"" && [ "$(printf "%s" "$R" | jget items.0.status)" = available ]'
A GET "patches/compliance?overdue_days=0"
chk "compliance (overdue 0 days): the device is non-compliant, 100 percent of the fleet" '[ "$(printf "%s" "$R" | jget summary.devices)" = 1 ] && [ "$(printf "%s" "$R" | jget summary.noncompliant)" = 1 ]'
chk "the read-only scan changed nothing on the machine" '[ "$(ver rivetit-dummy)" = 1.0 ] && [ "$(ver rivetit-other)" = 1.0 ]'

echo "== approve, rehearse, then install gated"
A POST "$DEV/patches/install" '{"ids":["rivetit-dummy"]}'
chk "a dry run of an update that is only available is allowed (202)" '[ "$CODE" = 202 ] && [ "$(printf "%s" "$R" | jget mode)" = dry_run ]'
J="$(printf '%s' "$R" | jget job_id)"; ST="$(job_done $J)"
chk "the dry run job succeeded and reported would_install" '[ "$ST" = succeeded ] && jout $J | grep -q would_install'
chk "a dry run installs nothing (rivetit-dummy still 1.0, status still available)" '[ "$(ver rivetit-dummy)" = 1.0 ] && [ "$(status_of rivetit-dummy)" = available ]'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true,"ids":["rivetit-dummy"]}'
chk "a real install of an update nobody approved is refused (404)" '[ "$CODE" = 404 ]'
A POST "patches/approve" '{"update_ids":["rivetit-dummy"]}'
chk "approve rivetit-dummy only (200, one row)" '[ "$CODE" = 200 ] && [ "$(printf "%s" "$R" | jget changed)" = 1 ]'
A POST "$DEV/patches/install" '{"mode":"install"}'
chk "a real install needs the explicit confirm (422)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
A POST "$DEV/patches/install" '{}'; J="$(printf '%s' "$R" | jget job_id)"
chk "the default install is a dry run and lists exactly the approved update" '[ "$CODE" = 202 ] && [ "$(printf "%s" "$R" | jget queued)" = 1 ] && [ "$(job_done $J)" = succeeded ] && jout $J | grep -q "rivetit-dummy" && ! jout $J | grep -q "rivetit-other"'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true}'; J="$(printf '%s' "$R" | jget job_id)"
chk "the real install is queued (202)" '[ "$CODE" = 202 ] && [ "$(printf "%s" "$R" | jget mode)" = install ]'
chk "the install job succeeded" '[ "$(job_done $J 180)" = succeeded ]'
chk "ONLY the approved package was upgraded (dummy 1.1, other still 1.0, every other package byte-identical)" '[ "$(ver rivetit-dummy)" = 1.1 ] && [ "$(ver rivetit-other)" = 1.0 ] && [ "$(q sh -c "dpkg-query -W | grep -v ^rivetit-dummy | sha256sum")" = "$OTHERS_BEFORE" ]'
chk "status installed and history recorded (installed)" '[ "$(status_of rivetit-dummy)" = installed ] && [ "$(last_result rivetit-dummy | cut -d"|" -f1)" = installed ]'
chk "the machine was not restarted" '[ "$(q cat /proc/sys/kernel/random/boot_id)" = "$BOOT" ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = '\''reboot'\''")" = 0 ]'
wait_for 90 "[ \"\$(dbq \"SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = 'patch_scan' AND state = 'succeeded'\")\" -ge 2 ]"
A GET "$DEV/patches?status=missing"
chk "the follow-up scan that the server queued after the install shows only rivetit-other left" '[ "$(printf "%s" "$R" | jget total)" = 1 ] && [ "$(printf "%s" "$R" | jget items.0.update_id)" = rivetit-other ]'
A POST "patches/decline" '{"update_ids":["rivetit-other"],"reason":"proof: not wanted"}'
chk "decline rivetit-other (it stays at 1.0 and is never scheduled)" '[ "$CODE" = 200 ] && [ "$(status_of rivetit-other)" = declined ]'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true,"ids":["rivetit-other"]}'
chk "a declined update cannot be installed (404)" '[ "$CODE" = 404 ]'
A GET "$DEV/patches/history"
chk "history shows the dry runs and the install with who/what/when" 'printf "%s" "$R" | grep -q "dry_run" && printf "%s" "$R" | grep -q "\"action\":\"install\""'

echo "== a restart is reported, never done"
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-dummy=1.2:'touch /run/reboot-required' rivetit-other=1.0 rivetit-other=1.1
A POST "$DEV/patches/scan"; J="$(printf '%s' "$R" | jget job_id)"; job_done $J >/dev/null
A POST "patches/approve" '{"update_ids":["rivetit-dummy"]}'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true,"reboot_policy":"if_required_defer"}'; J="$(printf '%s' "$R" | jget job_id)"
chk "install of a package that asks for a restart succeeded" '[ "$(job_done $J 180)" = succeeded ] && [ "$(ver rivetit-dummy)" = 1.2 ]'
chk "the machine says a restart is needed (/run/reboot-required) and the server shows reboot_pending after the next scan" 'q test -e /run/reboot-required && wait_for 90 "[ \"\$(dbq \"SELECT reboot_pending FROM rmm_patch_scans WHERE device_id = $DEV\")\" = 1 ]"'
chk "still no restart and no reboot job (policy if_required_defer is report-only)" '[ "$(q cat /proc/sys/kernel/random/boot_id)" = "$BOOT" ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = '\''reboot'\''")" = 0 ]'
q rm -f /run/reboot-required

echo "== failure path: a package manager lock held by someone else"
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-dummy=1.2 rivetit-dummy=1.3 rivetit-other=1.0 rivetit-other=1.1 'rivetit-slow=1.0:sleep 100'
q sh -c 'nohup env DEBIAN_FRONTEND=noninteractive apt-get install -y rivetit-slow >/w/slow.out 2>&1 &' 
wait_for 30 "q pgrep -f '[s]leep 100' >/dev/null" ; ok "another apt-get is running a long postinst and holds the dpkg lock"
A POST "$DEV/patches/scan"; J="$(printf '%s' "$R" | jget job_id)"; job_done $J >/dev/null
A POST "patches/approve" '{"update_ids":["rivetit-dummy"]}'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true}'; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J 120)"
chk "the install job FAILED (job state failed), not hung, and the other apt was not touched" '[ "$ST" = failed ] && q pgrep -f "[s]leep 100" >/dev/null'
chk "the agent reported locked for the update and nothing was installed (dummy still 1.2)" '[ "$(last_result rivetit-dummy | cut -d"|" -f1)" = locked ] && [ "$(ver rivetit-dummy)" = 1.2 ] && [ "$(status_of rivetit-dummy)" = failed ]'
chk "an alert patch.install_failed opened (the check evaluator, so the alerting phase owns it)" 'wait_for 20 "[ \"\$(dbq \"SELECT status FROM endpoint_agent_checks WHERE device_id = $DEV AND check_key = '\''patch.install_failed'\''\")\" = fail ]"'
chk "the lock holder was neither killed nor had its lock file removed" 'q pgrep -f "[s]leep 100" >/dev/null && q test -e /var/lib/dpkg/lock-frontend'
wait_for 150 "! q pgrep -f '[s]leep 100' >/dev/null"; q sh -c 'while pgrep -f "[a]pt-get install -y rivetit-slow" >/dev/null; do sleep 1; done'
A POST "patches/approve" '{"update_ids":["rivetit-dummy"]}'
A POST "$DEV/patches/install" '{"mode":"install","confirm":true,"ids":["rivetit-dummy"]}'; J="$(printf '%s' "$R" | jget job_id)"
chk "after the lock is released the retry succeeds (dummy 1.3)" '[ "$(job_done $J 180)" = succeeded ] && [ "$(ver rivetit-dummy)" = 1.3 ]'
chk "the failure alert recovers" 'wait_for 90 "[ \"\$(dbq \"SELECT status FROM endpoint_agent_checks WHERE device_id = $DEV AND check_key = '\''patch.install_failed'\''\")\" = ok ]"'

echo "== failure path: no longer applicable, unknown, hostile"
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-dummy=1.2 rivetit-dummy=1.3 rivetit-dummy=1.4 rivetit-other=1.0 rivetit-other=1.1 rivetit-slow=1.0
A POST "$DEV/patches/scan"; J="$(printf '%s' "$R" | jget job_id)"; job_done $J >/dev/null
A POST "patches/approve" '{"update_ids":["rivetit-dummy"]}'
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-dummy=1.2 rivetit-dummy=1.3 rivetit-other=1.0 rivetit-other=1.1 rivetit-slow=1.0
A POST "$DEV/patches/install" '{"mode":"install","confirm":true}'; J="$(printf '%s' "$R" | jget job_id)"
chk "an approved update that vanished from the repository before the install is not_applicable (the agent re-checks), nothing installed" '[ "$(job_done $J 120)" = succeeded ] && [ "$(last_result rivetit-dummy)" = "not_applicable|" ] && [ "$(ver rivetit-dummy)" = 1.3 ]'
dbq "INSERT INTO rmm_patch_available (device_id, client_id, update_id, title, kind, status, first_seen_at, last_seen_at) SELECT $DEV, client_id, 'nonexistent-pkg-xyz', 'unknown', 'security', 'approved', UTC_TIMESTAMP(), UTC_TIMESTAMP() FROM endpoint_agent_devices WHERE device_id = $DEV" >/dev/null
A POST "$DEV/patches/install" '{"mode":"install","confirm":true,"ids":["nonexistent-pkg-xyz"]}'; J="$(printf '%s' "$R" | jget job_id)"
chk "a package that exists nowhere is not_found, the job fails cleanly" '[ "$(job_done $J 120)" = failed ] && [ "$(last_result nonexistent-pkg-xyz | cut -d"|" -f1)" = not_found ]'
for H in '-oAPT::Foo' 'a b' 'x;touch /w/pwned' '$(touch /w/pwned)'; do
  A POST "$DEV/patches/install" "{\"mode\":\"install\",\"confirm\":true,\"ids\":[\"$H\"]}"
  chk "hostile id '$H' is refused by the server (422)" '[ "$CODE" = 422 ]'
done
# jobs forged AROUND the server (rows written straight into the queue, so the server signs them as if it had made them): the agent validates again
JID="$(php -r 'echo sprintf("%08x-%04x-4%03x-8%03x-%012x", random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));')"
dbq "INSERT INTO endpoint_agent_jobs (job_id, device_id, asset_id, client_id, type, params_json, timeout_s, max_output_bytes, destructive, run_as, state, attempt, issued_at, expires_at, created_by, created_at)
     SELECT '$JID', device_id, asset_id, client_id, 'patch_install', '{\"ids\":\"-oAPT::Foo,rivetit-dummy\",\"mode\":\"install\",\"max_minutes\":5}', 300, 65536, 1, 'SYSTEM', 'queued', 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), 1, UTC_TIMESTAMP() FROM endpoint_agent_devices WHERE device_id = $DEV" >/dev/null
chk "a forged install job with an option-looking id is refused by the AGENT (invalid_params), no command ran" '[ "$(job_done $JID 90)" = failed ] && jout $JID | grep -q invalid_params && [ "$(ver rivetit-dummy)" = 1.3 ]'
chk "nothing in the container was touched by the hostile ids" '! q test -e /w/pwned'

echo "== the scheduler (a housekeeping pass), not a person, does the work"
A POST "patches/rules" '{"name":"security after 0 days","kinds":["security"],"after_days":0}'
chk "an approval rule is saved" '[ "$CODE" = 201 ]'
A POST "patch_policies" '{"name":"proof policy","settings":{"install_enabled":true,"reboot_policy":"never","max_minutes":10}}'; PID_="$(printf '%s' "$R" | jget policy.policy_id)"
A POST "patch_policies/$PID_/assignments" '{"scope_type":"global"}'
chk "a patch policy that allows installing is assigned to everyone" '[ "$CODE" = 200 ]'
repo rivetit-dummy=1.0 rivetit-dummy=1.1 rivetit-dummy=1.2 rivetit-dummy=1.3 rivetit-dummy=1.5 rivetit-other=1.0 rivetit-other=1.1 rivetit-slow=1.0
dbq "UPDATE rmm_patch_scans SET scanned_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY), requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "a housekeeping pass queues the scan by itself (patch_scans_queued 1)" 'printf "%s" "$HK" | grep -q "\"patch_scans_queued\":1"'
wait_for 120 "[ \"\$(dbq \"SELECT COUNT(*) FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id WHERE p.source = 'scheduler' AND p.type = 'patch_scan' AND j.state = 'succeeded' AND p.applied_at IS NOT NULL\")\" -ge 1 ]"
dbq "UPDATE rmm_patch_scans SET evaluated_at = NULL" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "the rule approved the new security update by itself, a declined update stayed declined" '[ "$(status_of rivetit-dummy)" = approved ] && [ "$(status_of rivetit-other)" = declined ]'
chk "the pass queued a DRY RUN only (live installs are off by default)" 'printf "%s" "$HK" | grep -q "\"patch_dry_runs_queued\":1" && printf "%s" "$HK" | grep -q "\"patch_installs_queued\":0"'
wait_for 90 "[ \"\$(dbq \"SELECT COUNT(*) FROM rmm_patch_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id WHERE p.source = 'scheduler' AND p.mode = 'dry_run' AND j.state = 'succeeded'\")\" = 1 ]"
chk "...and nothing was installed (dummy still 1.3)" '[ "$(ver rivetit-dummy)" = 1.3 ]'
dbq "UPDATE endpoint_agent_settings SET limits_json = '{\"patch_live_install\":1}'" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "with live installs switched on (limit patch_live_install = 1) the pass queues the real install" 'printf "%s" "$HK" | grep -q "\"patch_installs_queued\":1"'
chk "the scheduled install ran on the device: dummy 1.5, nothing else upgraded" 'wait_for 180 "[ \"\$(ver rivetit-dummy)\" = 1.5 ]" && [ "$(ver rivetit-other)" = 1.0 ]'
chk "the machine was still not restarted by any of this" '[ "$(q cat /proc/sys/kernel/random/boot_id)" = "$BOOT" ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = '\''reboot'\''")" = 0 ]'
A GET "patches/compliance"
chk "the fleet compliance page reads the device as compliant after the follow-up scan" 'wait_for 90 "[ \"\$(api GET patches/compliance | jget summary.noncompliant)\" = 0 ]"'
echo "== uninstall"
q bash /w/install-linux.sh --uninstall --purge >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
[ $fail = 0 ] && echo "PATCH PROOF PASS" || { echo "PATCH PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 40 2>/dev/null | tail -40; exit 1; }
