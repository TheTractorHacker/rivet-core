<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Logs;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Remote\RemoteProtocol;
use RivetCore\Rmm\Remote\RemoteSettings;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Store\ClientScopedLogStore;
use RivetCore\Rmm\Store\DeviceNames;
use RivetCore\Rmm\Store\HotStoreUnavailableException;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\LogStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbLogStore;
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

    private readonly LogStore $store;

    public function __construct(
        private readonly Sql $sql,
        private readonly RemoteSettings $remote,
        private readonly ?LogRuleService $rules = null,
        ?LogStore $store = null,
    ) {
        $this->store = $store ?? new MariaDbLogStore($sql);
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

        try {
            $caps = ['rate_per_min' => $limits['logs_rate_per_min'], 'device_max_rows' => $limits['logs_device_max_rows'], 'fleet_max_rows' => $limits['logs_fleet_max_rows']];
            $res = $this->store instanceof ClientScopedLogStore ? $this->store->admit($deviceId, $clean, $caps, $now, $dropped, $agentDropped, isset($dev['client_id']) ? (int) $dev['client_id'] : null)
                : $this->store->admit($deviceId, $clean, $caps, $now, $dropped, $agentDropped);
        } catch (HotStoreUnavailableException) {
            // the data store is down: the agent keeps its batch and sends it again (the protocol's retryable 503), nothing is lost or counted as dropped
            throw new ApiError(503, 'unavailable', 'The log store is temporarily unavailable. Try again later.', ['Retry-After' => (string) random_int(60, 180)]);
        }
        $kept = $res['kept'];
        $inserted = $res['inserted'];
        $dropped = $res['dropped'];
        if ($kept !== [] && $this->rules !== null) {
            try {
                $this->rules->evaluate($dev, $kept);
            } catch (HotStoreUnavailableException) {
                // The batch is stored; the store went away before the rules could count (they read it). The agent must not resend a stored batch for that: this batch's rules
                // are skipped, the next batch's counts include it.
            }
        }

        return ['ok' => true, 'accepted' => $inserted, 'dropped' => $dropped, 'duplicates' => count($kept) - $inserted];
    }

    private function fleetRows(): int
    {
        return $this->store->fleetRowCount();
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
        $t = min($t, $now + RmmProtocol::CHECKIN_FUTURE_SKEW_S);
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
        if ($visibleClientIds !== null && $visibleClientIds === []) {
            return ['items' => [], 'total' => 0] + $range;
        }
        $level = isset($f['level']) && is_string($f['level']) ? RemoteProtocol::levelNumber($f['level']) : null;
        $source = isset($f['source']) && is_string($f['source']) && $f['source'] !== '' ? mb_substr($f['source'], 0, 100) : null;
        $message = isset($f['q']) && is_string($f['q']) && $f['q'] !== '' ? mb_substr($f['q'], 0, 200) : null;
        $page = $this->store->search(new LogCriteria(gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to), $device,
            isset($f['client_id']) && is_numeric($f['client_id']) && (int) $f['client_id'] > 0 ? (int) $f['client_id'] : 0, $visibleClientIds, $level, $source,
            isset($f['code']) && is_numeric($f['code']) ? (int) $f['code'] : null, $message), $limit, $offset);
        $rows = (new DeviceNames($this->sql))->hydrate($page['rows']);
        $total = $page['total'];

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
            $s = $this->store->deviceLedger($deviceId);
            $out['device'] = ['device_id' => $deviceId, 'rows' => (int) ($s['rows'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
                'last_event_at' => Sql::iso($s['last_event_at'] ?? null), 'last_batch_at' => Sql::iso($s['last_batch_at'] ?? null)];
        }

        return $out;
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

        return $this->store->enforceLimits($this->sql->utcAt(-$l['logs_retention_days'] * 86400), $l['logs_device_max_rows'], $l['logs_fleet_max_rows'], self::PRUNE_RUN_CAP, self::PRUNE_BATCH, $pause);
    }
}
