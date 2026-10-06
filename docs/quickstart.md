# Quickstart

Install from the Git tag (rivet-core is not on Packagist):

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/TheTractorHacker/rivet-core.git", "no-api": true }],
  "require": { "rivet/rivet-core": "^0.19" }
}
```

In `0.x` a caret accepts only the same minor, so bump the constraint for every minor release.

## Wire the contracts every module needs

A runnable one-file edition is in [examples/minimal-edition.php](examples/minimal-edition.php) (run it against a scratch database); its
database adapter, [examples/MysqliDatabaseAdapter.php](examples/MysqliDatabaseAdapter.php), is ready to copy. The essentials:

<!-- run -->
```php
require 'docs/examples/MysqliDatabaseAdapter.php';   // path relative to the repository root, for this sample

use Example\Edition\MysqliDatabaseAdapter;         // implements RivetCore\Database\DatabaseInterface
use RivetCore\Migration\{CoreMigrations, MigrationRunner};
use RivetCore\Support\SystemClock;

$db    = new MysqliDatabaseAdapter($mysqli);
$clock = new SystemClock();

// Run from your own updater, after your own migrations. Safe to run repeatedly; concurrent runs take turns.
$applied = (new MigrationRunner($db, CoreMigrations::all(), $clock))->run();
echo count($applied), " migration(s) applied now\n";
```

Then build only the modules you use, for example audit:

<!-- run -->
```php
use RivetCore\Audit\AuditService;

$audit = new AuditService($db, new RivetCore\Support\NullRequestContext());   // in a web edition: your RequestContextInterface
$audit->log('settings.edit', 7, 'settings', 1, 'edit', 'Changed security settings');
echo "logged\n";
```

Prove your adapters with the [conformance kit](adapters.md#conformance-kit) before you ship.

## Conventions

- Every module is off by default in your application; switch it on per instance.
- Core never reads superglobals or your functions. Anything it needs from you is an interface (see
  [writing an edition adapter](adapters.md)).
- Redis is optional. Helpers fail open: if Redis is down the work simply goes ahead.
- Migrations only touch Core-owned tables and are recorded in `rivet_core_migrations`.
