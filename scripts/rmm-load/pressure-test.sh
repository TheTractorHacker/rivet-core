#!/usr/bin/env bash
# Staged RMM pressure test: ramps simulated devices 500 -> 2,000 -> 5,000 -> 10,000 against ONE test install, one soak.sh run per
# stage, and stops at the first stage that fails. One process simulates every device (endpoint-agent/cmd/rmm-sim): no container per
# device, expected to be a few hundred MB of RAM for 10,000 devices (an estimate, not measured yet). It enrolls them all with a single multi-use token, so nothing needs approving.
#
# TOOLING ONLY, like soak.sh. Never point it at production: it creates thousands of fake devices that you then have to delete.
# Read docs/rmm/SOAK_RUNBOOK.md section 3.1 first (the single source address trap) and section 7 (pre-flight).
#
#   pressure-test.sh --url https://rmm-test.example/api/v1/ --token rvte1.... --out /var/soak/p1 \
#       [--stage-minutes 30] [--interval 300s] [--stages "500 2000 5000 10000"] [--soak-extra "ARGS passed to soak.sh"]
#
# The simulator host can be a very small VM (start with 2 vCPU and 2 GB RAM and watch it; not measured yet); it must be able to
# reach the URL over https with a certificate it trusts. To use two simulator hosts, give each host its own token and half the stages
# ("--stages 2500" on both) and start them together.

set -u
set -o pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

URL="" TOKEN="${RMM_SIM_TOKEN:-}" OUT="" STAGE_MIN=30 INTERVAL="300s" STAGES="500 2000 5000 10000" EXTRA=""
while [ $# -gt 0 ]; do
    case "$1" in
        --url) URL="$2"; shift 2 ;;
        --token) TOKEN="$2"; shift 2 ;;
        --out) OUT="$2"; shift 2 ;;
        --stage-minutes) STAGE_MIN="$2"; shift 2 ;;
        --interval) INTERVAL="$2"; shift 2 ;;
        --stages) STAGES="$2"; shift 2 ;;
        --soak-extra) EXTRA="$2"; shift 2 ;;
        -h|--help) sed -n 2,16p "$0"; exit 0 ;;
        *) echo "unknown option: $1" >&2; exit 2 ;;
    esac
done
[ -n "$URL" ] && [ -n "$TOKEN" ] && [ -n "$OUT" ] || { echo "need --url, --token and --out (see --help)" >&2; exit 2; }
case "$URL" in
    *foleyit.com*mwa*|*mwa.foleyit.com*) echo "refusing: that looks like the production install" >&2; exit 2 ;;
esac
[ -e "$OUT" ] && [ -n "$(ls -A "$OUT" 2>/dev/null)" ] && { echo "--out must not exist or be empty" >&2; exit 2; }
mkdir -p "$OUT"

# Build the simulator once (uses a golang container if the host has no Go; the image is pulled once and reused).
SIM="$SCRIPT_DIR/../../endpoint-agent/dist/rmm-sim"
if [ ! -x "$SIM" ]; then
    echo "building rmm-sim ..."
    (cd "$SCRIPT_DIR/../../endpoint-agent" && make sim) || { echo "could not build rmm-sim" >&2; exit 1; }
fi

echo "stages: $STAGES   ${STAGE_MIN} min each   interval ${INTERVAL}" | tee "$OUT/plan.txt"
for n in $STAGES; do
    echo; echo "=== stage: $n devices ($(date -u +%FT%TZ)) ==="
    # shellcheck disable=SC2086
    if ! RMM_SIM_TOKEN="$TOKEN" "$SCRIPT_DIR/soak.sh" --url "$URL" --devices "$n" --minutes "$STAGE_MIN" --interval "$INTERVAL" \
            --sim "$SIM" --out "$OUT/stage-$n" $EXTRA; then
        echo "STOP: stage $n failed or did not finish; see $OUT/stage-$n. Do not go to a larger stage." | tee -a "$OUT/plan.txt"
        exit 1
    fi
    echo "stage $n finished; report: $OUT/stage-$n" | tee -a "$OUT/plan.txt"
done
echo; echo "All stages ran. Every stage under 24 h or under 10,000 devices is a SMOKE RUN, not the validation of #87."
