#!/usr/bin/env bash
# Runs RivetCore's install path in a CLEAN ubuntu:24.04 container (issue #36): apt packages, composer install, MariaDB, Redis,
# every Core migration on an empty database, a second run that must do nothing, an upgrade from old tags, and the PHPUnit suite.
#
#   docker/ubuntu24/smoke.sh [--image ubuntu:24.04] [--tags "v0.3.0 v0.9.0 v0.17.0"] [--no-tests]
#
# It creates ONE throwaway container (name rivetcore-ubuntu24-smoke-<pid>) that is removed afterwards, never touches other containers,
# mounts the repository read-only and needs network access inside the container for apt and Packagist. What this does and does not
# prove is in docker/ubuntu24/README.md.
set -euo pipefail

IMAGE="ubuntu:24.04"
TAGS="v0.3.0 v0.9.0 v0.14.0 v0.17.0"
RUN_TESTS=1
while [ $# -gt 0 ]; do
  case "$1" in
    --image) IMAGE="$2"; shift 2 ;;
    --tags) TAGS="$2"; shift 2 ;;
    --no-tests) RUN_TESTS=0; shift ;;
    *) echo "unknown option $1" >&2; exit 2 ;;
  esac
done

REPO="$(cd "$(dirname "$0")/../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$WORK/tags"
for t in $TAGS; do
  if git -C "$REPO" rev-parse -q --verify "refs/tags/$t" >/dev/null; then
    mkdir -p "$WORK/tags/$t"
    git -C "$REPO" archive "$t" src | tar -x -C "$WORK/tags/$t"
  else
    echo "skipping unknown tag $t" >&2
  fi
done
NAME="rivetcore-ubuntu24-smoke-$$"
trap 'docker rm -f "$NAME" >/dev/null 2>&1 || true; rm -rf "$WORK"' EXIT

docker run --name "$NAME" --rm \
  -v "$REPO":/src:ro -v "$WORK/tags":/tags:ro \
  -e RUN_TESTS="$RUN_TESTS" -e TAGS="$(ls "$WORK/tags" | tr '\n' ' ')" \
  "$IMAGE" bash /src/docker/ubuntu24/inside.sh
