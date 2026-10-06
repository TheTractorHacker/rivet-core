# Edition checklist: what to do for every Core release

For the maintainers of an application that embeds RivetCore (RivetIT, RivetMSP). Follow it for every tag, patch releases included.
The one-off 0.x to 1.0 steps are in [UPGRADING.md](../UPGRADING.md); the compatibility rules are in
[ADR-004](architecture/ADR-004-versioning-and-compatibility.md).

## Before you change anything

- [ ] Read the `CHANGELOG.md` entries between your current pin and the target tag. Look for **Deprecated**, **Changed**, new
      migrations and anything that mentions a contract (an interface you implement).
- [ ] Note the current pin and keep the previous `composer.lock` (a branch or file, not a tag on a commit production runs) as the rollback pointer.
- [ ] Take a database backup if the release adds a migration (the changelog says so; `ls src/*/Migration` shows the new class).

## 1. Bump the pin

- [ ] Constraint: `^1.0` already accepts every 1.x; run `composer update rivet/rivet-core` and check `composer.lock` now names the new tag.
      (In 0.x the constraint itself must be bumped, for example `^0.21` to `^0.22`.)
- [ ] If the edition commits `vendor/` (RivetIT and RivetMSP do for some paths), commit the regenerated vendor files and autoload maps in the
      same change. A new Core dependency (for example `psr/log` in 0.17) shows up here.
- [ ] `composer validate` and `composer audit` pass.

## 2. Run the migrations

- [ ] The updater calls `MigrationRunner::run()` with `CoreMigrations::all()` (see [UPGRADING.md](../UPGRADING.md#how-an-editions-updater-calls-the-runner)),
      guarded by `class_exists`, and advances the edition's own database version only afterwards. If the release adds a
      migration, the edition needs a new database version step that calls it (even though `run()` is idempotent), so installations
      that are already at the previous version upgrade.
- [ ] Catch the "another update is running" exception from the runner and show it as a message, not a fatal error.
- [ ] On a scratch copy: `status()` lists every id as applied; run the updater twice; the second run applies nothing.

## 3. Keep `db.sql` in step

- [ ] A fresh install must equal an upgraded one. Append the new migration ids to the `INSERT INTO rivet_core_migrations` row list in the
      edition's `db.sql`, and add the DDL of any new Core-owned table or column (a migration that creates a table is not run on a fresh
      install if the id is recorded without the table existing, so add both or neither).
- [ ] Regenerate `db.sql` from a database that has run the updater, not by hand, and check the collation and row-size traps documented in the edition's
      release procedure. Diff against the previous `db.sql`: only the new objects should differ.
- [ ] Build a scratch database from `db.sql` and run the migration runner against it: it must report nothing pending that you did not expect.

## 4. Run the conformance kit

- [ ] The edition's `DatabaseInterface` adapter test extends `RivetCore\Testing\DatabaseContractTestCase` and passes against a scratch
      database (never production data). Any further kit cases shipped in `RivetCore\Testing` ([ADR-009](architecture/ADR-009-test-helpers-in-package.md)) run in the same CI job.
- [ ] A kit failure after a Core update is an adapter bug or a Core regression; do not edit the kit to make it pass, open an issue.
- [ ] The edition's regression scripts run against a scratch database built from `db.sql`, and against a schema-only copy of the live database.

## 5. Code changes

- [ ] Replace uses of anything newly `@deprecated` (the changelog lists them; `grep -rn "<class or method>"` in the edition).
- [ ] If an interface you implement gained a companion interface, implement it (a new capability never becomes a new method on an existing interface in 1.x).
- [ ] New modules or features stay **off by default**: add the settings column or flag, default 0, and gate each use of the module.
- [ ] Do not reference Core internals (`@internal` classes, private constants, table internals beyond what the module page lists).

## 6. Roll out

- [ ] Beta first: update, health endpoint, sign in, one flow per module in use (audit view, a webhook delivery, a queued job, retention preview, and so on).
- [ ] Production: fresh dump and a rollback branch, then the project's updater; verify health and sign-in again.
- [ ] Watch the logs and one full cycle of the hourly cron.

## Rollback

Restore the database backup **and** the previous pin together ([UPGRADING.md](../UPGRADING.md#rollback)). Core migrations have no `down()`.

## Per-release record (copy into the edition's release notes)

```
Core: <previous tag> -> <new tag>
Migrations added: <ids or none>   Applied on beta: <date>   Applied on production: <date>
Deprecated APIs touched: <none or list>
Conformance kit: pass (<run id or date>)   db.sql regenerated: yes/no
Rollback pointer: <branch or lock file>
```
