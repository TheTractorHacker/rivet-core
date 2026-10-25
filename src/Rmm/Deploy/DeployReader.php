<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Reads of the deployment state: one device, and the fleet report "deployed vs. expected" by package, client, ring or device. Reads only; the caller has authorized
 * the device or passes the clients the viewer may see ({@see \RivetCore\Rmm\Deploy\DeployActions}). A client restriction is part of every query.
 *
 * THE STATE of one expected package on one device, worst first: `failed` (the last attempt failed and it is not fixed), `pending` (a job is waiting or running),
 * `scheduled` (not due yet: the ring's delay has not passed), `unknown` (never detected), `missing` (an install is expected and it is not there), `present` (a removal is
 * expected and it is still there), `compliant` (it is as wanted). Only expectations count; a package nobody assigned to a device is not in the report.
 *
 * @api
 */
final class DeployReader
{
    public const GROUPS = ['package', 'client', 'ring', 'device'];
    public const STATES = DeployProtocol::STATES;

    private readonly \RivetCore\Rmm\Store\DeploymentHistoryStore $deployHistory;

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings, ?\RivetCore\Rmm\Store\DeploymentHistoryStore $history = null)
    {
        $this->deployHistory = $history ?? new \RivetCore\Rmm\Store\MariaDb\MariaDbDeploymentHistoryStore($sql);
    }

    /** SQL for the state, over the aliases i (rmm_package_installs) and j (the waiting job, may be NULL). */
    private function stateSql(): string
    {
        $failed = "'" . implode("','", DeployProtocol::FAILED_RESULTS) . "'";

        return "CASE
            WHEN i.expected_action = 'install' AND i.satisfied = 1 THEN 'compliant'
            WHEN i.expected_action = 'uninstall' AND i.installed = 0 THEN 'compliant'
            WHEN j.job_id IS NOT NULL THEN 'pending'
            WHEN i.fail_count > 0 AND i.last_result IN ($failed) THEN 'failed'
            WHEN i.expected_since > '" . $this->sql->utcNow() . "' THEN 'scheduled'
            WHEN i.detected_at IS NULL THEN 'unknown'
            WHEN i.expected_action = 'install' THEN 'missing'
            ELSE 'present' END";
    }

    private const FROM = "FROM rmm_package_installs i JOIN rmm_packages p ON p.package_id = i.package_id
        LEFT JOIN endpoint_agent_jobs j ON j.job_id = i.last_job_id AND j.state IN ('queued','running')";

    /**
     * What one device should have and has.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed>
     */
    public function deviceStatus(array $dev): array
    {
        $rows = $this->sql->all('SELECT i.*, p.name, v.version AS expected_version, v.fmt, ' . $this->stateSql() . ' AS state ' . self::FROM . '
            LEFT JOIN rmm_package_versions v ON v.version_id = i.expected_version_id
            WHERE i.device_id = ? AND (i.expected_action IS NOT NULL OR i.last_result <> \'\') ORDER BY p.name', [(int) $dev['device_id']]);
        $items = [];
        $counts = array_fill_keys(self::STATES, 0);
        foreach ($rows as $r) {
            $expected = $r['expected_action'] !== null;
            if ($expected) {
                ++$counts[(string) $r['state']];
            }
            $items[] = ['package_id' => (int) $r['package_id'], 'package' => (string) $r['name'], 'expected' => $expected ? (string) $r['expected_action'] : null, 'expected_version' => $r['expected_version'],
                'format' => $r['fmt'], 'state' => $expected ? (string) $r['state'] : null, 'installed' => $r['installed'] === null ? null : (int) $r['installed'] === 1, 'installed_version' => (string) $r['installed_version'],
                'ring' => (string) $r['ring'], 'expected_since' => Sql::iso($r['expected_since']), 'detected_at' => Sql::iso($r['detected_at']), 'last_action' => (string) $r['last_action'],
                'last_result' => (string) $r['last_result'], 'last_code' => (string) $r['last_code'], 'last_detail' => (string) $r['last_detail'], 'last_attempt_at' => Sql::iso($r['last_attempt_at']),
                'attempts' => (int) $r['attempts'], 'fail_count' => (int) $r['fail_count']];
        }

        return ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE), 'counts' => $counts, 'packages' => $items];
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function history(int $deviceId, int $limit, int $offset): array
    {
        $page = $this->deployHistory->forDevice($deviceId, $limit, $offset);
        $rows = $page['rows'];
        $names = [];   // the catalog is control data: the store does not join it
        $ids = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['package_id'], $rows)));
        if ($ids !== []) {
            foreach ($this->sql->all('SELECT package_id, name FROM rmm_packages WHERE package_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) as $n) {
                $names[(int) $n['package_id']] = (string) $n['name'];
            }
        }
        $rows = array_map(static fn (array $r): array => $r + ['name' => $names[(int) $r['package_id']] ?? null], $rows);

        return ['rows' => array_map(static fn (array $r): array => ['package_id' => (int) $r['package_id'], 'package' => (string) ($r['name'] ?? ''), 'action' => (string) $r['action'], 'mode' => (string) $r['mode'],
            'result' => (string) $r['result'], 'code' => (string) $r['code'], 'detail' => (string) $r['detail'], 'version' => (string) $r['version'], 'at' => Sql::iso($r['at']), 'job_id' => $r['job_id'], 'user_id' => $r['user_id'] === null ? null : (int) $r['user_id']], $rows),
            'total' => $page['total']];
    }

    /**
     * Deployed versus expected.
     *
     * @param list<int>|null $visible client ids the viewer may see; null = all
     * @param array<string,mixed> $filters {client_id?, package_id?, ring?, state?, group_by? (package|client|ring|device), limit?, offset?}
     * @return array<string,mixed>
     */
    public function report(?array $visible, array $filters = []): array
    {
        $by = (string) ($filters['group_by'] ?? 'package');
        if (!in_array($by, self::GROUPS, true)) {
            $by = 'package';
        }
        [$where, $params] = ['i.expected_action IS NOT NULL', []];
        if ($visible !== null) {
            if ($visible === []) {
                return $this->empty($by);
            }
            $where .= ' AND i.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($params, ...$visible);
        }
        foreach (['client_id' => 'i.client_id', 'package_id' => 'i.package_id'] as $k => $col) {
            if (isset($filters[$k]) && is_int($filters[$k]) && $filters[$k] > 0) {
                $where .= " AND $col = ?";
                $params[] = $filters[$k];
            }
        }
        if (isset($filters['ring']) && is_string($filters['ring'])) {
            $where .= ' AND i.ring = ?';
            $params[] = $filters['ring'];
        }
        $limit = max(1, min(1000, (int) ($filters['limit'] ?? 200)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $state = $this->stateSql();
        if ($by === 'device') {
            $stateFilter = isset($filters['state']) && is_string($filters['state']) && in_array($filters['state'], self::STATES, true) ? $filters['state'] : null;
            $rows = $this->sql->all("SELECT * FROM (SELECT d.device_id, d.hostname, d.client_id, p.package_id, p.name AS package, i.ring, i.expected_action, i.installed_version, i.last_result, v.version AS expected_version, $state AS state "
                . self::FROM . ' JOIN endpoint_agent_devices d ON d.device_id = i.device_id LEFT JOIN rmm_package_versions v ON v.version_id = i.expected_version_id WHERE ' . $where . ') t'
                . ($stateFilter === null ? '' : ' WHERE t.state = ?') . " ORDER BY FIELD(t.state, 'failed','pending','missing','present','unknown','scheduled','compliant'), t.hostname, t.package LIMIT $limit OFFSET $offset",
                $stateFilter === null ? $params : [...$params, $stateFilter]);

            return ['group_by' => 'device', 'rows' => array_map(static fn (array $r): array => ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'], 'client_id' => (int) $r['client_id'],
                'package_id' => (int) $r['package_id'], 'package' => (string) $r['package'], 'ring' => (string) $r['ring'], 'expected' => (string) $r['expected_action'], 'expected_version' => $r['expected_version'],
                'installed_version' => (string) $r['installed_version'], 'state' => (string) $r['state'], 'last_result' => (string) $r['last_result']], $rows), 'limit' => $limit, 'offset' => $offset];
        }
        [$keyCol, $labelCol, $join] = match ($by) {
            'client' => ['i.client_id', 'CAST(i.client_id AS CHAR)', ''],
            'ring' => ['i.ring', 'i.ring', ''],
            default => ['p.package_id', 'p.name', ''],
        };
        $rows = $this->sql->all("SELECT t.k, t.label, COUNT(*) AS expected, " . implode(', ', array_map(static fn (string $s): string => "SUM(t.state = '$s') AS s_$s", self::STATES)) . '
            FROM (SELECT ' . $keyCol . ' AS k, ' . $labelCol . ' AS label, ' . $state . ' AS state ' . self::FROM . ' ' . $join . ' WHERE ' . $where . ') t
            GROUP BY t.k, t.label ORDER BY t.label LIMIT ' . $limit . ' OFFSET ' . $offset, $params);
        $out = [];
        $sum = array_fill_keys(self::STATES, 0);
        $sum['expected'] = 0;
        foreach ($rows as $r) {
            $row = ['key' => $by === 'client' || $by === 'package' ? (int) $r['k'] : (string) $r['k'], 'label' => (string) ($by === 'ring' && $r['label'] === '' ? '(no ring)' : $r['label']), 'expected' => (int) $r['expected']];
            foreach (self::STATES as $s) {
                $row[$s] = (int) $r['s_' . $s];
                $sum[$s] += $row[$s];
            }
            $row['percent_compliant'] = $row['expected'] === 0 ? null : (int) round(100 * $row['compliant'] / $row['expected']);
            $sum['expected'] += $row['expected'];
            $out[] = $row;
        }
        $sum['percent_compliant'] = $sum['expected'] === 0 ? null : (int) round(100 * $sum['compliant'] / $sum['expected']);

        return ['group_by' => $by, 'rows' => $out, 'totals' => $sum, 'limit' => $limit, 'offset' => $offset];
    }

    /** @return array<string,mixed> */
    private function empty(string $by): array
    {
        $sum = array_fill_keys(self::STATES, 0);
        $sum['expected'] = 0;
        $sum['percent_compliant'] = null;

        return ['group_by' => $by, 'rows' => [], 'totals' => $by === 'device' ? null : $sum, 'limit' => 0, 'offset' => 0];
    }

    /**
     * A report as CSV (RFC 4180, CRLF). A cell that starts with = + - @ tab or CR gets a leading apostrophe so a spreadsheet never runs it as a formula: host names,
     * package names and results come from devices and administrators, not from this code.
     *
     * @param array<string,mixed> $report the result of {@see report()}
     */
    public static function toCsv(array $report): string
    {
        $rows = $report['rows'] ?? [];
        if ($rows === []) {
            return '';
        }
        $cols = array_keys($rows[0]);
        $lines = [self::csvLine($cols)];
        foreach ($rows as $r) {
            $lines[] = self::csvLine(array_map(static fn (string $c): mixed => $r[$c] ?? '', $cols));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param list<mixed> $cells
     */
    private static function csvLine(array $cells): string
    {
        return implode(',', array_map(static function (mixed $v): string {
            $s = $v === null ? '' : (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
            if ($s !== '' && strpbrk($s[0], "=+-@\t\r") !== false && preg_match('/^-?\d+(\.\d+)?$/D', $s) !== 1) {
                $s = "'" . $s;
            }

            return preg_match('/[",\r\n]/', $s) === 1 ? '"' . str_replace('"', '""', $s) . '"' : $s;
        }, $cells));
    }
}
