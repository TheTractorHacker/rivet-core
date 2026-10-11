#!/usr/bin/env bash
# Scheduled jobs overview (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
dump() { # file
  [ -f "$1" ] && [ -r "$1" ] || return
  echo "# $1"
  grep -v -E '^[[:space:]]*(#|$)' "$1" 2>/dev/null | head -n 50
}
echo "== Scheduled jobs (Linux) =="
echo "-- System crontab --"
dump /etc/crontab
echo
echo "-- /etc/cron.d --"
for f in /etc/cron.d/*; do dump "$f"; done
echo
echo "-- Periodic directories --"
for d in /etc/cron.hourly /etc/cron.daily /etc/cron.weekly /etc/cron.monthly; do
  [ -d "$d" ] && echo "$d: $(find "$d" -maxdepth 1 -type f -printf "%f\n" 2>/dev/null | tr '\n' ' ')"
done
echo
echo "-- Per-user crontabs --"
found=0
for d in /var/spool/cron /var/spool/cron/crontabs; do
  [ -d "$d" ] || continue
  for f in "$d"/*; do
    [ -f "$f" ] && [ -r "$f" ] || continue
    found=1; dump "$f"
  done
done
[ "$found" -eq 0 ] && echo "none found (or not readable)"
echo
echo "-- systemd timers --"
if have systemctl && [ -d /run/systemd/system ]; then
  systemctl list-timers --all --no-pager 2>/dev/null | head -n 40
else
  echo "NOT AVAILABLE: systemd is not running on this host"
fi
exit 0
