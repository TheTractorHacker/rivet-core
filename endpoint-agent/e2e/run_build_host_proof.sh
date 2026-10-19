#!/usr/bin/env bash
# Manual / optional proof of endpoint-agent/scripts/build-host.sh: runs the REAL script against the mock server
# (e2e/mock_build_host_server.py) with the REAL Docker build of the committed HEAD. Needs Docker, git, python3;
# takes several minutes (first run downloads images and builds the MSI image). Not part of CI.
#   PROOF_DIR=/path  keep artifacts there (default: a temp dir, removed on success)   PROOF_VERSION=0.0.1-hosttest
set -Eeuo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(git -C "$HERE" rev-parse --show-toplevel)
SCRIPT="$REPO/endpoint-agent/scripts/build-host.sh"
VERSION=${PROOF_VERSION:-0.0.1-hosttest}
TOKEN="proof_token_$(head -c12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
ROOT=${PROOF_DIR:-$(mktemp -d /tmp/bh-proof.XXXXXX)}; mkdir -p "$ROOT"
PASS=0 FAIL=0 MOCK_PID="" BG_PID=""
ok() { PASS=$((PASS+1)); printf '  PASS %s\n' "$1"; }
bad() { FAIL=$((FAIL+1)); printf '  FAIL %s\n' "$1"; }
check() { local d=$1; shift; if "$@"; then ok "$d"; else bad "$d"; fi; }
cleanup() { [ -n "$MOCK_PID" ] && kill "$MOCK_PID" 2>/dev/null || true; [ -n "$BG_PID" ] && kill "$BG_PID" 2>/dev/null || true
  docker ps -aq --filter name=rbh- | xargs -r docker rm -f >/dev/null 2>&1 || true; }
trap cleanup EXIT

