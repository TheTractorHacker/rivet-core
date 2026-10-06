<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;

/**
 * Compliance for a subject other than the installation itself, such as an MSP's customer: the frameworks chosen for it, its manual
 * checklist with sign-off, saved snapshots, and the one snapshot (if any) shared with the subject. There are no automatic checks:
 * Core cannot see a customer's systems, so everything here is evidenced by a person's recorded review.
 *
 * @api
 */
final class SubjectCompliance
{
    public function __construct(private DatabaseInterface $database, private ClockInterface $clock)
    {
    }

    /** @return list<string> */
    public function frameworks(int $subjectId): array
    {
        $row = $this->database->fetchOne('SELECT frameworks FROM compliance_subjects WHERE subject_id = ?', [$subjectId]);

        return self::parse((string) ($row['frameworks'] ?? ''));
    }

    /**
     * @param list<string> $frameworks unknown values are dropped
     * @return list<string> what was stored
     */
    public function setFrameworks(int $subjectId, array $frameworks): array
    {
        $clean = array_values(array_filter(Framework::all(), static fn (string $f) => in_array($f, $frameworks, true)));
        $this->database->execute(
            'INSERT INTO compliance_subjects (subject_id, frameworks) VALUES (?, ?) ON DUPLICATE KEY UPDATE frameworks = VALUES(frameworks), updated_at = NOW()',
            [$subjectId, implode(',', $clean)]
        );

        return $clean;
    }

    public function attestations(int $subjectId): AttestationStore
    {
        return new AttestationStore($this->database, $subjectId);
    }

    public function snapshots(int $subjectId): SnapshotStore
    {
        return new SnapshotStore($this->database, $subjectId);
    }

    /** @throws \InvalidArgumentException for an item that is not on the customer checklist, or bad review details */
    public function record(int $subjectId, string $itemId, ?int $byUserId, string $reviewer, string $reviewedOn, ?string $nextDue, ?string $note): int
    {
        if (!in_array($itemId, ClientChecklist::ids(), true)) {
            throw new \InvalidArgumentException('Unknown checklist item.');
        }

        return $this->attestations($subjectId)->record($itemId, $byUserId, $reviewer, $reviewedOn, $nextDue, $note, $this->clock->now()->setTime(0, 0));
    }

    public function assess(int $subjectId): Assessment
    {
        $frameworks = $this->frameworks($subjectId);

        return (new ComplianceAssessor([], ClientChecklist::forFrameworks($frameworks), $this->attestations($subjectId), $this->clock))->assess();
    }

    public function snapshot(int $subjectId, ?int $byUserId, string $trigger = 'manual'): int
    {
        return $this->snapshots($subjectId)->save($this->assess($subjectId), $byUserId, $trigger);
    }

    /** @throws \InvalidArgumentException when the snapshot is not this subject's */
    public function share(int $subjectId, int $snapshotId, ?string $note, ?int $by): void
    {
        if ($this->snapshots($subjectId)->get($snapshotId) === null) {
            throw new \InvalidArgumentException('That snapshot does not exist.');
        }
        $note = $note === null ? null : mb_substr(trim($note), 0, SharedReport::NOTE_MAX);
        $this->database->execute(
            'INSERT INTO compliance_subjects (subject_id, shared_snapshot_id, shared_note, shared_by, shared_at) VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE shared_snapshot_id = VALUES(shared_snapshot_id), shared_note = VALUES(shared_note), shared_by = VALUES(shared_by), shared_at = NOW()',
            [$subjectId, $snapshotId, $note === '' ? null : $note, $by]
        );
    }

    public function unshare(int $subjectId): void
    {
        $this->database->execute('UPDATE compliance_subjects SET shared_snapshot_id = NULL, shared_note = NULL, shared_by = NULL, shared_at = NULL WHERE subject_id = ?', [$subjectId]);
    }

    /** @return array{snapshot_id:int, note:?string, published_at:string, taken_at:string, view:array<string,mixed>}|null the reduced view only */
    public function shared(int $subjectId): ?array
    {
        $row = $this->database->fetchOne('SELECT shared_snapshot_id, shared_note, shared_at FROM compliance_subjects WHERE subject_id = ? AND shared_snapshot_id IS NOT NULL', [$subjectId]);
        if ($row === null) {
            return null;
        }
        $snap = $this->snapshots($subjectId)->get((int) $row['shared_snapshot_id']);
        if ($snap === null) {
            return null;
        }

        return [
            'snapshot_id' => (int) $row['shared_snapshot_id'],
            'note' => $row['shared_note'] === null ? null : (string) $row['shared_note'],
            'published_at' => (string) $row['shared_at'],
            'taken_at' => $snap['taken_at'],
            'view' => SharedReport::view($snap['assessment']),
        ];
    }

    /**
     * One row per subject that has frameworks chosen, for an overview across all customers.
     *
     * @return list<array{subject_id:int, frameworks:list<string>, score:?float, items:int, current:int, due_soon:int, overdue:int, never:int, last_snapshot:?string, shared:bool}>
     */
    public function overview(): array
    {
        $rows = $this->database->fetchAll("SELECT subject_id, frameworks, shared_snapshot_id FROM compliance_subjects WHERE subject_id > 0 AND frameworks <> '' ORDER BY subject_id");
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['subject_id'];
            $s = $this->assess($id)->summaries['all'];
            $out[] = [
                'subject_id' => $id,
                'frameworks' => self::parse((string) $r['frameworks']),
                'score' => $s['score'] === null ? null : (float) $s['score'],
                'items' => (int) $s['items'],
                'current' => (int) $s['manual_current'],
                'due_soon' => (int) $s['manual_due_soon'],
                'overdue' => (int) $s['manual_overdue'],
                'never' => (int) $s['manual_never'],
                'last_snapshot' => $this->snapshots($id)->latestTakenAt(),
                'shared' => $r['shared_snapshot_id'] !== null,
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function parse(string $csv): array
    {
        return array_values(array_filter(explode(',', $csv), [Framework::class, 'isValid']));
    }
}
