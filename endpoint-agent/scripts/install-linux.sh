#!/usr/bin/env bash
# Install, upgrade or remove the RMM endpoint agent on Linux (systemd). Run as root.
#
#   Stamped installer (per-department binary downloaded from the server, carries URL, CA and one-shot token):
#     sudo ./install-linux.sh --binary ./rivetit-agent-sales
#     sudo ./install-linux.sh --fetch-installer --server https://rmm.example.com --token-file /root/enroll.token
#   Plain agent + token passed separately (tarball, GitHub release, internal mirror):
#     sudo ./install-linux.sh --server https://rmm.example.com --token-file /root/enroll.token          # binary next to this script
#     sudo ./install-linux.sh --url https://mirror.example.com/rivetit-agent-linux-amd64 --sha256 <64 hex> \
#                             --server https://rmm.example.com --token-file /root/enroll.token
#   Remove:  sudo ./install-linux.sh --uninstall [--purge]
#
# What it does: picks the binary for this CPU, VERIFIES it (SHA-256 for --url downloads, ELF header and a `version`
# run for everything), then runs `rivetit-agent setup --silent` (stamped) or `rivetit-agent install` (plain), which
# enrolls the device, copies the binary to /opt/rivetit-agent, creates /var/lib/rivetit-agent (0700) and the
# hardened-for-root systemd unit rivetit-agent.service, and starts it. Re-running upgrades the binary and keeps the
# device identity. The enrollment token is never put on a command line.
#
# Exit codes: the agent's (0 ok, 2 bad/expired config, 3 token rejected, 4 network retryable, 5 install failure,
# 6 not root) plus 64 usage error, 65 download/verify failure.
set -euo pipefail
umask 077

die()  { echo "install-linux.sh: error: $*" >&2; exit "${2:-64}"; }
note() { echo "install-linux.sh: $*"; }

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

BINARY="" URL="" SHA256="" SERVER="" TOKEN_FILE="" CA="" PIN="" DEPARTMENT="" STATE_DIR="" INSTALL_DIR=""
NO_SERVICE=0 FETCH=0 UNINSTALL=0 PURGE=0
while [ $# -gt 0 ]; do
  case "$1" in
    --binary) BINARY="${2:?}"; shift 2;;
    --url) URL="${2:?}"; shift 2;;
    --sha256) SHA256="${2:?}"; shift 2;;
    --fetch-installer) FETCH=1; shift;;
    --server) SERVER="${2:?}"; shift 2;;
    --token-file) TOKEN_FILE="${2:?}"; shift 2;;
    --ca) CA="${2:?}"; shift 2;;
    --pin-spki) PIN="${2:?}"; shift 2;;
    --department) DEPARTMENT="${2:?}"; shift 2;;
    --state-dir) STATE_DIR="${2:?}"; shift 2;;
    --install-dir) INSTALL_DIR="${2:?}"; shift 2;;
    --no-service) NO_SERVICE=1; shift;;
    --uninstall) UNINSTALL=1; shift;;
    --purge) PURGE=1; shift;;
    -h|--help) usage 0;;
    *) echo "unknown option: $1" >&2; usage 64;;
  esac
done

[ "$(id -u)" = 0 ] || [ "${RIVETIT_AGENT_ALLOW_NONROOT:-}" = 1 ] || die "run as root (sudo)" 6
[ "$(uname -s)" = Linux ] || die "this script is for Linux"

case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64;;
  aarch64|arm64) ARCH=arm64;;
  *) die "unsupported CPU architecture: $(uname -m) (amd64 and arm64 are built)";;
esac

# A private work directory where programs may be executed (some hosts mount /tmp noexec).
WORK=""
for base in "${TMPDIR:-}" /tmp /var/tmp /run /root; do
  [ -n "$base" ] && [ -d "$base" ] && [ -w "$base" ] || continue
  d="$(mktemp -d "$base/rivetit-install.XXXXXX" 2>/dev/null)" || continue
  printf '#!/bin/sh\nexit 0\n' >"$d/probe"; chmod 0700 "$d/probe"
  if "$d/probe" 2>/dev/null; then WORK="$d"; break; fi
  rm -rf "$d"
done
[ -n "$WORK" ] || die "no writable directory that allows executing programs (tried \$TMPDIR, /tmp, /var/tmp, /run, /root)"
rm -f "$WORK/probe"
trap 'rm -rf "$WORK"' EXIT

DEST_STATE="${STATE_DIR:-/var/lib/rivetit-agent}"
DEST_INSTALL="${INSTALL_DIR:-/opt/rivetit-agent}"
COMMON=(--state-dir "$DEST_STATE" --install-dir "$DEST_INSTALL")

if [ "$UNINSTALL" = 1 ]; then
  BIN="$DEST_INSTALL/rivetit-agent"
  [ -x "$BIN" ] || BIN="$(command -v rivetit-agent || true)"
  [ -n "$BIN" ] || die "the agent is not installed (no $DEST_INSTALL/rivetit-agent)" 1
  args=(uninstall "${COMMON[@]}"); [ "$PURGE" = 1 ] && args+=(--purge)
  exec "$BIN" "${args[@]}"
fi

