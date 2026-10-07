<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

use RivetCore\Rmm\RmmState;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The data of the Administration > RMM > "Performance and capacity" panel (design 13.5, scaling item S8): enrolled devices by status,
 * check-in rate, queue depth and ingest latency, table sizes, the projection of the estimate model of 13.2 filled with the live
 * settings, warnings and the one-click "Reduce load" presets. The edition renders it; nothing here prints.
 *
 * The model and the presets are PURE static functions ({@see project()}, {@see warnings()}, {@see presets()}, {@see profiles()}): same
 * inputs, same outputs, no database. A preset is a PATCH, a list of settings columns for {@see RmmSettings::update()}; it is shown with its
 * before and after projection, applied only when an administrator confirms, never automatically, and it never deletes data.
 *
 * The model (13.2): check-ins/s = devices / check_in_interval_s; batches per check-in = max(1, round(check_in / collect)); about
 * 7 sample rows per batch (cpu, memory, 1 to 3 disks, network rx/tx) when metrics are on; about 7 further rows per check-in for the
 * check-in row, device updates and link; about 12 statements for the base path plus 7 + batches with metrics; storage = sample rows
 * per check-in x check-ins per day x days x 120 bytes; PHP workers busy = check-ins/s x 0.15 s, peak three times that. Replace the
 * constants with the measured ones as docs/rmm/CAPACITY.md records them.
 *
 * @api
 */
