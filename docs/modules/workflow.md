# Workflow

`RivetCore\Workflow`: a manual-first checklist engine for lifecycle processes (onboarding, offboarding). A template is a list of
tasks; a run snapshots the template's tasks for one subject, so editing a template later never rewrites a run in progress or finished.
No task dependencies, approvals or automation actions: those need real usage first (roadmap, post-1.0).

## What it owns

- `workflow_templates` (migration 0007): `workflow_template_id`, `name`, `type`, `description`, `is_active`, `archived_at`, `created_by`, `created_at`, `updated_at`.
- `workflow_template_tasks`: `template_task_id`, `workflow_template_id`, `sort_order`, `title`, `instructions`, `category`, `default_owner`, `required`.
- `workflow_runs`: `run_id`, `workflow_template_id`, `contact_id` (the subject: an opaque id the edition owns; the column name is historical), `type`, `status`, `notes`, `started_by`, `started_at`, `completed_at`.
- `workflow_run_tasks`: the snapshot: `run_task_id`, `run_id`, `sort_order`, `title`, `instructions`, `category`, `default_owner`, `required`, `status`, `skip_reason`, `completed_by`, `completed_at`.

## You supply

The subject records (ids) and the UI. Core only stores the id.

## Flags

None.

## Use it

<!-- run -->
```php
use RivetCore\Workflow\WorkflowService;

$db->execute("INSERT INTO workflow_templates (name, type) VALUES ('Onboarding', 'onboarding')");
$tpl = (int) $db->fetchOne('SELECT MAX(workflow_template_id) AS id FROM workflow_templates')['id'];
$db->execute("INSERT INTO workflow_template_tasks (workflow_template_id, sort_order, title, required) VALUES (?, 1, 'Create account', 1), (?, 2, 'Order laptop', 0)", [$tpl, $tpl]);

$wf = new WorkflowService($db);
$run = $wf->startRun($tpl, 4242, 7);
foreach ($db->fetchAll('SELECT run_task_id FROM workflow_run_tasks WHERE run_id = ?', [$run]) as $t) { $wf->completeTask((int) $t['run_task_id'], 7); }
echo $db->fetchOne('SELECT status FROM workflow_runs WHERE run_id = ?', [$run])['status'], "\n";
```

## How it fails

- `startRun()` is atomic: a failure part-way leaves no half-created run. An unknown template throws `InvalidArgumentException`.
- The task methods are **permissive**: `completeTask()`, `skipTask()` and `reopenTask()` do not validate their input. An unknown task id
  is a silent no-op, `skipTask()` accepts an empty reason (the edition's UI must require one), and nothing stops a task of a finished
  run from being edited. What the engine guarantees is the **run status**, recomputed from the tasks: `completed` when every required task
  is done, `completed_with_exceptions` when all required tasks are resolved and at least one was skipped, `in_progress` while a required
  task is pending. A cancelled run is never revived (reopening a task leaves `cancelled` alone; completing the last task cannot
  re-complete it). Optional tasks never gate completion. These behaviours are pinned by `tests/Integration/WorkflowFailureModesTest.php`.
- Storage failures throw `DatabaseException`.
