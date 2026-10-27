#!/usr/bin/env bash
# Recent logins (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Recent logins (Linux) =="
if have last; then
  echo "-- last -n 30 --"
  last -n 30 -w 2>&1 | head -n 40
  echo
  echo "-- Failed logins (lastb) --"
  if [ -r /var/log/btmp ]; then
    echo "Failed login records: $(lastb -w 2>/dev/null | grep -c -v -E '^(btmp begins|$)')"
  else
    echo "btmp not readable or absent"
  fi
else
  echo "NOT AVAILABLE: last is not installed"
fi
echo
echo "-- Currently logged in --"
if have who; then
  w_out=$(who 2>/dev/null)
  if [ -n "$w_out" ]; then printf '%s\n' "$w_out"; else echo "no users logged in"; fi
else
  echo "NOT AVAILABLE: who is not installed"
fi
exit 0
