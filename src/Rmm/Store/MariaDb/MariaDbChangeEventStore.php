<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\MariaDb;

use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ChangeEventStore;
use RivetCore\Rmm\Support\Sql;

/**
 * {@see ChangeEventStore} on MariaDB / MySQL: the statements the change service and the change rule service ran before the store existed, unchanged (INSERT IGNORE with the
 * UNHEX dedupe key, SELECT ... FOR UPDATE on the ledger row, the join with the device table for client scoping and host names, DELETE of a locked batch).
 *
 * @internal
 */
final class MariaDbChangeEventStore implements ChangeEventStore
{
    public function __construct(private readonly Sql $sql)
    {
    }

    public function admit(int $deviceId, array $records, array $caps, int $nowTs, int $rejected, int $agentDropped, string $configVersion, ?string $monitorJson): array
    {
        $kept = [];
        $inserted = 0;
        $dropped = $rejected;
        $this->sql->transaction(function () use ($deviceId, $caps, $nowTs, $records, &$dropped, &$kept, &$inserted, $agentDropped, $configVersion, $monitorJson): void {
            $this->sql->run('INSERT IGNORE INTO endpoint_agent_change_state (device_id) VALUES (?)', [$deviceId]);
            $st = $this->sql->one('SELECT * FROM endpoint_agent_change_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
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
            $allow = min(count($records), $room, $deviceRoom, $fleetRoom);
            $dropped += count($records) - $allow;
            $kept = array_slice($records, 0, $allow);
            $nowSql = gmdate('Y-m-d H:i:s', $nowTs);
            foreach (array_chunk($kept, 50) as $chunk) {
                $params = [];
                $sqlRows = [];
                foreach ($chunk as $r) {
                    $sqlRows[] = '(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UNHEX(?))';
                    array_push($params, $deviceId, gmdate('Y-m-d H:i:s', $r['ts']), $nowSql, $r['kind'], $r['watch'], $r['change'], $r['target'], $r['item'], $r['old'], $r['new'],
                        $r['summary'], $r['old_hash'], $r['new_hash'], $r['actor'], $r['process'], $r['flags'], $r['dedupe']);
                }
                $inserted += $this->sql->run('INSERT IGNORE INTO endpoint_agent_change_records (device_id, occurred_at, received_at, kind, watch, change_type, target, item, old_value, new_value, summary, old_hash, new_hash, actor, process, flags, dedupe) VALUES '
                    . implode(',', $sqlRows), $params);
            }
            $last = $kept === [] ? null : gmdate('Y-m-d H:i:s', max(array_column($kept, 'ts')));
            $this->sql->run('UPDATE endpoint_agent_change_state SET row_count = row_count + ?, dropped_total = dropped_total + ?, last_change_at = IF(? IS NULL, last_change_at, GREATEST(COALESCE(last_change_at, ?), ?)),
                last_batch_at = ?, window_start = ?, window_count = ?, config_version = IF(? = \'\', config_version, ?), monitor_json = IF(? IS NULL, monitor_json, ?), monitor_at = IF(? IS NULL, monitor_at, ?) WHERE device_id = ?',
                [$inserted, $dropped + $agentDropped, $last, $last, $last, $nowSql, gmdate('Y-m-d H:i:s', $winStart), $winCount + count($kept), $configVersion, $configVersion,
                    $monitorJson, $monitorJson, $monitorJson, $nowSql, $deviceId]);
        });

        return ['inserted' => $inserted, 'dropped' => $dropped, 'kept' => $kept];
    }

    public function dedupeKeysReceivedAt(int $deviceId, string $receivedAtUtc): array
    {
        $rows = $this->sql->all('SELECT HEX(dedupe) h FROM endpoint_agent_change_records WHERE device_id = ? AND received_at = ? ORDER BY change_id DESC LIMIT 500', [$deviceId, $receivedAtUtc]);

        return array_map(static fn (array $r): string => strtolower((string) $r['h']), $rows);
    }

    public function search(ChangeCriteria $c, int $limit, int $offset): array
    {
        $where = ['e.occurred_at >= ?', 'e.occurred_at <= ?'];
        $params = [$c->fromUtc, $c->toUtc];
        if ($c->deviceId > 0) {
            $where[] = 'e.device_id = ?';
            $params[] = $c->deviceId;
        }
        if ($c->clientId > 0) {
            $where[] = 'd.client_id = ?';
            $params[] = $c->clientId;
        }
        if ($c->visibleClientIds !== null) {
            if ($c->visibleClientIds === []) {
                return ['rows' => [], 'total' => 0];
            }
            $where[] = 'd.client_id IN (' . implode(',', array_fill(0, count($c->visibleClientIds), '?')) . ')';
            array_push($params, ...$c->visibleClientIds);
        }
        if ($c->kind !== null) {
            $where[] = 'e.kind = ?';
            $params[] = $c->kind;
        }
        if ($c->watch !== null) {
            $where[] = 'e.watch = ?';
            $params[] = $c->watch;
        }
        if ($c->changeType !== null) {
            $where[] = 'e.change_type = ?';
            $params[] = $c->changeType;
        }
        foreach (['e.target' => $c->targetContains, 'e.item' => $c->itemContains, 'e.actor' => $c->actorContains] as $col => $needle) {
            if ($needle !== null && $needle !== '') {
                $where[] = "$col LIKE ? ESCAPE '!'";
                $params[] = '%' . self::like($needle) . '%';
            }
        }
        if ($c->anyText !== null && $c->anyText !== '') {
            $like = '%' . self::like($c->anyText) . '%';
            $where[] = "(e.target LIKE ? ESCAPE '!' OR e.item LIKE ? ESCAPE '!' OR e.old_value LIKE ? ESCAPE '!' OR e.new_value LIKE ? ESCAPE '!' OR e.summary LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->sql->all("SELECT e.*, d.hostname, d.client_id FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w
            ORDER BY e.occurred_at DESC, e.change_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w LIMIT 10001) t", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    public function timeline(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        $limit = max(1, min(500, $limit));
        $rows = $this->sql->all("SELECT e.*, d.hostname, d.client_id FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id
            WHERE e.device_id = ? AND e.occurred_at BETWEEN ? AND ? ORDER BY e.occurred_at DESC, e.change_id DESC LIMIT $limit", [$deviceId, $fromUtc, $toUtc]);
        $actors = array_map(static fn (array $r): array => ['actor' => (string) $r['actor'], 'c' => (int) $r['c']],
            $this->sql->all('SELECT actor, COUNT(*) c FROM endpoint_agent_change_records WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY actor ORDER BY c DESC LIMIT 20', [$deviceId, $fromUtc, $toUtc]));
        $days = array_map(static fn (array $r): array => ['d' => (string) $r['d'], 'c' => (int) $r['c']],
            $this->sql->all('SELECT DATE(occurred_at) d, COUNT(*) c FROM endpoint_agent_change_records WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY DATE(occurred_at) ORDER BY d DESC LIMIT 400', [$deviceId, $fromUtc, $toUtc]));

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
                $where[] = "$col LIKE ? ESCAPE '!'";
                $params[] = '%' . self::like($v) . '%';
            }
        }

        return (int) $this->sql->val('SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records WHERE ' . implode(' AND ', $where) . ' LIMIT ' . $stopAt . ') t', $params);
    }

    public function fleetRowCount(?array $visibleClientIds = null): int
    {
        if ($visibleClientIds === null) {
            return (int) $this->sql->val('SELECT COALESCE(SUM(row_count), 0) FROM endpoint_agent_change_state');
        }
        $in = $visibleClientIds === [] ? 'NULL' : implode(',', array_map('intval', $visibleClientIds));

        return (int) $this->sql->val("SELECT COALESCE(SUM(s.row_count), 0) FROM endpoint_agent_change_state s JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE d.client_id IN ($in)");
    }

    public function deviceLedger(int $deviceId): ?array
    {
        $s = $this->sql->one('SELECT * FROM endpoint_agent_change_state WHERE device_id = ?', [$deviceId]);
        if ($s === null) {
            return null;
        }

        return ['rows' => (int) ($s['row_count'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
            'last_change_at' => isset($s['last_change_at']) ? (string) $s['last_change_at'] : null, 'last_batch_at' => isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null,
            'config_version' => (string) ($s['config_version'] ?? ''), 'monitor_json' => isset($s['monitor_json']) && is_string($s['monitor_json']) ? $s['monitor_json'] : null,
            'monitor_at' => isset($s['monitor_at']) ? (string) $s['monitor_at'] : null];
    }

    public function countsByWatch(string $sinceUtc, ?array $visibleClientIds = null): array
    {
        return array_map(static fn (array $r): array => ['watch' => (string) $r['watch'], 'c' => (int) $r['c']],
            $this->sql->all('SELECT e.watch, COUNT(*) c FROM endpoint_agent_change_records e' . ($visibleClientIds === null ? '' : ' JOIN endpoint_agent_devices d ON d.device_id = e.device_id')
                . ' WHERE e.occurred_at >= ?' . ($visibleClientIds === null ? '' : ' AND d.client_id IN (' . ($visibleClientIds === [] ? 'NULL' : implode(',', array_map('intval', $visibleClientIds))) . ')')
                . ' GROUP BY e.watch ORDER BY c DESC LIMIT 30', [$sinceUtc]));
    }

    public function ledgerTotals(): array
    {
        return ['rows' => (int) $this->sql->val('SELECT COALESCE(SUM(row_count), 0) FROM endpoint_agent_change_state'),
            'devices' => (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_change_state WHERE last_batch_at IS NOT NULL')];
    }

    public function hasLedger(): bool
    {
        return (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_change_state') > 0;
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
        foreach ($this->sql->all('SELECT device_id, row_count FROM endpoint_agent_change_state WHERE row_count > ? ORDER BY row_count DESC LIMIT 200', [$deviceMaxRows]) as $s) {
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
     * Delete one batch of the oldest matching rows and lower the owners' counters by exactly what was deleted.
     *
     * @param list<mixed> $params
     */
    private function deleteBatch(string $where, array $params, int $n): int
    {
        return (int) $this->sql->transaction(function () use ($where, $params, $n): int {
            $rows = $this->sql->all("SELECT e.change_id, e.device_id FROM endpoint_agent_change_records e WHERE $where ORDER BY e.occurred_at, e.change_id LIMIT " . max(1, $n) . ' FOR UPDATE', $params);
            if ($rows === []) {
                return 0;
            }
            $perDevice = [];
            foreach ($rows as $r) {
                $perDevice[(int) $r['device_id']] = ($perDevice[(int) $r['device_id']] ?? 0) + 1;
            }
            $ids = array_map(static fn (array $r): int => (int) $r['change_id'], $rows);
            $this->sql->run('DELETE FROM endpoint_agent_change_records WHERE change_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            foreach ($perDevice as $device => $c) {
                $this->sql->run('UPDATE endpoint_agent_change_state SET row_count = GREATEST(0, row_count - ?) WHERE device_id = ?', [$c, $device]);
            }

            return count($ids);
        });
    }

    private static function like(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }
}
