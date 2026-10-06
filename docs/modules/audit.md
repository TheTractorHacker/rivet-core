# Audit

`RivetCore\Audit`: an append-only structured audit trail (`AuditService`) and its read side (`AuditReader`, `AuditPage`).

## What it owns

Table `audit_events` (migration 0001):

| Column | Notes |
|---|---|
| `audit_id` | auto-increment primary key |
| `event_type` | up to 100 characters, dotted (`settings.edit`); the part before the first dot is the "group" |
| `actor_user_id` | nullable: system actors have none |
| `entity_type`, `entity_id` | what was touched; `entity_id` is text (up to 64) so non-numeric ids fit |
| `action` | up to 50 characters |
| `summary` | up to 500 characters of human text |
| `metadata_json` | extra structured data; secrets under common key names are stored as `[redacted]` |
| `ip_address`, `user_agent`, `request_id` | from the edition's `RequestContextInterface` |
| `created_at` | database clock |

Indexes: `(event_type, created_at)`, `(entity_type, entity_id)`, `(actor_user_id)`. There is no index that starts with
`created_at` alone, so date-only filters and retention scans read the table; see [PERFORMANCE.md](../PERFORMANCE.md).

## You supply

A `RequestContextInterface` (use `Support\NullRequestContext` in CLI and cron). Optionally an `$afterLog` callback to fan events
out to webhooks and automation; it cannot fail or slow the write.

## Flags

None. The edition decides whether to call it.

## Use it

<!-- run -->
```php
use RivetCore\Audit\{AuditReader, AuditService};
use RivetCore\Support\NullRequestContext;

$audit = new AuditService($db, new NullRequestContext());
$audit->log('settings.edit', 7, 'settings', 1, 'edit', 'Changed security settings', ['field' => 'session_timeout', 'api_key' => 'will be stored as [redacted]']);

$page = (new AuditReader($db))->page(['eventType' => 'settings', 'actorUserId' => 7], 1, 50);
echo $page->total, " event(s); first: ", $page->rows[0]['summary'], "\n";
```

## How it fails

- `log()` throws `DatabaseException` when the insert fails. Editions wrap it in try/catch where auditing must never block the user
  (both do). Over-long fields are clamped to their column width; metadata that cannot be JSON-encoded is replaced by a marker, not an error.
- `AuditReader` uses prepared parameters; the only text it interpolates is integers it has clamped. `page()` clamps the page number
  and `perPage` (max 200), so an out-of-range request returns the last page rather than an error.
- Nothing in Core updates or deletes audit rows except [Retention](retention.md).

Tamper-evidence (hash chaining) is not provided; it is a post-1.0 item in the roadmap.
