#!/usr/bin/env bash
# Container engines overview (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Containers (Linux) =="
any=0
for eng in docker podman; do
  have "$eng" || continue
  any=1
  echo "-- $eng ps -a --"
  if command -v timeout >/dev/null 2>&1; then
    timeout 30 "$eng" ps -a --format 'table {{.Names}}\t{{.Image}}\t{{.Status}}' 2>&1 | head -n 60
    echo "-- $eng system df --"
    timeout 30 "$eng" system df 2>&1 | head -n 20
  else
    "$eng" ps -a 2>&1 | head -n 60
    echo "-- $eng system df --"
    "$eng" system df 2>&1 | head -n 20
  fi
done
[ "$any" -eq 0 ] && echo "NOT AVAILABLE: neither docker nor podman is installed"
exit 0
