<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\CheckHistoryStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see CheckHistoryStore} on MariaDB / MySQL: the statements the check evaluator, the read model and the housekeeping ran before the store existed, unchanged.
 *
 * @internal
 */
final class MariaDbCheckHistoryStore implements CheckHistoryStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function append(int $deviceId, array $entries): void
    {
        foreach (array_chunk($entries, 200) as $chunk) {
            $params = [];
            foreach ($chunk as $e) {
                array_push($params, $deviceId, $e['key'], $e['status'], $e['detail'], $e['at']);
            }
            $this->sql->run('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ' . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)')), $params);
        }
    }

    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array
    {
        $rows = $this->sql->all('SELECT status, detail, reported_at FROM endpoint_agent_check_history WHERE device_id = ? AND check_key = ? AND reported_at >= ? ORDER BY reported_at, hist_id LIMIT ' . $limit,
            [$deviceId, $checkKey, $sinceUtc]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['status' => (string) $r['status'], 'detail' => (string) $r['detail'], 'reported_at' => (string) $r['reported_at']];
        }

        return $out;
    }

    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array
    {
        $r = $this->sql->one('SELECT status, reported_at FROM endpoint_agent_check_history WHERE device_id = ? AND check_key = ? AND reported_at < ? ORDER BY reported_at DESC, hist_id DESC LIMIT 1',
            [$deviceId, $checkKey, $beforeUtc]);

        return $r === null ? null : ['status' => (string) $r['status'], 'reported_at' => (string) $r['reported_at']];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->sql->run('DELETE FROM `endpoint_agent_check_history` WHERE reported_at < ? LIMIT ' . $limit, [$cutoffUtc]);
    }
}
