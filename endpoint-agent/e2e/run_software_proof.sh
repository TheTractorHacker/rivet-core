#!/usr/bin/env bash
# REAL-agent proof of RMM Phase 5 (software deployment) on Linux.
#
#   the Linux agent (this tree's build) running under a REAL systemd in a privileged ubuntu:24.04 container, with a real apt and a LOCAL repository
#   (file://, two versions of a harmless dummy package) in place of the Ubuntu archive
#     <--TLS-->  nginx (host network, self-signed CA)  <--http-->  `php -S` serving RivetCore\Rmm on a SCRATCH database
#
# Shows: the agent announces software:detect and software:install; the catalog refuses hostile versions; a dry run changes nothing; a real install (pinned version) installs ONLY that
# package; installing what is already there does nothing; a removal that would take another package with it is refused; an unknown package is not_found; a held package manager
# lock is reported and never broken; jobs forged around the server (option-looking package names, a download address on a package-manager install) are refused by the AGENT; and
# the scheduler (a housekeeping pass), not a person, detects, rehearses with a dry run (live installs are off by default), installs once live installs are switched on, and the
# deployed-versus-expected report and its CSV read the result. The agent never restarts the machine. Windows (msi, exe and the hash-pinned download) is NOT exercised here: it is
# unit-tested with a fake registry, runner and a local TLS server only (docs/rmm/SOFTWARE_DEPLOY.md, section 12).
#
# The server side is e2e/php_scratch (router.php, setup.php with RMM_PROOF_SOFTWARE=1, housekeeping.php). Nothing here touches a real database:
# RIVETCORE_TEST_DB_NAME must contain "scratch" and is DROPPED AND RECREATED. Needs docker, php (mysqli), curl, openssl.
#
#   RIVETCORE_TEST_DB_HOST/PORT/USER/PASS/NAME=... [AGENT_BIN=path] e2e/run_software_proof.sh
#
# Prints "ok:"/"FAIL:" lines and a final "SOFTWARE PROOF PASS" or "SOFTWARE PROOF FAIL". Windows is NOT exercised here (see docs/rmm/WINDOWS_VERIFICATION.md).
# shellcheck disable=SC2034  # variables are read inside the eval-ed check strings
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NGINX_IMG="${NGINX_IMAGE:-nginx:alpine}"
NAME="${PROOF_NAME:-rivetit-softproof}"
freeport() { python3 -c "import socket;s=socket.socket();s.bind(('127.0.0.1',0));print(s.getsockname()[1])"; }
PHPPORT="${PHP_PORT:-$(freeport)}"; TLSPORT="${TLS_PORT:-$(freeport)}"
GATEWAY="$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || echo 172.17.0.1)"
WORK="$(mktemp -d "${TMPDIR:-/tmp}/rivetit-softproof.XXXXXX")"
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
    go build -trimpath -ldflags "-s -w -X main.version=0.9.0-proof -X main.commit=softproof" -o /w/rivetit-agent . || { echo "cannot build the agent"; exit 2; }
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
openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=rivetit-softproof" -addext "subjectAltName=IP:$GATEWAY,IP:127.0.0.1,DNS:localhost" \
  -keyout "$WORK/tls/key.pem" -out "$WORK/tls/ca.pem" >/dev/null 2>&1