final class CapacityReport
{
    public const ROWS_PER_BATCH = 7;
    public const BASE_ROWS = 7;
    public const BASE_STATEMENTS = 12;
    public const BYTES_PER_SAMPLE_ROW = 120;
    public const SECONDS_PER_REQUEST = 0.15;
    public const PEAK_FACTOR = 3;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly IngestQueue $queue,
        private readonly RmmState $state,
        private readonly ?LoadShedder $shedder = null,
    ) {
    }

    /**
     * Everything the panel shows, read-only.
     *
     * @param array{redis_available?:?bool,storage_budget_gb?:?float} $env what only the edition knows
     * @return array<string,mixed>
     */
    public function build(array $env = []): array
    {
        $cfg = $this->settings->get(true);
        $features = $this->settings->features();
        $limits = $this->settings->limits();
        $now = $this->sql->time();
        $d = $this->devices($cfg);
        $perMin = (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_checkins WHERE received_at >= ?', [$this->sql->utcAt(-60)]);
        $perHour = (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_checkins WHERE received_at >= ?', [$this->sql->utcAt(-3600)]);
        $backlog = $this->queue->backlog();
        $mem = $this->shedder?->readMemory($this->state->directory());
        $inputs = [
            'devices' => max($d['active'], 1),
            'check_in_interval_s' => (int) $cfg['check_in_interval_s'],
            'collect_interval_s' => (int) $cfg['collect_interval_s'],
            'metrics' => $features['metrics'],
            'retention_days' => (int) $cfg['retention_days'],
        ];
        $projection = self::project($inputs);
        $facts = [
            'devices' => $d['active'], 'max_devices' => (int) $cfg['max_devices'], 'collect_interval_s' => $inputs['collect_interval_s'],
            'retention_days' => $inputs['retention_days'], 'queue_age_s' => $backlog['oldest_pending_age_s'], 'shed_level' => (int) $cfg['shed_level'],
            'redis_available' => $env['redis_available'] ?? null, 'projected_storage_gb' => $projection['storage_steady_gb'], 'storage_budget_gb' => $env['storage_budget_gb'] ?? null,
            'ingest_mode' => (string) $cfg['ingest_mode'], 'dead_letter' => $backlog['dead_letter'],
        ];

        return [
            'generated_at' => $now,
            'enabled' => $this->state->enabled(),
            'devices' => $d,
            'checkins' => ['last_minute' => $perMin, 'last_hour' => $perHour, 'per_minute_limit' => $limits['max_checkins_per_min']],
            'shed' => ['level' => (int) $cfg['shed_level'], 'signals' => $mem['signals'] ?? [], 'evaluated_at' => $mem['at'] ?? 0, 'thresholds' => array_intersect_key($limits, array_flip(array_filter(array_keys($limits), static fn (string $k): bool => str_starts_with($k, 'shed_'))))],
            'ingest_mode' => (string) $cfg['ingest_mode'],
            'queue' => $backlog,
            'tables' => $this->tables(),
            'settings' => $inputs + ['max_devices' => (int) $cfg['max_devices']],
            'projection' => $projection,
            'warnings' => self::warnings($facts),
            'presets' => self::presets($cfg, $features, $limits, $inputs['devices']),
        ];
    }

    /**
     * Enrolled devices by status. Online = checked in within offline_after_s, offline = later than that, stale = later than stale_after_s or never.
     *
     * @param array<string,mixed> $cfg
     * @return array{total:int,active:int,online:int,offline:int,stale:int,never:int,revoked:int,retired:int,pending_approval:int}
     */
    public function devices(array $cfg): array
    {
        $r = $this->sql->one(
            'SELECT COUNT(*) AS total,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL), 0) AS active,
                COALESCE(SUM(revoked_at IS NOT NULL), 0) AS revoked,
                COALESCE(SUM(retired_at IS NOT NULL AND revoked_at IS NULL), 0) AS retired,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL AND last_checkin_at IS NULL), 0) AS never,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL AND last_checkin_at >= ?), 0) AS online,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL AND last_checkin_at < ? AND last_checkin_at >= ?), 0) AS offline,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL AND last_checkin_at < ?), 0) AS stale,
                COALESCE(SUM(revoked_at IS NULL AND retired_at IS NULL AND link_state = ?), 0) AS pending_approval
             FROM endpoint_agent_devices',
            [$this->sql->utcAt(-(int) $cfg['offline_after_s']), $this->sql->utcAt(-(int) $cfg['offline_after_s']), $this->sql->utcAt(-(int) $cfg['stale_after_s']),
                $this->sql->utcAt(-(int) $cfg['stale_after_s']), 'pending_approval']
        ) ?? [];
        $out = [];
        foreach (['total', 'active', 'online', 'offline', 'stale', 'never', 'revoked', 'retired', 'pending_approval'] as $k) {
            $out[$k] = (int) ($r[$k] ?? 0);
        }
        $out['stale'] += $out['never'];

        return $out;
    }

    /**
     * Rows and size of every endpoint_agent_* table and of the shared job queue (information_schema: estimates, not counts).
     *
     * @return list<array{table:string,rows:int,data_mb:float,index_mb:float,total_mb:float}>
     */
    public function tables(): array
    {
        $out = [];
        $rows = $this->sql->all(
            "SELECT TABLE_NAME AS t, COALESCE(TABLE_ROWS, 0) AS r, COALESCE(DATA_LENGTH, 0) AS d, COALESCE(INDEX_LENGTH, 0) AS i FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_NAME LIKE 'endpoint\\_agent\\_%' OR TABLE_NAME = 'integration_jobs') ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC"
        );
        foreach ($rows as $r) {
            $d = (float) $r['d'] / 1048576;
            $i = (float) $r['i'] / 1048576;
            $out[] = ['table' => (string) $r['t'], 'rows' => (int) $r['r'], 'data_mb' => round($d, 2), 'index_mb' => round($i, 2), 'total_mb' => round($d + $i, 2)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ the pure model

    /**
     * The estimate model of design 13.2.
     *
     * @param array{devices:int,check_in_interval_s:int,collect_interval_s:int,metrics:bool,retention_days:int} $in
     * @return array{checkins_per_s:float,batches:int,sample_rows_per_checkin:int,rows_per_checkin:int,rows_per_s:float,statements_per_checkin:int,statements_per_s:float,workers_avg:float,workers_peak:float,storage_per_month_gb:float,storage_steady_gb:float}
     */
    public static function project(array $in): array
    {
        $checkin = max(1, $in['check_in_interval_s']);
        $collect = max(1, $in['collect_interval_s']);
        $perSecond = max(0, $in['devices']) / $checkin;
        $batches = max(1, (int) round($checkin / $collect));
        $sampleRows = $in['metrics'] ? $batches * self::ROWS_PER_BATCH : 0;
        $rows = self::BASE_ROWS + $sampleRows;
        $statements = self::BASE_STATEMENTS + ($in['metrics'] ? 7 + $batches : 0);
        $perDay = $perSecond * 86400;
        $bytesPerDay = $sampleRows * $perDay * self::BYTES_PER_SAMPLE_ROW;
        $gb = 1000000000;   // decimal gigabytes, as the design's table

        return [
            'checkins_per_s' => round($perSecond, 2),
            'batches' => $batches,
            'sample_rows_per_checkin' => $sampleRows,
            'rows_per_checkin' => $rows,
            'rows_per_s' => round($perSecond * $rows, 1),
            'statements_per_checkin' => $statements,
            'statements_per_s' => round($perSecond * $statements, 1),
            'workers_avg' => round($perSecond * self::SECONDS_PER_REQUEST, 2),
            'workers_peak' => round($perSecond * self::SECONDS_PER_REQUEST * self::PEAK_FACTOR, 2),
            'storage_per_month_gb' => round($bytesPerDay * 30 / $gb, 1),
            'storage_steady_gb' => round($bytesPerDay * max(1, $in['retention_days']) / $gb, 1),
        ];
    }

    /**
     * The warnings of 13.5.
     *
     * @param array{devices:int,max_devices:int,collect_interval_s:int,retention_days:int,queue_age_s:int,shed_level:int,redis_available:?bool,projected_storage_gb:float,storage_budget_gb:?float,ingest_mode:string,dead_letter:int} $f
     * @return list<array{code:string,message:string}>
     */
    public static function warnings(array $f): array
    {
        $w = [];
        if ($f['max_devices'] > 0 && $f['devices'] >= (int) ceil($f['max_devices'] * 0.8)) {
            $w[] = ['code' => 'device_limit', 'message' => sprintf('%d of %d devices: the device limit is almost reached.', $f['devices'], $f['max_devices'])];
        }
        if ($f['collect_interval_s'] < 60 && $f['devices'] > 200) {
            $w[] = ['code' => 'collect_interval', 'message' => 'Metrics are collected more often than once a minute with more than 200 devices; storage and write load grow quickly.'];
        }
        if ($f['queue_age_s'] > 300) {
            $w[] = ['code' => 'queue_age', 'message' => sprintf('The oldest queued ingest job is %d minutes old; the worker is not keeping up.', intdiv($f['queue_age_s'], 60))];
        }
        if ($f['dead_letter'] > 0) {
            $w[] = ['code' => 'dead_letter', 'message' => sprintf('%d ingest job(s) failed all their attempts and need a look (Administration > Jobs).', $f['dead_letter'])];
        }
        if ($f['shed_level'] > 0) {
            $w[] = ['code' => 'shedding', 'message' => sprintf('Load shedding is active (level %d).', $f['shed_level'])];
        }
        if ($f['devices'] > 1000 && $f['redis_available'] === false) {
            $w[] = ['code' => 'redis', 'message' => 'Redis is recommended above 1,000 devices (shared rate limits and locks).'];
        }
        if ($f['retention_days'] > 14 && $f['devices'] > 500) {
            $w[] = ['code' => 'retention', 'message' => 'Raw retention above 14 days with more than 500 devices makes the sample tables large; consider 7 days.'];
        }
        if ($f['storage_budget_gb'] !== null && $f['storage_budget_gb'] > 0 && $f['projected_storage_gb'] > $f['storage_budget_gb']) {
            $w[] = ['code' => 'storage', 'message' => sprintf('Projected raw sample storage %.1f GB exceeds the budget of %.1f GB.', $f['projected_storage_gb'], $f['storage_budget_gb'])];
        }
        if ($f['devices'] > 1000 && $f['ingest_mode'] !== 'queued') {
            $w[] = ['code' => 'queued_ingest', 'message' => 'Queued ingest is recommended above 1,000 devices or on slow disks.'];
        }

        return $w;
    }

    /**
     * The "Reduce load" presets, each with the patch to pass to {@see RmmSettings::update()} and the projection before and after.
     * A preset that would change nothing is returned with `applicable = false`.
     *
     * @param array<string,mixed> $cfg the settings row
     * @param array<string,bool> $features effective sub-switches
     * @param array<string,int> $limits effective limits
     * @return list<array{id:string,label:string,applicable:bool,patch:array<string,mixed>,before:array<string,mixed>,after:array<string,mixed>}>
     */
    public static function presets(array $cfg, array $features, array $limits, int $devices): array
    {
        $in = ['devices' => $devices, 'check_in_interval_s' => (int) $cfg['check_in_interval_s'], 'collect_interval_s' => (int) $cfg['collect_interval_s'],
            'metrics' => $features['metrics'], 'retention_days' => (int) $cfg['retention_days']];
        $withoutMetrics = $features;
        $withoutMetrics['metrics'] = false;
        $cap = (int) max(1, round(4 * $devices * 60 / max(1, $in['check_in_interval_s'])));
        $defs = [
            ['lengthen_check_in', 'Lengthen check-in to 600 s', ['check_in_interval_s' => 600], $in['check_in_interval_s'] < 600, ['check_in_interval_s' => 600]],
            ['collect_300', 'Collect every 300 s', ['collect_interval_s' => 300], $in['collect_interval_s'] < 300, ['collect_interval_s' => 300]],
            ['metrics_off', 'Metrics off (monitoring only)', ['features_json' => (string) json_encode($withoutMetrics)], $features['metrics'], ['metrics' => false]],
            ['retention_7', 'Raw retention 7 days', ['retention_days' => 7], $in['retention_days'] > 7, ['retention_days' => 7]],
            ['queued_ingest', 'Enable queued ingest', ['ingest_mode' => 'queued'], ($cfg['ingest_mode'] ?? 'sync') !== 'queued', []],
            ['cap_checkins', 'Cap check-ins per minute at 4x the steady rate', ['limits_json' => (string) json_encode(['max_checkins_per_min' => $cap] + array_diff_key(self::storedLimits($cfg), ['max_checkins_per_min' => 0]))], $limits['max_checkins_per_min'] === 0, []],
        ];
        $out = [];
        foreach ($defs as [$id, $label, $patch, $applicable, $projectionDelta]) {
            $after = array_merge($in, $projectionDelta);
            $out[] = ['id' => $id, 'label' => $label, 'applicable' => $applicable, 'patch' => $patch, 'before' => self::project($in), 'after' => self::project($after)];
        }

        return $out;
    }

    /**
     * The three named profiles of the docs, as patches for {@see RmmSettings::update()}. Defaults reproduce an existing install; Recommended is
     * what a new install gets; Light is monitoring only.
     *
     * @return array<string,array{label:string,patch:array<string,mixed>}>
     */
    public static function profiles(): array
    {
        return [
            'defaults' => ['label' => 'Defaults (existing installs)', 'patch' => ['check_in_interval_s' => 300, 'collect_interval_s' => 60, 'retention_days' => 30, 'features_json' => null]],
            'recommended' => ['label' => 'Recommended (new installs)', 'patch' => ['check_in_interval_s' => 300, 'collect_interval_s' => 300, 'retention_days' => 7,
                'features_json' => (string) json_encode(['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'remote' => false])]],
            'light' => ['label' => 'Light (monitoring only)', 'patch' => ['check_in_interval_s' => 600, 'collect_interval_s' => 600, 'retention_days' => 7,
                'features_json' => (string) json_encode(['monitoring' => true, 'updates' => true])]],
        ];
    }

    /**
     * @param array<string,mixed> $cfg
     * @return array<string,int>
     */
    private static function storedLimits(array $cfg): array
    {
        $raw = $cfg['limits_json'] ?? null;
        $d = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $out = [];
        if (is_array($d)) {
            foreach ($d as $k => $v) {
                if (is_string($k) && is_int($v)) {
                    $out[$k] = $v;
                }
            }
        }

        return $out;
    }
}
