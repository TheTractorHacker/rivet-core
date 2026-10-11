<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Logs;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Collected event-log and syslog entries (Phase 8): bounded ingest from a device, retention with batched pruning, per-device and per-fleet storage
 * caps, and the search read model.
 *
 * VOLUME CONTROL, in the order the server applies it to a batch: the batch itself (at most 200 entries, 256 KiB), the device's per-minute budget
 * (`logs_rate_per_min`), the device's row cap and the fleet's row cap (a little above the cap is tolerated between housekeeping passes, which trim the oldest
 * rows). Whatever is refused is COUNTED, not stored, and reported back as `dropped`. A repeated entry (the same device, time, level, source, code and
 * text) is stored once, so an agent that resends a batch whose answer was lost causes no duplicates.
 *
 * @api
 */
final class LogService
{
    private const TIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';
    /** Rows deleted per statement and per transaction. */
    public const PRUNE_BATCH = 2000;
    /** Rows one housekeeping pass may delete in total. */
    public const PRUNE_RUN_CAP = 100000;

    public function __construct(
        private readonly Sql $sql,
        private readonly RemoteSettings $remote,
        private readonly ?LogRuleService $rules = null,
    ) {
    }

    // ------------------------------------------------------------------ ingest

    /**
     * @param array<string,mixed> $dev the authenticated device row
     * @param array<string,mixed> $body the decoded request body
     * @return array{ok:bool,accepted:int,dropped:int,duplicates:int}
     * @throws ApiError 422 for a malformed body
     */
    public function ingest(array $dev, array $body): array
    {
        $events = $body['events'] ?? null;
        if (!is_array($events) || !array_is_list($events) || count($events) > RemoteProtocol::LOG_BATCH_MAX) {
            throw new ApiError(422, 'invalid', 'events must be a list of at most ' . RemoteProtocol::LOG_BATCH_MAX . ' entries');
        }
        $agentDropped = $body['dropped'] ?? 0;
        if (!is_int($agentDropped) || $agentDropped < 0 || $agentDropped > 1000000000) {
            throw new ApiError(422, 'invalid', 'dropped must be a non-negative integer');
        }
        $deviceId = (int) $dev['device_id'];
        $limits = $this->remote->limits();
        $now = $this->sql->time();
        $oldest = $now - $limits['logs_retention_days'] * 86400;

        // 1. validate and clean; an entry that cannot be understood is dropped, never an error for the whole batch
        $clean = [];
        $dropped = 0;
        foreach ($events as $e) {
            $row = is_array($e) ? self::cleanEvent($e, $now, $oldest) : null;
            if ($row === null) {
                ++$dropped;
                continue;
            }
            $clean[] = $row;
        }

        $kept = [];
        $inserted = 0;
        $this->sql->transaction(function () use ($deviceId, $limits, $now, $clean, &$dropped, &$kept, &$inserted, $agentDropped): void {
            $this->sql->run('INSERT IGNORE INTO endpoint_agent_log_state (device_id) VALUES (?)', [$deviceId]);
            $st = $this->sql->one('SELECT * FROM endpoint_agent_log_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
            $budget = $limits['logs_rate_per_min'];
            $winStart = ($st['window_start'] ?? null) === null ? 0 : Sql::ts((string) $st['window_start']);
            $winCount = (int) ($st['window_count'] ?? 0);
            if ($winStart === 0 || $now - $winStart >= 60) {
                $winStart = $now;
                $winCount = 0;
            }
            $rows = (int) ($st['row_count'] ?? 0);
            $room = max(0, $budget - $winCount);
            $deviceRoom = max(0, (int) ceil($limits['logs_device_max_rows'] * 1.2) - $rows);
            $fleetRoom = max(0, (int) ceil($limits['logs_fleet_max_rows'] * 1.1) - $this->fleetRows());
            $allow = min(count($clean), $room, $deviceRoom, $fleetRoom);
            $dropped += count($clean) - $allow;
            $kept = array_slice($clean, 0, $allow);
            $nowSql = gmdate('Y-m-d H:i:s', $now);
            foreach (array_chunk($kept, 100) as $chunk) {
                $params = [];
                $sqlRows = [];
                foreach ($chunk as $r) {
                    $sqlRows[] = '(?, ?, ?, ?, ?, ?, ?, UNHEX(?))';
                    array_push($params, $deviceId, gmdate('Y-m-d H:i:s', $r['ts']), $nowSql, $r['level'], $r['source'], $r['code'], $r['message'], $r['hash']);
                }
                $inserted += $this->sql->run('INSERT IGNORE INTO endpoint_agent_log_events (device_id, occurred_at, received_at, level, source, code, message, dedupe) VALUES ' . implode(',', $sqlRows), $params);
            }
            $last = $kept === [] ? null : gmdate('Y-m-d H:i:s', max(array_column($kept, 'ts')));
            $this->sql->run('UPDATE endpoint_agent_log_state SET row_count = row_count + ?, dropped_total = dropped_total + ?, last_event_at = IF(? IS NULL, last_event_at, GREATEST(COALESCE(last_event_at, ?), ?)),
                last_batch_at = ?, window_start = ?, window_count = ? WHERE device_id = ?',
                [$inserted, $dropped + $agentDropped, $last, $last, $last, $nowSql, gmdate('Y-m-d H:i:s', $winStart), $winCount + $inserted, $deviceId]);
        });
        if ($kept !== [] && $this->rules !== null) {
            $this->rules->evaluate($dev, $kept);
        }

        return ['ok' => true, 'accepted' => $inserted, 'dropped' => $dropped, 'duplicates' => count($kept) - $inserted];
    }

    private function fleetRows(): int
    {
        return (int) $this->sql->val('SELECT COALESCE(SUM(row_count), 0) FROM endpoint_agent_log_state');
    }

    /**
     * @param array<mixed> $e
     * @return array{ts:int,level:int,source:string,code:?int,message:string,hash:string}|null
     */
    public static function cleanEvent(array $e, int $now, int $oldest): ?array
    {
        $ts = $e['ts'] ?? null;
        if (!is_string($ts) || preg_match(self::TIME_RE, $ts) !== 1 || ($t = strtotime($ts)) === false) {
            return null;
        }
        if ($t < $oldest) {
            return null;   // older than the retention: it would be pruned at once
        }
        $t = min($t, $now + 300);
        $lvl = $e['level'] ?? null;
        if ($lvl === 'verbose') {
            $lvl = 'debug';
        }
        $level = is_string($lvl) ? RemoteProtocol::levelNumber($lvl) : null;
        if ($level === null) {
            return null;
        }
        $source = is_string($e['source'] ?? null) ? self::text($e['source'], RemoteProtocol::LOG_SOURCE_MAX) : '';
        $code = $e['event_id'] ?? null;
        if ($code !== null && (!is_int($code) || $code < 0 || $code > 2147483647)) {
            $code = null;
        }
        $msg = $e['message'] ?? '';
        if (!is_string($msg)) {
            return null;
        }
        $message = self::text(Redactor::redact(substr($msg, 0, RemoteProtocol::LOG_MESSAGE_MAX * 4)), RemoteProtocol::LOG_MESSAGE_MAX);

        return ['ts' => $t, 'level' => $level, 'source' => $source, 'code' => $code, 'message' => $message,
            'hash' => substr(hash('sha256', $t . '|' . $level . '|' . $source . '|' . ($code ?? '') . '|' . $message), 0, 32)];
    }

    /** Control characters become a space, invalid UTF-8 is dropped, the text is cut to a character budget. */
    private static function text(string $s, int $max): string
    {
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $s);

        return trim(mb_substr($s, 0, $max));
    }

