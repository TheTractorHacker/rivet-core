<?php

declare(strict_types=1);

namespace RivetCore\ITSM;

use RivetCore\Database\DatabaseInterface;

/**
 * Problem management, kept deliberately lightweight: a problem is a root-cause record that tickets
 * (incidents) link to through the edition, and that can itself link forward to the change meant to fix it
 * via problems.change_problem_id. No separate "incident" table - an incident is just a ticket.
 *
 * @api
 */
class ProblemService
{
    public const STATUSES = ['open', 'investigating', 'resolved', 'closed'];

    public const TRANSITIONS = [
        'open' => ['investigating', 'resolved', 'closed'],
        'investigating' => ['open', 'resolved', 'closed'],
        'resolved' => ['open', 'investigating', 'closed'],
        'closed' => ['open', 'investigating'],
    ];

    public function __construct(
        private DatabaseInterface $database,
        private TicketProblemLinkInterface $tickets,
    ) {
    }

    public function create(string $title, ?string $description, ?int $createdBy): int
    {
        $result = $this->database->execute(
            "INSERT INTO problems (title, description, status, created_by) VALUES (?, ?, 'open', ?)",
            [$title, $description, $createdBy]
        );

        return (int) $result->insertId;
    }

    public function setStatus(int $problemId, string $status): void
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("Invalid problem status: $status");
        }

        $current = $this->database->fetchOne('SELECT status FROM problems WHERE problem_id = ?', [$problemId]);
        if (!$current) {
            throw new \InvalidArgumentException("Problem $problemId not found");
        }

        $from = $current['status'];
        if ($from === $status) {
            return;
        }
        if (!in_array($status, self::TRANSITIONS[$from] ?? [], true)) {
            throw new \InvalidArgumentException("Cannot move a problem from '$from' to '$status'");
        }

        // resolved_at marks when the root cause was actually fixed - set it the first time a problem reaches
        // resolved/closed, clear it on reopen so a later re-resolve gets a fresh timestamp.
        if (in_array($status, ['resolved', 'closed'], true)) {
            $this->database->execute(
                'UPDATE problems SET status = ?, resolved_at = COALESCE(resolved_at, NOW()) WHERE problem_id = ?',
                [$status, $problemId]
            );
        } else {
            $this->database->execute(
                'UPDATE problems SET status = ?, resolved_at = NULL WHERE problem_id = ?',
                [$status, $problemId]
            );
        }
    }

    public function linkChange(int $problemId, ?int $changeId): void
    {
        $this->database->execute('UPDATE problems SET change_problem_id = ? WHERE problem_id = ?', [$changeId, $problemId]);
    }

    public function linkTicket(int $problemId, int $ticketId): void
    {
        $this->tickets->link($ticketId, $problemId);
    }

    public function unlinkTicket(int $problemId, int $ticketId): void
    {
        $this->tickets->unlink($ticketId, $problemId);
    }
}