export RMM_PROOF_SOFTWARE=1 RMM_GOLDEN_ALLOW_LINUX=1
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
docker cp "$AGENT_BIN" "$NAME:/w/rivetit-agent"; docker cp "$ROOT/scripts/install-linux.sh" "$NAME:/w/install-linux.sh"; docker cp "$WORK/tls/ca.pem" "$NAME:/w/ca.pem"; docker cp "$HERE/software_repo.sh" "$NAME:/w/software_repo.sh"
printf '%s' "$TOKEN" | docker exec -i "$NAME" sh -c 'cat >/w/enroll.token; chmod 600 /w/enroll.token'
q() { docker exec "$NAME" "$@"; }
# no network: the Ubuntu archive is removed from apt, only the local repository is a source
q sh -c 'rm -f /etc/apt/sources.list.d/*.sources /etc/apt/sources.list; echo "deb [trusted=yes] file:/srv/repo stable main" >/etc/apt/sources.list.d/proof.list'
repo() { q bash /w/software_repo.sh /srv/repo stable "$@" && q apt-get update -qq >/dev/null 2>&1; }
REPO_ALL=(rivetit-dummy=1.0 rivetit-dummy=1.5 rivetit-other=1.0 rivetit-base=1.0 'rivetit-app=1.0@rivetit-base' 'rivetit-slow=1.0:sleep 100')
repo "${REPO_ALL[@]}"
ver() { q dpkg-query -W -f='${Version}' "$1" 2>/dev/null; }
installed() { q dpkg-query -W -f='${Status}' "$1" 2>/dev/null | grep -q "install ok installed"; }
chk "baseline: none of the proof packages is installed" '! installed rivetit-dummy && ! installed rivetit-other && ! installed rivetit-base && ! installed rivetit-app'
BOOT="$(q cat /proc/sys/kernel/random/boot_id)"
q bash /w/install-linux.sh --binary /w/rivetit-agent --server "https://$GATEWAY:$TLSPORT" --token-file /w/enroll.token --ca /w/ca.pem >"$WORK/install.out" 2>&1
chk "install exit 0" '[ $? = 0 ]'
wait_for 40 "[ \"\$(api GET '' | jget total)\" = 1 ]"; DEV="$(api GET '' | jget data.0.device_id)"
chk "the agent enrolled against the PHP server and is a device ($DEV)" '[ -n "$DEV" ] && [ "$DEV" != "null" ]'
CAPS="$(wait_for 40 "dbq \"SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV\" | grep -q software:install" ; dbq "SELECT capabilities_json FROM rmm_device_state WHERE device_id = $DEV")"
chk "the agent announced software:detect, software:install, job:software_detect and job:software_install" 'for c in software:detect software:install job:software_detect job:software_install; do printf "%s" "$CAPS" | grep -q "\"$c\"" || { echo "missing $c"; false; break; }; done'
jstate() { dbq "SELECT state FROM endpoint_agent_jobs WHERE job_id = '$1'"; }
job_done() { local j=$1 t="${2:-90}" i=0 st; while [ $i -lt $((t*2)) ]; do st="$(jstate $j)"; case "$st" in succeeded|failed|timed_out|cancelled|expired) echo "$st"; return 0;; esac; i=$((i+1)); sleep 0.5; done; echo "$st"; return 1; }
jout() { dbq "SELECT output FROM endpoint_agent_jobs WHERE job_id = '$1'"; }
last_result() { dbq "SELECT CONCAT(result, '|', code) FROM rmm_package_history WHERE device_id = $DEV AND package_id = $1 ORDER BY id DESC LIMIT 1"; }
OTHERS_BEFORE="$(q sh -c "dpkg-query -W | grep -v '^rivetit-dummy' | sha256sum")"

echo "== the catalog refuses hostile versions"
A POST software_packages '{"name":"Dummy Tool","publisher":"proof"}'; PKG_D="$(printf '%s' "$R" | jget package_id)"
chk "a package is created (201)" '[ "$CODE" = 201 ] && [ -n "$PKG_D" ]'
for BADV in '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"-oAPT::Foo"}' '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"x;touch /w/pwned"}' '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"$(touch /w/pwned)"}' \
            '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"rivetit-dummy","url":"https://evil.example.com/x.deb"}' '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"rivetit-dummy","sha256":"'"$(printf 'a%.0s' $(seq 64))"'"}' \
            '{"version":"1","os":"windows","fmt":"msi","url":"http://dl.example.com/a.msi","sha256":"'"$(printf 'a%.0s' $(seq 64))"'","size_bytes":5,"product_code":"{12345678-ABCD-1234-ABCD-1234567890AB}"}'; do
  A POST "software_packages/$PKG_D/versions" "$BADV"
  chk "a hostile or malformed version is refused (422): ${BADV:0:90}" '[ "$CODE" = 422 ]'
