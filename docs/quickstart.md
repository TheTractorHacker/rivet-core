# Quickstart

Install from the Git tag (rivet-core is not on Packagist):

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core.git", "no-api": true }],
  "require": { "rivet/rivet-core": "^0.17" }
}
```

In `0.x` a caret accepts only the same minor, so bump the constraint for every minor release.

## Wire the three contracts every module needs

```php
use RivetCore\Migration\{CoreMigrations, MigrationRunner};
use RivetCore\Support\SystemClock;

$db    = new YourMysqliDatabaseAdapter($mysqli);   // implements RivetCore\Database\DatabaseInterface
$clock = new SystemClock();

// Run from your own updater, after your own migrations. Safe to run repeatedly; concurrent runs take turns.
(new MigrationRunner($db, CoreMigrations::all(), $clock))->run();
```

Then build only the modules you use, for example audit:

```php
$audit = new RivetCore\Audit\AuditService($db, new YourRequestContext());
$audit->log('settings.edit', $userId, 'settings', 1, 'edit', 'Changed security settings');
```

## Conventions

- Every module is off by default in your application; switch it on per instance.
- Core never reads superglobals or your functions. Anything it needs from you is an interface (see
  [writing an edition adapter](adapters.md)).
- Redis is optional. Helpers fail open: if Redis is down the work simply goes ahead.
- Migrations only touch Core-owned tables and are recorded in `rivet_core_migrations`.
