#!/usr/bin/env bash
# Vacuum the systemd journal. dry_run defaults to true.
dry_run="${dry_run:-true}"
keep_days="${keep_days:-14}"
case "$keep_days" in ''|*[!0-9]*) echo "invalid keep_days" >&2; exit 2;; esac
if [ "$keep_days" -lt 1 ] || [ "$keep_days" -gt 365 ]; then echo "keep_days out of range 1..365" >&2; exit 2; fi
echo "== Vacuum journal (Linux) =="
if ! command -v journalctl >/dev/null 2>&1; then echo "NOT AVAILABLE: journalctl is not installed"; exit 0; fi
before=$(journalctl --disk-usage 2>&1 | head -n 1)
echo "Before: $before"
if [ "$dry_run" = "true" ]; then
  echo "[DRY-RUN] would remove journal entries older than ${keep_days} days"
  exit 0
fi
if out=$(journalctl --vacuum-time="${keep_days}d" 2>&1); then
  printf '%s\n' "$out" | tail -n 3
  echo "After: $(journalctl --disk-usage 2>&1 | head -n 1)"
  echo "[DONE] vacuumed journal entries older than ${keep_days} days"
  exit 0
fi
printf '%s\n' "$out" | tail -n 3
echo "[SKIP] journal vacuum failed"
exit 3
