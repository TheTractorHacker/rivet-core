#!/usr/bin/env bash
# SMART health summary (read-only).
echo "== Disk SMART health (Linux) =="
if ! command -v smartctl >/dev/null 2>&1; then echo "NOT AVAILABLE: smartmontools (smartctl) is not installed"; exit 0; fi
scan=$(smartctl --scan 2>/dev/null)
if [ -z "$scan" ]; then echo "No SMART-capable devices found (virtual machine or container?)"; exit 0; fi
printf '%s\n' "$scan" | head -n 30
echo
n=0
while read -r dev rest; do
  [ -n "$dev" ] || continue
  case "$dev" in /dev/loop*|/dev/ram*|/dev/zram*|/dev/sr*) continue;; esac
  [[ "$dev" =~ ^/dev/[A-Za-z0-9/_-]{1,40}$ ]] || continue
  n=$((n + 1)); [ "$n" -gt 24 ] && { echo "(truncated)"; break; }
  dtype=""
  if [[ "$rest" =~ -d[[:space:]]+([a-z0-9,]+) ]]; then dtype="${BASH_REMATCH[1]}"; fi
  echo "-- $dev ${dtype:+(-d $dtype)} --"
  if [ -n "$dtype" ]; then smartctl -H -d "$dtype" "$dev" 2>&1 | grep -E 'overall-health|SMART Health Status|result|Unable|Permission|not supported' | head -n 4
  else smartctl -H "$dev" 2>&1 | grep -E 'overall-health|SMART Health Status|result|Unable|Permission|not supported' | head -n 4; fi
done <<<"$scan"
exit 0
