#!/bin/sh
# Package upgrade: restart the agent if it is installed as a service, so the new binary runs.
# A first install does nothing (the machine is not enrolled yet).
set -e
if [ -d /run/systemd/system ] && [ -f /etc/systemd/system/rivetit-agent.service ]; then
  systemctl daemon-reload >/dev/null 2>&1 || true
  if systemctl is-enabled --quiet rivetit-agent.service 2>/dev/null; then
    systemctl restart rivetit-agent.service >/dev/null 2>&1 || true
  fi
fi
exit 0
