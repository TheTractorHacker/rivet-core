# Audit

## Overview

`RivetCore\Audit` is an append-only structured audit trail with a separate read side.

- **Owns:** the `audit_events` table, created by migration `0001_audit_events` (`Audit\Migration\Migration0001AuditEvents`). The statement is `CREATE TABLE IF NOT EXISTS`, so it is a no-op on RivetIT (which created the identical table in its own 2.6.51 migration) and creates the table on RivetMSP.
- **Write side:** `AuditService::log()`. It never reads, updates or deletes.
- **Read side:** `AuditReader` (filtered pages, chunked export, group and actor lists) and `AuditPage` (the value object a page returns).
- **Deleting old rows** is not done here; see [retention.md](retention.md).

Columns: `audit_id`, `event_type`, `actor_user_id`, `entity_type`, `entity_id`, `action`, `summary`, `metadata_json`, `ip_address`, `user_agent`, `request_id`, `created_at`.

## Contracts an edition must implement

| Interface | Methods | What Core expects |
|---|---|---|
| `Contracts\RequestContextInterface` | `ipAddress()`, `userAgent()`, `requestId()` | Each returns `?string` (null when unknown). Core never reads superglobals, so the edition reads `$_SERVER` and hands the facts in. `Support\NullRequestContext` returns null for all three (CLI and cron). |
| `Database\DatabaseInterface` | `fetchOne`, `fetchAll`, `execute`, `transaction` | Positional `?` placeholders; failures must surface as `DatabaseException`. See [../adapters.md](../adapters.md). |

Users are not part of Core. Rows carry `actor_user_id` only; join to the edition's users table to show names.

## Key classes

### AuditService (write)

```php
use RivetCore\Audit\AuditService;
use RivetCore\Support\NullRequestContext;

$audit = new AuditService($database, new NullRequestContext());
$audit->log(
    'settings.updated',   // event type; dotted, the part before the first dot is the "group"
    7,                    // actor user id, or null for the system
    'settings',           // entity type (nullable)
    'security',           // entity id (int|string|null, stored as a string)
    'update',             // action
    'Changed policy',     // summary (nullable)
    ['password' => 'x', 'old' => 1],   // metadata, stored as JSON
);
// metadata_json is stored as {"password":"[redacted]","old":1}
```

`afterLog` is the optional third constructor argument, a `\Closure` called after the row is written with `(eventType, actorUserId, entityType, entityId, action, summary, metadata)`. Editions use it to fan the event out to webhooks and automation rules:

```php
$audit = new AuditService($database, $request, function ($type, $actor, $entityType, $entityId, $action, $summary, $metadata): void {
    // dispatch to webhooks / automation here
});
```

### AuditReader and AuditPage (read)

```php
use RivetCore\Audit\AuditReader;

$reader = new AuditReader($database);
$page = $reader->page(['eventType' => 'settings', 'from' => '2026-10-01'], 1, 25);
// $page->rows (metadata decoded into 'metadata'), ->total, ->page, ->pages, ->perPage

foreach ($reader->iterate(['actorUserId' => 7], 5000) as $row) {
    // newest first, keyset-chunked; use for CSV export
}

$reader->groups();   // ['settings' => 12, 'auth' => 40, ...]
$reader->actors();   // [1, 7, 9]
```

Filters (all optional, AND-ed): `eventType` (exact, or a group prefix: `settings` matches `settings` and `settings.*`), `actorUserId` (> 0), `entityType`, `entityId`, `from`/`to` (`YYYY-MM-DD`, where `to` means end of that day, or `YYYY-MM-DD HH:MM:SS`; invalid values are ignored), `search` (free text over summary, event type, entity id, IP and metadata; `%`, `_` and `\` are escaped).

## Configuration

There are no settings keys. Behaviour is fixed by constants:

- `AuditReader::MAX_PER_PAGE = 200`, `DEFAULT_PER_PAGE = 50`, `DEFAULT_EXPORT_CAP = 50000`. `page()` clamps `perPage` to 1..200 and the page number into range; `iterate()` clamps `chunkSize` to 1..1000.
- Column clamps in `AuditService`: event type 100, entity type 100, entity id 64, action 50, summary 500, user agent 255, request id 64, IP 64 characters.

## How it fails

- `log()` throws whatever the database throws (for example `DatabaseException`). It does not catch it. Editions wrap the call in try/catch when auditing must never block the user action; this is the edition's responsibility, not Core's.
- Over-long values are clamped to the column width instead of failing under strict SQL.
- Metadata that cannot be JSON-encoded is replaced by `{"_error":"metadata could not be encoded"}` instead of throwing; invalid UTF-8 is substituted.
- `afterLog` is best effort: anything it throws is swallowed, and the audit row and the caller are unaffected.
- `AuditReader::decodeMetadata()` returns null for empty, invalid or non-array JSON, so one bad row cannot break a page.

## Security notes

- Metadata values under these keys (case-insensitive, any depth up to 8) are stored as `[redacted]`: `password`, `passwd`, `pwd`, `secret`, `client_secret`, `token`, `access_token`, `refresh_token`, `id_token`, `api_key`, `apikey`, `authorization`, `private_key`. This is a key-name list; do not put secrets under other names.
- The reader uses prepared parameters; the only interpolated SQL is integers that were clamped.
- IP, user agent and request id come from the injected context, never from the request directly.
- The `afterLog` payload is the unredacted metadata as passed to `log()`. A listener that forwards it (webhooks) must apply its own redaction.

## Used by

- **RivetIT** (`/var/www/mw-itflow.foleyit.com`): `src/Audit/AuditService.php` is a compatibility wrapper around `RivetCore\Audit\AuditService`; `mcp_server/ReadTools.php` builds an `AuditService` for the MCP tool pipeline; `admin/audit_trail.php` uses `AuditReader` (page view and CSV export via `iterate()`).
- **RivetMSP** (`/home/sysadmin/rivetmsp-beta`): `src/Core/CoreBridge.php` (`audit()`, with an `afterLog` listener) is called from `includes/event_bus.php`, the compliance admin pages and `admin/post/*`; `admin/audit_trail.php` uses `AuditReader`.
- Core itself: `Mcp\ToolPipeline` writes an audit row per tool call.

## Links

- CHANGELOG: 0.1.0 (`AuditService`, migration 0001), 0.15.1 (`afterLog` callback), 0.17.0 (`AuditReader`/`AuditPage` read side), 0.18.1 (clamping, redaction, encode marker). See [../../CHANGELOG.md](../../CHANGELOG.md).
- Retention of old rows: [retention.md](retention.md). Migrations: [migration.md](migration.md).
- Tests: `tests/Unit/AuditServiceTest.php`, `tests/Unit/AuditReaderTest.php`, `tests/Integration/AuditReaderTest.php`, `tests/Integration/MigrationAndAuditTest.php`.
