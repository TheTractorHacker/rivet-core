#!/usr/bin/env bash
# Runs N REAL Linux endpoint agents (the genuine rivetit-agent binary) as small containers, to cover what the simulator cannot:
# the actual enroll, check-in, update and job code paths. Use 20 to 50 of these next to a 5,000 to 10,000 device rmm-sim run.
#
# One image is used for every container (pulled once, then reused from the local cache) and the agent binary is mounted read-only, so
# N containers cost one image and N small processes. Each container gets a tmpfs state directory, a memory cap and a CPU cap.
#
#   real-agents.sh up   --server https://rmm-test.example --token-file /root/enroll.token [--ca ca.pem] [-n 25] [--agent PATH] [--image IMG]
#   real-agents.sh status
#   real-agents.sh down
#
# TOOLING ONLY. Use a test install. Enrollment tokens may leave a device "pending approval": approve the batch in the UI once, or use
# the policy that auto-approves for the test install. Not run against a live server yet.

set -u
set -o pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cmd="${1:-}"; shift || true
N=25 SERVER="" TOKEN_FILE="" CA="" IMAGE="debian:stable-slim" AGENT="$SCRIPT_DIR/../../endpoint-agent/dist/rivetit-agent-linux-amd64" PREFIX="rmm-real-agent"
while [ $# -gt 0 ]; do
    case "$1" in
        -n) N="$2"; shift 2 ;;
        --server) SERVER="$2"; shift 2 ;;
        --token-file) TOKEN_FILE="$2"; shift 2 ;;
        --ca) CA="$2"; shift 2 ;;
        --image) IMAGE="$2"; shift 2 ;;
        --agent) AGENT="$2"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done

case "$cmd" in
    up)
        [ -n "$SERVER" ] && [ -f "$TOKEN_FILE" ] && [ -x "$AGENT" ] || { echo "need --server, a readable --token-file and an executable agent binary ($AGENT)" >&2; exit 2; }
        case "$SERVER" in *mwa.foleyit.com*) echo "refusing: that is the production install" >&2; exit 2 ;; esac
        docker image inspect "$IMAGE" >/dev/null 2>&1 || docker pull -q "$IMAGE" >/dev/null || { echo "could not pull $IMAGE" >&2; exit 1; }
        extra=(); [ -n "$CA" ] && extra=(-v "$(readlink -f "$CA"):/ca.pem:ro" -e AGENT_CA=/ca.pem)
        for i in $(seq 1 "$N"); do
            docker run -d --name "$PREFIX-$i" --hostname "load-agent-$i" --memory 64m --cpus 0.2 --pids-limit 64 --read-only \
                --tmpfs /var/lib/rivetit-agent:rw,mode=700,size=16m --tmpfs /tmp:rw,size=8m \
                -v "$(readlink -f "$AGENT"):/agent:ro" -v "$(readlink -f "$TOKEN_FILE"):/token:ro" "${extra[@]}" \
                -e SERVER="$SERVER" "$IMAGE" /bin/sh -c \
                '/agent enroll --server "$SERVER" --token-file /token ${AGENT_CA:+--ca "$AGENT_CA"} && exec /agent run --no-update' >/dev/null \
                && echo "started $PREFIX-$i" || echo "FAILED to start $PREFIX-$i" >&2
        done ;;
    status)
        docker ps -a --filter "name=$PREFIX-" --format '{{.Names}}\t{{.Status}}' | sort -V
        docker stats --no-stream --format '{{.Name}}\t{{.CPUPerc}}\t{{.MemUsage}}' $(docker ps -q --filter "name=$PREFIX-") 2>/dev/null | sort -V | head -50 ;;
    down)
        ids=$(docker ps -aq --filter "name=$PREFIX-"); [ -n "$ids" ] && docker rm -f $ids >/dev/null && echo "removed $(echo "$ids" | wc -l) containers" || echo "none running" ;;
    *) sed -n 2,13p "$0"; exit 2 ;;
esac
