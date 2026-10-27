# Automation

Namespace `RivetCore\Automation`. Event automation: "when event X happens and these fields match, do action Y".
Core matches, validates and runs one rule's action through handlers; the edition decides what an action actually does.

## Overview

One Core-owned table, `automation_rules` (migration `0006_automation_rules`; RivetIT already had the identical table
from its own 2.6.63 migration, so the `IF NOT EXISTS` is a no-op there): `rule_id`, `name`, `trigger_event` (varchar 150),
`condition_json`, `action_type` (`create_ticket`, `send_webhook`, `notify_user`), `action_config_json`, `is_enabled`,
`created_at`, with an index on `(trigger_event, is_enabled)`.

Core never creates tickets, sends webhooks or notifies users itself, and it does not log that a rule ran.

## Contracts an edition must implement

There are no interfaces. The contract is the handler map passed to `AutomationExecutor::execute()`:

```
array<string, callable(array $config, array $context, array $rule): (string|null)>
```

- Keys are action types (`AutomationRuleStore::ACTIONS`: `create_ticket`, `send_webhook`, `notify_user`).
- `$config` is the decoded `action_config_json` with `{placeholders}` already filled from the event.
- `$context` is the flat event context (`EventContext::flatten()`); `$rule` is the full row.
- Return a short result message (`null` becomes `done`); throw to report a failure. Handlers should be idempotent
  because the edition may run them from a retried job.

The edition also supplies a `DatabaseInterface`, decides when to call the evaluator (usually from its event bus),
and records the outcome (both editions write an `automation.rule_fired` audit row).

## Key classes

### EventContext

`EventContext::flatten(array $data): array<string,string>` turns an event payload into the flat map conditions compare
against. Nested maps become dotted keys (to depth 4), scalars become strings, booleans become `1`/`0`, nulls and lists
are dropped. The leaf name is also added when it does not collide, so `backup.name` is reachable as `name`.

### AutomationRuleEvaluator

`new AutomationRuleEvaluator($db)`. `findMatchingRules($eventType, $context): list<array>` returns enabled rules whose
`trigger_event` equals the event and whose conditions are all satisfied. `conditionsMatch($conditionJson, $context)`:
no conditions (null, empty, `{}`) always match; conditions are AND-ed string equality; a key missing from the context never
matches; malformed JSON never matches. The trigger is an exact string match (no wildcards).

### AutomationRuleStore

`new AutomationRuleStore($db)`: `all()`, `find($id)`, `save($id, $name, $triggerEvent, $conditions, $actionType, $config,
$enabled): int` (insert when `$id` is null, returns the id), `setEnabled($id, $enabled)`, `delete($id)`. `save()`
validates and throws `InvalidArgumentException` with a message safe to show an administrator: name 1-200 characters,
trigger matching `^[a-z0-9_.]{1,150}$`, known action, condition field names `^[A-Za-z0-9_.]{1,100}$` with scalar values up
to 200 characters, and per-action config:

| Action | Config kept |
|---|---|
| `create_ticket` | `subject` (required, 500), `details` (5000), `priority` (Low, Medium, High; default Low), `client_id` |
| `send_webhook` | `url` (http or https with a host, 500), `secret` (200) |
| `notify_user` | `message` (required, 1000), `user_id` (0 means everyone or the edition default) |

### AutomationExecutor

`(new AutomationExecutor())->execute($rule, $context, $handlers): array{rule_id, ok, message}`. A missing handler gives
`ok = false` with `No handler for action '<type>'`; a handler exception gives `ok = false` with the first 500 characters
of its message. Nothing is thrown. `AutomationExecutor::interpolate($config, $context)` replaces `{path}` in string
values (unknown paths become empty) but only for the free-text keys in `AutomationExecutor::FREE_TEXT_KEYS` (`subject`,
`details`, `message`, `title`, `body`, `description`, `text`, `name`, `note`, `notes`, `comment`, `summary`, `label`). Every other
key (`url`, `secret`, `email`, `to`, `assigned_to`, `asset_id`, `headers`, `user_id`, `client_id`, `priority`, ...) is used
verbatim, so event data cannot steer where a request goes or who is addressed.

