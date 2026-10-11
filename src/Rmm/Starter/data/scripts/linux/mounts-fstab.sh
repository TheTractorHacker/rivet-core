#!/usr/bin/env bash
# Mounts, read-only mounts, fstab, nearly-full filesystems (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Mounts and fstab (Linux) =="
echo "-- Mounts --"
if have findmnt; then
  findmnt -lo TARGET,SOURCE,FSTYPE,OPTIONS -t ext2,ext3,ext4,xfs,btrfs,vfat,nfs,nfs4,cifs,zfs,fuseblk,ntfs,iso9660 2>/dev/null | cut -c1-200 | head -n 60
elif [ -r /proc/mounts ]; then
  grep -E ' (ext[234]|xfs|btrfs|vfat|nfs4?|cifs|zfs|fuseblk|ntfs) ' /proc/mounts | head -n 60
else
  echo "NOT AVAILABLE: findmnt and /proc/mounts missing"
fi
echo
echo "-- Read-only mounts --"
if [ -r /proc/mounts ]; then
  ro=$(awk '$3 !~ /^(proc|sysfs|cgroup2?|squashfs|iso9660|devtmpfs|devpts|tmpfs|overlay|securityfs|debugfs|tracefs|configfs|fusectl|bpf|pstore|mqueue|hugetlbfs|autofs|binfmt_misc|efivarfs|rpc_pipefs|nsfs)$/ && $4 ~ /(^|,)ro(,|$)/ {print "READ-ONLY: " $2 " (" $3 ")"}' /proc/mounts)
  if [ -n "$ro" ]; then printf '%s\n' "$ro" | head -n 30; else echo "none"; fi
fi
echo
echo "-- /etc/fstab (non-comment) --"
if [ -r /etc/fstab ]; then
  fs=$(grep -v -E '^[[:space:]]*(#|$)' /etc/fstab)
  if [ -n "$fs" ]; then printf '%s\n' "$fs" | head -n 40; else echo "empty"; fi
else
  echo "no /etc/fstab"
fi
echo
echo "-- Filesystems at 90% or more --"
if have df; then
  full=$(df -P -x tmpfs -x devtmpfs -x overlay -x squashfs -x efivarfs 2>/dev/null | awk 'NR>1 { u=$5; sub("%","",u); if (u+0 >= 90) print "WARNING: " $6 " is " $5 " full" }')
  if [ -n "$full" ]; then printf '%s\n' "$full"; else echo "none"; fi
else
  echo "NOT AVAILABLE: df"
fi
exit 0
