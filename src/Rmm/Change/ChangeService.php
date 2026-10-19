<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\Support\RmmEventPublisher;
use RivetCore\Rmm\Support\Sql;

/**
 * Change records (registry, files, local groups, scheduled tasks): bounded ingest from a device, retention with batched pruning, per-device and per-fleet storage caps,
 * the search read model and the "who changed what" timeline. It mirrors {@see \RivetCore\Rmm\Logs\LogService} on purpose: the same database, the same volume controls.
 *
 * VOLUME CONTROL, in the order the server applies it to a batch: the batch itself (at most 100 records, 256 KiB), the device's per-minute budget (`change_rate_per_min`),
 * the device's row cap and the fleet's row cap (a little above the cap is tolerated between housekeeping passes, which trim the oldest rows). Whatever is refused is
 * COUNTED, not stored. A repeated record (same device, time, watch, target, item, change and values) is stored once, so a batch whose answer was lost causes no duplicates.
 *
 * A DEVICE IS NOT TRUSTED. The device id comes from the credential. Every record must name a watch the device was told to read, of the matching kind, and a target
 * inside that watch's paths that also passes the {@see \RivetCore\Rmm\Contracts\ChangeWatchPolicyInterface}; everything else is dropped and counted. Every text is cleaned,
 * cut and redacted again here, a value of a secret-looking name is never stored, and nothing a device sends is ever treated as markup, a path to open or a command.
 *
 * @api
 */
final class ChangeService
{
    private const TIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';
    public const PRUNE_BATCH = 2000;
    public const PRUNE_RUN_CAP = 100000;
    /** The most change events published for one batch (a mass change must not become a webhook storm). */
    public const EVENT_CAP = 20;
    private const STATUS_MAX_BYTES = 4096;

    public function __construct(
        private readonly Sql $sql,
        private readonly ChangeSettings $settings,
        private readonly ?ChangeRuleService $rules = null,
        private readonly ?RmmEventPublisher $events = null,
    ) {
    }

    // ------------------------------------------------------------------ ingest

    /**
     * @param array<string,mixed> $dev the authenticated device row
     * @param array<string,mixed> $body the decoded request body: {changes: [...], dropped, config_version, status?}
     * @return array{ok:bool,accepted:int,dropped:int,duplicates:int}
     * @throws ApiError 422 for a malformed body
     */
    public function ingest(array $dev, array $body): array
    {
        $changes = $body['changes'] ?? [];
        if (!is_array($changes) || !array_is_list($changes) || count($changes) > ChangeProtocol::BATCH_MAX) {
            throw new ApiError(422, 'invalid', 'changes must be a list of at most ' . ChangeProtocol::BATCH_MAX . ' records');
        }
        $agentDropped = $body['dropped'] ?? 0;
        if (!is_int($agentDropped) || $agentDropped < 0 || $agentDropped > 1000000000) {
            throw new ApiError(422, 'invalid', 'dropped must be a non-negative integer');
        }
        $version = $body['config_version'] ?? '';
        $version = is_string($version) && preg_match('/^[0-9a-f]{16}$/D', $version) === 1 ? $version : '';
        $status = self::cleanStatus($body['status'] ?? null);
        $deviceId = (int) $dev['device_id'];
        $os = (string) ($dev['os'] ?? 'windows');
        $known = $this->settings->knownWatches($os);
        $limits = $this->settings->limits();
        $now = $this->sql->time();
        $oldest = $now - $limits['change_retention_days'] * 86400;

        $clean = [];
        $dropped = 0;
        foreach ($changes as $c) {
            $row = is_array($c) ? $this->cleanChange($c, $known, $now, $oldest, $limits['change_max_value_bytes']) : null;
            if ($row === null) {
                ++$dropped;
                continue;
            }
            $clean[] = $row;
        }

        $kept = [];
        $inserted = 0;
        $this->sql->transaction(function () use ($deviceId, $limits, $now, $clean, &$dropped, &$kept, &$inserted, $agentDropped, $version, $status): void {
            $this->sql->run('INSERT IGNORE INTO endpoint_agent_change_state (device_id) VALUES (?)', [$deviceId]);
            $st = $this->sql->one('SELECT * FROM endpoint_agent_change_state WHERE device_id = ? FOR UPDATE', [$deviceId]) ?? [];
            $budget = $limits['change_rate_per_min'];
            $winStart = ($st['window_start'] ?? null) === null ? 0 : Sql::ts((string) $st['window_start']);
            $winCount = (int) ($st['window_count'] ?? 0);
            if ($winStart === 0 || $now - $winStart >= 60) {
                $winStart = $now;
                $winCount = 0;
            }
            $rows = (int) ($st['row_count'] ?? 0);
            $room = max(0, $budget - $winCount);
            $deviceRoom = max(0, (int) ceil($limits['change_device_max_rows'] * 1.2) - $rows);
            $fleetRoom = max(0, (int) ceil($limits['change_fleet_max_rows'] * 1.1) - $this->fleetRows());
            $allow = min(count($clean), $room, $deviceRoom, $fleetRoom);
            $dropped += count($clean) - $allow;
            $kept = array_slice($clean, 0, $allow);
            $nowSql = gmdate('Y-m-d H:i:s', $now);
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
                [$inserted, $dropped + $agentDropped, $last, $last, $last, $nowSql, gmdate('Y-m-d H:i:s', $winStart), $winCount + $inserted, $version, $version,
                    $status, $status, $status, $nowSql, $deviceId]);
        });
        if ($inserted > 0) {   // a resent batch the server already holds raises nothing twice
            $fresh = $inserted === count($kept) ? $kept : $this->onlyNew($deviceId, $kept, gmdate('Y-m-d H:i:s', $now));
            $this->rules?->evaluate($dev, $fresh);
            if ($this->events !== null) {
                foreach (array_slice($fresh, 0, self::EVENT_CAP) as $r) {
                    $this->events->emit(RmmEvent::CHANGE_DETECTED, $dev, ['kind' => $r['kind'], 'watch' => $r['watch'], 'change' => ChangeProtocol::changeName($r['change']), 'target' => $r['target'],
                        'item' => $r['item'], 'old_value' => $r['old'], 'new_value' => $r['new'], 'summary' => $r['summary'], 'actor' => $r['actor'], 'process' => $r['process']]);
                }
            }
        }

