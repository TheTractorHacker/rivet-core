#!/usr/bin/env bash
# Firewall state summary (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Firewall status (Linux) =="
any=0
if have ufw; then
  any=1; echo "-- ufw --"; ufw status verbose 2>&1 | head -n 40
fi
if have firewall-cmd; then
  any=1; echo "-- firewalld --"
  state=$(firewall-cmd --state 2>&1)
  echo "state: $state"
  [ "$state" = "running" ] && firewall-cmd --list-all 2>&1 | head -n 40
fi
if have nft; then
  any=1; echo "-- nftables --"
  rs=$(nft list ruleset 2>&1)
  echo "tables: $(printf '%s\n' "$rs" | grep -c '^table ')  chains: $(printf '%s\n' "$rs" | grep -c 'chain ')  rule lines: $(printf '%s\n' "$rs" | grep -c -E '^\s+(ip|tcp|udp|iif|oif|ct|meta|counter|accept|drop|reject|jump)')"
  printf '%s\n' "$rs" | head -n 60
fi
if have iptables; then
  any=1; echo "-- iptables --"; iptables -S 2>&1 | head -n 60
fi
[ "$any" -eq 0 ] && echo "NOT AVAILABLE: no firewall tool (ufw, firewall-cmd, nft, iptables) found"
exit 0
