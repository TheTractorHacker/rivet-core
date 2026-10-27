#!/usr/bin/env bash
# Reboot / service restart needed check (read-only, always exit 0).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Reboot and restart needed check (Linux) =="
reboot=unknown
if [ -f /var/run/reboot-required ]; then
  reboot=yes
  echo "Found /var/run/reboot-required"
  [ -r /var/run/reboot-required.pkgs ] && { echo "Packages requesting reboot:"; sort -u /var/run/reboot-required.pkgs | head -n 30; }
elif have needs-restarting; then
  reboot=no
  nrout=$(needs-restarting -r 2>&1) || reboot=yes
  printf '%s\n' "$nrout" | head -n 15
  echo "Services needing restart:"
  needs-restarting -s 2>/dev/null | head -n 30
elif have zypper && [ -d /etc/zypp ]; then
  if zypper --non-interactive needs-rebooting >/dev/null 2>&1; then reboot=no; else reboot=yes; fi
  echo "Processes using deleted files (zypper ps -s):"
  zypper --non-interactive ps -s 2>/dev/null | head -n 30
elif [ -d /var/lib/dpkg ] || [ -d /var/lib/apt ]; then
  reboot=no
else
  echo "NOT AVAILABLE: no reboot-detection method for this distribution"
fi
if have needrestart; then
  echo "-- needrestart (batch, list only) --"
  needrestart -b -r l 2>/dev/null | head -n 40
fi
echo "REBOOT REQUIRED: $reboot"
exit 0
