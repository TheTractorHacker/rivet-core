<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\PatchHistoryStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see PatchHistoryStore} on MariaDB / MySQL: the statements the patch install service, the patch reader and the patch scheduler ran before the store existed, unchanged.
 *
 * @internal
 */
final class MariaDbPatchHistoryStore implements PatchHistoryStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function record(array $entry): void
    {
        $this->sql->run('INSERT INTO rmm_patch_history (device_id, client_id, update_id, title, action, result, code, detail, at, job_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$entry['device_id'], $entry['client_id'], $entry['update_id'], $entry['title'], $entry['action'], $entry['result'], $entry['code'], $entry['detail'], $entry['at'], $entry['job_id'], $entry['user_id']]);
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        $limit = max(1, min(500, $limit));

        return ['rows' => $this->sql->all("SELECT * FROM rmm_patch_history WHERE device_id = ? ORDER BY at DESC, id DESC LIMIT $limit OFFSET " . max(0, $offset), [$deviceId]),
            'total' => (int) $this->sql->val('SELECT COUNT(*) FROM rmm_patch_history WHERE device_id = ?', [$deviceId])];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->sql->run('DELETE FROM rmm_patch_history WHERE at < ? LIMIT ' . $limit, [$cutoffUtc]);
    }
}
