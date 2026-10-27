#!/usr/bin/env bash
# Top processes by CPU and memory (read-only).
top_n="${top_n:-10}"
case "$top_n" in ''|*[!0-9]*) echo "invalid top_n" >&2; exit 2;; esac
if [ "$top_n" -lt 1 ] || [ "$top_n" -gt 50 ]; then echo "top_n out of range 1..50" >&2; exit 2; fi
echo "== Top processes (Linux) =="
if ! command -v ps >/dev/null 2>&1; then echo "NOT AVAILABLE: ps is not installed"; exit 0; fi
n=$((top_n + 1))
echo "-- By CPU (top $top_n) --"
ps -eo pid,user,pcpu,pmem,etime,comm --sort=-pcpu 2>/dev/null | head -n "$n"
echo
echo "-- By memory (top $top_n) --"
ps -eo pid,user,pcpu,pmem,rss,comm --sort=-rss 2>/dev/null | head -n "$n"
exit 0
