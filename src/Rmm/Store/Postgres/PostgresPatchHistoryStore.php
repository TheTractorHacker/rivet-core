<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\PatchHistoryStore;

/**
 * {@see PatchHistoryStore} on PostgreSQL (`rmm_patch_history`).
 *
 * @internal
 */
final class PostgresPatchHistoryStore implements PatchHistoryStore
{
    private const COLUMNS = 'id, device_id, client_id, update_id, title, action, result, code, detail, job_id, user_id';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function record(array $entry): void
    {
        $this->db->execute('INSERT INTO rmm_patch_history (device_id, client_id, update_id, title, action, result, code, detail, "at", job_id, user_id) VALUES ' . PostgresSql::rows(1, 11),
            [$entry['device_id'], $entry['client_id'], $entry['update_id'], $entry['title'], $entry['action'], $entry['result'], $entry['code'], $entry['detail'], $entry['at'], $entry['job_id'], $entry['user_id']]);
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        $limit = max(1, min(500, $limit));

        return ['rows' => $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('"at"', '"at"') . ' FROM rmm_patch_history WHERE device_id = ? ORDER BY rmm_patch_history."at" DESC, id DESC LIMIT '
            . $limit . ' OFFSET ' . max(0, $offset), [$deviceId]),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM rmm_patch_history WHERE device_id = ?', [$deviceId])];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->db->execute('DELETE FROM rmm_patch_history WHERE id IN (SELECT id FROM rmm_patch_history WHERE "at" < ? LIMIT ' . max(0, $limit) . ')', [$cutoffUtc]);
    }
}
