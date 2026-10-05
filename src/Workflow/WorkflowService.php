<?php

declare(strict_types=1);

namespace RivetCore\Workflow;

use RivetCore\Database\DatabaseInterface;

/**
 * Manual-first lifecycle workflow engine: a checklist tied to a subject (in RivetIT a person; the column is
 * still named contact_id, and Core treats it as an opaque id owned by the edition). Template tasks are
 * snapshotted onto the run when it starts (title/instructions copied, not referenced) so editing a template
 * later never rewrites the history of a run already in progress or completed. No task dependencies, approvals
 * or automation actions - those need real usage first.
 */
class WorkflowService
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function startRun(int $templateId, int $subjectId, ?int $startedByUserId): int
    {
        return $this->database->transaction(function () use ($templateId, $subjectId, $startedByUserId): int {
            $template = $this->database->fetchOne('SELECT * FROM workflow_templates WHERE workflow_template_id = ?', [$templateId]);
            if (!$template) {
                throw new \InvalidArgumentException("Workflow template $templateId not found");
            }

            $runId = (int) $this->database->execute(
                'INSERT INTO workflow_runs (workflow_template_id, contact_id, type, started_by) VALUES (?, ?, ?, ?)',
                [$templateId, $subjectId, $template['type'], $startedByUserId]
            )->insertId;

            $tasks = $this->database->fetchAll(
                'SELECT * FROM workflow_template_tasks WHERE workflow_template_id = ? ORDER BY sort_order ASC',
                [$templateId]
            );
            foreach ($tasks as $task) {
                $this->database->execute(
                    'INSERT INTO workflow_run_tasks (run_id, title, instructions, category, default_owner, required, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$runId, $task['title'], $task['instructions'], $task['category'], $task['default_owner'], (int) $task['required'], (int) $task['sort_order']]
                );
            }

            return $runId;
        });
    }

    public function completeTask(int $runTaskId, ?int $userId): void
    {
        $this->database->execute(
            "UPDATE workflow_run_tasks SET status = 'completed', completed_by = ?, completed_at = NOW(), skip_reason = NULL WHERE run_task_id = ?",
            [$userId, $runTaskId]
        );
        $this->refreshRunStatus($this->runIdForTask($runTaskId));
    }

    public function reopenTask(int $runTaskId): void
    {
        $this->database->execute(
            "UPDATE workflow_run_tasks SET status = 'pending', completed_by = NULL, completed_at = NULL, skip_reason = NULL WHERE run_task_id = ?",
            [$runTaskId]
        );
        // Reopening a task un-completes the run too, if it had been marked done.
        $this->database->execute(
            "UPDATE workflow_runs SET status = 'in_progress', completed_at = NULL WHERE run_id = ? AND status != 'cancelled'",
            [$this->runIdForTask($runTaskId)]
        );
    }

    public function skipTask(int $runTaskId, string $reason, ?int $userId): void
    {
        $this->database->execute(
            "UPDATE workflow_run_tasks SET status = 'skipped', completed_by = ?, completed_at = NOW(), skip_reason = ? WHERE run_task_id = ?",
            [$userId, $reason, $runTaskId]
        );
        $this->refreshRunStatus($this->runIdForTask($runTaskId));
    }

    public function cancelRun(int $runId): void
    {
        $this->database->execute("UPDATE workflow_runs SET status = 'cancelled' WHERE run_id = ?", [$runId]);
    }

    /**
     * Recomputes and persists the run's status from its tasks: completed (all required tasks done),
     * completed_with_exceptions (all required tasks resolved but at least one was skipped), or left in_progress
     * while any required task is still pending. Optional tasks never gate completion.
     */
    private function refreshRunStatus(int $runId): void
    {
        $tasks = $this->database->fetchAll('SELECT status, required FROM workflow_run_tasks WHERE run_id = ?', [$runId]);

        if (array_filter($tasks, fn ($t) => $t['required'] && $t['status'] === 'pending')) {
            return;
        }

        $newStatus = array_filter($tasks, fn ($t) => $t['status'] === 'skipped') ? 'completed_with_exceptions' : 'completed';
        $this->database->execute(
            "UPDATE workflow_runs SET status = ?, completed_at = NOW() WHERE run_id = ? AND status = 'in_progress'",
            [$newStatus, $runId]
        );
    }

    private function runIdForTask(int $runTaskId): int
    {
        $row = $this->database->fetchOne('SELECT run_id FROM workflow_run_tasks WHERE run_task_id = ?', [$runTaskId]);

        return (int) ($row['run_id'] ?? 0);
    }
}
