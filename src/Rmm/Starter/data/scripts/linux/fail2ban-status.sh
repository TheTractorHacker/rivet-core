#!/usr/bin/env bash
# fail2ban jail summary (read-only).
echo "== Fail2ban status (Linux) =="
if ! command -v fail2ban-client >/dev/null 2>&1; then echo "NOT AVAILABLE: fail2ban is not installed"; exit 0; fi
out=$(fail2ban-client status 2>&1)
printf '%s\n' "$out" | head -n 20
jails=$(printf '%s\n' "$out" | sed -n 's/.*Jail list:[[:space:]]*//p' | tr -d ',')
for j in $jails; do
  [[ "$j" =~ ^[A-Za-z0-9_.-]{1,64}$ ]] || continue
  echo "-- jail: $j --"
  fail2ban-client status "$j" 2>&1 | head -n 12
done
exit 0
