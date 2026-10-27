<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\CheckHistoryStore;

/**
 * {@see CheckHistoryStore} on PostgreSQL (`endpoint_agent_check_history`, RANGE partitioned or a hypertable on `reported_at`).
 *
 * @internal
 */
final class PostgresCheckHistoryStore implements CheckHistoryStore
{
    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function append(int $deviceId, array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $this->db->transaction(function () use ($deviceId, $entries): void {
            foreach (array_chunk($entries, 200) as $chunk) {
                $params = [];
                foreach ($chunk as $e) {
                    array_push($params, $deviceId, $e['key'], $e['status'], $e['detail'], $e['at']);
                }
                $this->db->execute('INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES ' . PostgresSql::rows(count($chunk), 5), $params);
            }
        });
    }

    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array
    {
        $rows = $this->db->query('SELECT status, detail, ' . PostgresSql::ts('reported_at', 'reported_at') . ' FROM endpoint_agent_check_history
            WHERE device_id = ? AND check_key = ? AND reported_at >= ? ORDER BY endpoint_agent_check_history.reported_at, hist_id LIMIT ' . max(0, $limit), [$deviceId, $checkKey, $sinceUtc]);
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['status' => (string) $r['status'], 'detail' => (string) $r['detail'], 'reported_at' => (string) $r['reported_at']];
        }

        return $out;
    }

    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array
    {
        $r = $this->db->queryOne('SELECT status, ' . PostgresSql::ts('reported_at', 'reported_at') . ' FROM endpoint_agent_check_history
            WHERE device_id = ? AND check_key = ? AND reported_at < ? ORDER BY endpoint_agent_check_history.reported_at DESC, hist_id DESC LIMIT 1', [$deviceId, $checkKey, $beforeUtc]);

        return $r === null ? null : ['status' => (string) $r['status'], 'reported_at' => (string) $r['reported_at']];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->db->execute('DELETE FROM endpoint_agent_check_history t USING (SELECT hist_id, reported_at FROM endpoint_agent_check_history WHERE reported_at < ? LIMIT ' . max(0, $limit)
            . ') d WHERE t.hist_id = d.hist_id AND t.reported_at = d.reported_at', [$cutoffUtc]);
    }
}
