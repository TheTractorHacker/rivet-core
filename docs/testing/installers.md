# Installers on clean Ubuntu 24.04 (issue #36): test plan and what is proven

Real proof needs a VM per edition. What can be proven without one is automated in `docker/ubuntu24/smoke.sh`; the rest is a manual plan.

## Automated: `docker/ubuntu24/smoke.sh` (clean `ubuntu:24.04` container, apt, no host state)

Installs the packages an installer pulls in (`php-cli php-mysql php-zip php-xml php-mbstring composer mariadb-server redis-server poppler-utils`),
then proves: Ubuntu 24.04 is really what runs; `composer install` (with dev dependencies) works from a clean checkout and `--no-dev` resolves;
MariaDB 10.11 (Ubuntu's) and Redis start; every Core migration applies to an empty database, and a second run applies nothing; upgrading from old
tags (their own runner first, then this tree's) reaches a schema equal to a fresh install, keeps data and leaves the old runner working; the API
surface snapshot (tables included) matches; the full PHPUnit suite passes with poppler, MariaDB and Redis present. Output is a PROVED/FAILED line per step.
The last run's PROVED lines are quoted in the pull request that introduced the harness.

## Not provable in a container: needs a real VM

| Area | Why not | Manual check |
|---|---|---|
| systemd units (queue worker, Redis, MariaDB, php-fpm) | the container has no init | `systemctl enable --now`, reboot, units active |
| nginx and TLS (certbot) | no public DNS or port 80/443 | HTTPS answers, HTTP redirects, HSTS, upload size limits |
| `cron.d` entries and `www-data` ownership | no cron daemon, single user | jobs run as the right user; log files exist and are writable (a missing `/var/log` file silently stops a job) |
| php-fpm pools, `open_basedir`, file ownership | no fpm | Admin update runs as the web user against a tree it may write |
| firewall, fail2ban, unattended upgrades | host level | rules present and survive reboot |
| the editions' installer scripts themselves | live in the edition repositories | see below |

## Manual plan per edition (RivetIT, RivetMSP), on a fresh VM and on a VM at the previous release

1. Snapshot the VM. Fresh install: run the installer with the documented answers; sign in; run the smoke suite (`tests/smoke`); check `/health` readiness; confirm
   Core migration ids in `rivet_core_migrations` equal `CoreMigrations::all()`.
2. Upgrade: install the previous release, create data (a ticket, an audit event, a queued job), run the updater to the release candidate, rerun the smoke suite, verify the data
   survived and `MigrationRunner::pending()` is empty; run the updater a second time (must be a no-op).
3. Redis: with and without a password, with TLS, and with Redis stopped (the application must keep working; see docs/REDIS.md).
4. Rollback: restore the snapshot, pin the previous tag, confirm sign-in.
5. Record date, VM image, edition commit and Core tag; attach the smoke report.
