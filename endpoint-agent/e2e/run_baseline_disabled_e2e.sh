#!/usr/bin/env bash
# T6b proof on the UNMODIFIED baseline agent (agent-v0.1.0-beta.1 tree, for example from RivetIT origin/beta):
# an agent that predates the module_disabled branch must already back off on `503 module_disabled` + Retry-After,
# keep its credential and its in-flight check-in, and not retry in a tight loop.
#   BASELINE_SRC=/path/to/old/endpoint-agent ./e2e/run_baseline_disabled_e2e.sh
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; ROOT="$(cd "$HERE/.." && pwd)"
SRC="${BASELINE_SRC:?set BASELINE_SRC to an old endpoint-agent tree}"
WORK="${RIVETIT_E2E_WORK:-$ROOT/e2e/work-baseline}"
GOIMG="${GO_IMAGE:-golang:$(sed -n 's/^go \([0-9.]*\).*/\1/p' "$ROOT/go.mod" | head -1)}"
rm -rf "$WORK"; mkdir -p "$WORK/gocache" "$WORK/gomod" "$WORK/out"
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e CGO_ENABLED=0 -e GOCACHE=/w/gocache -e GOMODCACHE=/w/gomod \
  -v "$SRC":/old:ro -v "$ROOT":/new:ro -v "$WORK":/w "$GOIMG" sh -ec '
  mkdir -p /w/oldsrc && cp -r /old/. /w/oldsrc/ && cd /w/oldsrc && go build -trimpath -ldflags "-X main.version=0.1.0-beta.1" -o /w/old-agent .
  cd /new && go build -trimpath -o /w/fakeserver ./e2e/fakeserver'
docker run --rm -u "$(id -u):$(id -g)" -v "$WORK":/w "$GOIMG" bash -c '
  set -u; OUT=/w/out
  /w/fakeserver -dir $OUT -interval 3 -collect 3 -ctl-listen 127.0.0.1:0 >$OUT/fs.out 2>&1 &
  for _ in $(seq 50); do [ -s $OUT/url ] && [ -s $OUT/ctl ] && break; sleep 0.1; done
  ctl() { a=$(cat $OUT/ctl); exec 3<>/dev/tcp/${a%:*}/${a#*:}; printf "GET /_ctl?%s HTTP/1.0\r\n\r\n" "$1" >&3; cat <&3 >/dev/null; exec 3>&-; }
  printf E2E-ENROLL-TOKEN >$OUT/tok
  /w/old-agent enroll --state-dir $OUT/state --server $(cat $OUT/url) --ca $OUT/ca.pem --token-file $OUT/tok >/dev/null
  /w/old-agent run --state-dir $OUT/state --min-interval 3 >$OUT/agent.log 2>&1 &
  AP=$!
  sleep 8
  ctl "mode=disabled&retry_after=3600"
  sleep 25
  kill $AP; wait $AP 2>/dev/null
  hits=$(grep -c "GATE disabled checkin" $OUT/events.log || true)
  echo "disabled answers seen by the OLD agent in 25 s with Retry-After 3600: $hits"
  [ "$hits" = 1 ] && echo "ok: old agent backs off (one request, then silent)" || { echo "FAIL: $hits requests"; exit 1; }
  grep -q "will retry same seq" $OUT/agent.log && echo "ok: old agent kept the in-flight check-in (retry same seq)" || { echo "FAIL: in-flight not kept"; exit 1; }
  [ -s $OUT/state/device.token ] && echo "ok: credential kept" || { echo "FAIL: credential wiped"; exit 1; }
  echo BASELINE PASS'
