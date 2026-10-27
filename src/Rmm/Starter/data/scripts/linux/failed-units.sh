#!/usr/bin/env bash
# Failed systemd units (read-only; exit 0 even when units failed).
echo "== Failed systemd units (Linux) =="
if ! command -v systemctl >/dev/null 2>&1 || [ ! -d /run/systemd/system ]; then
  echo "NOT AVAILABLE: systemd is not running on this host"; exit 0
fi
list=$(systemctl list-units --failed --no-legend --plain --no-pager 2>/dev/null)
count=$(printf '%s' "$list" | grep -c .)
echo "FAILED UNITS: $count"
[ "$count" -gt 0 ] && printf '%s\n' "$list" | head -n 50
if command -v systemd-analyze >/dev/null 2>&1; then
  echo
  echo "-- Slowest units at boot (top 10) --"
  systemd-analyze blame --no-pager 2>/dev/null | head -n 10
fi
exit 0
