#!/usr/bin/env bash
# Install, upgrade or remove the RMM endpoint agent on Linux (systemd). Run as root.
#
#   Stamped installer (per-department binary downloaded from the server, carries URL, CA and one-shot token):
#     sudo ./install-linux.sh --binary ./rivetit-agent-sales
#     sudo ./install-linux.sh --fetch-installer --server https://rmm.example.com --token-file /root/enroll.token [--installer-sha256 <64 hex>]
#       A stamped installer is built per enrollment token, so its SHA-256 cannot be known in advance: this download is protected by TLS (add --ca for a
#       private CA) and nothing else. --installer-sha256 is for the case where someone already knows the digest (for example the administrator
#       downloaded the same installer and hashed it); without it the script says so.
#   Plain agent + token passed separately (tarball, GitHub release, internal mirror):
#     sudo ./install-linux.sh --server https://rmm.example.com --token-file /root/enroll.token          # binary next to this script
#     sudo ./install-linux.sh --url https://mirror.example.com/rivetit-agent-linux-amd64 --sha256 <64 hex> \
#                             --server https://rmm.example.com --token-file /root/enroll.token
#   Optional MeshCentral agent, installed from YOUR OWN Mesh server (never bundled; a failure never fails the RivetIT install):
#     a stamped installer carries it when the administrator ticked "also install the MeshCentral agent"; add --no-mesh-agent to skip it.
#     with a plain agent:  ... --install-mesh-agent --mesh-url https://mesh.example.com --mesh-group-id <meshid from the Mesh download address, 64 chars or hex> [--mesh-cert-pin <sha384 hex>]
#     The result line printed at the end is the agent's VERIFIED result: "MeshCentral agent: installed." only after the Mesh files, the meshagent
#     service (active) were checked, else "MeshCentral agent: NOT installed (<reason>)." (the RivetIT agent stays installed, exit status 0;
#     the reason is also in /var/lib/rivetit-agent/install.log and in the device's Mesh state).
#   Remove:  sudo ./install-linux.sh --uninstall [--purge] [--remove-meshagent]   (Mesh is left alone unless --remove-meshagent)
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

usage() { sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit "${1:-0}"; }

BINARY="" URL="" SHA256="" INSTALLER_SHA256="" SERVER="" TOKEN_FILE="" CA="" PIN="" DEPARTMENT="" STATE_DIR="" INSTALL_DIR=""
NO_SERVICE=0 FETCH=0 UNINSTALL=0 PURGE=0
MESH_INSTALL=0 MESH_URL="" MESH_GROUP="" MESH_PIN="" NO_MESH=0 REMOVE_MESH=0
while [ $# -gt 0 ]; do
  case "$1" in
    --binary) BINARY="${2:?}"; shift 2;;
    --url) URL="${2:?}"; shift 2;;
    --sha256) SHA256="${2:?}"; shift 2;;
    --fetch-installer) FETCH=1; shift;;
    --installer-sha256) INSTALLER_SHA256="${2:?}"; shift 2;;
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
    --install-mesh-agent) MESH_INSTALL=1; shift;;
    --mesh-url) MESH_URL="${2:?}"; shift 2;;
    --mesh-group-id) MESH_GROUP="${2:?}"; shift 2;;
    --mesh-cert-pin) MESH_PIN="${2:?}"; shift 2;;
    --no-mesh-agent) NO_MESH=1; shift;;
    --remove-meshagent) REMOVE_MESH=1; shift;;
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

# Run the agent as a child (NOT exec: exec would replace the shell and the EXIT trap would never remove $WORK, which holds the fetched stamped
# installer with its one-shot enrollment token), remove the work directory, and exit with the agent's status.
run_and_clean() {
  local status=0
  "$@" || status=$?
  rm -rf "$WORK"
  exit "$status"
}

DEST_STATE="${STATE_DIR:-/var/lib/rivetit-agent}"
DEST_INSTALL="${INSTALL_DIR:-/opt/rivetit-agent}"
COMMON=(--state-dir "$DEST_STATE" --install-dir "$DEST_INSTALL")

if [ "$UNINSTALL" = 1 ]; then
  BIN="$DEST_INSTALL/rivetit-agent"
  [ -x "$BIN" ] || BIN="$(command -v rivetit-agent || true)"
  [ -n "$BIN" ] || die "the agent is not installed (no $DEST_INSTALL/rivetit-agent)" 1
  args=(uninstall "${COMMON[@]}"); [ "$PURGE" = 1 ] && args+=(--purge); [ "$REMOVE_MESH" = 1 ] && args+=(--remove-meshagent)
  run_and_clean "$BIN" "${args[@]}"
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
  if [ -n "$INSTALLER_SHA256" ]; then
    [[ "$INSTALLER_SHA256" =~ ^[A-Fa-f0-9]{64}$ ]] || die "--installer-sha256 must be 64 hex characters"
    got="$(sha256sum "$BINARY" | cut -d' ' -f1)"
    [ "${got,,}" = "${INSTALLER_SHA256,,}" ] || die "SHA-256 mismatch for the fetched installer: expected $INSTALLER_SHA256, got $got" 65
    note "installer SHA-256 verified"
  else
    note "warning: the fetched installer is trusted on TLS alone (no --installer-sha256 given)"
  fi
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
  args=(setup --silent "${COMMON[@]}"); [ "$NO_SERVICE" = 1 ] && args+=(--no-service); [ "$NO_MESH" = 1 ] && args+=(--no-mesh-agent)
  run_and_clean "$BINARY" "${args[@]}"
fi
[ -n "$SERVER" ] && [ -n "$TOKEN_FILE" ] || die "a plain agent needs --server and --token-file (or use a stamped installer)"
validate_token_file
args=(install --server "$SERVER" --token-file "$TOKEN_FILE" "${COMMON[@]}")
[ -n "$CA" ] && args+=(--ca "$CA")
[ -n "$PIN" ] && args+=(--pin-spki "$PIN")
[ -n "$DEPARTMENT" ] && args+=(--department "$DEPARTMENT")
[ "$NO_SERVICE" = 1 ] && args+=(--no-service)
if [ "$MESH_INSTALL" = 1 ]; then
  args+=(--install-mesh-agent --mesh-url "$MESH_URL" --mesh-group-id "$MESH_GROUP"); [ -n "$MESH_PIN" ] && args+=(--mesh-cert-pin "$MESH_PIN")
fi
run_and_clean "$BINARY" "${args[@]}"
