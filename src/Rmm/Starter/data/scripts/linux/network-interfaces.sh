#!/usr/bin/env bash
# Interfaces, routes and DNS (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Network interfaces (Linux) =="
if have ip; then
  echo "-- Addresses --"; ip -br addr 2>&1 | head -n 40
  echo "-- Routes --"; ip route 2>&1 | head -n 40
  echo "Default gateway: $(ip route show default 2>/dev/null | awk '{print $3 " via " $5}' | head -n 3 | tr '\n' ';')"
elif have ifconfig; then
  echo "-- ifconfig --"; ifconfig 2>&1 | head -n 60
  if have route; then echo "-- Routes --"; route -n 2>&1 | head -n 30; fi
else
  echo "NOT AVAILABLE: neither ip nor ifconfig is installed"
fi
echo "-- DNS servers (/etc/resolv.conf) --"
if [ -r /etc/resolv.conf ]; then grep -E '^(nameserver|search|domain)' /etc/resolv.conf; else echo "resolv.conf not readable"; fi
exit 0
