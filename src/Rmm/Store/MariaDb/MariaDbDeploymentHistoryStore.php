<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\DeploymentHistoryStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see DeploymentHistoryStore} on MariaDB / MySQL: the statements the deploy service, the deploy reader and the deploy scheduler ran before the store existed,
 * unchanged. The package name is not read here any more (phase 2 of #89): the caller adds it from the catalog.
 *
 * @internal
 */
final class MariaDbDeploymentHistoryStore implements DeploymentHistoryStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function record(array $entry): void
    {
        $this->sql->run('INSERT INTO rmm_package_history (device_id, client_id, package_id, version_id, action, mode, result, code, detail, version, at, job_id, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$entry['device_id'], $entry['client_id'], $entry['package_id'], $entry['version_id'], $entry['action'], $entry['mode'], $entry['result'], $entry['code'], $entry['detail'], $entry['version'], $entry['at'], $entry['job_id'], $entry['user_id']]);
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return ['rows' => $this->sql->all('SELECT * FROM rmm_package_history WHERE device_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)) . ' OFFSET ' . max(0, $offset), [$deviceId]),
            'total' => (int) $this->sql->val('SELECT COUNT(*) FROM rmm_package_history WHERE device_id = ?', [$deviceId])];
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->sql->run('DELETE FROM rmm_package_history WHERE at < ? LIMIT ' . $limit, [$cutoffUtc]);
    }
}
