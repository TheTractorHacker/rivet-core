#!/usr/bin/env bash
# List pending security updates (read-only; never refreshes indexes).
have() { command -v "$1" >/dev/null 2>&1; }
echo "== Security updates available (Linux) =="
if have apt-get; then
  echo "Package manager: apt"
  list=$(apt-get -s upgrade 2>/dev/null | grep '^Inst ' | grep -i 'security')
  count=$(printf '%s' "$list" | grep -c .)
  echo "Security updates: $count"
  [ "$count" -gt 0 ] && printf '%s\n' "$list" | cut -c1-200 | head -n 100
  [ "$count" -gt 100 ] && echo "(truncated)"
  echo "(based on the local package index; run a refresh to see newer data)"
elif have dnf || have yum; then
  pm=dnf; have dnf || pm=yum
  echo "Package manager: $pm"
  out=$($pm -q updateinfo list security 2>/dev/null)
  list=$(printf '%s\n' "$out" | grep -E '/Sec\.|^[A-Za-z]+-[0-9]{4}:' )
  if [ -n "$list" ]; then
    echo "Security advisories: $(printf '%s\n' "$list" | grep -c .)"
    printf '%s\n' "$list" | head -n 100
  else
    $pm -q check-update --security >/dev/null 2>&1
    rc=$?
    if [ "$rc" -eq 100 ]; then echo "Security updates pending (details unavailable from updateinfo)"; else echo "Security advisories: 0"; fi
  fi
elif have zypper; then
  echo "Package manager: zypper"
  out=$(zypper --non-interactive --quiet list-patches --category security 2>/dev/null)
  list=$(printf '%s\n' "$out" | grep -E '\| *(needed|security)' )
  echo "Security patches needed: $(printf '%s\n' "$out" | grep -c -E '\| *needed')"
  printf '%s\n' "$list" | head -n 100
else
  echo "NOT AVAILABLE: no supported package manager (apt, dnf, yum, zypper) found"
fi
exit 0