done
A POST "software_packages/$PKG_D/versions" '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"rivetit-dummy","pin_version":true}'; VER_D="$(printf '%s' "$R" | jget version_id)"
chk "the real version is accepted (201), detection rule derived from the package name" '[ "$CODE" = 201 ] && [ "$(printf "%s" "$R" | jget detect_value)" = rivetit-dummy ]'
A POST "software_packages/$PKG_D/versions" '{"version":"1.5","os":"linux","fmt":"apt","pkg_name":"rivetit-other"}'
chk "a version can not be replaced: the same version again is refused (422)" '[ "$CODE" = 422 ]'
A PATCH "software_packages/$PKG_D/versions/$VER_D" '{"pkg_name":"rivetit-other"}'
chk "a version can not be edited (only retired): 422" '[ "$CODE" = 422 ]'

echo "== one run: dry run first, then the real thing"
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D}"; J="$(printf '%s' "$R" | jget job_id)"
chk "the default is a dry run (202)" '[ "$CODE" = 202 ] && [ "$(printf "%s" "$R" | jget mode)" = dry_run ]'
chk "the dry run succeeded and reported would_install" '[ "$(job_done $J)" = succeeded ] && jout $J | grep -q would_install'
chk "a dry run installs nothing" '! installed rivetit-dummy'
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D,\"mode\":\"run\"}"
chk "a real run needs the explicit confirm (422)" '[ "$CODE" = 422 ] && [ "$(printf "%s" "$R" | jget code)" = confirmation_required ]'
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D,\"mode\":\"run\",\"confirm\":true,\"url\":\"https://evil.example.com/x.deb\",\"pkg\":\"-oAPT::Foo\"}"; J="$(printf '%s' "$R" | jget job_id)"
chk "the real run is queued (202); extra body fields are not parameters" '[ "$CODE" = 202 ] && [ "$(dbq "SELECT params_json FROM endpoint_agent_jobs WHERE job_id = '\''$J'\''" | grep -c evil)" = 0 ]'
chk "the install job succeeded" '[ "$(job_done $J 180)" = succeeded ]'
chk "ONLY the pinned package was installed (dummy 1.5, every other package byte-identical)" '[ "$(ver rivetit-dummy)" = 1.5 ] && [ "$(q sh -c "dpkg-query -W | grep -v ^rivetit-dummy | sha256sum")" = "$OTHERS_BEFORE" ]'
chk "history recorded (installed) and the server knows the version" '[ "$(last_result $PKG_D | cut -d"|" -f1)" = installed ] && [ "$(dbq "SELECT installed_version FROM rmm_package_installs WHERE device_id = $DEV AND package_id = $PKG_D")" = 1.5 ]'
DPKGLOG_BEFORE="$(q wc -l </var/log/dpkg.log 2>/dev/null || echo 0)"
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D,\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "installing what is already there does nothing (already_installed, dpkg log untouched)" '[ "$(job_done $J 60)" = succeeded ] && jout $J | grep -q already_installed && [ "$(q wc -l </var/log/dpkg.log)" = "$DPKGLOG_BEFORE" ]'
chk "the machine was not restarted and no reboot job exists" '[ "$(q cat /proc/sys/kernel/random/boot_id)" = "$BOOT" ] && [ "$(dbq "SELECT COUNT(*) FROM endpoint_agent_jobs WHERE type = '\''reboot'\''")" = 0 ]'
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D,\"action\":\"uninstall\",\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "a removal succeeds and takes the package away" '[ "$(job_done $J 120)" = succeeded ] && ! installed rivetit-dummy && jout $J | grep -q uninstalled'
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_D,\"action\":\"uninstall\",\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "removing what is not there does nothing (not_installed)" '[ "$(job_done $J 60)" = succeeded ] && jout $J | grep -q not_installed'

