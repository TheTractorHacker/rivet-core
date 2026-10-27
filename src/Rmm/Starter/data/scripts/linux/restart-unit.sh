#!/usr/bin/env bash
# Restart a named systemd unit (disruptive). dry_run defaults to true.
dry_run="${dry_run:-true}"
wait_seconds="${wait_seconds:-30}"
unit_name="${unit_name:-}"
case "$wait_seconds" in ''|*[!0-9]*) echo "invalid wait_seconds" >&2; exit 2;; esac
if [ "$wait_seconds" -lt 5 ] || [ "$wait_seconds" -gt 300 ]; then echo "wait_seconds out of range 5..300" >&2; exit 2; fi
if ! [[ "$unit_name" =~ ^[A-Za-z0-9_][A-Za-z0-9_.@:-]{0,99}$ ]]; then
  echo "invalid unit_name" >&2; exit 2
fi
lower="${unit_name,,}"
case "$lower" in
  rivet*|*rivetit*|systemd-*|dbus*)
    echo "refused: unit '$unit_name' is protected (rivet*, systemd-*, dbus*)" >&2; exit 2;;
esac
echo "== Restart unit $unit_name (Linux) =="
if ! command -v systemctl >/dev/null 2>&1 || [ ! -d /run/systemd/system ]; then
  echo "NOT AVAILABLE: systemd is not running on this host"; exit 0
fi
if ! systemctl cat -- "$unit_name" >/dev/null 2>&1; then
  echo "unit '$unit_name' does not exist" >&2; exit 3
fi
before=$(systemctl is-active -- "$unit_name" 2>/dev/null)
echo "State before: ${before:-unknown}"
if [ "$dry_run" = "true" ]; then
  echo "[DRY-RUN] would restart $unit_name and wait up to ${wait_seconds}s for it to become active"
  exit 0
fi
if command -v timeout >/dev/null 2>&1; then
  timeout "$((wait_seconds + 30))" systemctl restart -- "$unit_name"; rc=$?
else
  systemctl restart -- "$unit_name"; rc=$?
fi
if [ "$rc" -ne 0 ]; then
  echo "[SKIP] systemctl restart returned $rc"
fi
waited=0
after=$(systemctl is-active -- "$unit_name" 2>/dev/null)
while [ "$after" != "active" ] && [ "$waited" -lt "$wait_seconds" ]; do
  sleep 1; waited=$((waited + 1))
  after=$(systemctl is-active -- "$unit_name" 2>/dev/null)
done
echo "State after: ${after:-unknown}"
if [ "$after" = "active" ]; then
  echo "[DONE] restarted $unit_name (active)"
  exit 0
fi
echo "unit did not become active within ${wait_seconds}s" >&2
exit 3
