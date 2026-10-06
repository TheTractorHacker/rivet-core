# Retention

`RivetCore\Retention\RetentionService` prunes the Core-owned log tables so they cannot grow without bound, and
`Compliance\RetentionPolicy` provides minimum-retention presets so a stored setting can never go below a framework's floor.

## What it owns

No tables of its own. It deletes, with fixed SQL and no caller-supplied identifiers:

- `audit_events` older than the audit horizon (which may differ from the others),
- `webhook_deliveries` older than the delivery horizon,
- `integration_jobs` that are finished (`completed` or `dead_letter`) and older than the job horizon. Pending and running jobs are never deleted.

## You supply

Horizons in days and the call site (both editions use their hourly cron).

## Flags

A horizon below 1 means "keep everything" for that table. The optional compliance profile (`new RetentionService($db, 'pci')`) raises
every horizon to the preset's floor inside `prune()` and `plan()`: 365 days for ISO/IEC 27001, SOC 2, PCI DSS and NIST 800-171, 6 years for
HIPAA; webhook deliveries and finished jobs have a 7-day floor, or 30 days under any preset (`OPERATIONAL_MIN_DAYS`,
`OPERATIONAL_REGULATED_DAYS`).

## Use it

<!-- run -->
```php
use RivetCore\Retention\RetentionService;
use RivetCore\Compliance\RetentionPolicy;

$days = RetentionPolicy::effectiveDays('soc2', 30);          // a stored 30 is raised to the SOC 2 floor
$svc  = new RetentionService($db, 'soc2');
$plan = $svc->plan($days);                                    // dry run: rows that WOULD go
$gone = $svc->prune($days);                                   // deletes in batches of 5000
echo "floor=$days days, plan=", json_encode($plan), ', deleted=', json_encode($gone), "\n";
```

## How it fails

`prune()` deletes in batches (`LIMIT`, default 5000) so a large backlog does not hold one huge lock, and throws `DatabaseException`
if a batch fails (earlier batches stay deleted). Run `plan()` first when changing a horizon. The horizon is computed from the
database clock (`NOW()`), so it agrees with the timestamps the database wrote.
