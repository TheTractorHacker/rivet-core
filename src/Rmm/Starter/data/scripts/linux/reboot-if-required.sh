#!/usr/bin/env bash
# Schedule a delayed reboot only when one is required. dry_run defaults to true.
dry_run="${dry_run:-true}"
delay_minutes="${delay_minutes:-15}"
case "$delay_minutes" in ''|*[!0-9]*) echo "invalid delay_minutes" >&2; exit 2;; esac
if [ "$delay_minutes" -lt 1 ] || [ "$delay_minutes" -gt 1440 ]; then echo "delay_minutes out of range 1..1440" >&2; exit 2; fi
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Reboot if required (Linux) =="
required=no
if [ -f /var/run/reboot-required ]; then
  required=yes; echo "Reboot flag present: /var/run/reboot-required"
elif have needs-restarting; then
  needs-restarting -r >/dev/null 2>&1 || required=yes
  echo "needs-restarting -r says reboot required: $required"
elif have zypper; then
  zypper --non-interactive needs-rebooting >/dev/null 2>&1 || required=yes
  echo "zypper needs-rebooting says reboot required: $required"
fi
if [ "$required" != "yes" ]; then
  echo "[SKIP] no reboot is required"
  exit 0
fi
pending=no
if [ -e /run/systemd/shutdown/scheduled ]; then pending=yes
elif have shutdown && shutdown --show 2>&1 | grep -q -i -E 'scheduled'; then pending=yes; fi
if [ "$pending" = "yes" ]; then
  echo "[SKIP] a shutdown or reboot is already scheduled; not stacking another"
  exit 0
fi
if [ "$dry_run" = "true" ]; then
  echo "[DRY-RUN] would schedule: shutdown -r +${delay_minutes} (with a wall message)"
  exit 0
fi
if ! have shutdown; then echo "shutdown command not found" >&2; exit 3; fi
if shutdown -r "+${delay_minutes}" "Reboot scheduled in ${delay_minutes} minutes to finish pending updates. Please save your work." 2>&1; then
  echo "[DONE] reboot scheduled in ${delay_minutes} minutes (cancel with: shutdown -c)"
  exit 0
fi
echo "[SKIP] shutdown returned an error"
exit 3
