<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Database\DatabaseInterface;

/**
 * The one report an administrator chooses to show to portal users. Only a reduced view of a saved snapshot is ever exposed:
 * framework scores, the manual checklist's titles and state, and each automatic check's title and result. Never details,
 * counts, account names, settings values, reviewer names, notes or links into the admin area.
 */
final class SharedReport
{
    public const NOTE_MAX = 1000;

    public function __construct(private DatabaseInterface $database)
    {
    }

    /** @throws \InvalidArgumentException when the snapshot does not exist */
    public function publish(int $snapshotId, ?string $note, ?int $publishedBy): void
    {
        $snap = (new SnapshotStore($this->database))->get($snapshotId);
        if ($snap === null) {
            throw new \InvalidArgumentException('That snapshot does not exist.');
        }
        $note = $note === null ? null : mb_substr(trim($note), 0, self::NOTE_MAX);
        $this->database->execute(
            'INSERT INTO compliance_shared_report (shared_id, snapshot_id, note, published_by, published_at) VALUES (1, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE snapshot_id = VALUES(snapshot_id), note = VALUES(note), published_by = VALUES(published_by), published_at = NOW()',
            [$snapshotId, $note === '' ? null : $note, $publishedBy]
        );
    }

    public function unpublish(): void
    {
        $this->database->execute('DELETE FROM compliance_shared_report WHERE shared_id = 1');
    }

    public function isPublished(): bool
    {
        return $this->database->fetchOne('SELECT 1 AS x FROM compliance_shared_report WHERE shared_id = 1') !== null;
    }

    /** @return array{snapshot_id:int, note:?string, published_at:string, taken_at:string, view:array<string,mixed>}|null */
    public function current(): ?array
    {
        $row = $this->database->fetchOne('SELECT snapshot_id, note, published_at FROM compliance_shared_report WHERE shared_id = 1');
        if ($row === null) {
            return null;
        }
        $snap = (new SnapshotStore($this->database))->get((int) $row['snapshot_id']);
        if ($snap === null) {
            return null;
        }

        return [
            'snapshot_id' => (int) $row['snapshot_id'],
            'note' => $row['note'] === null ? null : (string) $row['note'],
            'published_at' => (string) $row['published_at'],
            'taken_at' => $snap['taken_at'],
            'view' => self::view($snap['assessment']),
        ];
    }

    /** @return array{scores:list<array{key:string,label:string,score:?float}>, manual:list<array<string,mixed>>, automatic:list<array<string,mixed>>} */
    public static function view(Assessment $a): array
    {
        $scores = [];
        foreach (['all' => 'All frameworks'] + Framework::LABELS as $key => $label) {
            $s = $a->summaries[$key]['score'] ?? null;
            $scores[] = ['key' => $key, 'label' => $label, 'score' => $s === null ? null : (float) $s];
        }
        $tags = static fn (array $controls): array => array_values(array_filter(array_keys($controls), [Framework::class, 'isValid']));
        $manual = [];
        foreach ($a->manual as $r) {
            $manual[] = ['title' => (string) $r['title'], 'category' => (string) $r['category'], 'state' => (string) $r['state'], 'reviewed_on' => $r['reviewed_on'] === null ? null : (string) $r['reviewed_on'], 'frameworks' => $tags((array) $r['controls'])];
        }
        $auto = [];
        foreach ($a->automatic as $r) {
            $auto[] = ['title' => (string) $r['title'], 'category' => (string) $r['category'], 'status' => (string) $r['status'], 'status_label' => (string) $r['status_label'], 'frameworks' => $tags((array) $r['controls'])];
        }

        return ['scores' => $scores, 'manual' => $manual, 'automatic' => $auto];
    }
}
