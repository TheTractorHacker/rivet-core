#!/usr/bin/env bash
# Privileged account audit (read-only; never prints hashes).
echo "== Privileged accounts audit (Linux) =="
echo "-- Accounts with uid 0 --"
awk -F: '$3==0 {print $1}' /etc/passwd 2>/dev/null
echo
echo "-- Members of sudo / wheel / admin groups --"
for g in sudo wheel admin; do
  line=$(getent group "$g" 2>/dev/null)
  [ -n "$line" ] && echo "$g: $(printf '%s' "$line" | cut -d: -f4)"
done
echo
echo "-- sudoers.d files --"
if [ -d /etc/sudoers.d ]; then find /etc/sudoers.d -maxdepth 1 -type f -printf "%f\n" 2>/dev/null | head -n 50; else echo "none"; fi
echo
echo "-- sudoers entries (non-comment) --"
shown=0
for f in /etc/sudoers /etc/sudoers.d/*; do
  [ -f "$f" ] && [ -r "$f" ] || continue
  while IFS= read -r l; do
    shown=1
    case "$l" in *NOPASSWD*) echo "[NOPASSWD] $f: $l";; *) echo "$f: $l";; esac
  done < <(grep -v -E '^[[:space:]]*(#|$)' "$f" 2>/dev/null | head -n 60)
done
[ "$shown" -eq 0 ] && echo "none readable"
echo
echo "-- Users with login shells --"
awk -F: '$7 !~ /(nologin|false|sync|shutdown|halt)$/ && $7 != "" {print $1 " (uid " $3 ", " $7 ")"}' /etc/passwd 2>/dev/null | head -n 50
echo
echo "-- Accounts with an empty password field (names only) --"
if [ -r /etc/shadow ]; then
  empty=$(awk -F: '$2=="" {print $1}' /etc/shadow)
  if [ -n "$empty" ]; then printf '%s\n' "$empty"; else echo "none"; fi
else
  echo "NOT AVAILABLE: /etc/shadow is not readable"
fi
exit 0
