#!/usr/bin/env bash
# Clean the package manager cache. dry_run defaults to true.
dry_run="${dry_run:-true}"
have() { command -v "$1" >/dev/null 2>&1; }
cache_bytes() { # dir
  [ -d "$1" ] || { echo 0; return; }
  du -sb -- "$1" 2>/dev/null | cut -f1
}
echo "== Clean package cache (Linux) =="
pm=""; dir=""
if have apt-get; then pm=apt; dir=/var/cache/apt/archives
elif have dnf; then pm=dnf; dir=/var/cache/dnf
elif have yum; then pm=yum; dir=/var/cache/yum
elif have zypper; then pm=zypper; dir=/var/cache/zypp
fi
if [ -z "$pm" ]; then echo "NOT AVAILABLE: no supported package manager (apt, dnf, yum, zypper) found"; exit 0; fi
before=$(cache_bytes "$dir")
echo "Package manager: $pm; cache $dir: ${before:-0} bytes"
if [ "$dry_run" = "true" ]; then
  echo "[DRY-RUN] would clean the $pm package cache (currently ${before:-0} bytes)"
  exit 0
fi
case "$pm" in
  apt) apt-get clean; rc=$?;;
  dnf) dnf -q clean packages; rc=$?;;
  yum) yum -q clean packages; rc=$?;;
  zypper) zypper --non-interactive clean; rc=$?;;
esac
after=$(cache_bytes "$dir")
if [ "$rc" -eq 0 ]; then
  echo "[DONE] $pm cache cleaned: ${before:-0} -> ${after:-0} bytes (freed $(( ${before:-0} - ${after:-0} )))"
  exit 0
fi
echo "[SKIP] $pm clean returned $rc"
exit 3
