#!/usr/bin/env bash
# Recent error-level log entries (read-only).
hours="${hours:-24}"
limit="${limit:-200}"
case "$hours" in ''|*[!0-9]*) echo "invalid hours" >&2; exit 2;; esac
case "$limit" in ''|*[!0-9]*) echo "invalid limit" >&2; exit 2;; esac
if [ "$hours" -lt 1 ] || [ "$hours" -gt 168 ]; then echo "hours out of range 1..168" >&2; exit 2; fi
if [ "$limit" -lt 10 ] || [ "$limit" -gt 1000 ]; then echo "limit out of range 10..1000" >&2; exit 2; fi
echo "== Journal errors, last ${hours}h (Linux) =="
if command -v journalctl >/dev/null 2>&1 && journalctl -n 1 --no-pager >/dev/null 2>&1; then
  tmp=$(mktemp 2>/dev/null) || { echo "NOT AVAILABLE: cannot create temp file"; exit 0; }
  trap 'rm -f "$tmp"' EXIT
  journalctl -p err -S "-${hours} hours" --no-pager -o short-iso -q >"$tmp" 2>/dev/null
  total=$(grep -c . "$tmp")
  echo "Error entries: $total"
  if [ "$total" -gt 0 ]; then
    echo "-- Count by unit/identifier --"
    awk '{ id=$3; sub(/\[[0-9]+\]:?$/,"",id); sub(/:$/,"",id); c[id]++ } END { for (k in c) printf "%7d  %s\n", c[k], k }' "$tmp" | sort -rn | head -n 20
    echo "-- Most recent $limit lines --"
    tail -n "$limit" "$tmp" | cut -c1-300
  fi
else
  found=0
  for f in /var/log/syslog /var/log/messages; do
    if [ -r "$f" ]; then
      found=1
      echo "journald unavailable; scanning $f for error lines (not time-filtered)"
      grep -i -E 'error|fail|crit' "$f" 2>/dev/null | tail -n "$limit" | cut -c1-300
      break
    fi
  done
  [ "$found" -eq 0 ] && echo "NOT AVAILABLE: no journald, /var/log/syslog or /var/log/messages"
fi
exit 0
