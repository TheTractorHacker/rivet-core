#!/usr/bin/env bash
# Release gate for the agent's trust anchors (CORE-AGENT-1, Chain C of the 2026-11-21 red team). CI-ONLY: no Makefile default runs it, so a dev or PR
# build without keys keeps working. Reads RELEASE_KEYS, TRUST_KEYS and RELEASE_BUILD from the environment (the same values passed to make).
#
#   check-release-keys.sh validate          RELEASE_KEYS and TRUST_KEYS, when set, must be comma separated canonical base64 of 32-byte Ed25519 PUBLIC
#                                           keys (no spaces, no duplicates). With RELEASE_BUILD=1 an empty or invalid RELEASE_KEYS fails the build.
#   check-release-keys.sh artifacts [DIR]   validate, then every agent binary in DIR (default dist) must contain every RELEASE_KEYS and TRUST_KEYS
#                                           entry, and the linux/amd64 binary (when it runs here) must report the same counts and the release stamp
#                                           through `rivetit-agent version --trust`.
#
# A 64-byte value (an Ed25519 PRIVATE key in Go/libsodium form) is refused. A 32-byte private SEED cannot be told apart from a public key by its
# length: the release key procedure (docs/rmm/AGENT_BUILD.md) keeps the private half offline, so only the .pub file is ever copied into CI.
set -euo pipefail

die() { echo "check-release-keys: $*" >&2; exit 1; }
release_build() { [ "${RELEASE_BUILD:-}" = 1 ] || [ "${RELEASE_BUILD:-}" = true ]; }

# check_list NAME VALUE: prints the number of keys; dies on a malformed list.
check_list() {
  local name=$1 v=$2 k n canon
  local -A seen=()
  if [ -z "$v" ]; then echo 0; return 0; fi
  [[ "$v" =~ ^[A-Za-z0-9+/=,]+$ ]] || die "$name may only contain base64 characters and commas (no spaces or quotes)"
  [[ "$v" != ,* && "$v" != *, && "$v" != *,,* ]] || die "$name has an empty entry (leading, trailing or doubled comma)"
  local -a keys
  IFS=, read -r -a keys <<<"$v"
  [ "${#keys[@]}" -gt 0 ] || die "$name is empty"
  for k in "${keys[@]}"; do
    n=$(printf '%s' "$k" | base64 -d 2>/dev/null | wc -c) || die "$name has an entry that is not base64"
    [ "$n" != 64 ] || die "$name has a 64-byte entry: that is an Ed25519 PRIVATE key. Pass the public key only and treat this one as leaked."
    [ "$n" = 32 ] || die "$name has an entry of $n bytes; an Ed25519 public key is 32 bytes"
    canon=$(printf '%s' "$k" | base64 -d | base64 -w0)
    [ "$canon" = "$k" ] || die "$name has an entry that is not in canonical base64 form"
    [ -z "${seen[$k]:-}" ] || die "$name lists the same key twice"
    seen[$k]=1
  done
  echo "${#keys[@]}"
}

validate() {
  local nr nt
  nr=$(check_list RELEASE_KEYS "${RELEASE_KEYS:-}")
  nt=$(check_list TRUST_KEYS "${TRUST_KEYS:-}")
  if release_build && [ "$nr" -eq 0 ]; then
    die "RELEASE_BUILD=1 but RELEASE_KEYS is empty: a release would ship with no update trust anchor (set the AGENT_RELEASE_KEYS repository variable; see docs/rmm/AGENT_BUILD.md)"
  fi
  echo "check-release-keys: release_keys=$nr trust_keys=$nt release_build=$(release_build && echo true || echo false)"
  NR=$nr NT=$nt
}

artifacts() {
  local dir=${1:-dist} f k found=0 out want
  validate
  [ -d "$dir" ] || die "no build output directory $dir"
  for f in "$dir"/rivetit-agent-linux-amd64 "$dir"/rivetit-agent-linux-arm64 "$dir"/rivetit-agent-windows-amd64.exe "$dir"/rivetit-agent-windows-arm64.exe; do
    [ -f "$f" ] || continue
    found=$((found + 1))
    for k in ${RELEASE_KEYS//,/ } ${TRUST_KEYS//,/ }; do
      grep -qaF -- "$k" "$f" || die "$(basename "$f") does not embed the configured key ${k:0:8}... (built without RELEASE_KEYS/TRUST_KEYS?)"
    done
  done
  [ "$found" -gt 0 ] || die "no agent binary in $dir"
  f="$dir/rivetit-agent-linux-amd64"
  if [ -x "$f" ] && [ "$(uname -s)/$(uname -m)" = Linux/x86_64 ]; then
    out=$("$f" version --trust)
    want="release_keys=$NR trust_keys=$NT release_build=$(release_build && echo true || echo false)"
    [ "$out" = "$want" ] || die "linux/amd64 binary reports '$out', expected '$want'"
  fi
  echo "check-release-keys: $found binaries embed the configured keys"
}

case "${1:-validate}" in
  validate) validate ;;
  artifacts) artifacts "${2:-dist}" ;;
  *) die "usage: $0 validate | artifacts [DIR]" ;;
esac
