# ITSM (problems and changes)

Namespace `RivetCore\ITSM`. Lightweight problem and change management: a ticket (incident) links to a problem, and a
problem can point at the change meant to fix it, giving a tickets, problem, change chain.

## Overview

Two Core-owned tables from migration `0004_problems_and_changes` (RivetIT created identical tables in its 2.6.62
migration, so `IF NOT EXISTS` makes it a no-op there):

- `problems`: `problem_id`, `title`, `description`, `status` (`open`, `investigating`, `resolved`, `closed`),
  `change_problem_id` (the change meant to fix it), `created_by`, `created_at`, `resolved_at`.
- `changes`: `change_id`, `title`, `reason`, `impact`, `risk` (`low`, `medium`, `high`), `implementation_plan`,
  `rollback_plan`, `scheduled_at`, `status`, `created_by`, `created_at`.

The ticket side is edition-owned: the `tickets.ticket_problem_id` column is not part of the migration and Core never
touches the tickets table.

## Contracts an edition must implement

`TicketProblemLinkInterface`:

- `link(int $ticketId, int $problemId): void` attach the ticket to the problem (the edition stores it on the ticket row).
- `unlink(int $ticketId, int $problemId): void` detach, but only if the ticket is currently linked to that problem.

Neither returns a value; throw on failure. `ChangeService` needs only a `DatabaseInterface`.

## Key classes

### ProblemService

`new ProblemService($db, $ticketLink)`.

- `create($title, $description, $createdBy): int` inserts with status `open`.
- `setStatus($problemId, $status)`: validates the status, loads the problem, no-ops when unchanged, refuses transitions not
  in `ProblemService::TRANSITIONS`. Reaching `resolved` or `closed` sets `resolved_at` the first time (`COALESCE`);
  moving back to `open` or `investigating` clears it.
- `linkChange($problemId, ?$changeId)` sets or (with `null`) clears the fix change.
- `linkTicket($problemId, $ticketId)` and `unlinkTicket(...)` delegate to the edition's link implementation.

Constants: `STATUSES`, `TRANSITIONS` (open: investigating, resolved, closed; investigating: open, resolved, closed;
resolved: open, investigating, closed; closed: open, investigating).

### ChangeService

`new ChangeService($db)`.

- `create($title, $reason, $impact, $risk, $implementationPlan, $rollbackPlan, $scheduledAt, $createdBy): int` starts in
  `draft`; an invalid risk throws; an empty-string `$scheduledAt` becomes null.
- `setStatus($changeId, $status, $scheduledAt = null)`: same rules as problems, plus moving to `scheduled` requires a
  scheduled time (given now or already stored).
- `reschedule($changeId, $scheduledAt)` updates the time only.

Constants: `STATUSES` (draft, awaiting_approval, approved, scheduled, in_progress, successful, failed, rolled_back,
cancelled), `RISKS`, `TRANSITIONS` (for example approved goes to scheduled, in_progress or cancelled; successful only to
rolled_back; rolled_back and cancelled are terminal). There is no separate approver model; approval is just a status.
Render status dropdowns from the constants instead of copying them into the UI.

```php
use RivetCore\ITSM\{ChangeService, ProblemService, TicketProblemLinkInterface};

$link = new class implements TicketProblemLinkInterface {
    public function link(int $ticketId, int $problemId): void { /* UPDATE tickets SET ticket_problem_id = ... */ }
    public function unlink(int $ticketId, int $problemId): void { /* only if currently linked */ }
};

$problems = new ProblemService($db, $link);
$problemId = $problems->create('Printer spooler crashes', null, $userId);
$problems->linkTicket($problemId, 42);
$problems->setStatus($problemId, 'investigating');

$changes = new ChangeService($db);
$changeId = $changes->create('Replace spooler', 'Crashes weekly', null, 'low', null, null, null, $userId);
$changes->setStatus($changeId, 'awaiting_approval');
$problems->linkChange($problemId, $changeId);
$allowed = ChangeService::TRANSITIONS['approved']; // ['scheduled', 'in_progress', 'cancelled']
```

## Configuration

None. Both classes are non-final so an edition can extend them. Editions gate the module behind a setting (RivetMSP: `core.itsm.enabled`, see
`includes/core_module_gates.php`, shown as "Problems & Changes").

## How it fails

- Closed: an unknown status, an unknown id, an illegal transition, an invalid risk and a `scheduled` change without a time
  all throw `InvalidArgumentException` with a readable message (for example `Cannot move a problem from 'closed' to
  'resolved'`). Catch it and show it as a form error.
- A same-status `setStatus()` is a silent no-op.
- `linkChange()`, `reschedule()` and `create()` do not check that the referenced row exists. Database errors propagate.
- Core does not audit; the edition should record status changes.

## Security notes

- All SQL uses prepared parameters; titles and text are stored as given, so escape on output.
- Authorization is the edition's job (who may create, approve or move a change). The state machine is a consistency
  check, not an access check.
- The `unlink` contract must verify the current link, so one problem page cannot detach a ticket from another problem.

## Used by

- RivetMSP (`/home/sysadmin/rivetmsp-beta`): `src/Core/CoreBridge.php` builds `ProblemService` and `ChangeService`;
  `includes/core_module_gates.php` exposes them as `rivetItsmProblems()` and `rivetItsmChanges()` (turned-off modules get a
  flash and redirect); `agent/post/problem.php` (and the change handlers) call them; `src/Core/Adapter/Itsm/TicketsProblemLink.php`
  implements the link interface.
- RivetIT (`/var/www/mw-itflow.foleyit.com`): `src/ITSM/ProblemService.php` and `src/ITSM/ChangeService.php` are
  compatibility shims over the Core classes; `src/Core/Adapter/Itsm/TicketsProblemLink.php` implements the interface.

## Links

- CHANGELOG: 0.5.0 (services, interface, migration 0004); see [../../CHANGELOG.md](../../CHANGELOG.md).
- [../adapters.md](../adapters.md), [../architecture/ADR-002-modules-that-stay-in-editions.md](../architecture/ADR-002-modules-that-stay-in-editions.md).
- Tests: `tests/Integration/ItsmTest.php`.
