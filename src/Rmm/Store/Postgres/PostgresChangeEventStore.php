<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ChangeEventStore;

/**
 * {@see ChangeEventStore} on PostgreSQL: the rules and the ledger of {@see \RivetCore\Rmm\Store\MariaDb\MariaDbChangeEventStore} in one PostgreSQL transaction per
 * batch. Rows carry `client_id` as written with the record (the ledger row carries the device's current client for the scoped fleet count) and no host name.
 *
 * @internal
 */
final class PostgresChangeEventStore implements ChangeEventStore
{
    private const COLUMNS = 'change_id, device_id, client_id, kind, watch, change_type, target, item, old_value, new_value, summary, old_hash, new_hash, actor, process, flags';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson, ?int $clientId = null): array
    {
        $kept = [];
        $inserted = 0;
        $dropped = $rejected;
        $this->db->transaction(function () use ($deviceId, $caps, $nowTs, $records, $rejected, &$dropped, &$kept, &$inserted, $agentDropped, $configVersion, $monitorJson, $clientId): void {
            $kept = [];
            $inserted = 0;
            $dropped = $rejected;
            $this->db->execute('INSERT INTO endpoint_agent_change_state (device_id) VALUES (?) ON CONFLICT DO NOTHING', [$deviceId]);
            $st = $this->db->queryOne('SELECT row_count, ' . PostgresSql::ts('last_change_at', 'last_change_at') . ', ' . PostgresSql::ts('window_start', 'window_start') . ', window_count, config_version, monitor_json, '
                . PostgresSql::ts('monitor_at', 'monitor_at') . ' FROM endpoint_agent_change_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
            $winStart = PostgresSql::epoch($st['window_start'] ?? null);
            $winCount = (int) ($st['window_count'] ?? 0);
            if ($winStart === 0 || $nowTs - $winStart >= 60) {
                $winStart = $nowTs;
                $winCount = 0;
            }
            $rows = (int) ($st['row_count'] ?? 0);
            $room = max(0, $caps['rate_per_min'] - $winCount);
            $deviceRoom = max(0, (int) ceil($caps['device_max_rows'] * 1.2) - $rows);
            $fleetRoom = max(0, (int) ceil($caps['fleet_max_rows'] * 1.1) - $this->fleetRowCount());
            $allow = min(count($records), $room, $deviceRoom, $fleetRoom);
            $dropped += count($records) - $allow;
            $kept = array_slice($records, 0, $allow);
            $nowSql = PostgresSql::utc($nowTs);
            foreach (array_chunk($kept, 50) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $deviceId, $clientId, PostgresSql::utc($r['ts']), $nowSql, $r['kind'], $r['watch'], $r['change'], $r['target'], $r['item'], $r['old'], $r['new'],
                        $r['summary'], $r['old_hash'], $r['new_hash'], $r['actor'], $r['process'], $r['flags'], $r['dedupe']);
                }
                $inserted += $this->db->execute('INSERT INTO endpoint_agent_change_records (device_id, client_id, occurred_at, received_at, kind, watch, change_type, target, item, old_value, new_value, summary, old_hash, new_hash, actor, process, flags, dedupe) VALUES '
                    . PostgresSql::rows(count($chunk), 18, "decode(?, 'hex')") . ' ON CONFLICT DO NOTHING', $params);
            }
            $last = $kept === [] ? null : PostgresSql::utc(max(array_column($kept, 'ts')));
            $lastChange = $last === null ? ($st['last_change_at'] ?? null) : max((string) ($st['last_change_at'] ?? ''), $last);
            $this->db->execute('UPDATE endpoint_agent_change_state SET client_id = COALESCE(?, client_id), row_count = row_count + ?, dropped_total = dropped_total + ?, last_change_at = ?, last_batch_at = ?,
                window_start = ?, window_count = ?, config_version = ?, monitor_json = ?, monitor_at = ? WHERE device_id = ?',
                [$clientId, $inserted, $dropped + $agentDropped, $lastChange === '' ? null : $lastChange, $nowSql, PostgresSql::utc($winStart), $winCount + count($kept),
                    $configVersion === '' ? (string) ($st['config_version'] ?? '') : $configVersion, $monitorJson ?? ($st['monitor_json'] ?? null), $monitorJson === null ? ($st['monitor_at'] ?? null) : $nowSql, $deviceId]);
        });

        return ['inserted' => $inserted, 'dropped' => $dropped, 'kept' => $kept];
    }

    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array
    {
        $rows = $this->db->query("SELECT encode(dedupe, 'hex') AS h FROM endpoint_agent_change_records WHERE device_id = ? AND received_at = ? ORDER BY change_id DESC LIMIT 500", [$deviceId, $receivedAtUtc]);

        return array_map(static fn (array $r): string => strtolower((string) $r['h']), $rows);
    }

    public function search(ChangeCriteria $c, int $limit, int $offset): array
    {
        $where = ['occurred_at >= ?', 'occurred_at <= ?'];
        $params = [$c->fromUtc, $c->toUtc];
        if ($c->deviceId > 0) {
            $where[] = 'device_id = ?';
            $params[] = $c->deviceId;
        }
        if ($c->clientId > 0) {
            $where[] = 'client_id = ?';
            $params[] = $c->clientId;
        }
        if ($c->visibleClientIds !== null) {
            if ($c->visibleClientIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            $where[] = 'client_id IN (' . PostgresSql::in(count($c->visibleClientIds)) . ')';
            array_push($params, ...$c->visibleClientIds);
        }
        if ($c->kind !== null) {
            $where[] = 'kind = ?';
            $params[] = $c->kind;
        }
        if ($c->watch !== null) {
            $where[] = 'watch = ?';
            $params[] = $c->watch;
        }
        if ($c->changeType !== null) {
            $where[] = 'change_type = ?';
            $params[] = $c->changeType;
        }
        foreach (['target' => $c->targetContains, 'item' => $c->itemContains, 'actor' => $c->actorContains] as $col => $needle) {
            if ($needle !== null && $needle !== '') {
                $where[] = "$col ILIKE ? ESCAPE '!'";
                $params[] = PostgresSql::contains($needle);
            }
        }
        if ($c->anyText !== null && $c->anyText !== '') {
            $like = PostgresSql::contains($c->anyText);
            $where[] = "(target ILIKE ? ESCAPE '!' OR item ILIKE ? ESCAPE '!' OR old_value ILIKE ? ESCAPE '!' OR new_value ILIKE ? ESCAPE '!' OR summary ILIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('occurred_at', 'occurred_at') . ', ' . PostgresSql::ts('received_at', 'received_at') . " FROM endpoint_agent_change_records WHERE $w
            ORDER BY endpoint_agent_change_records.occurred_at DESC, change_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records WHERE $w LIMIT 10001) t", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->db->query('SELECT ' . self::COLUMNS . ', ' . PostgresSql::ts('occurred_at', 'occurred_at') . ', ' . PostgresSql::ts('received_at', 'received_at') . ' FROM endpoint_agent_change_records
            WHERE device_id = ? AND occurred_at BETWEEN ? AND ? ORDER BY endpoint_agent_change_records.occurred_at DESC, change_id DESC LIMIT ' . $limit, [$deviceId, $fromUtc, $toUtc]);
        $actors = array_map(static fn (array $r): array => ['actor' => (string) $r['actor'], 'c' => (int) $r['c']],
            $this->db->query('SELECT actor, COUNT(*)::bigint AS c FROM endpoint_agent_change_records WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY actor ORDER BY c DESC, upper(actor) COLLATE "C", actor COLLATE "C" LIMIT 20', [$deviceId, $fromUtc, $toUtc]));
        $days = array_map(static fn (array $r): array => ['d' => (string) $r['d'], 'c' => (int) $r['c']],
            $this->db->query("SELECT to_char((occurred_at AT TIME ZONE 'UTC')::date, 'YYYY-MM-DD') AS d, COUNT(*)::bigint AS c FROM endpoint_agent_change_records
                WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY 1 ORDER BY d DESC LIMIT 400", [$deviceId, $fromUtc, $toUtc]));

        return ['rows' => $rows, 'actors' => $actors, 'days' => $days];
    }

    public function countMatching(int $deviceId, string $sinceUtc, string $watch, string $kind, int $changeType, string $targetContains, string $itemContains, int $stopAt): int
    {
        $where = ['device_id = ?', 'occurred_at >= ?'];
        $params = [$deviceId, $sinceUtc];
        foreach (['watch' => $watch, 'kind' => $kind] as $col => $v) {
            if ($v !== '') {
                $where[] = "$col = ?";
                $params[] = $v;
            }
        }
        if ($changeType !== 0) {
            $where[] = 'change_type = ?';
            $params[] = $changeType;
        }
        foreach (['target' => $targetContains, 'item' => $itemContains] as $col => $v) {
            if ($v !== '') {
                $where[] = "$col ILIKE ? ESCAPE '!'";
                $params[] = PostgresSql::contains($v);
            }
        }

        return (int) $this->db->value('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records WHERE ' . implode(' AND ', $where) . ' LIMIT ' . max(0, $stopAt) . ') t', $params);
    }

    public function fleetRowCount(?array $visibleClientIds = null): int
    {
        if ($visibleClientIds === null) {
            return (int) $this->db->value('SELECT COALESCE(SUM(row_count), 0)::bigint FROM endpoint_agent_change_state');
        }
        if ($visibleClientIds === []) {
            return 0;
        }

        return (int) $this->db->value('SELECT COALESCE(SUM(row_count), 0)::bigint FROM endpoint_agent_change_state WHERE client_id IN (' . PostgresSql::in(count($visibleClientIds)) . ')', $visibleClientIds);
    }

    public function deviceLedger(int $deviceId): ?array
    {
        $s = $this->db->queryOne('SELECT row_count, dropped_total, ' . PostgresSql::ts('last_change_at', 'last_change_at') . ', ' . PostgresSql::ts('last_batch_at', 'last_batch_at') . ', config_version, monitor_json, '
            . PostgresSql::ts('monitor_at', 'monitor_at') . ' FROM endpoint_agent_change_state WHERE device_id = ?', [$deviceId]);
        if ($s === null) {
            return null;
        }

        return ['rows' => (int) $s['row_count'], 'dropped_total' => (int) $s['dropped_total'],
            'last_change_at' => isset($s['last_change_at']) ? (string) $s['last_change_at'] : null, 'last_batch_at' => isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null,
            'config_version' => (string) ($s['config_version'] ?? ''), 'monitor_json' => isset($s['monitor_json']) && is_string($s['monitor_json']) ? $s['monitor_json'] : null,
            'monitor_at' => isset($s['monitor_at']) ? (string) $s['monitor_at'] : null];
    }

    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array
    {
        if ($visibleClientIds === []) {
            return [];
        }
        $scope = $visibleClientIds === null ? '' : ' AND client_id IN (' . PostgresSql::in(count($visibleClientIds)) . ')';

        return array_map(static fn (array $r): array => ['watch' => (string) $r['watch'], 'c' => (int) $r['c']],
            $this->db->query('SELECT watch, COUNT(*)::bigint AS c FROM endpoint_agent_change_records WHERE occurred_at >= ?' . $scope . ' GROUP BY watch ORDER BY c DESC, upper(watch) COLLATE "C", watch COLLATE "C" LIMIT 30',
                [$sinceUtc, ...($visibleClientIds ?? [])]));
    }

    public function ledgerTotals(): array
    {
        $r = $this->db->queryOne('SELECT COALESCE(SUM(row_count), 0)::bigint AS rows, COUNT(*) FILTER (WHERE last_batch_at IS NOT NULL)::bigint AS devices FROM endpoint_agent_change_state') ?? [];

        return ['rows' => (int) ($r['rows'] ?? 0), 'devices' => (int) ($r['devices'] ?? 0)];
    }

    public function hasLedger(): bool
    {
        return $this->db->value('SELECT 1 FROM endpoint_agent_change_state LIMIT 1') !== null;
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return (int) $this->db->transaction(function () use ($deviceId, $clientId): int {
            $this->db->execute('UPDATE endpoint_agent_change_state SET client_id = ? WHERE device_id = ? AND client_id IS DISTINCT FROM ?', [$clientId, $deviceId, $clientId]);

            return $this->db->execute('UPDATE endpoint_agent_change_records SET client_id = ? WHERE device_id = ? AND client_id IS DISTINCT FROM ?', [$clientId, $deviceId, $clientId]);
        });
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        $budget = $runCap;
        $out = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];
        while ($budget > 0) {
            $n = $this->deleteBatch('occurred_at < ?', [$cutoffUtc], min($batch, $budget));
            $out['retention'] += $n;
            $budget -= $n;
            if ($n === 0) {
                break;
            }
            if ($pause !== null) {
                $pause(20000);
            }
        }
        foreach ($this->db->query('SELECT device_id, row_count FROM endpoint_agent_change_state WHERE row_count > ? ORDER BY row_count DESC, device_id LIMIT 200', [$deviceMaxRows]) as $s) {
            $excess = (int) $s['row_count'] - $deviceMaxRows;
            while ($excess > 0 && $budget > 0) {
                $n = $this->deleteBatch('device_id = ?', [(int) $s['device_id']], min($batch, $excess, $budget));
                $out['device_cap'] += $n;
                $excess -= $n;
                $budget -= $n;
                if ($n === 0) {
                    break;
                }
                if ($pause !== null) {
                    $pause(20000);
                }
            }
        }
        $excess = $this->fleetRowCount() - $fleetMaxRows;
        while ($excess > 0 && $budget > 0) {
            $n = $this->deleteBatch('TRUE', [], min($batch, $excess, $budget));
            $out['fleet_cap'] += $n;
            $excess -= $n;
            $budget -= $n;
            if ($n === 0) {
                break;
            }
            if ($pause !== null) {
                $pause(20000);
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $params
     */
    private function deleteBatch(string $where, array $params, int $n): int
    {
        return (int) $this->db->transaction(function () use ($where, $params, $n): int {
            $rows = $this->db->query("SELECT change_id, device_id FROM endpoint_agent_change_records WHERE $where ORDER BY occurred_at, change_id LIMIT " . max(1, $n) . ' FOR UPDATE', $params);
            if ($rows === []) {
                return 0;
            }
            $perDevice = [];
            foreach ($rows as $r) {
                $perDevice[(int) $r['device_id']] = ($perDevice[(int) $r['device_id']] ?? 0) + 1;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['change_id'], $rows);
            $this->db->execute('DELETE FROM endpoint_agent_change_records WHERE change_id IN (' . PostgresSql::in(count($ids)) . ')', $ids);
            ksort($perDevice);
            foreach ($perDevice as $device => $c) {
                $this->db->execute('UPDATE endpoint_agent_change_state SET row_count = GREATEST(0, row_count - ?) WHERE device_id = ?', [$c, $device]);
            }

            return count($ids);
        });
    }
}
