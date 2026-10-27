#!/usr/bin/env bash
# Listening sockets (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Listening ports (Linux) =="
if have ss; then
  ss -tulpn 2>/dev/null | head -n 200
elif have netstat; then
  netstat -tulpn 2>/dev/null | head -n 200
else
  echo "NOT AVAILABLE: neither ss nor netstat is installed"
fi
exit 0
