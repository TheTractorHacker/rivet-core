<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\DeploymentHistoryStore;

/**
 * {@see DeploymentHistoryStore} on PostgreSQL (`rmm_package_history`). The package name is not stored here (the catalog is control data): the caller adds it.
 *
 * @internal
 */
final class PostgresDeploymentHistoryStore implements DeploymentHistoryStore
{
    private const COLUMNS = 'id, device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, job_id, user_id';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function record(array $entry): void
    {
        $this->db->execute('INSERT INTO rmm_package_history (device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, "at", job_id, user_id) VALUES ' . PostgresSql::rows(1, 13),
            [$entry['device_id'], $entry['client_id'], $entry['package_id'], $entry['version_id'], $entry['action'], $entry['mode'], $entry['result'], $entry['code'], $entry['detail'], $entry['version'], $entry['at'], $entry['job_id'], $entry['user_id']]);
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return ['rows' => $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('"at"', '"at"') . ' FROM rmm_package_history WHERE device_id = ? ORDER BY id DESC LIMIT '
            . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset), [$deviceId]),
            'total' => (int) $this->db->value('SELECT COUNT(*) FROM rmm_package_history WHERE device_id = ?', [$deviceId])];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->db->execute('DELETE FROM rmm_package_history WHERE id IN (SELECT id FROM rmm_package_history WHERE "at" < ? LIMIT ' . max(0, $limit) . ')', [$cutoffUtc]);
    }
}