fetch() { # fetch URL OUTFILE [curl args...]  (HTTPS only; file:// is accepted for offline mirrors and tests)
  local u="$1" out="$2"; shift 2
  case "$u" in https://*|file://*) ;; *) die "refusing non-HTTPS URL: $u" 65;; esac
  if command -v curl >/dev/null 2>&1; then
    local ca=(); [ -n "$CA" ] && ca=(--cacert "$CA")
    curl --fail --silent --show-error --location --proto '=https,file' --proto-redir '=https' --max-time 600 "${ca[@]}" -o "$out" "$@" "$u" || die "download failed: $u" 65
  elif command -v wget >/dev/null 2>&1 && [ "${u#https://}" != "$u" ] && [ "$#" -eq 0 ]; then
    local ca=(); [ -n "$CA" ] && ca=(--ca-certificate="$CA")
    wget -q --https-only "${ca[@]}" -O "$out" "$u" || die "download failed: $u" 65
  else
    die "curl is required (wget only supports plain downloads)" 65
  fi
}

check_elf() { # a Linux executable for this CPU: ELF magic, then the machine field (e_machine at offset 18)
  local f="$1" magic mach
  magic="$(head -c 4 "$f" | od -An -c | tr -d ' \n')"
  [ "$magic" = '177ELF' ] || die "not an ELF executable: $f" 65
  mach="$(od -An -tx1 -j18 -N2 "$f" | tr -d ' \n')"
  case "$ARCH:$mach" in amd64:3e00|arm64:b700) ;; *) die "binary is built for a different CPU (e_machine=$mach, this host is $ARCH)" 65;; esac
}

validate_token_file() {
  [ -r "$TOKEN_FILE" ] || die "--token-file is not readable: $TOKEN_FILE"
  local t; t="$(tr -d ' \t\r\n' < "$TOKEN_FILE")"
  [[ "$t" =~ ^rvte1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$ ]] || die "the token file does not hold an enrollment token (expected rvte1.<selector>.<secret>)"
}

# ---- choose and verify the binary ------------------------------------------------------------------------------
STAMPED_MODE=0
if [ "$FETCH" = 1 ]; then
  [ -n "$SERVER" ] && [ -n "$TOKEN_FILE" ] || die "--fetch-installer needs --server and --token-file"
  validate_token_file
  case "$SERVER" in https://*) ;; *) die "--server must be an https:// URL";; esac
  tok="$(tr -d ' \t\r\n' < "$TOKEN_FILE")"
  BINARY="$WORK/installer"
  # the token travels in the request body from a pipe: never in argv, never in the URL
  printf '{"token":"%s","arch":"%s"}' "$tok" "$ARCH" | fetch "${SERVER%/}/api/v1/agent_installer" "$BINARY" -X POST -H 'Content-Type: application/json' --data-binary @- \
    || die "could not fetch the stamped installer (does the server offer a Linux installer for $ARCH?)" 65
  unset tok
  STAMPED_MODE=1
elif [ -n "$URL" ]; then
  [ -n "$SHA256" ] || die "--url requires --sha256 (the expected SHA-256 of the binary)"
  [[ "$SHA256" =~ ^[A-Fa-f0-9]{64}$ ]] || die "--sha256 must be 64 hex characters"
  BINARY="$WORK/rivetit-agent"
  fetch "$URL" "$BINARY"
  got="$(sha256sum "$BINARY" | cut -d' ' -f1)"
  [ "${got,,}" = "${SHA256,,}" ] || die "SHA-256 mismatch: expected $SHA256, got $got" 65
  note "SHA-256 verified"
elif [ -z "$BINARY" ]; then
  here="$(cd "$(dirname "$0")" && pwd)"
  if [ -f "$here/rivetit-agent" ]; then BINARY="$here/rivetit-agent"; else die "no binary: pass --binary, --url or --fetch-installer (or run the script from the unpacked tarball)"; fi
fi
[ -f "$BINARY" ] || die "binary not found: $BINARY"
# work on a private copy: what is verified is exactly what runs, and the caller's file keeps its mode
if [ "$(dirname "$BINARY")" != "$WORK" ]; then cp -- "$BINARY" "$WORK/agent-bin"; BINARY="$WORK/agent-bin"; fi
chmod 0700 "$BINARY"
check_elf "$BINARY"
"$BINARY" version >"$WORK/version.txt" 2>&1 || die "the binary does not run on this host: $(head -c 200 "$WORK/version.txt")" 65
note "using $(head -n1 "$WORK/version.txt")"

# ---- install ----------------------------------------------------------------------------------------------------
# The agent sets explicit modes for everything secret (state 0700, credential 0600); the program directory,
# binary and unit must stay world-readable, so drop the restrictive umask used for the temp files above.
umask 022
if [ "$FETCH" = 1 ] || { [ -z "$SERVER" ] && [ -z "$TOKEN_FILE" ]; }; then
  STAMPED_MODE=1
fi
if [ "$STAMPED_MODE" = 1 ]; then
  args=(setup --silent "${COMMON[@]}"); [ "$NO_SERVICE" = 1 ] && args+=(--no-service)
  exec "$BINARY" "${args[@]}"
fi
[ -n "$SERVER" ] && [ -n "$TOKEN_FILE" ] || die "a plain agent needs --server and --token-file (or use a stamped installer)"
validate_token_file
args=(install --server "$SERVER" --token-file "$TOKEN_FILE" "${COMMON[@]}")
[ -n "$CA" ] && args+=(--ca "$CA")
[ -n "$PIN" ] && args+=(--pin-spki "$PIN")
[ -n "$DEPARTMENT" ] && args+=(--department "$DEPARTMENT")
[ "$NO_SERVICE" = 1 ] && args+=(--no-service)
exec "$BINARY" "${args[@]}"
