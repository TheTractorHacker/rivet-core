#!/usr/bin/env bash
# Regression test for endpoint-agent/scripts/install-linux.sh (rc.31 review F8/F9): no token-bearing work directory survives the run, the agent's exit
# status is passed through, and a pinned --installer-sha256 is enforced. Needs no root, no network and no real agent: a stub "curl" hands out a copy
# of an ordinary ELF program (/bin/true) as the "stamped installer".
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
script="$here/../scripts/install-linux.sh"
root="$(mktemp -d)"; trap 'rm -rf "$root"' EXIT
mkdir -p "$root/bin" "$root/tmp"
cat >"$root/bin/curl" <<'STUB'
#!/usr/bin/env bash
out=""; while [ $# -gt 0 ]; do [ "$1" = -o ] && out="$2"; shift; done
cp "${STUB_ELF:?}" "$out"
STUB
chmod +x "$root/bin/curl"
cp /bin/true "$root/true-elf"
# an ELF that answers `version` with 0 and fails (7) for anything else, so the agent run itself fails
if command -v cc >/dev/null 2>&1; then
  printf 'int main(int c,char**v){return c>1&&v[1][0]==118?0:7;}\n' >"$root/fail.c"; cc -o "$root/false-elf" "$root/fail.c"
else
  echo "no C compiler: skipping the exit-status check" >&2; cp /bin/true "$root/false-elf"
fi
echo "rvte1.abcdef.0123456789_-" >"$root/token"
fail=0
check() { if ! "$@"; then echo "FAIL: $*" >&2; fail=1; fi; }
leftovers() { find "$root/tmp" -maxdepth 1 -name 'rivetit-install.*' | wc -l; }
run() { env PATH="$root/bin:$PATH" TMPDIR="$root/tmp" RIVETIT_AGENT_ALLOW_NONROOT=1 STUB_ELF="$STUB_ELF" bash "$script" --state-dir "$root/state" --install-dir "$root/inst" "$@"; }

# 1. a successful fetch-installer run leaves no work directory (the old `exec` skipped the EXIT trap)
STUB_ELF="$root/true-elf" run --fetch-installer --server https://rmm.example.test --token-file "$root/token" >"$root/err1" 2>&1 || { echo "FAIL: success path exited non-zero" >&2; fail=1; }
check test "$(leftovers)" = 0
check grep -q "trusted on TLS alone" "$root/err1"
# 2. the agent's failure status is passed through and the directory is still removed
rc=0; STUB_ELF="$root/false-elf" run --fetch-installer --server https://rmm.example.test --token-file "$root/token" >/dev/null 2>&1 || rc=$?
if command -v cc >/dev/null 2>&1; then check test "$rc" = 7; fi
check test "$(leftovers)" = 0
# 3. a wrong pinned digest is refused (65) before anything runs; the right one is accepted
rc=0; STUB_ELF="$root/true-elf" run --fetch-installer --installer-sha256 "$(printf 'a%.0s' {1..64})" --server https://rmm.example.test --token-file "$root/token" >/dev/null 2>&1 || rc=$?
check test "$rc" = 65
check test "$(leftovers)" = 0
good="$(sha256sum "$root/true-elf" | cut -d' ' -f1)"
STUB_ELF="$root/true-elf" run --fetch-installer --installer-sha256 "$good" --server https://rmm.example.test --token-file "$root/token" >/dev/null 2>&1 || { echo "FAIL: correct digest refused" >&2; fail=1; }
check test "$(leftovers)" = 0
[ "$fail" = 0 ] && echo "install-linux.sh: all checks passed"
exit "$fail"
