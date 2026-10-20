#!/usr/bin/env bash
# RivetCore build host: builds the endpoint agent on THIS machine for a server that asks for it.
# Protocol: docs/rmm/BUILD_HOST_PROTOCOL.md. Setup guide: docs/rmm/BUILD_HOST.md.
# Pull model: only outbound https requests. Needs bash, curl, docker, git, python3 (standard library only), flock, timeout.
#
#   build-host.sh [--once]              one poll; build and upload a job if one is handed out (default; run it from the timer)
#   build-host.sh --register            check this machine and the token against the server; never builds
#   build-host.sh --dry-run [--ref R] [--version V]
#                                       build locally, talk to NO server, upload nothing (output: $WORKDIR/dry-run-out)
#   --progress                          print 'stage, pct, elapsed' lines to stderr (default when stderr is a terminal)
#   --config FILE                       config file (default /etc/rivetit/build-host.conf, or $BUILD_HOST_CONF)
set -Eeuo pipefail
umask 077

HOST_VERSION="1.0.0"
CHUNK_BYTES=1048576
MODE=once
CONF="${BUILD_HOST_CONF:-/etc/rivetit/build-host.conf}"
DRY_REF="" DRY_VERSION="0.0.0-dryrun"
PROGRESS_PRINT=0; [ -t 2 ] && PROGRESS_PRINT=1
PSTAGE="" PPCT=0 JOB_T0=0 CREEP_BASE=0 CREEP_TO=0 CREEP_S=0 CREEP_T0=0 UP_TOTAL=1 UP_DONE=0

log() { printf '%s build-host: %s\n' "$(date -u +%FT%TZ)" "$*" >&2; }
die() { FAIL_NOTE="$*"; log "ERROR: $*"; exit 1; }

while [ $# -gt 0 ]; do
  case "$1" in
    --once) MODE=once ;;
    --register) MODE=register ;;
    --progress) PROGRESS_PRINT=1 ;;
    --dry-run) MODE=dry ;;
    --config) CONF="${2:?--config needs a file}"; shift ;;
    --ref) DRY_REF="${2:?--ref needs a value}"; shift ;;
    --version) DRY_VERSION="${2:?--version needs a value}"; shift ;;
    -h|--help) sed -n '2,12p' "$0"; exit 0 ;;
    *) printf 'unknown option: %s\n' "$1" >&2; exit 2 ;;
  esac
  shift
done

LAST_CODE=0 SKIPPED=() FAIL_NOTE="" JOB_ID="" COMPLETED=0 TMP="" CONTAINERS=() SRC="" CACHE_REPO="" LOG="" ERR_LINE=""
trap 'ERR_LINE=$LINENO' ERR

