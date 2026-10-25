#!/usr/bin/env bash
# Samples `docker stats --no-stream` of the named containers every few seconds into a JSON-lines file, for scripts/rmm-store-load.php --docker-stats-file.
# Run on the HOST (the PHP container has no docker).  Usage: docker-sampler.sh FILE INTERVAL_S CONTAINER...   Stop it with SIGTERM.
set -u
file=${1:?file}; every=${2:?seconds}; shift 2
: >"$file"
while true; do
    docker stats --no-stream --format '{{json .}}' "$@" 2>/dev/null | while IFS= read -r line; do
        printf '%s\n' "$line" | python3 -c '
import json, re, sys
d = json.loads(sys.stdin.read())
def mib(s):
    m = re.match(r"([\d.]+)\s*([KMGT]?i?B)", s)
    f = {"B": 1 / 1048576, "KiB": 1 / 1024, "MiB": 1, "GiB": 1024, "TiB": 1048576, "kB": 1 / 1000, "MB": 1, "GB": 1000}
    return float(m.group(1)) * f.get(m.group(2), 1) if m else 0.0
print(json.dumps({"name": d["Name"], "cpu_pct": float(d["CPUPerc"].rstrip("%") or 0), "mem_mib": round(mib(d["MemUsage"].split("/")[0].strip()), 1), "block_io": d["BlockIO"]}))
' >>"$file"
    done
    sleep "$every"
done
