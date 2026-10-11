#!/usr/bin/env bash
# Inode usage (read-only).
echo "== Inode usage (Linux) =="
if ! command -v df >/dev/null 2>&1; then echo "NOT AVAILABLE: df is not installed"; exit 0; fi
df -i -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs 2>/dev/null | head -n 60
echo
echo "-- Filesystems at 90% or more inodes --"
found=0
while read -r line; do
  found=1
  echo "WARNING: $line"
done < <(df -Pi -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs 2>/dev/null | awk 'NR>1 { u=$5; sub("%","",u); if (u ~ /^[0-9]+$/ && u+0 >= 90) print $6 " is " $5 " of inodes used (" $1 ")" }')
[ "$found" -eq 0 ] && echo "none"
exit 0
