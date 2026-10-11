#!/usr/bin/env bash
# Ping, trace and resolve a validated target (read-only).
target="${target:-}"
count="${count:-4}"
case "$count" in ''|*[!0-9]*) echo "invalid count" >&2; exit 2;; esac
if [ "$count" -lt 1 ] || [ "$count" -gt 10 ]; then echo "count out of range 1..10" >&2; exit 2; fi
if ! { [[ "$target" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$ ]] || [[ "$target" =~ ^[0-9A-Fa-f:.]{2,45}$ ]]; }; then
  echo "invalid target" >&2; exit 2
fi
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Network diagnostics: $target (Linux) =="
echo "-- Name resolution --"
if have getent; then getent hosts "$target" 2>&1 | head -n 6; [ "${PIPESTATUS[0]}" -ne 0 ] && echo "getent: no result"; else echo "NOT AVAILABLE: getent"; fi
if have dig; then echo "dig +short:"; dig +short +time=3 +tries=1 "$target" 2>&1 | head -n 6; fi
echo "-- Ping --"
if have ping; then
  ping -c "$count" -W 2 "$target" 2>&1 | tail -n 6
else
  echo "NOT AVAILABLE: ping is not installed"
fi
echo "-- Route trace --"
if have traceroute; then
  traceroute -n -m 15 -w 2 "$target" 2>&1 | head -n 20
elif have tracepath; then
  tracepath -n -m 15 "$target" 2>&1 | head -n 20
else
  echo "NOT AVAILABLE: neither traceroute nor tracepath is installed"
fi
exit 0