freeport() { python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1])'; }
start_mock() { # name args... -> sets PORT, MOCK_PID
  PORT=$(freeport)
  python3 "$HERE/mock_build_host_server.py" --port "$PORT" --token "$TOKEN" "$@" >"$ROOT/mock-$$.log" 2>&1 & MOCK_PID=$!
  for _ in $(seq 50); do curl -s "http://127.0.0.1:$PORT/_state" >/dev/null 2>&1 && return 0; sleep 0.1; done; echo "mock did not start"; exit 1
}
stop_mock() { kill "$MOCK_PID" 2>/dev/null || true; wait "$MOCK_PID" 2>/dev/null || true; MOCK_PID=""; }
state() { curl -s "http://127.0.0.1:$PORT/_state"; }
sj() { state | python3 -I -c 'import json,sys; d=json.load(sys.stdin)
for k in sys.argv[1].split("."):
    d=d[k] if isinstance(d,dict) else d[int(k)]
print(d)' "$1"; }
write_conf() { # file refs
  cat >"$1" <<CONF
SERVER_URL=http://127.0.0.1:$PORT
TOKEN=$TOKEN
CORE_REPO=$ROOT/checkout
ALLOWED_REFS="$2"
WORKDIR=$ROOT/work
BUILD_CPUS=4
BUILD_MEMORY=8g
CONF
  chmod 600 "$1"
}
run_host() { BUILD_HOST_ALLOW_HTTP=1 BUILD_HOST_RETRY_DELAY=1 bash "$SCRIPT" --config "$ROOT/host.conf" "$@"; }
leftovers() { [ -z "$(ls -A "$ROOT/work/tmp" 2>/dev/null)" ] && [ -z "$(docker ps -aq --filter name=rbh-)" ] \
  && [ "$(git -C "$ROOT/work/source.git" worktree list --porcelain 2>/dev/null | grep -c '^worktree' || true)" -le 1 ]; }

echo "== setup in $ROOT"
SHA=$(git -C "$REPO" rev-parse HEAD); SHORT=${SHA:0:7}
git clone -q --bare "$REPO" "$ROOT/origin.git"
git -C "$ROOT/origin.git" update-ref refs/heads/main "$SHA"
git -C "$ROOT/origin.git" update-ref refs/heads/evil "$SHA"
git clone -q "$ROOT/origin.git" "$ROOT/checkout"
echo "source commit $SHA, version $VERSION"

echo "== A. happy path (with two injected 503s on upload)"
start_mock --job-id job-A --version "$VERSION" --ref main --msi --out-dir "$ROOT/uploaded" --fail-uploads 2
write_conf "$ROOT/host.conf" "main v* agent-v*"
T0=$SECONDS
if run_host --once >"$ROOT/runA.out" 2>&1; then ok "build-host exited 0 ($((SECONDS-T0)) s)"; else bad "build-host exited non-zero"; tail -20 "$ROOT/runA.out"; fi
check "job built on the server" test "$(sj state)" = built
check "no server-side validation errors" test "$(sj errors)" = "[]"
for f in rivetit-agent-windows-amd64.exe rivetit-agent-windows-arm64.exe rivetit-agent-windows-amd64.msi SHA256SUMS; do
  check "uploaded $f" test -s "$ROOT/uploaded/$f"; done
CH=$(sj uploaded.rivetit-agent-windows-amd64.exe.chunks)
check "exe was uploaded in several chunks ($CH)" test "$CH" -gt 1
check "upload retried after the injected 503s" test "$(sj upload_calls)" -gt 2
check "SHA256SUMS hashes match the reassembled files" bash -c "cd '$ROOT/uploaded' && sha256sum -c SHA256SUMS >/dev/null"
check "manifest commit is the source commit" test "$(sj manifest.commit)" = "$SHA"
check "token is not in the script output" bash -c "! grep -q '$TOKEN' '$ROOT/runA.out'"
check "no leftover temp dirs, containers or worktrees" leftovers
# the same commit built directly
git clone -q "$ROOT/origin.git" "$ROOT/direct"; git -C "$ROOT/direct" checkout -q "$SHA"; mkdir -p "$ROOT/gomod"
GOIMG="golang:$(awk '/^go /{print $2; exit}' "$ROOT/direct/endpoint-agent/go.mod")"
docker run --rm -u "$(id -u):$(id -g)" -e HOME=/tmp -e GOCACHE=/tmp/gocache -e GOMODCACHE=/gomod -v "$ROOT/gomod:/gomod" \
  -v "$ROOT/direct/endpoint-agent":/src -w /src "$GOIMG" make dist "VERSION=$VERSION" "COMMIT=$SHORT" >"$ROOT/direct.log" 2>&1 || { echo "direct build failed"; tail "$ROOT/direct.log"; }
for f in rivetit-agent-windows-amd64.exe rivetit-agent-windows-arm64.exe; do
  check "$f is byte-identical to a direct make dist" cmp -s "$ROOT/uploaded/$f" "$ROOT/direct/endpoint-agent/dist/$f"; done
stop_mock
echo "   (second independent build via --dry-run, for MSI reproducibility)"
if run_host --dry-run --ref main --version "$VERSION" >"$ROOT/dry.out" 2>&1; then
  check "MSI from --dry-run is byte-identical to the uploaded MSI" cmp -s "$ROOT/uploaded/rivetit-agent-windows-amd64.msi" "$ROOT/work/dry-run-out/rivetit-agent-windows-amd64.msi"
  check "dry run left no temp dirs" leftovers
else bad "dry run failed"; tail -10 "$ROOT/dry.out"; fi

echo "== B. refusals"
start_mock --job-id job-B --version "$VERSION" --ref evil
if run_host --once >"$ROOT/runB.out" 2>&1; then bad "non-allowlisted ref should fail"; else ok "non-allowlisted ref: exit non-zero"; fi
check "server told: failed (ref refused)" test "$(sj state)" = failed
check "reason names the allowlist" bash -c "state() { curl -s http://127.0.0.1:$PORT/_state; }; state | grep -q ALLOWED_REFS"
check "no leftovers after refusal" leftovers
stop_mock
start_mock --job-id job-B2 --version "$VERSION" --ref v9.9.9
if run_host --once >"$ROOT/runB2.out" 2>&1; then bad "unknown ref should fail"; else ok "allowlisted but nonexistent ref: failed"; fi
check "server told: failed (unknown ref)" test "$(sj state)" = failed
check "no leftovers after unknown ref" leftovers
stop_mock
start_mock --job-id job-B3 --version "$VERSION" --ref main
if bash "$SCRIPT" --config "$ROOT/host.conf" --once >"$ROOT/runB3.out" 2>&1; then bad "http without override should be refused"; else ok "http refused without BUILD_HOST_ALLOW_HTTP"; fi
check "http refusal made no request" test "$(sj state)" = queued
stop_mock

echo "== C. docker killed mid-build, and the lock"
start_mock --job-id job-C --version "$VERSION" --ref main --msi
run_host --once >"$ROOT/runC.out" 2>&1 & BG_PID=$!
for _ in $(seq 120); do [ -n "$(docker ps -q --filter name=rbh-)" ] && break; sleep 0.5; done
check "build container is running" test -n "$(docker ps -q --filter name=rbh-)"
sleep 3
if run_host --once >"$ROOT/runC2.out" 2>&1; then ok "second run while one is building: exit 0"; else bad "second run should exit 0"; fi
check "second run reported the lock" grep -q "lock" "$ROOT/runC2.out"
docker ps -q --filter name=rbh- | xargs -r docker kill >/dev/null
if wait "$BG_PID"; then bad "killed build should exit non-zero"; else ok "killed build: exit non-zero"; fi; BG_PID=""
check "server told: failed" test "$(sj state)" = failed
check "failure log tail was sent" bash -c "state() { curl -s http://127.0.0.1:$PORT/_state; }; [ \"\$(state | python3 -c 'import json,sys; print(len(json.load(sys.stdin)[\"failed_log_tail\"] or \"\"))')\" -gt 0 ] || state | grep -q 'make dist failed'"
check "only one claim happened" test "$(sj events)" = "['claimed', 'failed']"
check "no leftover temp dirs, containers or worktrees" leftovers
check "token never in the failure output" bash -c "! grep -q '$TOKEN' '$ROOT/runC.out' '$ROOT/runC2.out'"
stop_mock

echo
echo "RESULT: $PASS passed, $FAIL failed (artifacts in $ROOT)"
[ "$FAIL" -eq 0 ] && { [ -n "${PROOF_DIR:-}" ] || rm -rf "$ROOT"; exit 0; } || exit 1