# ---------- config ----------
load_config() {
  [ -f "$CONF" ] || die "config file $CONF not found (copy build-host.conf.example)"
  local mode owner
  mode=$(stat -c %a "$CONF"); owner=$(stat -c %u "$CONF")
  if [ $(( 8#$mode & 8#077 )) -ne 0 ]; then die "config $CONF must not be readable by group/others (chmod 600)"; fi
  if [ "$owner" != "$(id -u)" ] && [ "$owner" != 0 ]; then die "config $CONF must be owned by root or the current user"; fi
  # shellcheck source=/dev/null
  . "$CONF"
  SERVER_URL="${SERVER_URL:-}" TOKEN="${TOKEN:-}" CORE_REPO="${CORE_REPO:-}"
  ALLOWED_REFS="${ALLOWED_REFS:-main}" WORKDIR="${WORKDIR:-/var/lib/rivetit-build-host}"
  GO_IMAGE="${GO_IMAGE:-}" MSI_IMAGE="${MSI_IMAGE:-rivetit-msitools:0.103-2}"
  BUILD_CPUS="${BUILD_CPUS:-2}" BUILD_MEMORY="${BUILD_MEMORY:-4g}" BUILD_TIMEOUT_S="${BUILD_TIMEOUT_S:-1500}"
  HTTP_TIMEOUT="${HTTP_TIMEOUT:-120}"
  UPLOAD_LINUX_TARBALLS="${UPLOAD_LINUX_TARBALLS:-0}"
  [ -n "$CORE_REPO" ] || die "CORE_REPO is not set in $CONF"
  case "$WORKDIR" in /*) ;; *) die "WORKDIR must be an absolute path" ;; esac
  if [ "$MODE" != dry ]; then
    [ -n "$TOKEN" ] || die "TOKEN is not set in $CONF"
    [ -n "$SERVER_URL" ] || die "SERVER_URL is not set in $CONF"
    check_url
  fi
}
check_url() {
  local rest
  case "$SERVER_URL" in
    https://*) rest=${SERVER_URL#https://} ;;
    http://*) [ "${BUILD_HOST_ALLOW_HTTP:-0}" = 1 ] || die "SERVER_URL must be https:// (http is refused)"; rest=${SERVER_URL#http://} ;;
    *) die "SERVER_URL must start with https://" ;;
  esac
  case "${rest%%/*}" in *@*) die "SERVER_URL must not contain credentials" ;; esac
  case "$SERVER_URL" in *[[:space:]]*|*\'*|*\"*) die "SERVER_URL contains invalid characters" ;; esac
  SERVER_URL=${SERVER_URL%/}
}
need() { command -v "$1" >/dev/null 2>&1 || die "required program not found: $1"; }

# ---------- JSON helpers (python3 standard library only) ----------
jget() { # file dotted.path -> scalar text ("" for null/missing/non-scalar)
  python3 -I -c '
import json, sys
d = json.load(open(sys.argv[1]))
for k in sys.argv[2].split("."):
    d = d.get(k) if isinstance(d, dict) else None
if isinstance(d, bool): print("true" if d else "false")
elif isinstance(d, list): print(" ".join(str(x) for x in d))
elif d is None or isinstance(d, dict): print("")
else: print(d)' "$1" "$2"
}
jmk() { # outfile key/nested:type:value ... (types s b i f=text from file)
  python3 -I -c '
import json, sys
out = {}
for spec in sys.argv[2:]:
    key, typ, val = spec.split(":", 2)
    if typ == "b": val = (val == "true")
    elif typ == "i": val = int(val)
    elif typ == "f": val = open(val, encoding="utf-8", errors="replace").read()
    d = out
    parts = key.split("/")
    for p in parts[:-1]: d = d.setdefault(p, {})
    d[parts[-1]] = val
json.dump(out, open(sys.argv[1], "w"))' "$@"
}

# ---------- server calls ----------
api() { # endpoint bodyfile outfile -> 0 on success; retries 5xx/network with backoff, never 4xx
  local ep=$1 body=$2 out=$3 attempt=1 code rc delay="${BUILD_HOST_RETRY_DELAY:-2}" proto='=https'
  local maxtime="${API_MAXTIME:-$HTTP_TIMEOUT}" maxtries="${API_ATTEMPTS:-5}" quiet="${API_QUIET:-0}"
  [ "${BUILD_HOST_ALLOW_HTTP:-0}" = 1 ] && proto='=http,https'
  while :; do
    rc=0
    code=$(curl -sS --fail --max-time "$maxtime" --connect-timeout 20 --proto "$proto" --proto-redir =https \
      -K "$TMP/curl.cfg" -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' \
      --data-binary "@$body" -o "$out" -w '%{http_code}' "$SERVER_URL/$ep" 2>"$TMP/curl.err") || rc=$?
    LAST_CODE=${code:-0}
    [ "$rc" -eq 0 ] && return 0
    [ "$quiet" = 1 ] || log "$ep: attempt $attempt failed (curl $rc, http ${code:-0}): $(tr '\n' ' ' <"$TMP/curl.err" | cut -c1-200)"
    case "${code:-0}" in 4[0-9][0-9]) [ "$code" = 408 ] || [ "$code" = 429 ] || return 1 ;; esac
    [ "$attempt" -ge "$maxtries" ] && return 1
    sleep "$delay"; delay=$(( delay * 2 > 30 ? 30 : delay * 2 )); attempt=$(( attempt + 1 ))
  done
}
status() { # state note [logtailfile]
  [ -n "$JOB_ID" ] || return 0
  local args=("job_id:s:$JOB_ID" "state:s:$1" "note:s:${2:0:500}")
  [ -n "${3:-}" ] && args+=("log_tail:f:$3")
  if [ -n "$PSTAGE" ]; then args+=("stage:s:$PSTAGE" "pct:i:$PPCT" "detail:s:$PDETAIL" "elapsed_s:i:$(( SECONDS - JOB_T0 ))"); fi
  jmk "$TMP/status.json" "${args[@]}" && api status "$TMP/status.json" "$TMP/status.out" || return 1
}
progress() { # stage pct detail : monotonic; prints for an operator; tells the server quietly; never fails the build
  local pct=$2 d
  d=$(printf '%s' "$3" | LC_ALL=C tr -cd '[:print:]' | cut -c1-120)
  [ "$pct" -ge "$PPCT" ] || pct=$PPCT
  [ "$pct" -le 100 ] || pct=100
  PSTAGE=$1 PPCT=$pct PDETAIL=$d
  [ "$PROGRESS_PRINT" != 1 ] || printf '%s build-host: progress: %-20s %3d%%  elapsed %ss  %s\n' "$(date -u +%FT%TZ)" "$PSTAGE" "$PPCT" "$(( SECONDS - JOB_T0 ))" "$PDETAIL" >&2
  API_QUIET=1 API_ATTEMPTS=3 API_MAXTIME=15 BUILD_HOST_RETRY_DELAY=1 status "$([ "$PSTAGE" = upload ] && echo uploading || echo building)" "$PSTAGE: $PDETAIL" || true
}
creep() { CREEP_BASE=$PPCT CREEP_TO=$1 CREEP_S=$2 CREEP_T0=$SECONDS; } # heartbeats move pct from here toward $1 over about $2 s, never reaching it
mask() { # stdin -> stdout, secrets removed
  local s; s=$(cat)
  [ -n "${TOKEN:-}" ] && s=${s//"$TOKEN"/'***'}
  printf '%s\n' "$s" | sed -E 's/(Bearer|Basic)[[:space:]]+[A-Za-z0-9._~+\/=-]+/\1 ***/Ig;
    s/((token|secret|passw(or)?d|authorization|api[_-]?key)[A-Za-z_]*[=:][[:space:]]*)[^[:space:]"'"'"']+/\1***/Ig'
}

# ---------- cleanup on every exit path ----------
on_exit() {
  local code=$1 c
  trap - EXIT; set +e
  if [ -n "$JOB_ID" ] && [ "$COMPLETED" != 1 ] && [ -n "$TMP" ] && [ -f "$TMP/curl.cfg" ]; then
    [ -n "$FAIL_NOTE" ] || FAIL_NOTE="build host stopped unexpectedly (exit $code${ERR_LINE:+, line $ERR_LINE})"
    if [ -n "$LOG" ] && [ -f "$LOG" ]; then tail -c 4096 "$LOG" | mask >"$TMP/tail.txt"; else : >"$TMP/tail.txt"; fi
    BUILD_HOST_RETRY_DELAY=1 status failed "$FAIL_NOTE" "$TMP/tail.txt" || log "could not report the failure to the server"
  fi
  for c in "${CONTAINERS[@]}"; do docker rm -f "$c" >/dev/null 2>&1; done
  if [ -n "$SRC" ] && [ -n "$CACHE_REPO" ]; then git -C "$CACHE_REPO" worktree remove --force "$SRC" >/dev/null 2>&1; git -C "$CACHE_REPO" worktree prune >/dev/null 2>&1; fi
  [ -n "$TMP" ] && rm -rf "$TMP"
  [ "$code" -ne 0 ] && log "finished with errors (exit $code)${FAIL_NOTE:+: $FAIL_NOTE}"
  exit "$code"
}
trap 'on_exit $?' EXIT
trap 'FAIL_NOTE="${FAIL_NOTE:-interrupted by a signal}"; exit 143' INT TERM HUP

# ---------- source and build ----------
ref_allowed() {
  local ref=$1 pat
  case "$ref" in ''|-*|*..*|*//*|*/|/*|*[!A-Za-z0-9._/-]*) return 1 ;; esac
  for pat in $ALLOWED_REFS; do
    # shellcheck disable=SC2254
    case "$ref" in $pat) return 0 ;; esac
  done
  return 1
}
sync_source() { # ref -> sets COMMIT_FULL, SRC
  CACHE_REPO="$WORKDIR/source.git"
  local ref=$1 r
  if [ ! -d "$CACHE_REPO" ]; then git init --bare -q "$CACHE_REPO"; fi
  if [ -d "$CORE_REPO/.git" ] && git -C "$CORE_REPO" remote get-url origin >/dev/null 2>&1; then
    git -C "$CORE_REPO" fetch -q --tags --prune origin >>"$LOG" 2>&1 || die "git fetch in $CORE_REPO failed"
  fi
  git -C "$CACHE_REPO" fetch -q --prune --force "$CORE_REPO" '+refs/heads/*:refs/heads/*' '+refs/tags/*:refs/tags/*' \
    '+refs/remotes/origin/*:refs/remotes/origin/*' >>"$LOG" 2>&1 || die "git fetch from the source repository failed"
  COMMIT_FULL=""
  for r in "refs/remotes/origin/$ref" "refs/tags/$ref" "refs/heads/$ref"; do
    COMMIT_FULL=$(git -C "$CACHE_REPO" rev-parse --verify -q "$r^{commit}" 2>/dev/null) && break || COMMIT_FULL=""
  done
  [ -n "$COMMIT_FULL" ] || die "ref '$ref' does not exist in the source repository"
  SRC="$TMP/src"
  git -C "$CACHE_REPO" worktree add -q --detach "$SRC" "$COMMIT_FULL" >>"$LOG" 2>&1 || die "could not create a clean worktree"
  [ -f "$SRC/endpoint-agent/Makefile" ] || die "ref '$ref' has no endpoint-agent/Makefile"
}
BUILD_UID="" BUILD_GID=""
docker_base() { # name -> DOCKER_ARGS
  CONTAINERS+=("$1")
  DOCKER_ARGS=(run --rm --name "$1" -u "$BUILD_UID:$BUILD_GID" --cap-drop ALL --security-opt no-new-privileges
    --pids-limit 1024 --cpus "$BUILD_CPUS" --memory "$BUILD_MEMORY" --memory-swap "$BUILD_MEMORY"
    --read-only --tmpfs "/tmp:rw,exec,nosuid,size=3g" -e HOME=/tmp -e GOCACHE=/tmp/gocache -e GOMODCACHE=/gomod
    -e GOFLAGS=-mod=readonly -v "$SRC/endpoint-agent:/src" -w /src)
}
run_limited() { # note cmd... : runs in background, heartbeats, enforces the overall deadline
  local note=$1 pid last=$SECONDS now t p; shift
  "$@" >>"$LOG" 2>&1 & pid=$!
  while kill -0 "$pid" 2>/dev/null; do
    now=$SECONDS
    if [ "$now" -ge "$DEADLINE" ]; then log "build timed out"; kill "$pid" 2>/dev/null || true
      for c in "${CONTAINERS[@]}"; do docker rm -f "$c" >/dev/null 2>&1 || true; done
      wait "$pid" 2>/dev/null || true; die "build timed out after $(( BUILD_TIMEOUT_S / 60 )) minutes"; fi
    [ -z "${STAGE_WATCH:-}" ] || "$STAGE_WATCH"
    if [ $(( now - last )) -ge 15 ]; then
      t=$(( now - CREEP_T0 )); p=$PPCT
      if [ "$CREEP_S" -gt 0 ]; then [ "$t" -le "$CREEP_S" ] || t=$CREEP_S; p=$(( CREEP_BASE + (CREEP_TO - 1 - CREEP_BASE) * t / CREEP_S )); fi
      progress "$PSTAGE" "$p" "$note, $(( now - JOB_T0 ))s into the job"; last=$now
    fi
    sleep 2
  done
  wait "$pid" || die "$note failed (see the log tail)"
}
elf_check() { # file expected-arch (amd64|arm64): ELF magic, 64-bit little-endian, executable/shared type, machine; python3 standard library only
  python3 -I -c '
import struct, sys
f, arch = sys.argv[1], sys.argv[2]
h = open(f, "rb").read(64)
want = {"amd64": 0x3e, "arm64": 0xb7}[arch]
if len(h) < 64 or h[:4] != b"\x7fELF": sys.exit("no ELF magic")
if h[4] != 2 or h[5] != 1: sys.exit("not a 64-bit little-endian ELF")
etype, mach = struct.unpack_from("<HH", h, 16)
if etype not in (2, 3): sys.exit("ELF type %d is not an executable" % etype)
if mach != want: sys.exit("wrong machine 0x%x, expected 0x%x" % (mach, want))' "$1" "$2" || die "$(basename "$1") failed the ELF check"
}
size_cap() { # file max-bytes
  local sz; sz=$(stat -c %s "$1"); [ "$sz" -le "$2" ] || die "$(basename "$1") is $sz bytes, over the $2 byte cap"
}
pe_check() { # file expected-machine-hex-LE-bytes e.g. "64 86"
  local f=$1 want=$2 off sig mach
  [ "$(head -c2 "$f")" = MZ ] || die "$(basename "$f") is not a PE file (no MZ)"
  off=$(od -An -t u4 -j 60 -N 4 "$f" | tr -d ' ')
  sig=$(od -An -t x1 -j "$off" -N 4 "$f" | tr -s ' ' | sed 's/^ //')
  [ "$sig" = "50 45 00 00" ] || die "$(basename "$f") has no PE signature"
  mach=$(od -An -t x1 -j $(( off + 4 )) -N 2 "$f" | tr -s ' ' | sed 's/^ //')
  [ "$mach" = "$want" ] || die "$(basename "$f") has the wrong machine type ($mach, expected $want)"
}
dist_watch() { # make dist builds amd64 first: its file appearing marks the start of the arm64 build
  if [ "$PSTAGE" = build_windows_amd64 ] && [ -s "$OUT/rivetit-agent-windows-amd64.exe" ]; then
    progress build_windows_arm64 40 "windows amd64 built, building arm64"; creep 60 150
  fi
}
build_job() { # version ref targets msi(true|false) linux(true|false|"") -> files in $OUT, SHA256SUMS written
  local version=$1 ref=$2 targets=$3 want_msi=$4 want_linux=${5:-} gov n f
  DEADLINE=$(( SECONDS + BUILD_TIMEOUT_S ))
  progress prepare 5 "job accepted, fetching source"
  progress clone 5 "fetching and checking out $ref"
  sync_source "$ref"
  progress clone 10 "source ready"
  local short=${COMMIT_FULL:0:7}
  if [ -z "$GO_IMAGE" ]; then
    gov=$(awk '/^go /{print $2; exit}' "$SRC/endpoint-agent/go.mod"); [ -n "$gov" ] || die "cannot read the Go version from go.mod"
    GO_IMAGE="golang:$gov"
  fi
  mkdir -p "$WORKDIR/gomod"
  if [ "$(id -u)" = 0 ]; then BUILD_UID=10001 BUILD_GID=10001; chown -R "$BUILD_UID:$BUILD_GID" "$SRC" "$WORKDIR/gomod"
  else BUILD_UID=$(id -u) BUILD_GID=$(id -g); fi
  OUT="$SRC/endpoint-agent/dist"
  log "building $version from $ref ($short) with $GO_IMAGE"
  progress build_windows_amd64 10 "make dist $version from $ref ($short)"; creep 40 180

  docker_base "rbh-$$-dist"
  STAGE_WATCH=dist_watch run_limited "make dist" docker "${DOCKER_ARGS[@]}" -v "$WORKDIR/gomod:/gomod" "$GO_IMAGE" make dist "VERSION=$version" "COMMIT=$short"
  [ "$PSTAGE" != build_windows_amd64 ] || progress build_windows_arm64 40 "windows amd64 built, building arm64"
  progress build_windows_arm64 60 "windows executables built"
  if [ "$want_msi" = true ]; then
    progress msi 60 "preparing the MSI image"; creep 64 300
    docker image inspect "$MSI_IMAGE" >/dev/null 2>&1 || run_limited "docker build of $MSI_IMAGE" docker build -t "$MSI_IMAGE" "$SRC/endpoint-agent/packaging/msi"
    for n in 1 2; do
      progress msi $(( 60 + n * 4 )) "MSI build pass $n of 2"; creep $(( 64 + n * 4 )) 60
      docker_base "rbh-$$-msi$n"
      run_limited "MSI build pass $n" docker "${DOCKER_ARGS[@]}" --network none "$MSI_IMAGE" \
        packaging/msi/build.sh build "$version" dist/rivetit-agent-windows-amd64.exe "dist/.msi-pass$n.msi"
    done
    cmp -s "$OUT/.msi-pass1.msi" "$OUT/.msi-pass2.msi" || die "the two MSI builds differ (not reproducible)"
    mv "$OUT/.msi-pass1.msi" "$OUT/rivetit-agent-windows-amd64.msi"; rm -f "$OUT/.msi-pass2.msi"
    progress msi 73 "MSI round-trip test"; creep 80 120
    docker_base "rbh-$$-msitest"
    run_limited "MSI round-trip test" docker "${DOCKER_ARGS[@]}" -v "$WORKDIR/gomod:/gomod" -e RIVETIT_TEST_MSI=/src/dist/rivetit-agent-windows-amd64.msi \
      "$GO_IMAGE" go test -count=1 -run TestRealWixlMSIRoundTrip ./internal/embed
  fi

  [ "$want_msi" != true ] || progress msi 80 "MSI built and tested"
  # ----- self-verification of the outputs -----
  progress verify 80 "checking the outputs"
  UPLOAD=()
  case " $targets " in *" windows-amd64 "*) UPLOAD+=(rivetit-agent-windows-amd64.exe) ;; esac
  case " $targets " in *" windows-arm64 "*) UPLOAD+=(rivetit-agent-windows-arm64.exe) ;; esac
  [ "$want_msi" = true ] && UPLOAD+=(rivetit-agent-windows-amd64.msi)
  [ "${#UPLOAD[@]}" -gt 0 ] || die "the job asks for no uploadable target (windows-amd64, windows-arm64)"
  local lin=() a
  if [ "$want_linux" != false ]; then
    for a in amd64 arm64; do
      if [ "$want_linux" = true ] || case " $targets " in *" linux-$a "*) true ;; *) false ;; esac; then lin+=("rivetit-agent-linux-$a"); fi
    done
    if [ "${#lin[@]}" -gt 0 ]; then
      lin+=(install-linux.sh)
      if [ "$UPLOAD_LINUX_TARBALLS" = 1 ]; then for a in "${lin[@]}"; do case "$a" in rivetit-agent-linux-*) lin+=("$a.tar.gz") ;; esac; done; fi
    fi
  fi
  UPLOAD+=("${lin[@]}")
  for f in "${UPLOAD[@]}"; do [ -s "$OUT/$f" ] || die "expected output $f is missing or empty"; done
  [ ! -f "$OUT/rivetit-agent-windows-amd64.exe" ] || pe_check "$OUT/rivetit-agent-windows-amd64.exe" "64 86"
  [ ! -f "$OUT/rivetit-agent-windows-arm64.exe" ] || pe_check "$OUT/rivetit-agent-windows-arm64.exe" "64 aa"
  for f in "$OUT"/rivetit-agent-windows-*.exe; do
    grep -aqF -- "$version" "$f" || die "$(basename "$f") does not contain the version string $version"
  done
  if [ "$want_msi" = true ]; then
    [ "$(head -c8 "$OUT/rivetit-agent-windows-amd64.msi" | od -An -t x1 | tr -d ' \n')" = d0cf11e0a1b11ae1 ] || die "the MSI is not an OLE compound file"
  fi
  local la; case "$(uname -m)" in x86_64) la=amd64 ;; aarch64|arm64) la=arm64 ;; *) la="" ;; esac
  if [ -n "$la" ]; then
    docker_base "rbh-$$-ver"
    local vout; vout=$(docker "${DOCKER_ARGS[@]}" --network none "$GO_IMAGE" "./dist/rivetit-agent-linux-$la" version 2>>"$LOG") || die "the Linux agent does not run"
    case "$vout" in "rivetit-agent $version ($short) "*) ;; *) die "the agent reports '$vout', expected version $version commit $short" ;; esac
  fi
  for f in "${lin[@]}"; do
    case "$f" in
      rivetit-agent-linux-*.tar.gz) [ "$(head -c2 "$OUT/$f" | od -An -t x1 | tr -d ' \n')" = 1f8b ] || die "$f is not a gzip file"; size_cap "$OUT/$f" 67108864 ;;
      rivetit-agent-linux-amd64|rivetit-agent-linux-arm64)
        elf_check "$OUT/$f" "${f##*-}"; size_cap "$OUT/$f" 67108864
        grep -aqF -- "$version" "$OUT/$f" || die "$f does not contain the version string $version" ;;
      install-linux.sh)
        [ "$(head -c2 "$OUT/$f")" = '#!' ] || die "install-linux.sh has no shebang"; size_cap "$OUT/$f" 262144
        bash -n "$OUT/$f" || die "install-linux.sh has a syntax error" ;;
    esac
  done
  write_sums
  progress verify 85 "outputs verified"
  GO_VERSION_BUILT=$(docker run --rm --network none "$GO_IMAGE" go env GOVERSION 2>/dev/null || echo unknown)
}

