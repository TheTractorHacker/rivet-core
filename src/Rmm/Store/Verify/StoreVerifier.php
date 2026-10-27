<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Verify;

use RivetCore\Contracts\ClockInterface;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\RmmDataStoreKind;

/**
 * Reconciliation of the control database with the HOT RMM store after a restore (or any time you doubt them), #89 phase 3. The two are separate databases when the
 * store is PostgreSQL, restored from separate dumps taken at slightly different moments (docs/rmm/POSTGRES.md, "Backups and restore"). This READS both and reports:
 *
 *  - `orphan_rows`: hot rows (events, histories, ledgers) of a device id the control database does not know (an older control database than the hot store);
 *  - `ledger_drift`: a per-device ledger (`endpoint_agent_log_state`, `endpoint_agent_change_state`) whose row_count differs from the rows really stored;
 *  - `events_without_ledger`: a device with stored events and no ledger row;
 *  - `rollup_*`: the daily-rollup watermark that contradicts the tiers (no watermark but daily rows, daily rows at or after the watermark, hourly days before the
 *    watermark without a daily row);
 *  - `hot_older_than_control`: the newest check history / metric time in the hot store is older than the control database's newest check-in (a hot store restored
 *    from an older dump): a gap of that many seconds of history;
 *  - `hot_store_unreachable`: the hot store could not be read at all.
 *
 * Read-only: it never writes to either database. It runs for the store the control database names (mariadb: the same database; postgres: through the connection).
 *
 * @api
 */
final class StoreVerifier
{
    /** Hot tables that carry a device id: table => time column (null = none worth comparing). */
    private const DEVICE_TABLES = [
        'endpoint_agent_log_events' => 'received_at',
        'endpoint_agent_log_state' => null,
        'endpoint_agent_change_records' => 'received_at',
        'endpoint_agent_change_state' => null,
        'endpoint_agent_check_history' => 'reported_at',
        'rmm_software_history' => 'occurred_at',
        'rmm_patch_history' => 'at',
        'rmm_package_history' => 'at',
        'rmm_hypervisor_vm_history' => 'at',
    ];

    /** ledger table => [events table, the ledger's own name for the count column] */
    private const LEDGERS = [
        'endpoint_agent_log_state' => 'endpoint_agent_log_events',
        'endpoint_agent_change_state' => 'endpoint_agent_change_records',
    ];

    private const ROLLUP = 'metric_daily_rollup';

    public function __construct(
        private readonly DatabaseInterface $control,
        private readonly ClockInterface $clock,
        private readonly ?PostgresConnectionInterface $postgres = null,
        private readonly ?string $kind = null,
    ) {
    }

