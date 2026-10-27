# Workflow

Namespace `RivetCore\Workflow`. A manual-first checklist engine: a template of tasks is started as a run for a subject
(in the editions, a person), and people complete or skip the tasks. Core derives the run's status from its tasks.
There are no task dependencies, approvals, due dates or automated actions in Core.

## Overview

Four Core-owned tables from migration `0007_workflow_tables` (RivetIT created identical tables in its 2.6.65 migration,
so `IF NOT EXISTS` makes it a no-op there):

- `workflow_templates`: `workflow_template_id`, `name`, `type` (`onboarding`, `offboarding`), `description`, `is_active`,
  `created_by`, `created_at`, `updated_at`, `archived_at`.
- `workflow_template_tasks`: `template_task_id`, `workflow_template_id`, `title`, `instructions`, `category`,
  `default_owner`, `required`, `sort_order`.
- `workflow_runs`: `run_id`, `workflow_template_id`, `contact_id` (the subject; Core treats it as an opaque integer the
  edition owns), `type`, `status` (`in_progress`, `completed_with_exceptions`, `completed`, `cancelled`), `started_by`,
  `started_at`, `completed_at`, `notes`.
- `workflow_run_tasks`: `run_task_id`, `run_id`, copied `title`, `instructions`, `category`, `default_owner`, `required`,
  `sort_order`, plus `status` (`pending`, `completed`, `skipped`), `completed_by`, `completed_at`, `skip_reason`.

`WorkflowService` only reads templates; creating and editing templates and listing runs is the edition's job (plain SQL on
these tables).

## Contracts an edition must implement

No interfaces. The edition supplies a `DatabaseInterface` (with working `transaction()`), user ids for audit columns, and
the subject id passed to `startRun()` (it is stored in `contact_id`, so it must be an existing id in the edition's own
table; Core does not check). The edition also owns authorization, template CRUD and presentation of the run.

## Key classes

### WorkflowService

`new WorkflowService($db)`; non-final.

- `startRun(int $templateId, int $subjectId, ?int $startedByUserId): int` runs in one transaction: loads the template
  (throws `InvalidArgumentException` when it does not exist), inserts the run with the template's `type`, then copies every
  template task, ordered by `sort_order`, into `workflow_run_tasks`. The copy is a snapshot, so editing or archiving the
  template later never rewrites an existing run. A failure mid-way rolls everything back. It does not check `is_active`
  or `archived_at`; filter those in your template picker.
- `completeTask($runTaskId, ?$userId)` marks the task completed (clearing any skip reason), then recomputes the run.
- `skipTask($runTaskId, $reason, ?$userId)` marks it skipped with a reason, then recomputes the run.
- `reopenTask($runTaskId)` sets the task back to `pending` and the run back to `in_progress` (clearing `completed_at`)
  unless the run is `cancelled`.
- `cancelRun($runId)` sets the run to `cancelled`.

Run status is derived: while any required task is `pending` the run stays `in_progress`; once all required tasks are
resolved the run becomes `completed`, or `completed_with_exceptions` when at least one task was skipped, and
`completed_at` is stamped. Optional tasks never gate completion. A run that is no longer `in_progress` (completed or
cancelled) is not changed by `refreshRunStatus`.

```php
use RivetCore\Workflow\WorkflowService;

$workflow = new WorkflowService($db);
$runId = $workflow->startRun($templateId, $contactId, $userId);   // snapshot of the template's tasks
$workflow->completeTask($runTaskId, $userId);
$workflow->skipTask($otherTaskId, 'Not applicable for contractors', $userId);
$workflow->reopenTask($otherTaskId);                               // run goes back to in_progress
$workflow->cancelRun($runId);
```

(The snippet was run against the test `FakeDatabase`; real behavior against MariaDB is covered by
`tests/Integration/WebhookAutomationWorkflowTest.php`.)

## Configuration

None. Editions gate the module behind a setting (RivetMSP: `core.workflow.enabled`, which turns the service into `null`
when off; see `includes/core_module_gates.php`).

## How it fails

- `startRun()` throws `InvalidArgumentException` for an unknown template and rolls back on any other error.
- The task methods do not validate: an unknown task id updates nothing (the run id resolves to 0 and no run row matches),
  completing an already completed task is harmless, and completing a task of a cancelled run changes the task but leaves
  the run `cancelled`. Despite the summary table in [README.md](README.md) there is no state machine that refuses
  illegal task moves; check permissions and state in the edition if you need that.
- Database errors propagate. Core does not audit; record starts, completions and skips in the edition.

## Security notes

- Prepared parameters throughout. Task text and skip reasons are stored as given; escape on output.
- `skipTask` reasons are free text up to the column width (500); truncate before calling, as RivetMSP does.
- Authorization (who may start a run for a subject, who may skip required tasks) belongs to the edition.
- The subject id is not validated by Core, so never pass an id from untrusted input without checking it first.

## Used by

- RivetMSP (`/home/sysadmin/rivetmsp-beta`): `src/Core/CoreBridge.php::workflow()` builds the service;
  `includes/core_module_gates.php` exposes `rivetWorkflows()`; `agent/post/workflow_run.php` calls `startRun`,
  `completeTask`, `skipTask` and `reopenTask`.
- RivetIT (`/var/www/mw-itflow.foleyit.com`): not used. `src/Workflow/WorkflowService.php` started as a shim over this class
  but is now its own engine (task dependencies, approvals, action tasks, extra task and run states) operating on the same
  four tables. Keep that in mind: rows written by that engine can hold statuses (`blocked`, `running`, `action_failed`,
  `rejected`, `paused`) that this service and the migration's enums do not describe.

## Links

- CHANGELOG: 0.6.0 (service, migration 0007, atomic `startRun`); see [../../CHANGELOG.md](../../CHANGELOG.md).
- [../adapters.md](../adapters.md), [../architecture/ADR-002-modules-that-stay-in-editions.md](../architecture/ADR-002-modules-that-stay-in-editions.md).
- Tests: `tests/Integration/WebhookAutomationWorkflowTest.php`.
