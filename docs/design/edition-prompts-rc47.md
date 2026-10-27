# Edition prompts for RivetCore 1.0.0-rc.47

Ready to paste. One block per chat. Everything here is edition work; Core already ships the pieces. The longer per-feature prompts these point at are in this folder:

- `rmm-data-store-phase4-edition-prompts.md` (data store card, Update-page tile, backup hook)
- `compliance-edition-prompts.md` (framework UI, BackupFactsProvider)
- `reporting-edition-prompts.md` (Power BI settings page and edition views)
- `security-baseline-edition-prompts.md` (baseline check, the edition-responsibility controls, trust page)

Order for each edition: Prompt 1 (adopt rc.47 and fix the CLI bootstrap) first, then the rest in any order. Always beta before production; dump the database before any migration.

---

## RivetIT chat

### R1. Adopt rc.47 and fix the CLI bootstrap

```
Adopt RivetCore 1.0.0-rc.47 in RivetIT. Work in a worktree, open a PR, do not deploy.

1. Pin rivet/rivet-core to ^1.0.0-rc.47 and run the usual adoption steps: composer.lock, ONE DB step that runs the new Core migrations (0046 compliance frameworks, 0047 reporting views, and any others since the current pin), mirror them in db.sql, regenerate nothing by hand.
2. Fix the CLI bootstrap used by the Core scripts (scripts/rmm-store-migrate.php, rmm-store-verify.php, rmm-postgres.php, rmm-postgres-backup/restore). Today CliBootstrap::load() needs the edition bootstrap to (a) include functions.php and (b) export the config variables ($config_* and friends) to $GLOBALS, because the bootstrap is included inside a function so its variables are not global. Fix it so the scripts run from the shell as www-data with no wrapper. Add a test that runs one script with --help and one dry run.
3. Tests/ is currently untracked in git for this repo: decide with the maintainer whether to track it; do not change that yourself.
4. Bug: EndpointPanelGateway::toUtc() shifts timestamps by +5 hours (the server timezone is not UTC). Fix it to treat the stored value as UTC (or convert from the real server timezone), and add a test with a non-UTC PHP/MySQL timezone.
Report: files changed, test results, anything that needs a server step.
```

### R2. Data store card, Update page, secure credentials

```
In RivetIT: (a) move the "RMM data store" card to its own tab under Settings; (b) show the PostgreSQL version on the Update page the same way MariaDB/MySQL is shown, using RivetCore\Rmm\Store\DataStoreInfo (server version, newest version we have tested, whether TimescaleDB is on); (c) make the PostgreSQL connection secure by default: the connection form must prefer a unix socket host (host starting with "/") with peer authentication and an empty password, offer TLS verify-full for remote hosts, seal any stored password with the current v3 format, and add a "rotate password" action that uses DataStoreSwitch. Never print or log a password. Use the prompts in vendor/rivet/rivet-core/docs/design/rmm-data-store-phase4-edition-prompts.md for the details. Tests for each.
```

### R3. Backups and compliance

```
In RivetIT: (a) include the RMM PostgreSQL store in the in-app backup: add a BackupHook that runs the Core dump, encrypts it (RivetCore\Crypto, AES-256-GCM) and writes it into the same backups folder and the same off-site destination as the app's archive (today there is no off-site destination: if none is configured, say so on the Backup page). Add a restore test that restores into a scratch database and runs rmm-store-verify. The old server cron that dumps to backups/rmm-postgres/ is a stopgap and should be removed once this lands. (b) Implement BackupFactsProviderInterface so the compliance assessments can see backup age, success and encryption, and build the compliance framework UI (pick/create frameworks per client) from vendor/rivet/rivet-core/docs/design/compliance-edition-prompts.md. (c) Add the Power BI settings page and the reporting role creation from vendor/rivet/rivet-core/docs/design/reporting-edition-prompts.md.
```

### R4. Security baseline

Paste Prompts 1, 2 and 3 from `security-baseline-edition-prompts.md`, replacing `<TAG>` with `1.0.0-rc.47`.

---

## RivetMSP chat

### M1. Staged catch-up to rc.47

```
RivetMSP is several RivetCore releases behind (rc.37 era). Catch up to 1.0.0-rc.47 in stages, one PR per stage, in a worktree, beta only, dumping the database before each migration: rc.37 -> rc.41 (RMM data store phases 1-3, panel API, fleet tokens), rc.42 -> rc.44 (PostgreSQL 18, DataStoreInfo, compliance frameworks, migration 0046), rc.45 -> rc.47 (reporting views, migration 0047, security audit, security baseline). Read vendor/rivet/rivet-core/UPGRADING.md and CHANGELOG.md for each step; run the golden replay and the full test suite after each stage; do not skip a migration. Also apply the CLI bootstrap fix described in the RivetIT prompt R1 item 2.
```

### M2. Secrets and repository hygiene

```
In RivetMSP: (1) the repository contains committed .sql backup files and at least one Azure AD client secret has been exposed: list every committed .sql/backup file and every secret-looking string WITHOUT printing values, remove the files from the tree, add them to .gitignore, and give me the rotation checklist (Azure AD app secret first). Do not rewrite git history without my approval. (2) Run vendor/rivet/rivet-core/scripts/secret-scan.php against this repo and fix what it finds.
```

### M3. The same feature work as RivetIT

Paste R2, R3 and R4 above (they apply unchanged), after M1 is merged and verified on beta.

---

## Server tasks I handle from the Core chat (no prompt needed)

Removing the leftover Postgres password files and `/tmp/rivet-run` on the beta box and mwa once the edition uses socket plus peer authentication, and deleting the old `/var/backups/rivetit-postgres` copies after the in-app backup covers Postgres.