echo "== safety: removal that would take another package, unknown package, lock held"
A POST software_packages '{"name":"App"}'; PKG_A="$(printf '%s' "$R" | jget package_id)"
A POST "software_packages/$PKG_A/versions" '{"version":"1.0","os":"linux","fmt":"apt","pkg_name":"rivetit-app"}' >/dev/null
A POST software_packages '{"name":"Base"}'; PKG_B="$(printf '%s' "$R" | jget package_id)"
A POST "software_packages/$PKG_B/versions" '{"version":"1.0","os":"linux","fmt":"apt","pkg_name":"rivetit-base"}' >/dev/null
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_A,\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "App installs together with its dependency Base" '[ "$(job_done $J 180)" = succeeded ] && installed rivetit-app && installed rivetit-base'
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_B,\"action\":\"uninstall\",\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "removing Base would remove App too: the agent refuses (job failed), both stay installed" '[ "$(job_done $J 120)" = failed ] && installed rivetit-app && installed rivetit-base && jout $J | grep -q would_remove_other'
A POST software_packages '{"name":"Ghost"}'; PKG_G="$(printf '%s' "$R" | jget package_id)"
A POST "software_packages/$PKG_G/versions" '{"version":"1.0","os":"linux","fmt":"apt","pkg_name":"nonexistent-pkg-xyz"}' >/dev/null
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_G,\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "a package that exists in no repository is not_found, the job fails cleanly" '[ "$(job_done $J 120)" = failed ] && [ "$(last_result $PKG_G | cut -d"|" -f1)" = not_found ]'
A POST software_packages '{"name":"Slow"}'; PKG_S="$(printf '%s' "$R" | jget package_id)"
A POST "software_packages/$PKG_S/versions" '{"version":"1.0","os":"linux","fmt":"apt","pkg_name":"rivetit-other"}' >/dev/null
q sh -c 'nohup env DEBIAN_FRONTEND=noninteractive apt-get install -y rivetit-slow >/w/slow.out 2>&1 &'
wait_for 30 "q pgrep -f '[s]leep 100' >/dev/null"; ok "another apt-get is running a long postinst and holds the dpkg lock"
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_S,\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
ST="$(job_done $J 120)"
chk "the install FAILED (job state failed), not hung, and the other apt was not touched" '[ "$ST" = failed ] && q pgrep -f "[s]leep 100" >/dev/null'
chk "the agent reported locked and nothing was installed" '[ "$(last_result $PKG_S | cut -d"|" -f1)" = locked ] && ! installed rivetit-other'
chk "the lock holder was neither killed nor had its lock file removed" 'q pgrep -f "[s]leep 100" >/dev/null && q test -e /var/lib/dpkg/lock-frontend'
wait_for 150 "! q pgrep -f '[s]leep 100' >/dev/null"; q sh -c 'while pgrep -f "[a]pt-get install -y rivetit-slow" >/dev/null; do sleep 1; done'
dbq "UPDATE endpoint_agent_jobs SET state = state" >/dev/null
A POST "$DEV/deployments/run" "{\"package_id\":$PKG_S,\"mode\":\"run\",\"confirm\":true}"; J="$(printf '%s' "$R" | jget job_id)"
chk "after the lock is released the retry succeeds" '[ "$(job_done $J 180)" = succeeded ] && installed rivetit-other'

echo "== jobs forged AROUND the server: the agent validates again"
forge() { # forge TYPE PARAMS-JSON -> job id (rows written straight into the queue, so the server signs them as if it had made them)
  local pj; pj="$(printf '%s' "$2" | sed 's/\\/\\\\/g')"   # a backslash is an escape in an SQL string
  local id; id="$(php -r 'echo sprintf("%08x-%04x-4%03x-8%03x-%012x", random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0xfff), random_int(0, 0xffffffffffff));')"
  dbq "INSERT INTO endpoint_agent_jobs (job_id, device_id, asset_id, client_id, type, params_json, timeout_s, max_output_bytes, destructive, run_as, state, attempt, issued_at, expires_at, created_by, created_at)
       SELECT '$id', device_id, asset_id, client_id, '$1', '$pj', 300, 65536, 1, 'SYSTEM', 'queued', 1, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR), 1, UTC_TIMESTAMP() FROM endpoint_agent_devices WHERE device_id = $DEV" >/dev/null
  echo "$id"
}
for P in '{"action":"install","mode":"run","fmt":"apt","pkg":"-oAPT::Foo"}' '{"action":"install","mode":"run","fmt":"apt","pkg":"x;touch /w/pwned"}' '{"action":"install","mode":"run","fmt":"apt","pkg":"rivetit-dummy","url":"https://evil.example.com/x.deb","sha256":"'"$(printf 'a%.0s' $(seq 64))"'","size":5}' \
         '{"action":"install","mode":"run","fmt":"apt","pkg":"rivetit-dummy","version":"1.0;touch /w/pwned"}' '{"action":"install","mode":"run","fmt":"msi","url":"https://evil.example.com/x.msi","sha256":"'"$(printf 'a%.0s' $(seq 64))"'","size":5,"product_code":"{12345678-ABCD-1234-ABCD-1234567890AB}"}' '{"action":"install","mode":"run","fmt":"apt","pkg":"rivetit-dummy","extra":"x"}'; do
  JID="$(forge software_install "$P")"
  chk "forged install refused by the AGENT ($(printf '%s' "$P" | cut -c1-70)...)" '[ "$(job_done $JID 90)" = failed ] && jout $JID | grep -Eq "invalid_params|unsupported_platform" && ! installed rivetit-dummy && ! q test -e /w/pwned'
