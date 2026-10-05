<?php

declare(strict_types=1);

namespace RivetCore\ITSM;

use RivetCore\Database\DatabaseInterface;

/**
 * Change management, kept deliberately lightweight: no separate approver/CAB model - a change just carries
 * its own status, risk and plans, and a problem can point at the change meant to fix it
 * (problems.change_problem_id) to complete the tickets -> problem -> change chain.
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

        if ($scheduledAt) {
            $this->database->execute('UPDATE changes SET status = ?, scheduled_at = ? WHERE change_id = ?', [$status, $scheduledAt, $changeId]);
        } else {
            $this->database->execute('UPDATE changes SET status = ? WHERE change_id = ?', [$status, $changeId]);
        }
    }

    public function reschedule(int $changeId, string $scheduledAt): void
    {
        $this->database->execute('UPDATE changes SET scheduled_at = ? WHERE change_id = ?', [$scheduledAt, $changeId]);
    }
}
