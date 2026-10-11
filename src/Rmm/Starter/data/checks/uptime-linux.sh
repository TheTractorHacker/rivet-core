#!/usr/bin/env bash
up=$(cut -d. -f1 /proc/uptime 2>/dev/null)
d=$(( ${up:-0} / 86400 ))
if [ "$d" -ge {{uptime_fail_days}} ]; then echo "Up $d days (limit {{uptime_fail_days}} days): restart soon"; exit 2; fi
if [ "$d" -ge {{uptime_warn_days}} ]; then echo "Up $d days (limit {{uptime_warn_days}} days)"; exit 1; fi
echo "Up $d days"
exit 0