done
JID="$(forge software_detect '{"rules":"[{\"key\":\"p1\",\"kind\":\"package\",\"value\":\"-oAPT::Foo\",\"min\":\"\"}]"}')"
chk "a forged detection rule with an option-looking package name is refused by the agent" '[ "$(job_done $JID 90)" = failed ] && jout $JID | grep -q invalid_params'
JID="$(forge software_detect '{"rules":"[{\"key\":\"p1\",\"kind\":\"package\",\"value\":\"rivetit-other\",\"min\":\"\"},{\"key\":\"p2\",\"kind\":\"package\",\"value\":\"rivetit-dummy\",\"min\":\"\"}]"}')"
ST="$(job_done $JID 60)"; echo "   detection output: $(jout $JID | cut -c1-400)"
chk "a valid detection answers per rule: other installed, dummy not" '[ "$(job_done $JID 60)" = succeeded ] && jout $JID | grep -q "\"key\":\"p1\",\"installed\":true" && jout $JID | grep -q "\"key\":\"p2\",\"installed\":false"'
chk "nothing in the container was touched by the hostile parameters" '! q test -e /w/pwned'

echo "== the scheduler (a housekeeping pass), not a person, does the work"
q env DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq rivetit-app rivetit-base rivetit-other >/dev/null 2>&1
CLIENT="$(dbq "SELECT client_id FROM endpoint_agent_devices WHERE device_id = $DEV")"
A POST software_assignments "{\"package_id\":$PKG_D,\"scope_type\":\"client\",\"scope_id\":$CLIENT}"
chk "the package is assigned to the device's client (201)" '[ "$CODE" = 201 ]'
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "a housekeeping pass looks at the device and asks it what it has (deploy_detects_queued 1)" 'printf "%s" "$HK" | grep -q "\"deploy_detects_queued\":1" && printf "%s" "$HK" | grep -q "\"deploy_evaluated\":1"'
DJ="$(dbq "SELECT job_id FROM rmm_package_jobs WHERE type = 'software_detect' AND source = 'scheduler' ORDER BY created_at DESC LIMIT 1")"
chk "the detection job succeeded and its result was applied" '[ "$(job_done $DJ 90)" = succeeded ] && wait_for 30 "[ \"\$(dbq \"SELECT applied_at IS NOT NULL FROM rmm_package_jobs WHERE job_id = '"'"'$DJ'"'"'\")\" = 1 ]"'
A GET "$DEV/deployments"; echo "   device state: $(printf '%s' "$R" | cut -c1-700)"
chk "the device is not compliant: the package is missing (or a dry run for it is already waiting)" '[ $(( $(printf "%s" "$R" | jget counts.missing) + $(printf "%s" "$R" | jget counts.pending) )) = 1 ] && [ "$(printf "%s" "$R" | jget counts.compliant)" = 0 ]'
HK="$(hk)"; echo "   housekeeping pass: $HK"
wait_for 90 "[ \"\$(dbq \"SELECT COUNT(*) FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id WHERE p.source = 'scheduler' AND p.mode = 'dry_run' AND j.state = 'succeeded' AND p.applied_at IS NOT NULL\")\" = 1 ]"
chk "the scheduler queued exactly one DRY RUN and no real install (live installs are off by default)" '[ "$(dbq "SELECT COUNT(*) FROM rmm_package_jobs WHERE source = '"'"'scheduler'"'"' AND mode = '"'"'dry_run'"'"'")" = 1 ] && [ "$(dbq "SELECT COUNT(*) FROM rmm_package_jobs WHERE source = '"'"'scheduler'"'"' AND mode = '"'"'run'"'"'")" = 0 ]'
wait_for 90 "[ \"\$(dbq \"SELECT COUNT(*) FROM rmm_package_jobs p JOIN endpoint_agent_jobs j ON j.job_id = p.job_id WHERE p.source = 'scheduler' AND p.mode = 'dry_run' AND j.state = 'succeeded' AND p.applied_at IS NOT NULL\")\" = 1 ]"
chk "...and nothing was installed" '! installed rivetit-dummy'
HK="$(hk)"
chk "a second pass queues no second dry run the same day" 'printf "%s" "$HK" | grep -q "\"deploy_dry_runs_queued\":0"'
dbq "UPDATE endpoint_agent_settings SET limits_json = '{\"software_live_install\":1}'" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "with live installs switched on (limit software_live_install = 1) the pass queues the real install" 'printf "%s" "$HK" | grep -q "\"deploy_installs_queued\":1"'
chk "the scheduled install ran on the device: dummy 1.5 installed" 'wait_for 180 "installed rivetit-dummy" && [ "$(ver rivetit-dummy)" = 1.5 ]'
chk "the scheduled job was attributed to the scheduler, not to a person" '[ "$(dbq "SELECT source FROM rmm_package_jobs WHERE mode = '\''run'\'' AND created_by IS NULL ORDER BY created_at DESC LIMIT 1")" = scheduler ]'
wait_for 60 "[ \"\$(dbq \"SELECT satisfied FROM rmm_package_installs WHERE device_id = $DEV AND package_id = $PKG_D\")\" = 1 ]"
A GET "software_report"
chk "the report reads the device as compliant (1 expected, 1 compliant, 100 percent)" '[ "$(printf "%s" "$R" | jget totals.expected)" = 1 ] && [ "$(printf "%s" "$R" | jget totals.compliant)" = 1 ] && [ "$(printf "%s" "$R" | jget totals.percent_compliant)" = 100 ]'
R="$(curl -s -H "Authorization: Bearer $ADMIN" "http://127.0.0.1:$PHPPORT/api/v1/endpoint_devices/software_report/csv?group_by=device")"
chk "the CSV export has a header and the device row" 'printf "%s" "$R" | head -1 | grep -q "^device_id,hostname" && printf "%s" "$R" | grep -q ",Dummy Tool,"'
HK="$(hk)"
chk "a pass when everything is as expected queues nothing" 'printf "%s" "$HK" | grep -q "\"deploy_installs_queued\":0" && printf "%s" "$HK" | grep -q "\"deploy_dry_runs_queued\":0"'
# someone removes it behind our back: the next detection notices and the scheduler puts it back
q env DEBIAN_FRONTEND=noninteractive apt-get remove -y -qq rivetit-dummy >/dev/null 2>&1
dbq "UPDATE rmm_deploy_state SET detected_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY), detect_requested_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 3 DAY)" >/dev/null
HK="$(hk)"
wait_for 90 "[ \"\$(dbq \"SELECT satisfied FROM rmm_package_installs WHERE device_id = $DEV AND package_id = $PKG_D\")\" = 0 ]"
dbq "UPDATE rmm_package_jobs SET idem_key = NULL" >/dev/null
HK="$(hk)"; echo "   housekeeping pass: $HK"
chk "drift: removed by hand, detected, and put back by the scheduler" 'printf "%s" "$HK" | grep -q "\"deploy_installs_queued\":1" && wait_for 180 "installed rivetit-dummy"'
A GET "software_assignments"; AID="$(printf '%s' "$R" | jget assignments.0.assignment_id)"
A DELETE "software_assignments/$AID"
chk "removing the assignment uninstalls nothing" '[ "$CODE" = 200 ] && installed rivetit-dummy'
echo "== uninstall"
q bash /w/install-linux.sh --uninstall --purge >"$WORK/uninstall.out" 2>&1
chk "uninstall exit 0" '[ $? = 0 ]'
[ $fail = 0 ] && echo "SOFTWARE PROOF PASS" || { echo "SOFTWARE PROOF FAIL"; tail -20 "$WORK/php.log" 2>/dev/null; docker exec "$NAME" journalctl -u rivetit-agent --no-pager -n 40 2>/dev/null | tail -40; exit 1; }
