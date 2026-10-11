#!/usr/bin/env bash
# Running systemd services (read-only).
echo "== Running services (Linux) =="
if ! command -v systemctl >/dev/null 2>&1 || [ ! -d /run/systemd/system ]; then
  echo "NOT AVAILABLE: systemd is not running on this host"; exit 0
fi
list=$(systemctl list-units --type=service --state=running --no-legend --plain --no-pager 2>/dev/null | cut -c1-160)
echo "Running services: $(printf '%s' "$list" | grep -c .)"
printf '%s\n' "$list" | head -n 100
echo "Enabled unit files: $(systemctl list-unit-files --state=enabled --no-legend --no-pager 2>/dev/null | grep -c .)"
exit 0
