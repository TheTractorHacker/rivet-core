<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Checkin;

use RivetCore\Rmm\Capacity\LoadShedder;
use RivetCore\Rmm\Checks\CheckEvaluator;
use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Enrollment\DeviceValidator;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Job\JobService;
use RivetCore\Rmm\Link\RmmLinker;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Update\UpdateService;

/**
 * POST agent_checkin processing: validation, idempotency by (device_id, seq), inventory, metrics (through the edition's sink),
 * checks (debounced alerts) and the response.
 *
 * A missing metric (null or absent) produces NO sample. It is never stored as 0. Out-of-range readings are dropped, never clamped.
 *
 * @api
 */
final class CheckinService
{
    public const MAX_BODY_BYTES = RmmProtocol::CHECKIN_MAX_BODY;
    public const MAX_CHECKS = RmmProtocol::CHECKIN_MAX_CHECKS;
    public const MAX_BUFFERED = RmmProtocol::CHECKIN_MAX_BUFFERED;
    public const MAX_DISKS = RmmProtocol::CHECKIN_MAX_DISKS;
    public const MAX_INVENTORY_BYTES = RmmProtocol::CHECKIN_MAX_INVENTORY_BYTES;
    private const TIME_RE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:\d{2})$/';
    /** At load-shedding level 1 buffered samples older than this are acknowledged but not ingested. */
    private const SHED_STALE_SAMPLE_S = 900;
    /** Above this many bytes of work a check-in is processed inline even in queued mode (the job payload column is text). */
    public const MAX_QUEUED_PAYLOAD_BYTES = 60000;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly DeviceRepository $devices,
        private readonly CheckEvaluator $checks,
        private readonly JobService $jobs,
        private readonly UpdateService $updates,
        private readonly RmmLinker $linker,
        private readonly RmmAssetsInterface $assets,
        private readonly RmmMetricSinkInterface $metrics,
        /** @var (\Closure(array<string,mixed>): void)|null enqueues the heavy part of a check-in (ingest_mode queued); null = always inline */
        private readonly ?\Closure $enqueue = null,
    ) {
    }

    /**
     * @param array<string,mixed> $dev the authenticated device row
     * @param array<string,mixed> $body the decoded request body
     * @return array<string,mixed> the 200 body
     * @throws ApiError
     */
    public function handle(array $dev, array $body, string $ip): array
    {
        $cfg = $this->settings->get();
        $features = $this->settings->features();

        $seq = $body['seq'] ?? null;
        if (!is_int($seq) || $seq < 0 || $seq > 9007199254740991) {
            throw new ApiError(422, 'invalid', 'seq must be a non-negative integer');
        }
        $collected = $this->parseTime($body['collected_at'] ?? null, 'collected_at', $cfg);
        $ver = $body['agent_version'] ?? null;
        if (!is_string($ver) || preg_match(RmmProtocol::AGENT_VERSION_RE, $ver) !== 1) {
            throw new ApiError(422, 'invalid', 'agent_version is invalid');
        }
        $inventory = $body['inventory'] ?? null;
        if ($inventory !== null) {
            if (!is_array($inventory) || array_is_list($inventory) || strlen((string) json_encode($inventory)) > self::MAX_INVENTORY_BYTES) {
                throw new ApiError(422, 'invalid', 'inventory must be an object under ' . self::MAX_INVENTORY_BYTES . ' bytes');
            }
        }
        $metrics = $body['metrics'] ?? null;
        if ($metrics !== null && (!is_array($metrics) || (array_is_list($metrics) && $metrics !== []))) {
            throw new ApiError(422, 'invalid', 'metrics must be an object');
        }
        $checks = $body['checks'] ?? [];
        if (!is_array($checks) || !array_is_list($checks) || count($checks) > self::MAX_CHECKS) {
            throw new ApiError(422, 'invalid', 'checks must be a list of at most ' . self::MAX_CHECKS . ' items');
        }
        $buffered = $body['buffered'] ?? [];
        if (!is_array($buffered) || !array_is_list($buffered) || count($buffered) > self::MAX_BUFFERED) {
            throw new ApiError(422, 'invalid', 'buffered must be a list of at most ' . self::MAX_BUFFERED . ' samples');
        }
        $primaryChecks = $this->cleanChecks($checks, true);
        $this->shedIfOverloaded($dev, $cfg);

        $this->sql->transaction(function () use ($dev, $seq, $collected, $ver, $inventory, $metrics, $primaryChecks, $buffered, $cfg, $features, $ip, $body): void {
            // Serialise check-ins of ONE device; different devices never contend.
            $cur = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ? FOR UPDATE', [$dev['device_id']]);
            if ($cur === null || $cur['revoked_at'] !== null || $cur['retired_at'] !== null || $cur['token_hash'] !== $dev['token_hash']) {
                throw new ApiError(401, 'revoked', 'This device credential was revoked.');
            }
            $now = $this->sql->utcNow();
            $fresh = $this->sql->run('INSERT IGNORE INTO endpoint_agent_checkins (device_id, seq, received_at, collected_at) VALUES (?, ?, ?, ?)',
                [$cur['device_id'], $seq, $now, $collected->format('Y-m-d H:i:s')]);
            // Liveness is real even for a duplicate delivery.
            $this->sql->run('UPDATE endpoint_agent_devices SET last_checkin_at = ?, last_ip = ? WHERE device_id = ?', [$now, substr($ip, 0, 64), $cur['device_id']]);
            if ($fresh === 1) {
                $this->process($cur, $seq, $collected, $ver, $inventory, $metrics, $primaryChecks, $buffered, $cfg, $features, $body['update_result'] ?? null);
            }
        });

        $dev = $this->devices->find((int) $dev['device_id']) ?? $dev;
        $interval = (int) $cfg['check_in_interval_s'];
        if ((int) ($cfg['shed_level'] ?? 0) >= 2) {
            $interval = min(RmmSettings::CHECK_IN_INTERVAL_MAX_S * 2, $interval * 2);   // level 2: lengthen the intervals
        }
        $out = [
            'ok' => true,
            'status' => $dev['link_state'] === 'linked' ? 'linked' : 'pending_approval',
            'matched_asset_id' => ($dev['link_state'] === 'linked' && !empty($dev['asset_id'])) ? (int) $dev['asset_id'] : null,
            'next_check_in_s' => $interval,
            'jobs_pending' => $features['jobs'] ? $this->jobs->pendingCount((int) $dev['device_id']) : 0,
            'config' => ['checks' => $this->settings->signedChecks(), 'collect_interval_s' => (int) $cfg['collect_interval_s']],
        ];
        if ($features['updates']) {
            $out['update'] = $this->updates->manifestFor($dev);
        }
        $out['server_time'] = $this->sql->isoNow();
        $out['signing_key_id'] = $this->settings->get()['signing_key_id'];

        return $out;
    }

    /**
     * Load shedding level 3 refuses new work: the device is told to come back later (503 + Retry-After with jitter) before any
     * work is done. Enrollment, job reports and revocation never pass through here. Levels 1 (optional samples dropped) and 2
     * (longer intervals) answer normally.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $cfg
     */
    private function shedIfOverloaded(array $dev, array $cfg): void
    {
        if ((int) ($cfg['shed_level'] ?? 0) < 3) {
            return;
        }
        throw LoadShedder::refusal($this->settings->limits());
    }

    /**
     * The request's share of a fresh check-in: the device-state writes every mode does inside the request, then either the
     * heavy part inline (ingest_mode sync, the default) or one `rmm.ingest` job (ingest_mode queued, payloads up to
     * {@see MAX_QUEUED_PAYLOAD_BYTES}; a larger one is processed inline).
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed>|null $inventory
     * @param array<string,mixed>|null $metrics
     * @param list<array{key:string,status:string,detail:string,at:string}> $primaryChecks
     * @param array<mixed> $buffered
     * @param array<string,mixed> $cfg
     * @param array<string,bool> $features
     */
    private function process(array $dev, int $seq, \DateTimeImmutable $collected, string $ver, ?array $inventory, ?array $metrics, array $primaryChecks, array $buffered, array $cfg, array $features, mixed $updateResult): void
    {
        $deviceId = (int) $dev['device_id'];
        $collectedSql = $collected->format('Y-m-d H:i:s');
        $newer = $dev['last_collected_at'] === null || $collectedSql >= $dev['last_collected_at'];
        $this->updates->recordResult($dev, $updateResult);
        if ($newer) {
            $this->sql->run('UPDATE endpoint_agent_devices SET agent_version = ?, last_collected_at = ?, last_seq = GREATEST(last_seq, ?),
                last_metrics_json = ? WHERE device_id = ?', [$ver, $collectedSql, $seq, $metrics === null ? null : json_encode(self::cleanMetrics($metrics)), $deviceId]);
        } else {
            $this->sql->run('UPDATE endpoint_agent_devices SET last_seq = GREATEST(last_seq, ?) WHERE device_id = ?', [$seq, $deviceId]);
        }
        $work = ['device_id' => $deviceId, 'seq' => $seq, 'collected' => $collectedSql, 'received' => $this->sql->time(), 'newer' => $newer, 'inventory' => $inventory,
            'metrics' => $metrics, 'checks' => $primaryChecks, 'buffered' => $buffered, 'shed' => (int) ($cfg['shed_level'] ?? 0) >= 1];
        if ($this->enqueue !== null && ($cfg['ingest_mode'] ?? 'sync') === 'queued') {
            $encoded = json_encode($work);
            if (is_string($encoded) && strlen($encoded) <= self::MAX_QUEUED_PAYLOAD_BYTES) {
                ($this->enqueue)($work);

                return;
            }
        }
        $this->applyWork($work, false, $dev);
    }

    /**
     * The heavy part of a check-in, shared by the inline path and the `rmm.ingest` job (Capacity\IngestQueue): apply the inventory,
     * update the edition's asset blanks, build the metric samples (current batch plus buffered backlog), push the health to the
     * edition's RMM link and evaluate the checks. Every step is idempotent, so a retried job repeats them safely. The samples go to the
     * edition's sink here unless $deferSamples is set, in which case they are RETURNED (the caller delivers them last, and may merge
     * the samples of many check-ins into one sink call).
     *
     * @param array<string,mixed> $w work as built by {@see process()} (it is the job payload)
     * @param array<string,mixed>|null $dev the device row when the caller just read it (saves one SELECT)
     * @return list<array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string}>|null samples not yet delivered; null when the device no longer exists or was revoked (nothing to do)
     */
    public function applyWork(array $w, bool $deferSamples = false, ?array $dev = null): ?array
    {
        $deviceId = (int) $w['device_id'];
        $cfg = $this->settings->get();
        $features = $this->settings->features();
        $dev ??= $this->devices->find($deviceId);
        if ($dev === null || $dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            return null;
        }
        $collected = new \DateTimeImmutable((string) $w['collected'] . ' UTC');
        $inventory = is_array($w['inventory'] ?? null) ? $w['inventory'] : null;
        $metrics = is_array($w['metrics'] ?? null) ? $w['metrics'] : null;
        $buffered = is_array($w['buffered'] ?? null) ? $w['buffered'] : [];
        /** @var list<array{key:string,status:string,detail:string,at:string}> $primaryChecks */
        $primaryChecks = is_array($w['checks'] ?? null) ? $w['checks'] : [];

        // ---- inventory ----
        $cleanInv = null;
        if ($inventory !== null && !empty($w['newer'])) {
            $cleanInv = $this->applyInventory($dev, $inventory);
        }
        $dev = $this->devices->find($deviceId) ?? $dev;
        $linked = $dev['link_state'] === 'linked' && !empty($dev['asset_id']);
        if ($cleanInv !== null && $linked) {
            $this->assets->fillBlanks((int) $dev['asset_id'], [
                'serial' => DeviceValidator::cleanSerial($cleanInv['serial']) ?? ($dev['serial'] === null ? null : (string) $dev['serial']),
                'model' => $cleanInv['model'],
                'manufacturer' => $cleanInv['manufacturer'],
                'os' => (($dev['os'] ?? 'windows') === 'linux' ? 'Linux' : 'Windows') . ' ' . ($cleanInv['os_version'] ?? $dev['os_version']),
            ]);
        }

        // ---- metrics, current sample and buffered backlog ----
        $assetId = $linked ? (int) $dev['asset_id'] : 0;
        $batches = [[$collected, $metrics, $inventory]];
        $bufChecks = [];
        foreach ($buffered as $b) {
            if (!is_array($b)) {
                continue;
            }
            try {
                $bt = $this->parseTime($b['collected_at'] ?? null, 'buffered.collected_at', $cfg);
            } catch (ApiError) {
                continue;   // a bad backlog entry is dropped on its own; the request still succeeds
            }
            $bm = $b['metrics'] ?? null;
            $batches[] = [$bt, is_array($bm) ? $bm : null, null];
            if (isset($b['checks']) && is_array($b['checks']) && array_is_list($b['checks'])) {
                foreach ($this->cleanChecks(array_slice($b['checks'], 0, self::MAX_CHECKS), false) as $c) {
                    $c['at'] = $bt->format('Y-m-d H:i:s');
                    $bufChecks[] = $c;
                }
            }
        }
        $samples = [];
        if ($assetId > 0 && $features['metrics']) {
            $staleBefore = (int) ($w['received'] ?? $this->sql->time()) - self::SHED_STALE_SAMPLE_S;
            $shed = !empty($w['shed']);
            foreach ($batches as $i => [$at, $m, $inv]) {
                if ($shed && $i > 0 && $at->getTimestamp() < $staleBefore) {
                    continue;   // acknowledged, not ingested
                }
                foreach ($this->samplesFor($assetId, $at, $m, $inv) as $s) {
                    $samples[] = $s;
                }
            }
            if ($samples !== [] && !$deferSamples) {
                $this->metrics->ingest($samples, $this->settings->integrationId());
                $samples = [];
            }
        }

        // ---- health columns on the edition's RMM link ----
        $health = ['cpu' => self::num($metrics['cpu_pct'] ?? null), 'mem' => self::num($metrics['mem_pct'] ?? null), 'disk' => null];
        $disks = is_array($metrics['disk'] ?? null) ? $metrics['disk'] : [];
        foreach ($disks as $dk) {
            $u = is_array($dk) ? self::num($dk['used_pct'] ?? null) : null;
            if ($u !== null && ($health['disk'] === null || $u > $health['disk'])) {
                $health['disk'] = $u;
            }
        }
        if ($features['monitoring']) {
            $this->linker->applyCheckin($dev, $health);

            // ---- checks: backlog oldest first, then the current results ----
            usort($bufChecks, static fn (array $a, array $b): int => strcmp($a['at'], $b['at']));
            $this->checks->apply($dev, array_merge($bufChecks, $primaryChecks));
        }

        return $samples;
    }

    /**
     * @param array<string,mixed> $dev
     * @param array<string,mixed> $inv
     * @return array{hostname:?string,os:?string,os_version:?string,manufacturer:?string,model:?string,serial:?string,uptime_s:?int,pending_reboot:?bool}
     */
    private function applyInventory(array $dev, array $inv): array
    {
        $txt = static fn (mixed $v, int $n): ?string => DeviceValidator::cleanText($v, $n);
        $deviceId = (int) $dev['device_id'];
        $macs = [];
        $nics = is_array($inv['network'] ?? null) ? array_slice($inv['network'], 0, 32) : [];
        foreach ($nics as $nic) {
            $mac = is_array($nic) && is_string($nic['mac'] ?? null) ? DeviceValidator::normalizeMac($nic['mac']) : null;
            if ($mac !== null) {
                $macs[$mac] = $mac;
            }
        }
        $cpu = is_array($inv['cpu'] ?? null) ? $inv['cpu'] : [];
        $clean = [
            'hostname' => $txt($inv['hostname'] ?? null, 200),
            'os' => $txt($inv['os'] ?? null, 50),
            'os_version' => $txt($inv['os_version'] ?? null, 200),
            'manufacturer' => $txt($inv['manufacturer'] ?? null, 200),
            'model' => $txt($inv['model'] ?? null, 200),
            'serial' => $txt($inv['serial'] ?? null, 100),
            'cpu' => ['model' => $txt($cpu['model'] ?? null, 200), 'cores' => self::intOrNull($cpu['cores'] ?? null)],
            'memory_total_bytes' => self::intOrNull($inv['memory_total_bytes'] ?? null),
            'uptime_s' => self::intOrNull($inv['uptime_s'] ?? null),
            'logged_in_user' => $txt($inv['logged_in_user'] ?? null, 200),
            'pending_reboot' => isset($inv['pending_reboot']) ? (bool) $inv['pending_reboot'] : null,
            'disks' => [],
            'network' => [],
        ];
        $disks = is_array($inv['disks'] ?? null) ? array_slice($inv['disks'], 0, self::MAX_DISKS) : [];
        foreach ($disks as $d) {
            if (is_array($d) && ($mount = $txt($d['mount'] ?? null, 64)) !== null) {
                $clean['disks'][] = ['mount' => $mount, 'total_bytes' => self::intOrNull($d['total_bytes'] ?? null), 'free_bytes' => self::intOrNull($d['free_bytes'] ?? null), 'fs' => $txt($d['fs'] ?? null, 20)];
            }
        }
        foreach ($nics as $n) {
            if (!is_array($n)) {
                continue;
            }
            $ips = [];
            $rawIps = is_array($n['ips'] ?? null) ? array_slice($n['ips'], 0, 16) : [];
            foreach ($rawIps as $ip) {
                if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $ips[] = $ip;
                }
            }
            $clean['network'][] = ['name' => $txt($n['name'] ?? null, 100), 'mac' => is_string($n['mac'] ?? null) ? DeviceValidator::normalizeMac($n['mac']) : null, 'ips' => $ips];
        }
        $meshNode = $inv['mesh_node_id'] ?? null;

        $this->sql->run('UPDATE endpoint_agent_devices SET inventory_json = ?, last_inventory_at = ?, hostname = COALESCE(?, hostname), os_version = COALESCE(?, os_version),
            manufacturer = COALESCE(?, manufacturer), model = COALESCE(?, model), serial = COALESCE(serial, ?), mac_addresses = ?, logged_in_user = ?, pending_reboot = ?, uptime_s = ?
            WHERE device_id = ?',
            [json_encode($clean), $this->sql->utcNow(), $clean['hostname'], $clean['os_version'], $clean['manufacturer'], $clean['model'], DeviceValidator::cleanSerial($clean['serial']),
                $macs !== [] ? json_encode(array_values($macs)) : $dev['mac_addresses'], $clean['logged_in_user'], $clean['pending_reboot'] === null ? null : (int) $clean['pending_reboot'], $clean['uptime_s'], $deviceId]);

        if (is_string($meshNode) && DeviceRepository::validMeshNodeId($meshNode)) {
            $existing = $this->sql->one('SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);
            // A node an administrator set by hand is never overwritten by what the device claims.
            if ($existing === null || $existing['source'] === 'agent') {
                $this->devices->setMeshNode($deviceId, $meshNode, 0, 'agent');
            }
        }

        return ['hostname' => $clean['hostname'], 'os' => $clean['os'], 'os_version' => $clean['os_version'], 'manufacturer' => $clean['manufacturer'], 'model' => $clean['model'],
            'serial' => $clean['serial'], 'uptime_s' => $clean['uptime_s'], 'pending_reboot' => $clean['pending_reboot']];
    }

    /**
     * @param array<string,mixed>|null $m
     * @param array<string,mixed>|null $inv
     * @return list<array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string}>
     */
    private function samplesFor(int $assetId, \DateTimeImmutable $at, ?array $m, ?array $inv): array
    {
        $out = [];
        $add = static function (string $key, ?string $inst, int|float|null $val, ?string $label = null) use (&$out, $assetId, $at): void {
            if ($val === null) {
                return;   // a missing reading is a missing sample, never zero
            }
            if (!is_finite((float) $val) || $val < 0 || (str_ends_with($key, '.utilization') && $val > 100)) {
                return;   // out of range: dropped, never clamped
            }
            $out[] = ['asset_id' => $assetId, 'key' => $key, 'instance' => $inst, 'value' => $val, 'at' => $at, 'label' => $label];
        };
        if ($m !== null) {
            $add('cpu.utilization', null, self::num($m['cpu_pct'] ?? null));
            $add('memory.utilization', null, self::num($m['mem_pct'] ?? null));
            $disks = is_array($m['disk'] ?? null) ? array_slice($m['disk'], 0, self::MAX_DISKS) : [];
            foreach ($disks as $d) {
                if (is_array($d) && is_string($d['mount'] ?? null) && $d['mount'] !== '') {
                    $add('disk.utilization', mb_substr($d['mount'], 0, 64), self::num($d['used_pct'] ?? null), mb_substr($d['mount'], 0, 64));
                }
            }
            // The agent reports BITS per second; the metric registry stores bytes per second.
            $rx = self::num($m['net_rx_bps'] ?? null);
            $tx = self::num($m['net_tx_bps'] ?? null);
            $add('network.rx_bytes_per_s', 'total', $rx === null ? null : $rx / 8, 'All adapters');
            $add('network.tx_bytes_per_s', 'total', $tx === null ? null : $tx / 8, 'All adapters');
        }
        if ($inv !== null) {
            $add('system.uptime_seconds', null, self::intOrNull($inv['uptime_s'] ?? null));
            if (isset($inv['pending_reboot'])) {
                $add('system.pending_reboot', null, $inv['pending_reboot'] ? 1 : 0);
            }
            $add('memory.total_bytes', null, self::intOrNull($inv['memory_total_bytes'] ?? null));
            $disks = is_array($inv['disks'] ?? null) ? array_slice($inv['disks'], 0, self::MAX_DISKS) : [];
            foreach ($disks as $d) {
                if (is_array($d) && is_string($d['mount'] ?? null) && $d['mount'] !== '') {
                    $mt = mb_substr($d['mount'], 0, 64);
                    $add('disk.total_bytes', $mt, self::intOrNull($d['total_bytes'] ?? null), $mt);
                    $add('disk.free_bytes', $mt, self::intOrNull($d['free_bytes'] ?? null), $mt);
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ small validators

    /**
     * @param array<string,mixed> $cfg
     * @throws ApiError 422
     */
    public function parseTime(mixed $raw, string $field, array $cfg): \DateTimeImmutable
    {
        if (!is_string($raw) || preg_match(self::TIME_RE, $raw) !== 1) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        try {
            $dt = (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new ApiError(422, 'invalid', "$field must be an RFC 3339 timestamp");
        }
        $ts = $dt->getTimestamp();
        $now = $this->sql->time();
        if ($ts > $now + RmmProtocol::CHECKIN_FUTURE_SKEW_S) {
            throw new ApiError(422, 'invalid', "$field is in the future");
        }
        if ($ts < $now - (int) $cfg['retention_days'] * 86400) {
            throw new ApiError(422, 'invalid', "$field is older than the retention window");
        }

        return $dt->setTime((int) $dt->format('H'), (int) $dt->format('i'), (int) $dt->format('s'));
    }

    /**
     * @param array<mixed> $checks
     * @return list<array{key:string,status:string,detail:string,at:string}>
     */
    private function cleanChecks(array $checks, bool $strict): array
    {
        $out = [];
        $seen = [];
        foreach ($checks as $c) {
            $key = is_array($c) ? ($c['key'] ?? null) : null;
            $status = is_array($c) ? ($c['status'] ?? null) : null;
            if (!is_string($key) || preg_match(RmmProtocol::CHECK_KEY_RE, $key) !== 1 || !is_string($status) || !in_array($status, RmmProtocol::CHECK_STATUSES, true)) {
                if ($strict) {
                    throw new ApiError(422, 'invalid', 'each check needs a valid key and a status of ok, warn, fail or unknown');
                }
                continue;
            }
            if (isset($seen[$key])) {
                continue;   // one result per check per check-in
            }
            $seen[$key] = 1;
            /** @var array<string,mixed> $c */
            $detail = DeviceValidator::cleanText($c['detail'] ?? '', 500) ?? '';
            $out[] = ['key' => $key, 'status' => $status, 'detail' => $detail, 'at' => $this->sql->utcNow()];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $m
     * @return array<string,mixed>
     */
    private static function cleanMetrics(array $m): array
    {
        $o = ['cpu_pct' => self::num($m['cpu_pct'] ?? null), 'mem_pct' => self::num($m['mem_pct'] ?? null), 'disk' => [],
            'net_rx_bps' => self::num($m['net_rx_bps'] ?? null), 'net_tx_bps' => self::num($m['net_tx_bps'] ?? null)];
        $disks = is_array($m['disk'] ?? null) ? array_slice($m['disk'], 0, self::MAX_DISKS) : [];
        foreach ($disks as $d) {
            if (is_array($d) && is_string($d['mount'] ?? null)) {
                $o['disk'][] = ['mount' => mb_substr($d['mount'], 0, 64), 'used_pct' => self::num($d['used_pct'] ?? null)];
            }
        }

        return $o;
    }

    private static function num(mixed $v): ?float
    {
        return (is_int($v) || is_float($v)) && is_finite((float) $v) ? (float) $v : null;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return (is_int($v) && $v >= 0) ? $v : ((is_float($v) && is_finite($v) && $v >= 0 && $v < 9e15) ? (int) $v : null);
    }
}