write_sums() { ( cd "$OUT" && sha256sum "${UPLOAD[@]}" >SHA256SUMS ); }
skipped() { local s; for s in "${SKIPPED[@]}"; do [ "$s" != "$1" ] || return 0; done; return 1; }

# ---------- upload ----------
is_linux_name() { case "$1" in rivetit-agent-linux-*|install-linux.sh) return 0 ;; esac; return 1; }
upload_file() { # name
  local name=$1 f="$OUT/$1" size total i=0 chunk sha
  size=$(stat -c %s "$f"); [ "$size" -gt 0 ] || die "$name is empty"
  total=$(( (size + CHUNK_BYTES - 1) / CHUNK_BYTES ))
  rm -rf "$TMP/chunks"; mkdir -p "$TMP/chunks"
  split -b "$CHUNK_BYTES" -d -a 6 "$f" "$TMP/chunks/c."
  for chunk in "$TMP"/chunks/c.*; do
    sha=$(sha256sum "$chunk" | cut -d' ' -f1)
    base64 -w0 "$chunk" >"$TMP/chunk.b64"
    jmk "$TMP/up.json" "job_id:s:$JOB_ID" "name:s:$name" "index:i:$i" "total:i:$total" "sha256_chunk:s:$sha" "data_b64:f:$TMP/chunk.b64"
    if ! api upload "$TMP/up.json" "$TMP/up.out"; then
      # an older server does not know the Linux names: it answers 4xx on the first chunk; warn, skip, keep the Windows files
      if [ "$i" -eq 0 ] && is_linux_name "$name" && case "$LAST_CODE" in 400|403|404|409|415|422) true ;; *) false ;; esac; then
        log "WARNING: the server does not accept $name (HTTP $LAST_CODE, older edition?): skipping it"
        SKIPPED+=("$name"); UP_DONE=$(( UP_DONE + total )); return 0
      fi
      die "upload of $name chunk $i/$total failed"
    fi
    i=$(( i + 1 )); UP_DONE=$(( UP_DONE + 1 ))
    progress upload $(( 85 + 15 * UP_DONE / UP_TOTAL )) "chunk $UP_DONE of $UP_TOTAL ($name)"
  done
  log "uploaded $name ($size bytes, $total chunk(s))"
}
upload_all() {
  local f specs=() sz
  UP_TOTAL=0 UP_DONE=0
  for f in "${UPLOAD[@]}"; do sz=$(stat -c %s "$OUT/$f"); UP_TOTAL=$(( UP_TOTAL + (sz + CHUNK_BYTES - 1) / CHUNK_BYTES )); done
  UP_TOTAL=$(( UP_TOTAL + 1 )) # SHA256SUMS, one chunk, uploaded last
  progress upload 85 "uploading ${#UPLOAD[@]} files in $UP_TOTAL chunks"
  for f in "${UPLOAD[@]}"; do upload_file "$f"; done
  if [ "${#SKIPPED[@]}" -gt 0 ]; then # rebuild the file list and SHA256SUMS without what the server refused, then upload the sums last
    local keep=(); for f in "${UPLOAD[@]}"; do skipped "$f" || keep+=("$f"); done
    UPLOAD=("${keep[@]}"); write_sums
  fi
  UPLOAD+=(SHA256SUMS)
  upload_file SHA256SUMS
  for f in "${UPLOAD[@]}"; do [ "$f" = SHA256SUMS ] || specs+=("manifest/files/$f:s:$(sha256sum "$OUT/$f" | cut -d' ' -f1)"); done
  jmk "$TMP/complete.json" "job_id:s:$JOB_ID" "${specs[@]}" "manifest/commit:s:$COMMIT_FULL" "manifest/go_version:s:$GO_VERSION_BUILT" \
    "manifest/built_at:s:$(date -u +%FT%TZ)"
  api complete "$TMP/complete.json" "$TMP/complete.out" || die "the server did not accept the build"
  COMPLETED=1
  log "job $JOB_ID completed"
}

