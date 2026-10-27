#!/usr/bin/env bash
# Installed packages (read-only).
limit="${limit:-500}"
case "$limit" in ''|*[!0-9]*) echo "invalid limit" >&2; exit 2;; esac
if [ "$limit" -lt 50 ] || [ "$limit" -gt 5000 ]; then echo "limit out of range 50..5000" >&2; exit 2; fi
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Installed packages (Linux) =="
if have dpkg-query; then
  list=$(dpkg-query -W -f='${Package} ${Version}\n' 2>/dev/null | sort)
elif have rpm; then
  list=$(rpm -qa --qf '%{NAME} %{VERSION}-%{RELEASE}\n' 2>/dev/null | sort)
else
  echo "NOT AVAILABLE: neither dpkg-query nor rpm is installed"; exit 0
fi
total=$(printf '%s\n' "$list" | grep -c .)
echo "Total packages: $total"
printf '%s\n' "$list" | head -n "$limit"
[ "$total" -gt "$limit" ] && echo "(truncated at $limit)"
exit 0
