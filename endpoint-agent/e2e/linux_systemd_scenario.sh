#!/usr/bin/env bash
# Runs inside the systemd container as root. See run_linux_systemd_e2e.sh.
set -uo pipefail
W=/w; BIN=$W/bin; OUT=$W/out; fail=0
ok()  { echo "ok: $*"; }
bad() { echo "FAIL: $*"; fail=1; }
chk() { if eval "$2"; then ok "$1"; else bad "$1  [$2]"; fi; }
wait_for() { local t=$1 c=$2 i=0; while ! eval "$c"; do i=$((i+1)); [ $i -ge $((t*5)) ] && return 1; sleep 0.2; done; return 0; }
ctl() { local a; a="$(cat "$OUT/ctl")"; exec 3<>"/dev/tcp/${a%:*}/${a#*:}"; printf 'GET /_ctl?%s HTTP/1.0\r\n\r\n' "$1" >&3; cat <&3 >/dev/null; exec 3>&-; }
chk "PID 1 is systemd" '[ "$(cat /proc/1/comm)" = systemd ]'
"$BIN/fakeserver" -dir "$OUT" -interval 5 -collect 5 -token rvte1.e2esel.E2Esecret123 -job-type shell \
  -job-script 'echo sd-job uid=$(id -u) pid1=$(cat /proc/1/comm); exit 0' -update-binary "$BIN/rivetit-agent-1.0.1" -update-version 1.0.1 -ctl-listen 127.0.0.1:0 >"$OUT/fs.out" 2>&1 &
wait_for 10 '[ -s "$OUT/url" ] && [ -s "$OUT/ctl" ]' || { bad "fake server"; exit 1; }
printf 'rvte1.e2esel.E2Esecret123' >"$OUT/tok"; chmod 600 "$OUT/tok"
bash $W/install-linux.sh --binary "$BIN/rivetit-agent-1.0.0" --server "$(cat $OUT/url)" --token-file "$OUT/tok" --ca "$OUT/ca.pem" >"$OUT/install.out" 2>&1; rc=$?
chk "install exit 0 (/tmp here is noexec: the script must find another work dir)" '[ $rc = 0 ]'
[ $rc = 0 ] || { cat "$OUT/install.out"; echo "SYSTEMD FAIL"; exit 1; }
chk "this container really has a noexec /tmp" 'grep -E " /tmp .*noexec" /proc/mounts >/dev/null'
chk "systemd reports the unit active and enabled" 'wait_for 20 "systemctl is-active --quiet rivetit-agent" && systemctl is-enabled --quiet rivetit-agent'
chk "unit properties: root, Restart=always, StateDirectory 0700" '[ "$(systemctl show -p User --value rivetit-agent)" = root ] && [ "$(systemctl show -p Restart --value rivetit-agent)" = always ] && [ "$(stat -c %a /var/lib/rivetit-agent)" = 700 ]'
chk "agent checked in under systemd with platform/capabilities" 'wait_for 60 "grep -q \"CHECKIN #2 \" $OUT/events.log" && grep -q "platform=linux/amd64" $OUT/events.log'
chk "signed shell job ran as root under the unit" 'wait_for 60 "grep -q \"JOB-REPORT job=e2e-job-1 state=succeeded\" $OUT/events.log"'
chk "agent log is in the journal" 'journalctl -u rivetit-agent --no-pager | grep -q "agent starting"'
OLD="$(systemctl show -p MainPID --value rivetit-agent)"
[ "${OLD:-0}" -gt 1 ] || { bad "no main PID"; echo "SYSTEMD FAIL"; exit 1; }
kill -9 "$OLD"
chk "Restart=always: a SIGKILLed agent is running again (new PID)" 'wait_for 40 "[ \"\$(systemctl show -p MainPID --value rivetit-agent)\" != 0 ] && [ \"\$(systemctl show -p MainPID --value rivetit-agent)\" != $OLD ] && systemctl is-active --quiet rivetit-agent"'
ctl "update=on"
chk "self-update: new binary active after exit 75 + systemd restart" 'wait_for 90 "grep -q \"CHECKIN #[0-9]* seq=[0-9]* version=1.0.1\" $OUT/events.log" && /opt/rivetit-agent/rivetit-agent version | grep -q 1.0.1 && systemctl is-active --quiet rivetit-agent'
chk "journal records the update restart" 'journalctl -u rivetit-agent --no-pager | grep -q "update staged and activated"'
chk "stop is clean (service inactive, no leftover job dirs)" 'systemctl stop rivetit-agent && ! systemctl is-active --quiet rivetit-agent && [ -z "$(ls -d /tmp/rivetit-job-* 2>/dev/null)" ]'
bash $W/install-linux.sh --uninstall --purge >"$OUT/un.out" 2>&1
chk "uninstall removed unit, binaries, state; systemd forgot the unit" '[ ! -e /etc/systemd/system/rivetit-agent.service ] && [ ! -e /opt/rivetit-agent ] && [ ! -e /var/lib/rivetit-agent ] && ! systemctl is-enabled --quiet rivetit-agent 2>/dev/null'
if [ $fail = 0 ]; then echo "SYSTEMD PASS"; else echo "SYSTEMD FAIL"; exit 1; fi
