<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Database\DatabaseInterface;

/** Append-only record of manual reviews. The newest row per item decides its state; older rows are the history. */
final class AttestationStore implements AttestationProviderInterface
{
    public const NOTE_MAX = 2000;

    public function __construct(private DatabaseInterface $database)
    {
    }

    /**
     * @throws \InvalidArgumentException for an unusable item id, date or reviewer
     */
    public function record(string $itemId, ?int $reviewedByUserId, string $reviewerName, string $reviewedOn, ?string $nextDueOn, ?string $note, ?\DateTimeImmutable $today = null): int
    {
        $today ??= new \DateTimeImmutable('today');
        if (!preg_match('/^[a-z0-9_]{1,64}$/', $itemId)) {
            throw new \InvalidArgumentException('Unknown checklist item.');
        }
        $reviewer = trim($reviewerName);
        if ($reviewer === '') {
            throw new \InvalidArgumentException('Enter who did the review.');
        }
        $reviewed = self::date($reviewedOn, 'review date');
        if ($reviewed > $today->modify('+1 day')) {
            throw new \InvalidArgumentException('The review date cannot be in the future.');
        }
        $next = null;
        if ($nextDueOn !== null && trim($nextDueOn) !== '') {
            $next = self::date($nextDueOn, 'next due date');
            if ($next < $reviewed) {
                throw new \InvalidArgumentException('The next due date cannot be before the review date.');
            }
        }

        $note = $note === null ? null : mb_substr(trim($note), 0, self::NOTE_MAX);

        return (int) $this->database->execute(
            'INSERT INTO compliance_attestations (item_id, reviewed_by, reviewer_name, reviewed_on, next_due_on, note) VALUES (?, ?, ?, ?, ?, ?)',
            [$itemId, $reviewedByUserId, mb_substr($reviewer, 0, 200), $reviewed->format('Y-m-d'), $next?->format('Y-m-d'), $note === '' ? null : $note]
        )->insertId;
    }

    public function latestPerItem(): array
    {
        $rows = $this->database->fetchAll(
            'SELECT a.item_id, a.reviewed_on, a.next_due_on, a.reviewer_name, a.note
             FROM compliance_attestations a
             WHERE a.attestation_id = (SELECT MAX(b.attestation_id) FROM compliance_attestations b WHERE b.item_id = a.item_id)'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['item_id']] = [
                'reviewed_on' => (string) $r['reviewed_on'],
                'next_due_on' => $r['next_due_on'] === null ? null : (string) $r['next_due_on'],
                'reviewer_name' => (string) $r['reviewer_name'],
                'note' => $r['note'] === null ? null : (string) $r['note'],
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> newest first */
    public function history(string $itemId, int $limit = 20): array
    {
        return $this->database->fetchAll(
            'SELECT attestation_id, reviewed_by, reviewer_name, reviewed_on, next_due_on, note, created_at
             FROM compliance_attestations WHERE item_id = ? ORDER BY attestation_id DESC LIMIT ?',
            [$itemId, max(1, min(200, $limit))]
        );
    }

    private static function date(string $value, string $what): \DateTimeImmutable
    {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($value));
        if (!$d || $d->format('Y-m-d') !== trim($value)) {
            throw new \InvalidArgumentException("The $what is not a valid date (use YYYY-MM-DD).");
        }

        return $d;
    }
}
