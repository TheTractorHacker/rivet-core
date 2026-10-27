#!/usr/bin/env bash
# Read-only audit of the effective sshd configuration.
have() { command -v "$1" >/dev/null 2>&1; }
echo "== SSH server configuration audit (Linux) =="
sshd_bin=""
for c in sshd /usr/sbin/sshd /usr/local/sbin/sshd; do
  if have "$c" || [ -x "$c" ]; then sshd_bin="$c"; break; fi
done
if [ -z "$sshd_bin" ] && [ ! -r /etc/ssh/sshd_config ]; then
  echo "NOT AVAILABLE: OpenSSH server is not installed"; exit 0
fi
cfg=""
source_note=""
if [ -n "$sshd_bin" ]; then
  cfg=$("$sshd_bin" -T 2>/dev/null)
  [ -n "$cfg" ] && source_note="sshd -T (effective configuration)"
fi
if [ -z "$cfg" ]; then
  files=()
  if [ -d /etc/ssh/sshd_config.d ]; then
    for f in /etc/ssh/sshd_config.d/*.conf; do [ -r "$f" ] && files+=("$f"); done
  fi
  [ -r /etc/ssh/sshd_config ] && files+=(/etc/ssh/sshd_config)
  if [ "${#files[@]}" -eq 0 ]; then echo "NOT AVAILABLE: no readable sshd configuration"; exit 0; fi
  cfg=$(for f in "${files[@]}"; do
    awk '{ sub(/^[ \t]+/,""); if (tolower($1)=="match") exit; if ($0 !~ /^#/ && NF>=2) print tolower($1) " " substr($0, length($1)+2) }' "$f"
  done)
  source_note="parsed config files (first value wins; Match blocks ignored; defaults assumed when unset)"
fi
echo "Source: $source_note"
get() { printf '%s\n' "$cfg" | awk -v k="$1" '$1==k { sub(/^[^ ]+ /,""); print; exit }'; }
review=0
row() { # key default pass-regex
  local key="$1" def="$2" re="$3" v
  v=$(get "$key"); [ -n "$v" ] || v="$def"
  if [[ "$v" =~ $re ]]; then printf '%-24s %-22s PASS\n' "$key" "$v"; else printf '%-24s %-22s REVIEW\n' "$key" "$v"; review=$((review + 1)); fi
}
row permitrootlogin prohibit-password '^(no|prohibit-password|without-password|forced-commands-only)$'
row passwordauthentication yes '^no$'
row permitemptypasswords no '^no$'
row pubkeyauthentication yes '^yes$'
row x11forwarding no '^no$'
row maxauthtries 6 '^[1-4]$'
row clientaliveinterval 0 '^([1-9][0-9]{0,3})$'
row usepam yes '^yes$'
proto=$(get protocol)
if [ -n "$proto" ]; then
  if [ "$proto" = "2" ]; then printf '%-24s %-22s PASS\n' protocol "$proto"; else printf '%-24s %-22s REVIEW\n' protocol "$proto"; review=$((review + 1)); fi
else
  printf '%-24s %-22s PASS\n' protocol "2 (implicit)"
fi
for k in allowusers allowgroups denyusers denygroups; do
  v=$(get "$k")
  [ -n "$v" ] && printf '%-24s %s\n' "$k" "$v"
done
if [ -z "$(get allowusers)$(get allowgroups)" ]; then echo "Note: no AllowUsers/AllowGroups restriction set (REVIEW if the host is internet-facing)"; fi
echo "Items to review: $review"
exit 0
