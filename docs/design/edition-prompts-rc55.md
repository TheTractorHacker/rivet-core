# Edition prompts: RivetCore rc.55 and the new PHP build

Ready to paste. Context: since 2026-10-29 both servers run PHP 8.5.12 built from source in `/opt/php-8.5.12` (Ubuntu 26.04 only ships 8.5.4). The FPM unit is `php8.5.12-fpm.service` on the same socket `/run/php/php8.5-fpm.sock`; apt no longer manages PHP.

## RivetIT chat

```
Two small changes in RivetIT. Worktree, one PR, do not deploy.

1. deploy/update.sh reload_php_fpm() derives the unit name as php<major.minor>-fpm. Our servers now run a source-built PHP whose unit is php<full version>-fpm (for example php8.5.12-fpm), so the script prints "php8.5-fpm is not active; skipping reload" and opcache keeps serving old bytecode. Fix it: try php<full version>-fpm first (from PHP_VERSION), then php<major.minor>-fpm, and reload whichever is active. If none is active, FAIL LOUDLY instead of warning. Apply the same to any other script that builds a php-fpm unit name (grep deploy/ for "php\${" and "fpm"), including harden.sh and the health check. Add a test with a stubbed systemctl for both names.
2. Pin rivet/rivet-core to ^1.0.0-rc.55 if it is not already (it is on origin/beta), and confirm the Update page shows the PHP version correctly for a source build.
Report files changed and test results.
```

## RivetMSP chat

```
Three small changes in RivetMSP. Worktree, one PR, do not deploy.

1. Pin rivet/rivet-core to 1.0.0-rc.55 (rc.55 is UI-only: table hover and the patches table). Run the shared-assets sync so assets/ui and its manifest match, and the golden replay plus the full test suite.
2. If this edition has a deploy/update.sh or any script that derives a php-fpm unit name from major.minor, apply the same fix as RivetIT: the unit may be php<full version>-fpm (php8.5.12-fpm). Fail loudly when no unit is active.
3. Confirm the Admin Update page works on a source-built PHP (no apt package metadata).
Report files changed and test results.
```

## Pressure test, when you are ready

- Simulator: `scripts/rmm-load/pressure-test.sh` (500 -> 2,000 -> 5,000 -> 10,000 simulated devices, one process, one multi-use token).
- A few real agents beside it: `scripts/rmm-load/real-agents.sh up -n 25 --server https://<test install> --token-file <file>`.
- Test install only. Read `docs/rmm/SOAK_RUNBOOK.md` sections 3.1 and 7 first.
