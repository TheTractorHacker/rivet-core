#!/usr/bin/env bash
# Clock synchronization status (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Time synchronization (Linux) =="
sync=unknown
any=0
if have timedatectl; then
  td=$(timedatectl 2>&1)
  if printf '%s\n' "$td" | grep -q -E 'System clock synchronized|NTP synchronized'; then
    any=1; echo "-- timedatectl --"; printf '%s\n' "$td" | head -n 12
    if printf '%s\n' "$td" | grep -E 'System clock synchronized|NTP synchronized' | grep -q 'yes'; then sync=yes; else sync=no; fi
  elif [ -n "$td" ]; then
    any=1; echo "-- timedatectl --"; printf '%s\n' "$td" | head -n 6
  fi
fi
if have chronyc; then
  any=1; echo "-- chrony --"
  chronyc tracking 2>&1 | head -n 12
  chronyc sources 2>&1 | head -n 10
  if chronyc tracking 2>/dev/null | grep -q 'Leap status *: *Normal'; then sync=yes; fi
fi
if have ntpq; then
  any=1; echo "-- ntpq -p --"
  ntpq -p 2>&1 | head -n 12
  ntpq -p 2>/dev/null | grep -q '^\*' && sync=yes
fi
if have systemctl && [ -d /run/systemd/system ]; then
  st=$(systemctl is-active systemd-timesyncd 2>/dev/null)
  if [ "$st" = "active" ]; then any=1; echo "-- systemd-timesyncd --"; systemctl status systemd-timesyncd --no-pager 2>&1 | head -n 10; fi
fi
[ "$any" -eq 0 ] && echo "NOT AVAILABLE: no time synchronization tool found"
echo "SYNCHRONIZED: $sync"
exit 0