```php
use RivetCore\Automation\{AutomationExecutor, AutomationRuleEvaluator, AutomationRuleStore, EventContext};

$store = new AutomationRuleStore($db);
$store->save(null, 'Backup failed', 'backup.failed', ['severity' => 'critical'], 'create_ticket',
    ['subject' => 'Backup failed: {backup.name}', 'priority' => 'High'], true);

$context = EventContext::flatten(['backup' => ['name' => 'nightly'], 'severity' => 'critical']);
foreach ((new AutomationRuleEvaluator($db))->findMatchingRules('backup.failed', $context) as $rule) {
    $result = (new AutomationExecutor())->execute($rule, $context, [
        'create_ticket' => fn (array $config, array $context, array $rule): string => 'queued "' . $config['subject'] . '"',
    ]);
    // ['rule_id' => ..., 'ok' => true, 'message' => 'queued "Backup failed: nightly"']
}
```

## Configuration

None in Core. Editions gate the module behind a setting (`core.automation.enabled` in both editions) and choose
whether rules run inline or as queued jobs (`automation.action` jobs with 3 attempts).

## How it fails

- Evaluation: fails closed. Bad condition JSON or a missing key means "no match", never "match".
- Execution: a failing or missing action is returned as `ok = false`, never thrown, so one rule cannot stop the others
  (the caller loops). The edition should log the result; Core does not.
- The store throws `InvalidArgumentException` on invalid input and for an update of a rule that no longer exists.
- Database errors (`DatabaseException`) from the evaluator and store propagate; the editions wrap the whole emit step in
  try/catch so an event never breaks the action that caused it.

## Security notes

- Placeholder interpolation skips routing fields; keep that list in mind when adding action types in an edition.
- `send_webhook` configs are only checked for scheme and host. Handlers must vet the URL again at run time (resolve to
  a public address, or use `Webhooks\UrlPolicy`); both editions do so. Do not follow redirects.
- `secret` is stored as given in `action_config_json`; store an encrypted value or accept that it is plaintext in the database.
- Write the "rule fired" record straight to the audit table without the after-log hook, otherwise a rule can trigger rules
  in a loop (RivetIT does this on purpose).
- Event data in the context can come from outside users (ticket subjects); treat interpolated text as untrusted when rendered.

## Used by

- RivetIT (`/var/www/mw-itflow.foleyit.com`): `includes/event_bus.php` (evaluator, `EventContext`, `AutomationRuleStore`
  and `AutomationExecutor`, with its own `rivetAutomationActionHandlers()`); `admin/event_rules.php` and
  `admin/post/event_rules.php` (rule editor via `AutomationRuleStore`); `src/Automation/AutomationRuleEvaluator.php` is a
  compatibility shim.
- RivetMSP (`/home/sysadmin/rivetmsp-beta`): `includes/event_bus.php` (same flow); `includes/event_rules_lib.php`,
  `admin/event_rules.php`, `admin/post/event_rules.php`, `admin/event_rules_tools.php` (editor and tools);
  `src/Automation/RuleDryRun.php` (dry run through `AutomationExecutor`); `src/Core/CoreBridge.php` (evaluator).

## Links

- CHANGELOG: 0.6.0 (evaluator, migration 0006), 0.15.0 (`EventContext`, `AutomationRuleStore`, `AutomationExecutor`);
  see [../../CHANGELOG.md](../../CHANGELOG.md).
- [../adapters.md](../adapters.md), [../webhooks.md](../webhooks.md), [webhooks.md](webhooks.md) (event ids come from `EventCatalog`).
- Tests: `tests/Integration/WebhookAutomationWorkflowTest.php`, `tests/Integration/JobsAutomationWebhookTest.php`.
