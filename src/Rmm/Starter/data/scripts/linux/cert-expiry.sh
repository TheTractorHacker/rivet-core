#!/usr/bin/env bash
# Certificate expiry check (read-only). Refuses private key files.
cert_file="${cert_file:-}"
if ! [[ "$cert_file" =~ ^/[A-Za-z0-9._/@+=:-]{1,250}$ ]] || [[ "$cert_file" == *..* ]]; then
  echo "invalid cert_file (absolute path, no '..')" >&2; exit 2
fi
echo "== Certificate expiry: $cert_file (Linux) =="
if ! command -v openssl >/dev/null 2>&1; then echo "NOT AVAILABLE: openssl is not installed"; exit 0; fi
if [ ! -f "$cert_file" ] || [ ! -r "$cert_file" ]; then echo "NOT AVAILABLE: file not found or not readable"; exit 0; fi
if grep -q "PRIVATE KEY" -- "$cert_file" 2>/dev/null; then
  echo "refused: file contains a private key; point to the certificate file only" >&2; exit 2
fi
if ! info=$(openssl x509 -noout -subject -issuer -enddate -in "$cert_file" 2>&1); then
  echo "NOT AVAILABLE: not a readable PEM certificate ($(printf '%s' "$info" | head -n 1 | cut -c1-120))"; exit 0
fi
printf '%s\n' "$info"
end=$(printf '%s\n' "$info" | sed -n 's/^notAfter=//p')
end_ts=$(date -d "$end" +%s 2>/dev/null)
if [ -n "$end_ts" ]; then
  days=$(((end_ts - $(date +%s)) / 86400))
  echo "Days left: $days"
  if [ "$days" -lt 0 ]; then echo "STATUS: EXPIRED"; elif [ "$days" -lt 30 ]; then echo "STATUS: EXPIRES WITHIN 30 DAYS"; else echo "STATUS: OK"; fi
fi
if openssl x509 -noout -checkend 2592000 -in "$cert_file" >/dev/null 2>&1; then echo "checkend 30 days: valid"; else echo "checkend 30 days: expires or expired within 30 days"; fi
exit 0
