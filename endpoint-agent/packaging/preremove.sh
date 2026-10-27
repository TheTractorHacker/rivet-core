#!/bin/sh
# Removal (deb: remove|purge, rpm: $1 = 0): stop and disable the service. Upgrades ($1 = upgrade / 1+) keep it running.
case "$1" in
  remove|purge|0)
    if [ -d /run/systemd/system ] && [ -f /etc/systemd/system/rivetit-agent.service ]; then
      systemctl stop rivetit-agent.service >/dev/null 2>&1 || true
      systemctl disable rivetit-agent.service >/dev/null 2>&1 || true
    fi
    ;;
esac
exit 0
