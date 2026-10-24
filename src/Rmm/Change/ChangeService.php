<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Change;

use RivetCore\Rmm\Crypto\Redactor;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\ChangeEventStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbChangeEventStore;
use RivetCore\Rmm\Store\SoftwareHistoryStore;
use RivetCore\Rmm\Store\MariaDb\MariaDbSoftwareHistoryStore;
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

    private readonly ChangeEventStore $store;
    private readonly SoftwareHistoryStore $softwareHistory;

    public function __construct(
        private readonly Sql $sql,
        private readonly ChangeSettings $settings,
        private readonly ?ChangeRuleService $rules = null,
        private readonly ?RmmEventPublisher $events = null,
        ?ChangeEventStore $store = null,
        ?SoftwareHistoryStore $softwareHistory = null,
    ) {
        $this->store = $store ?? new MariaDbChangeEventStore($sql);
        $this->softwareHistory = $softwareHistory ?? new MariaDbSoftwareHistoryStore($sql);
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

        $res = $this->store->admit($deviceId, $clean, ['rate_per_min' => $limits['change_rate_per_min'], 'device_max_rows' => $limits['change_device_max_rows'], 'fleet_max_rows' => $limits['change_fleet_max_rows']],
            $now, $dropped, $agentDropped, $version, $status);
        $kept = $res['kept'];
        $inserted = $res['inserted'];
        $dropped = $res['dropped'];
        if ($inserted > 0) {   // a resent batch the server already holds raises nothing twice
            $fresh = $inserted === count($kept) ? $kept : $this->onlyNew($deviceId, $kept, gmdate('Y-m-d H:i:s', $now));
            $this->rules?->evaluate($dev, $fresh);
            if ($this->events !== null) {
                foreach (array_slice($fresh, 0, self::EVENT_CAP) as $r) {
                    // device-supplied text goes into webhooks and tickets: markup characters, quotes and backticks are removed here (the stored record keeps its text, escape it on output)
                    $t = static fn (string $v, int $max): string => ChangeProtocol::alertText($v, $max);
                    $this->events->emit(RmmEvent::CHANGE_DETECTED, $dev, ['kind' => $r['kind'], 'watch' => $r['watch'], 'change' => ChangeProtocol::changeName($r['change']), 'target' => $t($r['target'], ChangeProtocol::TARGET_MAX),
                        'item' => $t($r['item'], ChangeProtocol::ITEM_MAX), 'old_value' => $t($r['old'], ChangeProtocol::VALUE_MAX), 'new_value' => $t($r['new'], ChangeProtocol::VALUE_MAX), 'summary' => $t($r['summary'], ChangeProtocol::SUMMARY_MAX),
                        'actor' => $t($r['actor'], ChangeProtocol::ACTOR_MAX), 'process' => $t($r['process'], ChangeProtocol::PROCESS_MAX)]);
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
        $new = array_flip($this->store->dedupeKeysReceivedAt($deviceId, $nowSql));

        return array_values(array_filter($kept, static fn (array $r): bool => isset($new[$r['dedupe']])));
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
        $t = min($t, $now + RmmProtocol::CHECKIN_FUTURE_SKEW_S);
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
        if ($visibleClientIds !== null && $visibleClientIds === []) {
            return ['items' => [], 'total' => 0] + $range;
        }
        $text = static fn (string $k, int $max): ?string => isset($f[$k]) && is_string($f[$k]) && $f[$k] !== '' ? mb_substr($f[$k], 0, $max) : null;
        $page = $this->store->search(new ChangeCriteria(gmdate('Y-m-d H:i:s', $from), gmdate('Y-m-d H:i:s', $to), $device,
            isset($f['client_id']) && is_numeric($f['client_id']) && (int) $f['client_id'] > 0 ? (int) $f['client_id'] : 0, $visibleClientIds,
            isset($f['kind']) && is_string($f['kind']) && in_array($f['kind'], ChangeProtocol::KINDS, true) ? $f['kind'] : null,
            isset($f['watch']) && is_string($f['watch']) && preg_match(ChangeProtocol::WATCH_ID_RE, $f['watch']) === 1 ? $f['watch'] : null,
            isset($f['change']) && is_string($f['change']) ? ChangeProtocol::changeNumber($f['change']) : null,
            $text('target', 100), $text('item', 100), $text('actor', 100), $text('q', 200)), $limit, $offset);
        $rows = $page['rows'];
        $total = $page['total'];

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
     * @param bool $software false leaves the software inventory history out (the caller may see changes but not software)
     * @return array{items:list<array<string,mixed>>,actors:list<array<string,mixed>>,days:list<array<string,mixed>>,from:string,to:string,truncated:bool}
     */
    public function timeline(int $deviceId, array $f, int $limit = 200, bool $software = true): array
    {
        $now = $this->sql->time();
        $to = self::when($f['to'] ?? null, $now);
        $from = max(self::when($f['from'] ?? null, max(0, $to - 30 * 86400)), $to - 365 * 86400);
        $limit = max(1, min(500, $limit));
        $a = gmdate('Y-m-d H:i:s', $from);
        $b = gmdate('Y-m-d H:i:s', $to);
        $tl = $this->store->timeline($deviceId, $a, $b, $limit);
        $items = array_map(self::present(...), $tl['rows']);
        try {
            $soft = !$software ? [] : $this->softwareHistory->between($deviceId, $a, $b, $limit);
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
        $actors = array_map(static fn (array $r): array => ['actor' => $r['actor'], 'count' => $r['c']], $tl['actors']);
        $days = array_map(static fn (array $r): array => ['day' => $r['d'], 'count' => $r['c']], $tl['days']);

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
        $out = ['fleet_rows' => $this->store->fleetRowCount($visibleClientIds), 'fleet_max_rows' => $l['change_fleet_max_rows'], 'device_max_rows' => $l['change_device_max_rows'], 'retention_days' => $l['change_retention_days'],
            'rate_per_min' => $l['change_rate_per_min'], 'interval_s' => $l['change_interval_s']];
        if ($deviceId !== null) {
            $s = $this->store->deviceLedger($deviceId);
            $monitor = isset($s['monitor_json']) ? json_decode($s['monitor_json'], true) : null;
            $out['device'] = ['device_id' => $deviceId, 'rows' => (int) ($s['rows'] ?? 0), 'dropped_total' => (int) ($s['dropped_total'] ?? 0),
                'last_change_at' => Sql::iso($s['last_change_at'] ?? null), 'last_batch_at' => Sql::iso($s['last_batch_at'] ?? null),
                'config_version' => (string) ($s['config_version'] ?? ''), 'monitor' => is_array($monitor) ? $monitor : null, 'monitor_at' => Sql::iso($s['monitor_at'] ?? null)];
        } else {
            $out['last_24h_by_watch'] = array_map(static fn (array $r): array => ['watch' => $r['watch'], 'count' => $r['c']], $this->store->countsByWatch($this->sql->utcAt(-86400), $visibleClientIds));
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
     * Delete what is past retention, then trim every device above its row cap, then the fleet above its cap, in batches of at most {@see PRUNE_BATCH} rows (one pass: at most
     * {@see PRUNE_RUN_CAP}).
     *
     * @param (\Closure(int):void)|null $pause called between batches with microseconds to wait
     * @return array{retention:int,device_cap:int,fleet_cap:int}
     */
    public function prune(?\Closure $pause = null): array
    {
        $l = $this->settings->limits();

        return $this->store->enforceLimits($this->sql->utcAt(-$l['change_retention_days'] * 86400), $l['change_device_max_rows'], $l['change_fleet_max_rows'], self::PRUNE_RUN_CAP, self::PRUNE_BATCH, $pause);
    }

    /** Whether any device has a change ledger (housekeeping keeps pruning after the feature is switched off while this is true). */
    public function hasRecorded(): bool
    {
        return $this->store->hasLedger();
    }
}
