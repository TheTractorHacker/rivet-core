#!/usr/bin/env bash
# Logged-in users and sessions (read-only).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Logged-in users and sessions (Linux) =="
any=0
if have loginctl && [ -d /run/systemd/system ]; then
  any=1; echo "-- loginctl list-sessions --"; loginctl list-sessions --no-pager 2>&1 | head -n 30
fi
if have who; then
  any=1; echo "-- who -a --"; who -a 2>&1 | head -n 40
fi
if have w; then
  any=1; echo "-- w --"; w 2>&1 | head -n 40
fi
[ "$any" -eq 0 ] && echo "NOT AVAILABLE: loginctl, who and w are all missing"
exit 0
