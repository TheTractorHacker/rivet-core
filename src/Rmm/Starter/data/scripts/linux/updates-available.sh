#!/usr/bin/env bash
# List pending package updates (read-only; never refreshes indexes).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Updates available (Linux) =="
if have apt-get && have apt; then
  echo "Package manager: apt"
  newest=$(find /var/lib/apt/lists -maxdepth 1 -type f -name '*Packages*' -printf '%T@\n' 2>/dev/null | sort -n | tail -n 1 | cut -d. -f1)
  if [ -n "$newest" ]; then
    now=$(date +%s)
    echo "Package index last refreshed: $(date -d "@$newest" '+%Y-%m-%d %H:%M' 2>/dev/null) ($(((now - newest) / 3600)) hours ago)"
  else
    echo "Package index: no lists present (the lists were never refreshed); results may be empty"
  fi
  if [ -f /var/lib/apt/periodic/update-success-stamp ]; then
    echo "Last successful periodic update: $(date -r /var/lib/apt/periodic/update-success-stamp '+%Y-%m-%d %H:%M' 2>/dev/null)"
  fi
  list=$(apt list --upgradable 2>/dev/null | grep -v '^Listing')
  count=$(printf '%s' "$list" | grep -c .)
  echo "Upgradable packages: $count"
  [ "$count" -gt 0 ] && printf '%s\n' "$list" | head -n 100
  [ "$count" -gt 100 ] && echo "(truncated)"
elif have dnf || have yum; then
  pm=dnf; have dnf || pm=yum
  echo "Package manager: $pm"
  out=$($pm -q check-update 2>/dev/null)
  rc=$?
  if [ "$rc" -eq 100 ]; then
    list=$(printf '%s\n' "$out" | awk 'NF>=3 && $1 !~ /^(Obsoleting|Security:)/ && $1 ~ /\./')
    echo "Upgradable packages: $(printf '%s\n' "$list" | grep -c .)"
    printf '%s\n' "$list" | head -n 100
  elif [ "$rc" -eq 0 ]; then
    echo "Upgradable packages: 0"
  else
    echo "NOTE: $pm check-update returned $rc (repository metadata may be unreachable)"
  fi
elif have zypper; then
  echo "Package manager: zypper"
  out=$(zypper --non-interactive --quiet list-updates 2>/dev/null)
  list=$(printf '%s\n' "$out" | grep -E '^v ' )
  echo "Upgradable packages: $(printf '%s\n' "$list" | grep -c .)"
  printf '%s\n' "$list" | head -n 100
else
  echo "NOT AVAILABLE: no supported package manager (apt, dnf, yum, zypper) found"
fi
exit 0