        return ['ok' => true, 'accepted' => $inserted, 'dropped' => $dropped, 'duplicates' => count($kept) - $inserted];
    }

    /**
     * Of the records of a batch, those this request just stored (a batch that is partly a resend): the ones whose dedupe key was received at this very second.
     *
     * @param list<array<string,mixed>> $kept
     * @return list<array<string,mixed>>
     */
    private function onlyNew(int $deviceId, array $kept, string $nowSql): array
    {
        $rows = $this->sql->all('SELECT HEX(dedupe) h FROM endpoint_agent_change_records WHERE device_id = ? AND received_at = ? ORDER BY change_id DESC LIMIT 500', [$deviceId, $nowSql]);
        $new = array_flip(array_map(static fn (array $r): string => strtolower((string) $r['h']), $rows));

        return array_values(array_filter($kept, static fn (array $r): bool => isset($new[$r['dedupe']])));
    }

    private function fleetRows(): int
    {
        return (int) $this->sql->val('SELECT COALESCE(SUM(row_count), 0) FROM endpoint_agent_change_state');
    }

    /**
     * The agent's own status block, reduced to what the device page shows and cut to a small JSON text; null when it is absent or malformed.
     */
    private static function cleanStatus(mixed $s): ?string
    {
        if (!is_array($s) || !is_array($s['watches'] ?? null) || !array_is_list($s['watches'])) {
            return null;
        }
        $watches = [];
        foreach (array_slice($s['watches'], 0, 60) as $w) {
            if (!is_array($w) || !is_string($w['id'] ?? null) || preg_match(ChangeProtocol::WATCH_ID_RE, $w['id']) !== 1) {
                continue;
            }
            $state = in_array($w['state'] ?? '', ['ok', 'baseline', 'error', 'partial'], true) ? $w['state'] : 'ok';
            $watches[] = ['id' => $w['id'], 'state' => $state, 'items' => is_int($w['items'] ?? null) ? max(0, min(1000000, $w['items'])) : 0,
                'error' => is_string($w['error'] ?? null) ? ChangeProtocol::text(Redactor::redact($w['error']), 160) : '',
                'skipped' => is_int($w['skipped'] ?? null) ? max(0, min(1000000, $w['skipped'])) : 0];
        }
        $out = ['watches' => $watches, 'resumed_after_s' => is_int($s['resumed_after_s'] ?? null) ? max(0, min(31536000, $s['resumed_after_s'])) : 0, 'baseline_at' => is_string($s['baseline_at'] ?? null) && preg_match(self::TIME_RE, $s['baseline_at']) === 1 ? $s['baseline_at'] : null];
        $json = (string) json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return strlen($json) <= self::STATUS_MAX_BYTES ? $json : null;
    }

    /**
     * Validate and clean one record. Null = dropped (and counted). Public and static-friendly for the tests and the shared vectors.
     *
     * @param array<mixed> $e
     * @param array<string,array<string,mixed>> $known the device's watches by id
     * @return array{ts:int,kind:string,watch:string,change:int,target:string,item:string,old:string,new:string,summary:string,old_hash:string,new_hash:string,actor:string,process:string,flags:int,dedupe:string}|null
     */
    public function cleanChange(array $e, array $known, int $now, int $oldest, int $maxValue = 512): ?array
    {
        $ts = $e['ts'] ?? null;
        if (!is_string($ts) || preg_match(self::TIME_RE, $ts) !== 1 || ($t = strtotime($ts)) === false || $t < $oldest) {
            return null;
        }
        $t = min($t, $now + 300);
        $kind = $e['kind'] ?? null;
        $watch = $e['watch'] ?? null;
        if (!is_string($kind) || !in_array($kind, ChangeProtocol::KINDS, true) || !is_string($watch) || !isset($known[$watch]) || ($known[$watch]['kind'] ?? '') !== $kind) {
            return null;   // not a watch this device was told to read
        }
        $change = is_string($e['change'] ?? null) ? ChangeProtocol::changeNumber($e['change']) : null;
        if ($change === null) {
            return null;
        }
        $targetRaw = $e['target'] ?? null;
        if (!is_string($targetRaw) || $targetRaw === '' || strlen($targetRaw) > ChangeProtocol::TARGET_MAX * 4) {
            return null;
        }
        $target = ChangeProtocol::text($targetRaw, ChangeProtocol::TARGET_MAX);
        if ($target !== $targetRaw || !ChangeSettings::targetInWatch($known[$watch], $target)) {
            return null;   // control characters, or outside the watched paths
        }
        if (($kind === 'registry' || $kind === 'file') && !$this->settings->policy()->allows($kind, $target, 0)) {
            return null;   // a credential hive or file, whatever the device claims
        }
        $sudoers = $kind === 'file' && (basename($target) === 'sudoers' || strtolower(basename(dirname($target))) === 'sudoers.d');
        $item = is_string($e['item'] ?? null) ? ChangeProtocol::text($e['item'], ChangeProtocol::ITEM_MAX) : '';
        $flags = 0;
        $secret = $item !== '' && ChangeProtocol::secretName($item);
        $old = self::value($e['old'] ?? '', $secret, $maxValue, $flags, $sudoers);
        $new = self::value($e['new'] ?? '', $secret, $maxValue, $flags, $sudoers);
        $summary = is_string($e['summary'] ?? null) ? ChangeProtocol::text(ChangeProtocol::redact(substr($e['summary'], 0, ChangeProtocol::SUMMARY_MAX * 4), $sudoers), ChangeProtocol::SUMMARY_MAX) : '';
        $oldHash = self::hash($e['old_hash'] ?? null);
        $newHash = self::hash($e['new_hash'] ?? null);
        if (!empty($e['redacted'])) {
            $flags |= ChangeProtocol::FLAG_REDACTED;
        }
        if (!empty($e['truncated'])) {
            $flags |= ChangeProtocol::FLAG_TRUNCATED;
        }
        $actor = is_string($e['actor'] ?? null) ? ChangeProtocol::text($e['actor'], ChangeProtocol::ACTOR_MAX) : '';
        $process = is_string($e['process'] ?? null) ? ChangeProtocol::text(Redactor::redact(substr($e['process'], 0, ChangeProtocol::PROCESS_MAX * 4)), ChangeProtocol::PROCESS_MAX) : '';

        return ['ts' => $t, 'kind' => $kind, 'watch' => $watch, 'change' => $change, 'target' => $target, 'item' => $item, 'old' => $old, 'new' => $new, 'summary' => $summary,
            'old_hash' => $oldHash, 'new_hash' => $newHash, 'actor' => $actor, 'process' => $process, 'flags' => $flags,
            'dedupe' => substr(hash('sha256', implode('|', [$t, $kind, $watch, $change, $target, $item, $oldHash, $newHash, $new, $summary])), 0, 32)];
    }

    /** A stored value text: redacted, cut, and replaced by the mask when its name looks like a secret. */
    private static function value(mixed $v, bool $secretName, int $max, int &$flags, bool $sudoers): string
    {
        if (!is_string($v) || $v === '') {
            return '';
        }
        if ($secretName) {
            $flags |= ChangeProtocol::FLAG_REDACTED;

            return ChangeProtocol::MASK;
        }
        $max = max(16, min(ChangeProtocol::VALUE_MAX, $max));
        $raw = substr($v, 0, $max * 4);
        $red = ChangeProtocol::redact($raw, $sudoers);
        if ($red !== $raw) {
            $flags |= ChangeProtocol::FLAG_REDACTED;
        }
        $text = ChangeProtocol::text($red, ChangeProtocol::VALUE_MAX);
        if (mb_strlen($text) > $max) {
            $text = mb_substr($text, 0, $max);
            $flags |= ChangeProtocol::FLAG_TRUNCATED;
        }

        return $text;
    }

    private static function hash(mixed $h): string
    {
        return is_string($h) && preg_match('/^[0-9a-f]{64}$/D', $h) === 1 ? substr($h, 0, 32) : '';
    }

    // ------------------------------------------------------------------ search

    /**
     * Search the recorded changes. A device search may span up to 365 days, a fleet search up to 30, so the cost of a query is bounded by the device cap or by a month of
     * the fleet's traffic. Text filters are substring matches applied inside that bounded window.
     *
     * @param array<string,mixed> $f device_id, client_id, from, to, kind, watch, change, target, item, actor, q
     * @param list<int>|null $visibleClientIds null = every client
     * @return array{items:list<array<string,mixed>>,total:int,from:string,to:string}
     */
    public function search(array $f, ?array $visibleClientIds, int $limit = 100, int $offset = 0): array
    {
        $now = $this->sql->time();
        $device = isset($f['device_id']) && is_numeric($f['device_id']) ? (int) $f['device_id'] : 0;
        $to = self::when($f['to'] ?? null, $now);
        $span = $device > 0 ? 365 * 86400 : 30 * 86400;
        $from = max(self::when($f['from'] ?? null, max(0, $to - 7 * 86400)), $to - $span);
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
        if (isset($f['kind']) && is_string($f['kind']) && in_array($f['kind'], ChangeProtocol::KINDS, true)) {
            $where[] = 'e.kind = ?';
            $params[] = $f['kind'];
        }
        if (isset($f['watch']) && is_string($f['watch']) && preg_match(ChangeProtocol::WATCH_ID_RE, $f['watch']) === 1) {
            $where[] = 'e.watch = ?';
            $params[] = $f['watch'];
        }
        if (isset($f['change']) && is_string($f['change']) && ($n = ChangeProtocol::changeNumber($f['change'])) !== null) {
            $where[] = 'e.change_type = ?';
            $params[] = $n;
        }
        foreach (['target' => 'e.target', 'item' => 'e.item', 'actor' => 'e.actor'] as $k => $col) {
            if (isset($f[$k]) && is_string($f[$k]) && $f[$k] !== '') {
                $where[] = "$col LIKE ? ESCAPE '!'";
                $params[] = '%' . self::like(mb_substr($f[$k], 0, 100)) . '%';
            }
        }
        if (isset($f['q']) && is_string($f['q']) && $f['q'] !== '') {
            $like = '%' . self::like(mb_substr($f['q'], 0, 200)) . '%';
            $where[] = "(e.target LIKE ? ESCAPE '!' OR e.item LIKE ? ESCAPE '!' OR e.old_value LIKE ? ESCAPE '!' OR e.new_value LIKE ? ESCAPE '!' OR e.summary LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $offset = max(0, min(100000, $offset));
        $rows = $this->sql->all("SELECT e.*, d.hostname, d.client_id FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w
            ORDER BY e.occurred_at DESC, e.change_id DESC LIMIT $limit OFFSET $offset", $params);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id WHERE $w LIMIT 10001) t", $params);

        return ['items' => array_map(self::present(...), $rows), 'total' => $total] + $range;
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function present(array $r): array
    {
        return ['change_id' => (int) $r['change_id'], 'device_id' => (int) $r['device_id'], 'hostname' => (string) ($r['hostname'] ?? ''), 'client_id' => (int) ($r['client_id'] ?? 0),
            'occurred_at' => Sql::iso((string) $r['occurred_at']), 'source' => 'agent', 'kind' => (string) $r['kind'], 'watch' => (string) $r['watch'],
            'change' => ChangeProtocol::changeName((int) $r['change_type']), 'target' => (string) $r['target'], 'item' => (string) $r['item'], 'old_value' => (string) $r['old_value'],
            'new_value' => (string) $r['new_value'], 'summary' => (string) $r['summary'], 'old_hash' => (string) $r['old_hash'], 'new_hash' => (string) $r['new_hash'],
            'actor' => (string) $r['actor'], 'process' => (string) $r['process'], 'redacted' => ((int) $r['flags'] & ChangeProtocol::FLAG_REDACTED) !== 0, 'truncated' => ((int) $r['flags'] & ChangeProtocol::FLAG_TRUNCATED) !== 0];
    }

    /**
     * The "who changed what" timeline of one device: its recorded changes and, when the software inventory is collected, the installs, removals and version changes,
     * newest first, with the people and programs behind them (blank when Windows did not record them) and a per-day count.
     *
     * @param array<string,mixed> $f from, to (default the last 30 days; at most 365)
     * @return array{items:list<array<string,mixed>>,actors:list<array<string,mixed>>,days:list<array<string,mixed>>,from:string,to:string,truncated:bool}
     */
    public function timeline(int $deviceId, array $f, int $limit = 200): array
    {
        $now = $this->sql->time();
        $to = self::when($f['to'] ?? null, $now);
        $from = max(self::when($f['from'] ?? null, max(0, $to - 30 * 86400)), $to - 365 * 86400);
        $limit = max(1, min(500, $limit));
        $a = gmdate('Y-m-d H:i:s', $from);
        $b = gmdate('Y-m-d H:i:s', $to);
        $rows = $this->sql->all("SELECT e.*, d.hostname, d.client_id FROM endpoint_agent_change_records e JOIN endpoint_agent_devices d ON d.device_id = e.device_id
            WHERE e.device_id = ? AND e.occurred_at BETWEEN ? AND ? ORDER BY e.occurred_at DESC, e.change_id DESC LIMIT $limit", [$deviceId, $a, $b]);
        $items = array_map(self::present(...), $rows);
        try {
            $soft = $this->sql->all("SELECT * FROM rmm_software_history WHERE device_id = ? AND occurred_at BETWEEN ? AND ? ORDER BY occurred_at DESC, history_id DESC LIMIT $limit", [$deviceId, $a, $b]);
        } catch (\Throwable) {
            $soft = [];   // the inventory tables do not exist yet
        }
        foreach ($soft as $s) {
            $type = (string) $s['change_type'];
            $items[] = ['change_id' => 0, 'device_id' => $deviceId, 'hostname' => '', 'client_id' => 0, 'occurred_at' => Sql::iso((string) $s['occurred_at']), 'source' => 'inventory', 'kind' => 'software',
                'watch' => 'inventory', 'change' => in_array($type, ['added', 'removed'], true) ? $type : 'modified', 'target' => (string) $s['name'],
                'item' => (string) $s['source'], 'old_value' => (string) ($s['old_version'] ?? ''), 'new_value' => (string) ($s['new_version'] ?? ''), 'summary' => '', 'old_hash' => '', 'new_hash' => '',
                'actor' => '', 'process' => '', 'redacted' => false, 'truncated' => false];
        }
        usort($items, static fn (array $x, array $y): int => strcmp((string) $y['occurred_at'], (string) $x['occurred_at']) ?: ((int) $y['change_id'] <=> (int) $x['change_id']));
        $truncated = count($items) > $limit;
        $items = array_slice($items, 0, $limit);
        $actors = array_map(static fn (array $r): array => ['actor' => (string) $r['actor'], 'count' => (int) $r['c']],
            $this->sql->all('SELECT actor, COUNT(*) c FROM endpoint_agent_change_records WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY actor ORDER BY c DESC LIMIT 20', [$deviceId, $a, $b]));
        $days = array_map(static fn (array $r): array => ['day' => (string) $r['d'], 'count' => (int) $r['c']],
            $this->sql->all('SELECT DATE(occurred_at) d, COUNT(*) c FROM endpoint_agent_change_records WHERE device_id = ? AND occurred_at BETWEEN ? AND ? GROUP BY DATE(occurred_at) ORDER BY d DESC LIMIT 400', [$deviceId, $a, $b]));

        return ['items' => $items, 'actors' => $actors, 'days' => $days, 'from' => gmdate('Y-m-d\TH:i:s\Z', $from), 'to' => gmdate('Y-m-d\TH:i:s\Z', $to), 'truncated' => $truncated];
    }

    /**
     * Storage figures for the fleet and, when a device is given, for it (including what the agent last said about its watches).
     *
     * @param list<int>|null $visibleClientIds the caller's clients (null = all): the fleet figures are then theirs only
     * @return array<string,mixed>
     */
    public function stats(?int $deviceId = null, ?array $visibleClientIds = null): array
    {
        $l = $this->settings->limits();
        if ($visibleClientIds !== null) {
            $in = $visibleClientIds === [] ? 'NULL' : implode(',', array_map('intval', $visibleClientIds));
            $rows = (int) $this->sql->val("SELECT COALESCE(SUM(s.row_count), 0) FROM endpoint_agent_change_state s JOIN endpoint_agent_devices d ON d.device_id = s.device_id WHERE d.client_id IN ($in)");
        }
        $out = ['fleet_rows' => $visibleClientIds === null ? $this->fleetRows() : $rows, 'fleet_max_rows' => $l['change_fleet_max_rows'], 'device_max_rows' => $l['change_device_max_rows'], 'retention_days' => $l['change_retention_days'],
            'rate_per_min' => $l['change_rate_per_min'], 'interval_s' => $l['change_interval_s']];
        if ($deviceId !== null) {
            $s = $this->sql->one('SELECT * FROM endpoint_agent_change_state WHERE device_id = ?', [$deviceId]);
            $monitor = isset($s['monitor_json']) && is_string($s['monitor_json']) ? json_decode($s['monitor_json'], true) : null;
            $out['device'] = ['device_id' => $deviceId, 'rows' => (int) ($s['row_count'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
                'last_change_at' => Sql::iso(isset($s['last_change_at']) ? (string) $s['last_change_at'] : null), 'last_batch_at' => Sql::iso(isset($s['last_batch_at']) ? (string) $s['last_batch_at'] : null),
                'config_version' => (string) ($s['config_version'] ?? ''), 'monitor' => is_array($monitor) ? $monitor : null, 'monitor_at' => Sql::iso(isset($s['monitor_at']) ? (string) $s['monitor_at'] : null)];
        } else {
            $out['last_24h_by_watch'] = array_map(static fn (array $r): array => ['watch' => (string) $r['watch'], 'count' => (int) $r['c']],
                $this->sql->all('SELECT e.watch, COUNT(*) c FROM endpoint_agent_change_records e' . ($visibleClientIds === null ? '' : ' JOIN endpoint_agent_devices d ON d.device_id = e.device_id')
                    . ' WHERE e.occurred_at >= ?' . ($visibleClientIds === null ? '' : ' AND d.client_id IN (' . ($visibleClientIds === [] ? 'NULL' : implode(',', array_map('intval', $visibleClientIds))) . ')')
                    . ' GROUP BY e.watch ORDER BY c DESC LIMIT 30', [$this->sql->utcAt(-86400)]));
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
     * Delete what is past retention, then trim every device above its row cap, then the fleet above its cap, in batches of at most {@see PRUNE_BATCH} rows (one pass: at most
     * {@see PRUNE_RUN_CAP}).
     *
     * @param (\Closure(int):void)|null $pause called between batches with microseconds to wait
     * @return array{retention:int,device_cap:int,fleet_cap:int}
     */
    public function prune(?\Closure $pause = null): array
    {
        $l = $this->settings->limits();
        $budget = self::PRUNE_RUN_CAP;
        $out = ['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0];
        $cut = $this->sql->utcAt(-$l['change_retention_days'] * 86400);
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
        foreach ($this->sql->all('SELECT device_id, row_count FROM endpoint_agent_change_state WHERE row_count > ? ORDER BY row_count DESC LIMIT 200', [$l['change_device_max_rows']]) as $s) {
            $excess = (int) $s['row_count'] - $l['change_device_max_rows'];
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
        $excess = $this->fleetRows() - $l['change_fleet_max_rows'];
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
}
