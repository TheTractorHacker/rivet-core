# Automation

`RivetCore\Automation`: event rules. `AutomationRuleStore` validates and stores rules; `AutomationRuleEvaluator` says which enabled
rules match an event; `AutomationExecutor` runs one rule's action through handlers the edition supplies. Core cannot create tickets
or notify users, so the actions are the edition's.

## What it owns

Table `automation_rules` (migration 0006): `rule_id`, `name`, `trigger_event`, `condition_json`, `action_type`
(`create_ticket`, `send_webhook`, `notify_user`), `action_config_json`, `is_enabled`, `created_at`.

## You supply

Action handlers (`array $handlers` keyed by action type, each `callable(array $config, array $context, array $rule): ?string` returning a short result message) and the call site that turns an event into `findMatchingRules()` +
`execute()`. A common wiring is `AuditService`'s `$afterLog` callback.

## Flags

Per rule: `is_enabled`. The module itself is off until the edition calls it.

## Use it

<!-- run -->
```php
use RivetCore\Automation\{AutomationExecutor, AutomationRuleEvaluator, AutomationRuleStore, EventContext};

$store = new AutomationRuleStore($db);
$store->save(null, 'Alert on backup failure', 'backup.failed', [], 'notify_user', ['user_id' => 7, 'message' => 'Backup failed: {backup.name}'], true);

$event = ['backup' => ['name' => 'nightly']];
$context = EventContext::flatten($event);                      // 'backup.name' => 'nightly' (and the bare key 'name')
$notified = [];
$handlers = ['notify_user' => function (array $config, array $context, array $rule) use (&$notified): string { $notified[] = $config['message']; return 'queued'; }];
foreach ((new AutomationRuleEvaluator($db))->findMatchingRules('backup.failed', $event) as $rule) {
    $outcome = (new AutomationExecutor())->execute($rule, $context, $handlers);   // ['rule_id' => .., 'ok' => true, 'message' => 'queued']
}
echo implode(',', $notified), ' (', $outcome['message'], ")\n";
```

## How it fails

`AutomationRuleStore::save()` validates the trigger, condition and action config and throws on invalid input. A handler that throws is
reported as `ok: false` with the exception message (first 500 characters) and does not stop other rules; keep secrets out of handler exception messages. `{placeholders}` are filled from the event and are never allowed in URLs or
ids, so event data cannot redirect a webhook. Execution depth (approvals, dry run, audit of what ran) is a post-1.0 item.
