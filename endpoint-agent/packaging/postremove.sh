#!/bin/sh
# Removal: drop the unit the agent's `install` command created. The state directory (credential, buffers) is kept
# on remove and deleted only by `purge` (deb) so a re-install keeps the device identity.
case "$1" in
  remove|purge|0)
    rm -f /etc/systemd/system/rivetit-agent.service
    if [ -d /run/systemd/system ]; then systemctl daemon-reload >/dev/null 2>&1 || true; fi
    ;;
esac
case "$1" in
  purge) rm -rf /var/lib/rivetit-agent ;;
esac
exit 0
