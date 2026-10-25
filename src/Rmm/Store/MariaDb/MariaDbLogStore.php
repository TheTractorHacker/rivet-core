<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\LogStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see LogStore} on MariaDB / MySQL: the statements the log service and the log rule service ran before the store existed, unchanged (INSERT IGNORE with the
 * UNHEX dedupe key, SELECT ... FOR UPDATE on the ledger row, DELETE of a locked batch). Since phase 2 of #89 it no longer joins the device table: the client is stored with each
 * entry (`client_id`) and the caller adds host names.
 *
 * @internal
 */
final class MariaDbLogStore implements LogStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function admit(int $deviceId, array $entries, array $caps, int $nowTs, int $rejected, int $agentDropped, ?int $clientId = null): array
    {
        $kept = [];
        $inserted = 0;
        $dropped = $rejected;
        $this->sql->transaction(function () use ($deviceId, $caps, $nowTs, $entries, &$dropped, &$kept, &$inserted, $agentDropped, $clientId): void {
            $this->sql->run('INSERT IGNORE INTO endpoint_agent_log_state (device_id) VALUES (?)', [$deviceId]);
            $st = $this->sql->one('SELECT * FROM endpoint_agent_log_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
            $budget = $caps['rate_per_min'];
            $winStart = ($st['window_start'] ?? null) === null ? 0 : Sql::ts((string) $st['window_start']);
            $winCount = (int) ($st['window_count'] ?? 0);
            if ($winStart === 0 || $nowTs - $winStart >= 60) {
                $winStart = $nowTs;
                $winCount = 0;
            }
            $rows = (int) ($st['row_count'] ?? 0);
            $room = max(0, $budget - $winCount);
            $deviceRoom = max(0, (int) ceil($caps['device_max_rows'] * 1.2) - $rows);
            $fleetRoom = max(0, (int) ceil($caps['fleet_max_rows'] * 1.1) - $this->fleetRowCount());
            $allow = min(count($entries), $room, $deviceRoom, $fleetRoom);
            $dropped += count($entries) - $allow;
            $kept = array_slice($entries, 0, $allow);
            $nowSql = gmdate('Y-m-d H:i:s', $nowTs);
            foreach (array_chunk($kept, 100) as $chunk) {
                $params = [];
                $sqlRows = [];
                foreach ($chunk as $r) {
                    $sqlRows[] = '(?, ?, ?, ?, ?, ?, ?, ?, UNHEX(?))';
                    array_push($params, $deviceId, $clientId, gmdate('Y-m-d H:i:s', $r['ts']), $nowSql, $r['level'], $r['source'], $r['code'], $r['message'], $r['hash']);
                }
                $inserted += $this->sql->run('INSERT IGNORE INTO endpoint_agent_log_events (device_id, client_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES ' . implode(',', $sqlRows), $params);
            }
            $last = $kept === [] ? null : gmdate('Y-m-d H:i:s', max(array_column($kept, 'ts')));
            $this->sql->run('UPDATE endpoint_agent_log_state SET row_count = row_count + ?, dropped_total = dropped_total + ?, last_event_at = IF(? IS NULL, last_event_at, GREATEST(COALESCE(last_event_at, ?), ?)),
                last_batch_at = ?, window_start = ?, window_count = ? WHERE device_id = ?',
                [$inserted, $dropped + $agentDropped, $last, $last, $last, $nowSql, gmdate('Y-m-d H:i:s', $winStart), $winCount + $inserted, $deviceId]);
        });

        return ['inserted' => $inserted, 'dropped' => $dropped, 'kept' => $kept];
    }

    public function search(LogCriteria $c, int $limit, int $offset): array
    {
        $where = ['e.occurred_at >= ?', 'e.occurred_at <= ?'];
        $params = [$c->fromUtc, $c->toUtc];
        if ($c->deviceId > 0) {
            $where[] = 'e.device_id = ?';
            $params[] = $c->deviceId;
        }
        if ($c->clientId > 0) {
            $where[] = 'e.client_id = ?';
            $params[] = $c->clientId;
        }
        if ($c->visibleClientIds !== null) {
            if ($c->visibleClientIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            $where[] = 'e.client_id IN (' . implode(',', array_fill(0, count($c->visibleClientIds), '?')) . ')';
            array_push($params, ...$c->visibleClientIds);
        }
        if ($c->maxLevel !== null) {
            $where[] = 'e.level <= ?';
            $params[] = $c->maxLevel;
        }
        if ($c->sourceContains !== null && $c->sourceContains !== '') {
            $where[] = "e.source LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like($c->sourceContains) . '%';
        }
        if ($c->code !== null) {
            $where[] = 'e.code = ?';
            $params[] = $c->code;
        }
        if ($c->messageContains !== null && $c->messageContains !== '') {
            $where[] = "e.message LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like($c->messageContains) . '%';
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->sql->all("SELECT e.event_id, e.device_id, e.client_id, e.occurred_at, e.level, e.source, e.code, e.message
            FROM endpoint_agent_log_events e WHERE $w ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events e WHERE $w LIMIT 10001) t", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    public function countMatching(int $deviceId, string $sinceUtc, int $maxLevel, string $sourceContains, ?int $code, string $messageContains, int $stopAt): int
    {
        $where = ['device_id = ?', 'occurred_at >= ?', 'level <= ?'];
        $params = [$deviceId, $sinceUtc, $maxLevel];
        if ($sourceContains !== '') {
            $where[] = "source LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like($sourceContains) . '%';
        }
        if ($code !== null) {
            $where[] = 'code = ?';
            $params[] = $code;
        }
        if ($messageContains !== '') {
            $where[] = "message LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like($messageContains) . '%';
        }

        return (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events WHERE ' . implode(' AND ', $where) . ' LIMIT ' . $stopAt . ') t', $params);
    }

    public function fleetRowCount(): int
    {
        return (int) $this->sql->val('SELECT COALESCE(SUM(row_count), 0) FROM endpoint_agent_log_state');
    }

    public function deviceLedger(int $deviceId): ?array
    {
        $s = $this->sql->one('SELECT * FROM endpoint_agent_log_state WHERE device_id = ?', [$deviceId]);
        if ($s === null) {
            return null;
        }

        return ['rows' => (int) ($s['row_count'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
            'last_event_at' => isset($s['last_event_at']) ? (string) $s['last_event_at'] : null, 'last_batch_at' => isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null];
    }

    public function oldestEventAt(?int $deviceId = null): ?string
    {
        $v = $deviceId === null ? $this->sql->val('SELECT MIN(occurred_at) FROM endpoint_agent_log_events')
            : $this->sql->val('SELECT MIN(occurred_at) FROM endpoint_agent_log_events WHERE device_id = ?', [$deviceId]);

        return $v === null ? null : (string) $v;
    }

    public function reassignClient(int $deviceId, int $clientId): int
    {
        return $this->sql->run('UPDATE endpoint_agent_log_events SET client_id = ? WHERE device_id = ? AND (client_id IS NULL OR client_id <> ?)', [$clientId, $deviceId, $clientId]);
    }

    public function enforceLimits(string $cutoffUtc, int $deviceMaxRows, int $fleetMaxRows, int $runCap, int $batch, ?\Closure $pause = null): array
    {
        $budget = $runCap;
        $out = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];
        while ($budget > 0) {
            $n = $this->deleteBatch('e.occurred_at < ?', [$cutoffUtc], min($batch, $budget));
            $out['retention'] += $n;
            $budget -= $n;
            if ($n === 0) {
                break;
            }
            if ($pause !== null) {
                $pause(20000);
            }
        }
        foreach ($this->sql->all('SELECT device_id, row_count FROM endpoint_agent_log_state WHERE row_count > ? ORDER BY row_count DESC, device_id LIMIT 200', [$deviceMaxRows]) as $s) {
            $excess = (int) $s['row_count'] - $deviceMaxRows;
            while ($excess > 0 && $budget > 0) {
                $n = $this->deleteBatch('e.device_id = ?', [(int) $s['device_id']], min($batch, $excess, $budget));
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
            $n = $this->deleteBatch('1 = 1', [], min($batch, $excess, $budget));
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
     * Delete one batch of the oldest rows that match, and lower the owners' counters by exactly what was deleted.
     *
     * @param list<mixed> $params
     */
    private function deleteBatch(string $where, array $params, int $n): int
    {
        return (int) $this->sql->transaction(function () use ($where, $params, $n): int {
            $rows = $this->sql->all("SELECT e.event_id, e.device_id FROM endpoint_agent_log_events e WHERE $where ORDER BY e.occurred_at, e.event_id LIMIT " . max(1, $n) . ' FOR UPDATE', $params);
            if ($rows === []) {
                return 0;
            }
            $perDevice = [];
            foreach ($rows as $r) {
                $perDevice[(int) $r['device_id']] = ($perDevice[(int) $r['device_id']] ?? 0) + 1;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['event_id'], $rows);
            $this->sql->run('DELETE FROM endpoint_agent_log_events WHERE event_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($perDevice as $device => $c) {
                $this->sql->run('UPDATE endpoint_agent_log_state SET row_count = GREATEST(0, row_count - ?) WHERE device_id = ?', [$c, $device]);
            }

            return count($ids);
        });
    }

    private static function like(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }
}
