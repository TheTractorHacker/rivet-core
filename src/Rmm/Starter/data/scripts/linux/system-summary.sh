#!/usr/bin/env bash
# Read-only system summary.
have() { command -v "$1" >/dev/null 2>&1; }
echo "== System summary (Linux) =="
echo "Hostname:       $(hostname 2>/dev/null || cat /proc/sys/kernel/hostname 2>/dev/null)"
if [ -r /etc/os-release ]; then
  # shellcheck disable=SC1091
  osname=$(. /etc/os-release 2>/dev/null; echo "${PRETTY_NAME:-${NAME:-unknown}}")
  echo "OS:             $osname"
fi
echo "Kernel:         $(uname -sr 2>/dev/null)"
echo "Architecture:   $(uname -m 2>/dev/null)"
if [ -r /proc/uptime ]; then
  up=$(cut -d. -f1 /proc/uptime)
  echo "Uptime:         $((up / 86400))d $((up % 86400 / 3600))h $((up % 3600 / 60))m"
fi
if have uptime && boot=$(uptime -s 2>/dev/null); then echo "Last boot:      $boot"; fi
[ -r /proc/loadavg ] && echo "Load average:   $(cut -d' ' -f1-3 /proc/loadavg)"
virt=""
if have systemd-detect-virt; then virt=$(systemd-detect-virt 2>/dev/null); fi
if [ -z "$virt" ] || [ "$virt" = "none" ]; then
  if [ -r /sys/class/dmi/id/product_name ]; then virt="$(cat /sys/class/dmi/id/product_name 2>/dev/null) (dmi)"; else virt="none/unknown"; fi
fi
echo "Virtualization: $virt"
if [ -r /proc/cpuinfo ]; then
  model=$(grep -m1 -i 'model name' /proc/cpuinfo | cut -d: -f2- | sed 's/^ *//')
  cores=$(grep -c '^processor' /proc/cpuinfo)
  echo "CPU:            ${model:-unknown} (${cores} logical)"
fi
if [ -r /proc/meminfo ]; then
  mt=$(awk '/^MemTotal:/{print int($2/1024)}' /proc/meminfo)
  ma=$(awk '/^MemAvailable:/{print int($2/1024)}' /proc/meminfo)
  st=$(awk '/^SwapTotal:/{print int($2/1024)}' /proc/meminfo)
  echo "Memory:         ${mt:-?} MB total, ${ma:-?} MB available, swap ${st:-0} MB"
fi
exit 0
