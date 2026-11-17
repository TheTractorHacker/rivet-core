<?php

declare(strict_types=1);

namespace RivetCore\ITSM;

use RivetCore\Database\DatabaseInterface;

/**
 * Change management, kept deliberately lightweight: no separate approver/CAB model - a change just carries
 * its own status, risk and plans, and a problem can point at the change meant to fix it
 * (problems.change_problem_id) to complete the tickets -> problem -> change chain.
 *
 * @api
 */
class ChangeService
{
    public const STATUSES = [
        'draft', 'awaiting_approval', 'approved', 'scheduled', 'in_progress',
        'successful', 'failed', 'rolled_back', 'cancelled',
    ];

    public const RISKS = ['low', 'medium', 'high'];

    public const TRANSITIONS = [
        'draft' => ['awaiting_approval', 'cancelled'],
        'awaiting_approval' => ['draft', 'approved', 'cancelled'],
        'approved' => ['scheduled', 'in_progress', 'cancelled'],
        'scheduled' => ['approved', 'in_progress', 'cancelled'],
        'in_progress' => ['successful', 'failed', 'cancelled'],
        'failed' => ['rolled_back', 'cancelled'],
        'successful' => ['rolled_back'],
        'rolled_back' => [],
        'cancelled' => [],
    ];

    public function __construct(private DatabaseInterface $database)
    {
    }

    public function create(
        string $title,
        ?string $reason,
        ?string $impact,
        string $risk,
        ?string $implementationPlan,
        ?string $rollbackPlan,
        ?string $scheduledAt,
        ?int $createdBy,
    ): int {
        if (!in_array($risk, self::RISKS, true)) {
            throw new \InvalidArgumentException("Invalid change risk: $risk");
        }
        $scheduledAt = $scheduledAt !== '' ? $scheduledAt : null;

        $result = $this->database->execute(
            "INSERT INTO changes (title, reason, impact, risk, implementation_plan, rollback_plan, scheduled_at, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
            [$title, $reason, $impact, $risk, $implementationPlan, $rollbackPlan, $scheduledAt, $createdBy]
        );

        return (int) $result->insertId;
    }

    public function setStatus(int $changeId, string $status, ?string $scheduledAt = null): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid change status: $status");
        }

        $current = $this->database->fetchOne('SELECT status, scheduled_at FROM changes WHERE change_id = ?', [$changeId]);
        if (!$current) {
            throw new \InvalidArgumentException("Change $changeId not found");
        }

        $from = $current['status'];
        if ($from === $status) {
            return;
        }
        if (!in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \InvalidArgumentException("Cannot move a change from '$from' to '$status'");
        }

        $scheduledAt = $scheduledAt !== '' ? $scheduledAt : null;
        if ($status === 'scheduled' && !$scheduledAt && !$current['scheduled_at']) {
            throw new \InvalidArgumentException('A scheduled change needs a scheduled_at time');
        }

        // CORE-DS-59 (CORE-37): the write is conditional on the status that was read, so two people acting at once cannot both win (a cancelled change stays cancelled)
        if ($scheduledAt) {
            $done = $this->database->execute('UPDATE changes SET status = ?, scheduled_at = ? WHERE change_id = ? AND status = ?', [$status, $scheduledAt, $changeId, $from]);
        } else {
            $done = $this->database->execute('UPDATE changes SET status = ? WHERE change_id = ? AND status = ?', [$status, $changeId, $from]);
        }
        if ($done->affectedRows < 1) {
            throw new \InvalidArgumentException("Change $changeId was changed by someone else; reload it and try again");
        }
    }

    /** Statuses a change can still be moved in time: once it runs or has finished, its schedule is history. */
    private const RESCHEDULABLE = ['draft', 'awaiting_approval', 'approved', 'scheduled'];

    public function reschedule(int $changeId, string $scheduledAt): void
    {
        // CORE-DS-59: not for a change that is running, finished or cancelled
        $done = $this->database->execute('UPDATE changes SET scheduled_at = ? WHERE change_id = ? AND status IN (' . implode(',', array_fill(0, count(self::RESCHEDULABLE), '?')) . ')',
            [$scheduledAt, $changeId, ...self::RESCHEDULABLE]);
        if ($done->affectedRows < 1 && $this->database->fetchOne('SELECT 1 AS x FROM changes WHERE change_id = ? AND status NOT IN (' . implode(',', array_fill(0, count(self::RESCHEDULABLE), '?')) . ')', [$changeId, ...self::RESCHEDULABLE]) !== null) {
            throw new \InvalidArgumentException("Change $changeId can no longer be rescheduled");
        }
    }
}