    /**
     * @param int $gapSeconds a hot store this much older than the control database's newest check-in is reported
     * @param int $examples how many example device ids a finding lists
     * @return array{kind:string,checked_at:string,findings:list<array<string,mixed>>,counts:array<string,int>,clean:bool}
     */
    public function run(int $gapSeconds = 3600, int $examples = 10): array
    {
        $kind = $this->kind ?? $this->storedKind();
        $findings = [];
        $counts = [];
        $pg = $kind === RmmDataStoreKind::POSTGRES;
        if ($pg && $this->postgres === null) {
            throw new \InvalidArgumentException('The data store is "postgres" but no PostgreSQL connection was given.');
        }
        try {
            $this->check($pg, $gapSeconds, max(1, $examples), $findings, $counts);
        } catch (\Throwable $e) {
            $findings[] = ['code' => 'hot_store_unreachable', 'severity' => 'error', 'table' => '', 'count' => 1, 'detail' => 'The hot store could not be read: ' . mb_substr($e->getMessage(), 0, 300)];
        }

        return ['kind' => $kind, 'checked_at' => gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp()), 'findings' => $findings, 'counts' => $counts, 'clean' => $findings === []];
    }

    private function storedKind(): string
    {
        $v = $this->control->fetchOne('SELECT data_store FROM endpoint_agent_settings WHERE id = 1')['data_store'] ?? RmmDataStoreKind::MARIADB;

        return is_string($v) && in_array($v, RmmDataStoreKind::ALL, true) ? $v : RmmDataStoreKind::MARIADB;
    }

    /**
     * @param list<array<string,mixed>> $findings
     * @param array<string,int> $counts
     */
    private function check(bool $pg, int $gap, int $examples, array &$findings, array &$counts): void
    {
        $hot = fn (string $sql, array $p = []): array => $pg ? $this->postgres->query($sql, $p) : $this->control->fetchAll($sql, $p);
        $ts = static fn (string $expr): string => $pg ? "to_char($expr AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS')" : "DATE_FORMAT($expr, '%Y-%m-%d %H:%i:%s')";

        $known = [];
        foreach ($this->control->fetchAll('SELECT device_id FROM endpoint_agent_devices') as $r) {
            $known[(int) $r['device_id']] = true;
        }
        $counts['control_devices'] = count($known);

        // ---- per-device row counts of every device-keyed hot table
        $perDevice = [];
        foreach (self::DEVICE_TABLES as $table => $timeCol) {
            $perDevice[$table] = [];
            foreach ($hot("SELECT device_id, COUNT(*) AS n FROM $table GROUP BY device_id") as $r) {
                $perDevice[$table][(int) $r['device_id']] = (int) $r['n'];
            }
            $counts[$table] = array_sum($perDevice[$table]);
            $orphans = array_diff_key($perDevice[$table], $known);
            if ($orphans !== []) {
                $findings[] = ['code' => 'orphan_rows', 'severity' => 'warning', 'table' => $table, 'count' => count($orphans), 'rows' => array_sum($orphans),
                    'devices' => array_slice(array_keys($orphans), 0, $examples),
                    'detail' => count($orphans) . ' device id(s) in ' . $table . ' are not in endpoint_agent_devices (' . array_sum($orphans) . ' rows). The control database is older than the hot store, or the devices were deleted.'];
            }
        }

        // ---- the ledgers must equal the rows
        foreach (self::LEDGERS as $ledger => $events) {
            $ledgerRows = [];
            foreach ($hot("SELECT device_id, row_count FROM $ledger") as $r) {
                $ledgerRows[(int) $r['device_id']] = (int) $r['row_count'];
            }
            $drift = [];
            foreach ($ledgerRows as $dev => $n) {
                $actual = $perDevice[$events][$dev] ?? 0;
                if ($actual !== $n) {
                    $drift[$dev] = [$n, $actual];
                }
            }
            if ($drift !== []) {
                $findings[] = ['code' => 'ledger_drift', 'severity' => 'warning', 'table' => $ledger, 'count' => count($drift),
                    'devices' => array_slice(array_keys($drift), 0, $examples),
                    'examples' => array_map(static fn (int $d, array $v): array => ['device_id' => $d, 'ledger' => $v[0], 'rows' => $v[1]], array_slice(array_keys($drift), 0, $examples), array_slice(array_values($drift), 0, $examples)),
                    'detail' => count($drift) . ' device(s): ' . $ledger . '.row_count differs from the rows in ' . $events . '.'];
            }
            $missing = array_diff_key($perDevice[$events], $ledgerRows);
            if ($missing !== []) {
                $findings[] = ['code' => 'events_without_ledger', 'severity' => 'warning', 'table' => $events, 'count' => count($missing), 'devices' => array_slice(array_keys($missing), 0, $examples),
                    'detail' => count($missing) . ' device(s) have rows in ' . $events . ' and no row in ' . $ledger . '.'];
            }
        }

        // ---- the rollup watermark against the tiers
        $wm = $hot('SELECT ' . $ts('watermark') . ' AS wm FROM rmm_metric_rollup_state WHERE name = ?', [self::ROLLUP])[0]['wm'] ?? null;
        $daily = $hot('SELECT ' . $ts('MAX(day_start)') . ' AS mx, COUNT(*) AS n FROM rmm_metric_daily')[0] ?? [];
        $hourly = $hot('SELECT ' . $ts('MIN(hour_start)') . ' AS mn, ' . $ts('MAX(hour_start)') . ' AS mx, COUNT(*) AS n FROM rmm_metric_hourly')[0] ?? [];
        $counts['rmm_metric_daily'] = (int) ($daily['n'] ?? 0);
        $counts['rmm_metric_hourly'] = (int) ($hourly['n'] ?? 0);
        if ($wm === null && $counts['rmm_metric_daily'] > 0) {
            $findings[] = ['code' => 'rollup_no_watermark', 'severity' => 'warning', 'table' => 'rmm_metric_rollup_state', 'count' => 1,
                'detail' => 'Daily rollup rows exist but the rollup has no watermark: it would roll the same days up again.'];
        }
        if ($wm !== null && ($daily['mx'] ?? null) !== null && strcmp((string) $daily['mx'], (string) $wm) >= 0) {
            $findings[] = ['code' => 'rollup_daily_beyond_watermark', 'severity' => 'warning', 'table' => 'rmm_metric_daily', 'count' => 1,
                'detail' => 'The newest daily row (' . $daily['mx'] . ') is at or after the rollup watermark (' . $wm . '): the watermark is older than the daily tier (a restore of mismatched dumps).'];
        }
        if ($wm !== null) {
            $days = $hot('SELECT ' . $ts('h.d') . ' AS d FROM (SELECT DISTINCT ' . ($pg ? "date_trunc('day', hour_start AT TIME ZONE 'UTC')" : 'DATE(hour_start)') . ' AS d FROM rmm_metric_hourly WHERE hour_start < ?) h '
                . 'WHERE NOT EXISTS (SELECT 1 FROM rmm_metric_daily x WHERE x.day_start >= h.d AND x.day_start < h.d + ' . ($pg ? "INTERVAL '1 day'" : 'INTERVAL 1 DAY') . ') ORDER BY h.d LIMIT ' . ($examples + 1), [$wm]);
            if ($days !== []) {
                $findings[] = ['code' => 'rollup_missing_days', 'severity' => 'warning', 'table' => 'rmm_metric_daily', 'count' => count($days),
                    'days' => array_map(static fn (array $r): string => substr((string) $r['d'], 0, 10), array_slice($days, 0, $examples)),
                    'detail' => 'Hourly buckets exist before the rollup watermark (' . $wm . ') for days that have no daily row: the daily tier lost those days.'];
            }
        }

        // ---- the hot store must not be older than the control database
        $control = $this->control->fetchOne('SELECT MAX(last_checkin_at) AS m FROM endpoint_agent_devices')['m'] ?? null;
        if (is_string($control) && $control !== '') {
            $newest = [
                'endpoint_agent_check_history' => $hot('SELECT ' . $ts('MAX(reported_at)') . ' AS m FROM endpoint_agent_check_history')[0]['m'] ?? null,
                'rmm_metric_latest' => $hot('SELECT ' . $ts('MAX(sampled_at)') . ' AS m FROM rmm_metric_latest')[0]['m'] ?? null,
                'rmm_metric_hourly' => $hourly['mx'] ?? null,
            ];
            $c = strtotime($control . ' UTC');
            foreach ($newest as $table => $m) {
                if (!is_string($m) || $m === '') {
                    continue;   // an empty table is not a gap: the history ring or the metric tiers may be switched off, or the edition keeps its metrics elsewhere
                }
                $span = $c - strtotime($m . ' UTC');
                $tolerance = $table === 'rmm_metric_hourly' ? $gap + 3600 : $gap;   // a bucket is stamped with the start of its hour
                if ($span > $tolerance) {
                    $findings[] = ['code' => 'hot_older_than_control', 'severity' => 'warning', 'table' => $table, 'count' => 1, 'gap_seconds' => $span, 'control_newest' => $control, 'hot_newest' => $m,
                        'detail' => "The newest row of $table is $m, the control database saw a check-in at $control: history of about $span seconds is missing (the hot store was restored from an older dump, or writes were dropped)."];
                }
            }
        }
    }
}