    // ------------------------------------------------------------------ search

    /**
     * Search the collected entries. A device search may span up to 90 days, a fleet search up to 7, so the cost of a query is bounded by the device cap or by a week
     * of the fleet's traffic. Text and source filters are substring matches, not an index lookup: they are applied inside that bounded window.
     *
     * @param array<string,mixed> $f device_id, client_id, from, to (RFC 3339 or database time), level (this severity or worse), source, code, q
     * @param list<int>|null $visibleClientIds null = every client
     * @return array{items:list<array<string,mixed>>,total:int,from:string,to:string}
     */
    public function search(array $f, ?array $visibleClientIds, int $limit = 100, int $offset = 0): array
    {
        $now = $this->sql->time();
        $device = isset($f['device_id']) && is_numeric($f['device_id']) ? (int) $f['device_id'] : 0;
        $to = self::when($f['to'] ?? null, $now);
        $span = $device > 0 ? 90 * 86400 : 7 * 86400;
        $from = self::when($f['from'] ?? null, max(0, $to - 86400));
        $from = max($from, $to - $span);
        $range = ['from' => gmdate('Y-m-d\TH:i:s\Z', $from), 'to' => gmdate('Y-m-d\TH:i:s\Z', $to)];
        $where = ['e.occurred_at >= ?', 'e.occurred_at <= ?'];
        $params = [gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to)];
        if ($device > 0) {
            $where[] = 'e.device_id = ?';
            $params[] = $device;
        }
        if (isset($f['client_id']) && is_numeric($f['client_id']) && (int) $f['client_id'] > 0) {
            $where[] = 'd.client_id = ?';
            $params[] = (int) $f['client_id'];
        }
        if ($visibleClientIds !== null) {
            if ($visibleClientIds === []) {
                return ['items' => [], 'total' => 0] + $range;
            }
            $where[] = 'd.client_id IN (' . implode(',', array_fill(0, count($visibleClientIds), '?')) . ')';
            array_push($params, ...$visibleClientIds);
        }
        if (isset($f['level']) && is_string($f['level']) && ($n = RemoteProtocol::levelNumber($f['level'])) !== null) {
            $where[] = 'e.level <= ?';
            $params[] = $n;
        }
        if (isset($f['source']) && is_string($f['source']) && $f['source'] !== '') {
            $where[] = "e.source LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like(mb_substr($f['source'], 0, 100)) . '%';
        }
        if (isset($f['code']) && is_numeric($f['code'])) {
            $where[] = 'e.code = ?';
            $params[] = (int) $f['code'];
        }
        if (isset($f['q']) && is_string($f['q']) && $f['q'] !== '') {
            $where[] = "e.message LIKE ? ESCAPE '!'";
            $params[] = '%' . self::like(mb_substr($f['q'], 0, 200)) . '%';
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->sql->all("SELECT e.event_id, e.device_id, d.hostname, d.client_id, e.occurred_at, e.level, e.source, e.code, e.message
            FROM endpoint_agent_log_events e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w ORDER BY e.occurred_at DESC, e.event_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_log_events e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w LIMIT 10001) t", $params);

        return [
            'items' => array_map(static fn (array $r): array => ['event_id' => (int) $r['event_id'], 'device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'],
                'client_id' => (int) $r['client_id'], 'occurred_at' => Sql::iso((string) $r['occurred_at']), 'level' => RemoteProtocol::levelName((int) $r['level']),
                'source' => (string) $r['source'], 'code' => $r['code'] === null ? null : (int) $r['code'], 'message' => (string) $r['message']], $rows),
            'total' => $total,
        ] + $range;
    }

    /**
     * Storage figures for the fleet and, when a device is given, for it.
     *
     * @return array<string,mixed>
     */
    public function stats(?int $deviceId = null): array
    {
        $l = $this->remote->limits();
        $out = ['fleet_rows' => $this->fleetRows(), 'fleet_max_rows' => $l['logs_fleet_max_rows'], 'device_max_rows' => $l['logs_device_max_rows'], 'retention_days' => $l['logs_retention_days'],
            'rate_per_min' => $l['logs_rate_per_min']];
        if ($deviceId !== null) {
            $s = $this->sql->one('SELECT * FROM endpoint_agent_log_state WHERE device_id = ?', [$deviceId]);
            $out['device'] = ['device_id' => $deviceId, 'rows' => (int) ($s['row_count'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
                'last_event_at' => Sql::iso(isset($s['last_event_at']) ? (string) $s['last_event_at'] : null), 'last_batch_at' => Sql::iso(isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null)];
        }

        return $out;
    }

    private static function like(string $s): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    }

    private static function when(mixed $v, int $default): int
    {
        if (!is_string($v) || $v === '') {
            return $default;
        }
        $t = strtotime(preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $v) === 1 ? $v : $v . ' UTC');

        return $t === false || $t < 0 ? $default : $t;
    }

    // ------------------------------------------------------------------ retention

    /**
     * Delete what is past retention, then trim every device above its row cap, then the fleet above its cap. Every delete is a batch of at most
     * {@see PRUNE_BATCH} rows in its own transaction together with the matching counter update, and one pass deletes at most {@see PRUNE_RUN_CAP} rows.
     *
     * @param (\Closure(int):void)|null $pause called between batches with microseconds to wait
     * @return array{retention:int,device_cap:int,fleet_cap:int}
     */
    public function prune(?\Closure $pause = null): array
    {
        $l = $this->remote->limits();
        $budget = self::PRUNE_RUN_CAP;
        $out = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];
        $cut = $this->sql->utcAt(-$l['logs_retention_days'] * 86400);
        while ($budget > 0) {
            $n = $this->deleteBatch('e.occurred_at < ?', [$cut], min(self::PRUNE_BATCH, $budget));
            $out['retention'] += $n;
            $budget -= $n;
            if ($n === 0) {
                break;
            }
            if ($pause !== null) {
                $pause(20000);
            }
        }
        foreach ($this->sql->all('SELECT device_id, row_count FROM endpoint_agent_log_state WHERE row_count > ? ORDER BY row_count DESC LIMIT 200', [$l['logs_device_max_rows']]) as $s) {
            $excess = (int) $s['row_count'] - $l['logs_device_max_rows'];
            while ($excess > 0 && $budget > 0) {
                $n = $this->deleteBatch('e.device_id = ?', [(int) $s['device_id']], min(self::PRUNE_BATCH, $excess, $budget));
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
        $excess = $this->fleetRows() - $l['logs_fleet_max_rows'];
        while ($excess > 0 && $budget > 0) {
            $n = $this->deleteBatch('1 = 1', [], min(self::PRUNE_BATCH, $excess, $budget));
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
}
