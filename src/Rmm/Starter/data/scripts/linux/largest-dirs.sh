#!/usr/bin/env bash
# Largest first-level directories under root_dir (read-only).
root_dir="${root_dir:-/}"
top_n="${top_n:-15}"
[ -n "$root_dir" ] || root_dir="/"
case "$top_n" in ''|*[!0-9]*) echo "invalid top_n" >&2; exit 2;; esac
if [ "$top_n" -lt 1 ] || [ "$top_n" -gt 50 ]; then echo "top_n out of range 1..50" >&2; exit 2; fi
if ! [[ "$root_dir" =~ ^/[A-Za-z0-9._/@+=:-]{0,249}$ ]] || [[ "$root_dir" == *..* ]]; then
  echo "invalid root_dir (absolute path, no '..')" >&2; exit 2
fi
echo "== Largest directories under $root_dir (Linux) =="
if ! command -v du >/dev/null 2>&1; then echo "NOT AVAILABLE: du is not installed"; exit 0; fi
if ! [ -d "$root_dir" ]; then echo "NOT AVAILABLE: $root_dir is not a directory"; exit 0; fi
if command -v timeout >/dev/null 2>&1; then runner=(timeout 120); else runner=(); fi
tmp=$(mktemp 2>/dev/null) || { echo "NOT AVAILABLE: cannot create temp file"; exit 0; }
trap 'rm -f "$tmp"' EXIT
"${runner[@]}" du -x -k --max-depth=1 -- "$root_dir" >"$tmp" 2>/dev/null
rc=$?
if [ "$rc" -eq 124 ]; then echo "NOTE: scan timed out after 120 seconds; results are partial"; fi
sort -rn "$tmp" | head -n "$((top_n + 1))" | awk '{ s=$1; $1=""; sub(/^ /,""); if (s>=1048576) printf "%8.1f GB  %s\n", s/1048576, $0; else printf "%8.1f MB  %s\n", s/1024, $0 }'
exit 0
