#!/usr/bin/env bash
# Memory, swap and pressure (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Memory and swap (Linux) =="
if have free; then free -h; elif [ -r /proc/meminfo ]; then head -n 8 /proc/meminfo; else echo "NOT AVAILABLE: free and /proc/meminfo missing"; fi
echo
echo "-- Swap devices --"
if [ -r /proc/swaps ]; then cat /proc/swaps; else echo "NOT AVAILABLE: /proc/swaps"; fi
echo
echo "-- Top 5 processes by memory --"
if have ps; then ps -eo pid,user,pmem,rss,comm --sort=-rss 2>/dev/null | head -n 6; else echo "NOT AVAILABLE: ps is not installed"; fi
echo
echo "-- Pressure stall information --"
shown=0
for f in cpu memory io; do
  if [ -r "/proc/pressure/$f" ]; then shown=1; echo "$f: $(tr '\n' ' ' <"/proc/pressure/$f")"; fi
done
[ "$shown" -eq 0 ] && echo "PSI not available on this kernel"
exit 0
