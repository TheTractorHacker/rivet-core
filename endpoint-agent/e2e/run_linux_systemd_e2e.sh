#!/usr/bin/env bash
# OPTIONAL real-systemd pass: boots jrei/systemd-ubuntu:24.04 (needs `docker run --privileged`, cgroup v2) and runs the
# unit that `install-linux.sh` creates under a REAL systemd: enable/start, Restart=always after a kill, self-update exit 75
# restart, a signed shell job as root, journal output, uninstall. Reuses the binaries built by run_linux_container_e2e.sh
# (run that first; it leaves them in e2e/work-linux/bin).
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
WORK="${RIVETIT_E2E_WORK:-$ROOT/e2e/work-linux}"
IMG="${SYSTEMD_IMAGE:-jrei/systemd-ubuntu:24.04}"
NAME=rivetit-e2e-systemd
[ -x "$WORK/bin/rivetit-agent-1.0.0" ] || { echo "run e2e/run_linux_container_e2e.sh first (needs $WORK/bin)"; exit 1; }
cleanup() { docker rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT; cleanup
docker run -d --name "$NAME" --privileged --cgroupns=host -v /sys/fs/cgroup:/sys/fs/cgroup:rw --tmpfs /tmp --tmpfs /run --tmpfs /run/lock "$IMG" >/dev/null
for _ in $(seq 1 50); do docker exec "$NAME" systemctl is-system-running 2>/dev/null | grep -Eq 'running|degraded' && break; sleep 0.5; done
docker exec "$NAME" mkdir -p /w/bin /w/out
docker cp "$WORK/bin/." "$NAME:/w/bin/"; docker cp "$ROOT/scripts/install-linux.sh" "$NAME:/w/install-linux.sh"
docker cp "$HERE/linux_systemd_scenario.sh" "$NAME:/w/scenario.sh"
docker exec "$NAME" bash /w/scenario.sh 2>&1 | tee "$WORK/systemd.out"
grep -q '^SYSTEMD PASS' "$WORK/systemd.out"
