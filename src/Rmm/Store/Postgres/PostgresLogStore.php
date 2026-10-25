<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\LogStore;

/**
 * {@see LogStore} on PostgreSQL: the same admission rules and ledger as {@see \RivetCore\Rmm\Store\MariaDb\MariaDbLogStore}, in one PostgreSQL transaction per batch
 * (`INSERT ... ON CONFLICT DO NOTHING` with a `bytea` dedupe key, `SELECT ... FOR UPDATE` on the ledger row, `ILIKE ... ESCAPE '!'`, a locked batch delete).
 * Search rows carry `client_id` as written with the entry and no host name (the caller adds it from the control database).
 *
 * @internal
 */
final class PostgresLogStore implements LogStore
{
    private const COLUMNS = 'device_id, client_id, level, source, code, message';

    public function __construct(private readonly PostgresConnectionInterface $db)
    {
    }

    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array
    {
        $kept = [];
        $inserted = 0;
        $dropped = $rejected;
        $this->db->transaction(function () use ($deviceId, $caps, $nowTs, $entries, $rejected, &$dropped, &$kept, &$inserted, $agentDropped, $clientId): void {
            // the closure runs again after a deadlock: start from the same values every time
            $kept = [];
            $inserted = 0;
            $dropped = $rejected;
            $this->db->execute('INSERT INTO endpoint_agent_log_state (device_id) VALUES (?) ON CONFLICT DO NOTHING', [$deviceId]);
            $st = $this->db->queryOne('SELECT row_count, ' . PostgresSql::ts('last_event_at', 'last_event_at') . ', ' . PostgresSql::ts('window_start', 'window_start') . ', window_count
                FROM endpoint_agent_log_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
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
            $allow = min(count($entries), $room, $deviceRoom, $fleetRoom);
            $dropped += count($entries) - $allow;
            $kept = array_slice($entries, 0, $allow);
            $nowSql = PostgresSql::utc($nowTs);
            foreach (array_chunk($kept, 100) as $chunk) {
                $params = [];
                foreach ($chunk as $r) {
                    array_push($params, $deviceId, $clientId, PostgresSql::utc($r['ts']), $nowSql, $r['level'], $r['source'], $r['code'], $r['message'], $r['hash']);
                }
                $inserted += $this->db->execute('INSERT INTO endpoint_agent_log_events (device_id, client_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES '
                    . PostgresSql::rows(count($chunk), 9, "decode(?, 'hex')") . ' ON CONFLICT DO NOTHING', $params);
            }
            $last = $kept === [] ? null : PostgresSql::utc(max(array_column($kept, 'ts')));
            $lastEvent = $last === null ? ($st['last_event_at'] ?? null) : max((string) ($st['last_event_at'] ?? ''), $last);
            $this->db->execute('UPDATE endpoint_agent_log_state SET row_count = row_count + ?, dropped_total = dropped_total + ?, last_event_at = ?, last_batch_at = ?, window_start = ?, window_count = ?
                WHERE device_id = ?', [$inserted, $dropped + $agentDropped, $lastEvent === '' ? null : $lastEvent, $nowSql, PostgresSql::utc($winStart), $winCount + $inserted, $deviceId]);
        });

        return ['inserted' => $inserted, 'dropped' => $dropped, 'kept' => $kept];
    }

    public function search(LogCriteria $c, int $limit, int $offset): array
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
        if ($c->maxLevel !== null) {
            $where[] = 'level <= ?';
            $params[] = $c->maxLevel;
        }
        if ($c->sourceContains !== null && $c->sourceContains !== '') {
            $where[] = "source ILIKE ? ESCAPE '!'";
            $params[] = PostgresSql::contains($c->sourceContains);
        }
        if ($c->code !== null) {
            $where[] = 'code = ?';
            $params[] = $c->code;
        }
        if ($c->messageContains !== null && $c->messageContains !== '') {
            $where[] = "message ILIKE ? ESCAPE '!'";
            $params[] = PostgresSql::contains($c->messageContains);
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->db->query('SELECT event_id, ' . self::COLUMNS . ', ' . PostgresSql::ts('occurred_at', 'occurred_at') . " FROM endpoint_agent_log_events WHERE $w
            ORDER BY endpoint_agent_log_events.occurred_at DESC, event_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->db->value("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events WHERE $w LIMIT 10001) t", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int
    {
        $where = ['device_id = ?', 'occurred_at >= ?', 'level <= ?'];
        $params = [$deviceId, $sinceUtc, $maxLevel];
        if ($sourceContains !== '') {
            $where[] = "source ILIKE ? ESCAPE '!'";
            $params[] = PostgresSql::contains($sourceContains);
        }
        if ($code !== null) {
            $where[] = 'code = ?';
            $params[] = $code;
        }
        if ($messageContains !== '') {
            $where[] = "message ILIKE ? ESCAPE '!'";
            $params[] = PostgresSql::contains($messageContains);
        }

        return (int) $this->db->value('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events WHERE ' . implode(' AND ', $where) . ' LIMIT ' . max(0, $stopAt) . ') t', $params);
    }

    public function fleetRowCount(): int
    {
        return (int) $this->db->value('SELECT COALESCE(SUM(row_count), 0)::bigint FROM endpoint_agent_log_state');
    }

    public function deviceLedger(int $deviceId): ?array
    {
        $s = $this->db->queryOne('SELECT row_count, dropped_total, ' . PostgresSql::ts('last_event_at', 'last_event_at') . ', ' . PostgresSql::ts('last_batch_at', 'last_batch_at') . ' FROM endpoint_agent_log_state WHERE device_id = ?', [$deviceId]);
        if ($s === null) {
            return null;
        }

        return ['rows' => (int) $s['row_count'], 'dropped_total' => (int) $s['dropped_total'],
            'last_event_at' => isset($s['last_event_at']) ? (string) $s['last_event_at'] : null, 'last_batch_at' => isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null];
    }

    public function oldestEventAt(?int $deviceId = null): ?string
    {
        $v = $deviceId === null ? $this->db->value('SELECT ' . PostgresSql::ts('MIN(occurred_at)') . ' FROM endpoint_agent_log_events')
            : $this->db->value('SELECT ' . PostgresSql::ts('MIN(occurred_at)') . ' FROM endpoint_agent_log_events WHERE device_id = ?', [$deviceId]);

        return $v === null ? null : (string) $v;
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->db->execute('UPDATE endpoint_agent_log_events SET client_id = ? WHERE device_id = ? AND client_id IS DISTINCT FROM ?', [$clientId, $deviceId, $clientId]);
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
        foreach ($this->db->query('SELECT device_id, row_count FROM endpoint_agent_log_state WHERE row_count > ? ORDER BY row_count DESC, device_id LIMIT 200', [$deviceMaxRows]) as $s) {
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
     * Delete one batch of the oldest rows that match, and lower the owners' counters by exactly what was deleted, in one transaction.
     *
     * @param list<mixed> $params
     */
    private function deleteBatch(string $where, array $params, int $n): int
    {
        return (int) $this->db->transaction(function () use ($where, $params, $n): int {
            $rows = $this->db->query("SELECT event_id, device_id FROM endpoint_agent_log_events WHERE $where ORDER BY occurred_at, event_id LIMIT " . max(1, $n) . ' FOR UPDATE', $params);
            if ($rows === []) {
                return 0;
            }
            $perDevice = [];
            foreach ($rows as $r) {
                $perDevice[(int) $r['device_id']] = ($perDevice[(int) $r['device_id']] ?? 0) + 1;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['event_id'], $rows);
            $this->db->execute('DELETE FROM endpoint_agent_log_events WHERE event_id IN (' . PostgresSql::in(count($ids)) . ')', $ids);
            ksort($perDevice);   // one lock order for every pruner
            foreach ($perDevice as $device => $c) {
                $this->db->execute('UPDATE endpoint_agent_log_state SET row_count = GREATEST(0, row_count - ?) WHERE device_id = ?', [$c, $device]);
            }

            return count($ids);
        });
    }
}
