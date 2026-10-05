<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

use RivetCore\Database\DatabaseInterface;

/** Saved assessments, so an organization can show how its posture changed over time. Snapshots are evidence and are never pruned by retention. */
final class SnapshotStore
{
    public function __construct(private DatabaseInterface $database)
    {
    }

    public function save(Assessment $assessment, ?int $takenByUserId, string $trigger = 'manual', ?string $appVersion = null): int
    {
        $trigger = in_array($trigger, ['manual', 'scheduled'], true) ? $trigger : 'manual';

        return (int) $this->database->execute(
            'INSERT INTO compliance_snapshots (taken_by, trigger_type, app_version, summary_json, results_json) VALUES (?, ?, ?, ?, ?)',
            [
                $takenByUserId,
                $trigger,
                $appVersion === null ? null : mb_substr($appVersion, 0, 40),
                json_encode($assessment->summaries, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                json_encode($assessment->toArray(), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]
        )->insertId;
    }

    /** @return list<array<string,mixed>> newest first; summaries decoded, full results not included */
    public function list(int $limit = 24): array
    {
        $rows = $this->database->fetchAll(
            'SELECT snapshot_id, taken_at, taken_by, trigger_type, app_version, summary_json FROM compliance_snapshots ORDER BY snapshot_id DESC LIMIT ?',
            [max(1, min(500, $limit))]
        );
        foreach ($rows as &$r) {
            $r['summaries'] = json_decode((string) $r['summary_json'], true) ?: [];
            unset($r['summary_json']);
        }

        return $rows;
    }

    /** @return array{snapshot_id:int, taken_at:string, taken_by:?int, trigger_type:string, app_version:?string, assessment:Assessment}|null */
    public function get(int $id): ?array
    {
        $r = $this->database->fetchOne('SELECT snapshot_id, taken_at, taken_by, trigger_type, app_version, results_json FROM compliance_snapshots WHERE snapshot_id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $data = json_decode((string) $r['results_json'], true);
        if (!is_array($data)) {
            return null;
        }

        return [
            'snapshot_id' => (int) $r['snapshot_id'],
            'taken_at' => (string) $r['taken_at'],
            'taken_by' => $r['taken_by'] === null ? null : (int) $r['taken_by'],
            'trigger_type' => (string) $r['trigger_type'],
            'app_version' => $r['app_version'] === null ? null : (string) $r['app_version'],
            'assessment' => Assessment::fromArray($data),
        ];
    }

    public function latestTakenAt(): ?string
    {
        $r = $this->database->fetchOne('SELECT MAX(taken_at) AS t FROM compliance_snapshots');

        return $r && $r['t'] !== null ? (string) $r['t'] : null;
    }
}