# ---------- modes ----------
start() {
  need curl; need git; need python3; need docker; need flock; need timeout; need sha256sum; need base64; need od
  mkdir -p "$WORKDIR/tmp" "$WORKDIR"
  chmod 700 "$WORKDIR" 2>/dev/null || true
  exec 9>"$WORKDIR/build-host.lock"
  flock -n 9 || { log "another run holds the lock: nothing to do"; exit 0; }
  JOB_T0=$SECONDS
  TMP=$(mktemp -d "$WORKDIR/tmp/run.XXXXXX")
  LOG="$TMP/build.log"; : >"$LOG"
  if [ "$MODE" != dry ]; then
    ( umask 077; printf 'header = "Authorization: Bearer %s"\n' "$TOKEN" >"$TMP/curl.cfg" )
  fi
}
caps_json() { jmk "$1" "host_version:s:$HOST_VERSION" "capabilities/docker:b:$2" "capabilities/msi:b:$2"; }

load_config
case "$MODE" in
  register)
    start
    need_ok=true; docker info >/dev/null 2>&1 || { log "docker is not usable by this user"; need_ok=false; }
    # Always announces NO capabilities, so the server has nothing to hand out: --register must never claim a real job.
    caps_json "$TMP/poll.json" false
    api poll "$TMP/poll.json" "$TMP/poll.out" || die "the server did not accept the token or is unreachable"
    [ -z "$(jget "$TMP/poll.out" job.id)" ] || die "the server handed out a job during --register; it will expire unclaimed"
    printf 'OK build host %s: token accepted by %s, docker %s, allowed refs: %s\n' "$HOST_VERSION" "$SERVER_URL" \
      "$([ "$need_ok" = true ] && echo usable || echo NOT-usable)" "$ALLOWED_REFS"
    [ "$need_ok" = true ] ;;
  dry)
    start
    ref=${DRY_REF:-${ALLOWED_REFS%% *}}
    ref_allowed "$ref" || die "ref '$ref' is not in ALLOWED_REFS"
    [[ $DRY_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || die "bad --version"
    build_job "$DRY_VERSION" "$ref" "windows-amd64 windows-arm64 linux-amd64 linux-arm64" true true
    rm -rf "$WORKDIR/dry-run-out"; mkdir -p "$WORKDIR/dry-run-out"; cp -a "$OUT"/. "$WORKDIR/dry-run-out/"
    log "dry run complete: nothing was uploaded; files are in $WORKDIR/dry-run-out"
    printf '%s\n' "$WORKDIR/dry-run-out" ;;
  once)
    start
    caps_json "$TMP/poll.json" true
    api poll "$TMP/poll.json" "$TMP/poll.out" || die "poll failed"
    id=$(jget "$TMP/poll.out" job.id)
    if [ -z "$id" ]; then log "no job"; exit 0; fi
    JOB_ID=$id; JOB_T0=$SECONDS
    [[ $JOB_ID =~ ^[A-Za-z0-9_-]{1,64}$ ]] || { JOB_ID=""; die "the server sent an invalid job id"; }
    version=$(jget "$TMP/poll.out" job.version); ref=$(jget "$TMP/poll.out" job.source_ref)
    targets=$(jget "$TMP/poll.out" job.targets); want_msi=$(jget "$TMP/poll.out" job.msi); want_linux=$(jget "$TMP/poll.out" job.linux)
    log "job $JOB_ID: version $version ref $ref"
    [[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]] || die "refused: invalid version"
    [[ $targets =~ ^[a-z0-9\ -]*$ ]] || die "refused: invalid targets"
    ref_allowed "$ref" || die "refused: source ref '${ref:0:80}' is not in this build host's ALLOWED_REFS"
    [ "$want_msi" = true ] || want_msi=false
    progress prepare 0 "job claimed and checked"
    build_job "$version" "$ref" "$targets" "$want_msi" "$want_linux"
    upload_all ;;
esac
