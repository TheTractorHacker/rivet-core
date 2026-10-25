<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\SoftwareHistoryStore;

/**
 * {@see SoftwareHistoryStore} on PostgreSQL. The inventory it describes is committed in the control database and this history here, in two transactions, so
 * {@see append()} is idempotent: a change written twice (a retried check-in) is stored once (unique index `rmm_software_history_once`).
 *
 * @internal
 */
final class PostgresSoftwareHistoryStore implements SoftwareHistoryStore
{
    private const CHUNK = 250;
    private const COLUMNS = 'history_id, device_id, software_key, name, source, change_type, old_version, new_version, publisher';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function sharesControlTransaction(): bool
    {
        return false;
    }

    public function append(int $deviceId, array $changes, string $atUtc): void
    {
        if ($changes === []) {
            return;
        }
        $this->db->transaction(function () use ($deviceId, $changes, $atUtc): void {
            foreach (array_chunk($changes, self::CHUNK) as $chunk) {
                $params = [];
                foreach ($chunk as $c) {
                    array_push($params, $deviceId, $c['key'], $c['name'], $c['source'], $c['type'], $c['old'], $c['new'], $c['publisher'], $atUtc);
                }
                $this->db->execute('INSERT INTO rmm_software_history (device_id, software_key, name, source, change_type, old_version, new_version, publisher, occurred_at) VALUES '
                    . PostgresSql::rows(count($chunk), 9) . ' ON CONFLICT DO NOTHING', $params);
            }
        });
    }

    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array
    {
        $where = 'device_id = ?';
        $params = [$deviceId];
        if ($nameLike !== null && trim($nameLike) !== '') {
            $where .= " AND name ILIKE ? ESCAPE '!'";
            $params[] = PostgresSql::contains(trim($nameLike));
        }
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM rmm_software_history WHERE $where", $params);

        return ['rows' => $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('occurred_at', 'occurred_at') . " FROM rmm_software_history WHERE $where
            ORDER BY rmm_software_history.occurred_at DESC, history_id DESC LIMIT $limit OFFSET $offset", $params), 'total' => $total];
    }

    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('occurred_at', 'occurred_at') . ' FROM rmm_software_history WHERE device_id = ? AND occurred_at BETWEEN ? AND ?
            ORDER BY rmm_software_history.occurred_at DESC, history_id DESC LIMIT ' . max(0, $limit), [$deviceId, $fromUtc, $toUtc]);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->db->execute('DELETE FROM rmm_software_history WHERE history_id IN (SELECT history_id FROM rmm_software_history WHERE occurred_at < ? LIMIT ' . max(0, $limit) . ')', [$cutoffUtc]);
    }
}
