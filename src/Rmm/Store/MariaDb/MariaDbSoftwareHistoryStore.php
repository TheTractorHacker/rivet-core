<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\SoftwareHistoryStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see SoftwareHistoryStore} on MariaDB / MySQL: the statements the software service, the read model, the change timeline and the housekeeping ran before the
 * store existed, unchanged. It shares the connection (and so the transaction) of the caller, which keeps the history rows and the inventory update atomic.
 *
 * @internal
 */
final class MariaDbSoftwareHistoryStore implements SoftwareHistoryStore
{
    /** Rows per INSERT statement. */
    private const CHUNK = 250;

    public function __construct(private readonly Sql $sql)
    {
    }

    public function sharesControlTransaction(): bool
    {
        return true;
    }

    public function append(int $deviceId, array $changes, string $atUtc): void
    {
        foreach (array_chunk($changes, self::CHUNK) as $chunk) {
            $params = [];
            foreach ($chunk as $c) {
                array_push($params, $deviceId, $c['key'], $c['name'], $c['source'], $c['type'], $c['old'], $c['new'], $c['publisher'], $atUtc);
            }
            $this->sql->run('INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES '
                . implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?, ?)')), $params);
        }
    }

    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array
    {
        $where = 'device_id = ?';
        $params = [$deviceId];
        if ($nameLike !== null && trim($nameLike) !== '') {
            $where .= ' AND name LIKE ?';
            $params[] = '%' . addcslashes(trim($nameLike), '\\%_') . '%';
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_software_history WHERE $where", $params);

        return ['rows' => $this->sql->all("SELECT * FROM rmm_software_history WHERE $where ORDER BY occurred_at DESC, history_id DESC LIMIT $limit OFFSET $offset", $params), 'total' => $total];
    }

    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->sql->all("SELECT * FROM rmm_software_history WHERE device_id = ? AND occurred_at BETWEEN ? AND ? ORDER BY occurred_at DESC, history_id DESC LIMIT $limit", [$deviceId, $fromUtc, $toUtc]);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->sql->run('DELETE FROM `rmm_software_history` WHERE occurred_at < ? LIMIT ' . $limit, [$cutoffUtc]);
    }
}
