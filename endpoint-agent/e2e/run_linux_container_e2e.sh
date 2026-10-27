#!/usr/bin/env bash
# Linux end-to-end: builds the agent (v1.0.0, an update build v1.0.1, an arm64 build, the dev stamper) and the
# Go fake server in a golang container, then runs linux_container_scenario.sh as ROOT in ubuntu:24.04 against it:
# install script -> unit file -> enroll -> check-in/inventory/metrics -> shell job (+ unsupported_platform) ->
# self-update swap and restart -> offline buffering -> module_disabled back-off -> uninstall.
# Needs only Docker. systemd inside the container is not required: `systemctl` is a recording shim and the
# agent is supervised by a loop that executes the unit's own ExecStart line (restart on exit, like Restart=always).
# A second, optional pass (SYSTEMD=1) boots jrei/systemd-ubuntu:24.04 for a REAL systemd run of the same unit.
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
WORK="${RIVETIT_E2E_WORK:-$ROOT/e2e/work-linux}"
GOVER="$(sed -n 's/^go \([0-9.]*\).*/\1/p' "$ROOT/go.mod" | head -1)"
GOIMG="${GO_IMAGE:-golang:$GOVER}"
UBUNTU="${UBUNTU_IMAGE:-ubuntu:24.04}"
rm -rf "$WORK"; mkdir -p "$WORK/bin" "$WORK/gocache" "$WORK/gomod"

echo "== building in $GOIMG"
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e CGO_ENABLED=0 -e GOCACHE=/w/gocache -e GOMODCACHE=/w/gomod \
  -v "$ROOT":/src -v "$WORK":/w -w /src "$GOIMG" sh -ec '
  b() { v=$1; shift; go build -trimpath -ldflags "-s -w -X main.version=$v -X main.commit=e2e" "$@"; }
  b 1.0.0 -o /w/bin/rivetit-agent-1.0.0 .
  b 1.0.1 -o /w/bin/rivetit-agent-1.0.1 .
  GOARCH=arm64 b 1.0.0 -o /w/bin/rivetit-agent-arm64 .
  b 1.0.0 -tags devtools -o /w/bin/rivetit-agent-dev .
  go build -trimpath -o /w/bin/fakeserver ./e2e/fakeserver
'
cp "$ROOT/scripts/install-linux.sh" "$WORK/install-linux.sh"

run_ubuntu() {
  docker run --rm --name rivetit-e2e-linux -v "$WORK":/w -v "$ROOT":/src:ro -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" "$UBUNTU" bash /src/e2e/linux_container_scenario.sh
}
echo "== running the scenario as root in $UBUNTU"
run_ubuntu 2>&1 | tee "$WORK/scenario.out"
grep -q '^SCENARIO PASS' "$WORK/scenario.out"
