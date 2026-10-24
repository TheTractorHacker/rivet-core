<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Patch;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * The read side of patch management: what one device is missing, its history, and the compliance figures of a fleet. Pure reads, no authorization (the
 * caller, {@see PatchActions} or an edition page, has already decided which devices and clients the viewer may see and passes them in).
 *
 * @api
 */
final class PatchReader
{
    /** Devices whose effective ring is looked up for the ring distribution; beyond this the distribution is marked approximate. */
    public const RING_SAMPLE = 1000;

    private readonly \RivetCore\Rmm\Store\PatchHistoryStore $patchHistory;

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly PatchPolicyStore $policies,
        private readonly ?RmmTenancyInterface $tenancy = null,
        ?\RivetCore\Rmm\Store\PatchHistoryStore $history = null,
    ) {
        $this->patchHistory = $history ?? new \RivetCore\Rmm\Store\MariaDb\MariaDbPatchHistoryStore($sql);
    }

    // ------------------------------------------------------------------ one device

    /**
     * The summary of one device: the last scan, counts by status and kind, the overdue figure and the effective patch policy.
     *
     * @param array<string,mixed> $dev the device row
     * @return array<string,mixed>
     */
    public function patchSummary(array $dev): array
    {
        $deviceId = (int) $dev['device_id'];
        $limits = $this->settings->limits();
        $scan = $this->sql->one('SELECT * FROM rmm_patch_scans WHERE device_id = ?', [$deviceId]);
        $status = array_fill_keys(PatchProtocol::STATUSES, 0);
        foreach ($this->sql->all('SELECT status, COUNT(*) AS c FROM rmm_patch_available WHERE device_id = ? GROUP BY status', [$deviceId]) as $r) {
            $status[(string) $r['status']] = (int) $r['c'];
        }
        $kinds = array_fill_keys(PatchProtocol::KINDS, 0);
        foreach ($this->sql->all("SELECT kind, COUNT(*) AS c FROM rmm_patch_available WHERE device_id = ? AND status <> 'installed' GROUP BY kind", [$deviceId]) as $r) {
            $kinds[(string) $r['kind']] = (int) $r['c'];
        }
        $days = (int) $limits['patch_overdue_days'];
        $overdue = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_patch_available WHERE device_id = ? AND kind IN ('critical','security') AND status IN ('available','approved','failed') AND first_seen_at <= ?",
            [$deviceId, $this->sql->utcAt(-$days * 86400)]);
        $eff = $this->policies->effectiveFor($dev);
        $scanned = $scan !== null && $scan['scanned_at'] !== null;

        return [
            'enabled' => $this->settings->featureOn(PatchProtocol::FEATURE),
            'scan' => $scan === null ? ['state' => 'never', 'scanned_at' => null] : [
                'state' => (string) $scan['scan_state'], 'scanned_at' => Sql::iso($scan['scanned_at'] === null ? null : (string) $scan['scanned_at']), 'requested_at' => Sql::iso($scan['requested_at'] === null ? null : (string) $scan['requested_at']),
                'manager' => (string) $scan['manager'], 'wu_state' => (string) $scan['wu_state'], 'reboot_pending' => (int) $scan['reboot_pending'] === 1,
                'last_install_at' => Sql::iso($scan['last_install_at'] === null ? null : (string) $scan['last_install_at']), 'truncated' => (int) $scan['truncated'] === 1, 'error' => (string) $scan['error'],
            ],
            'counts' => ['by_status' => $status, 'missing_by_kind' => $kinds, 'missing' => array_sum($kinds), 'reboot_required' => $scan === null ? 0 : (int) $scan['reboot_required_count']],
            'overdue' => ['days' => $days, 'critical_or_security' => $overdue],
            'compliant' => $scanned && $overdue === 0,
            'policy' => ['ring' => $eff['ring'], 'ring_delay_days' => $eff['ring_delay_days'], 'approval_mode' => $eff['approval_mode'], 'auto_days' => $eff['auto_days'], 'install_enabled' => $eff['install_enabled'],
                'window' => $eff['window'], 'reboot_policy' => $eff['reboot_policy'], 'max_minutes' => $eff['max_minutes'], 'two_person' => $eff['two_person'], 'sources' => $eff['sources'],
                'live_install' => (int) $limits['patch_live_install'] === 1],
        ];
    }

    /**
     * @param array{status?:string,kind?:string,q?:string} $f
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function deviceUpdates(int $deviceId, array $f = [], int $limit = 100, int $offset = 0): array
    {
        $where = ['device_id = ?'];
        $p = [$deviceId];
        if (isset($f['status']) && in_array($f['status'], PatchProtocol::STATUSES, true)) {
            $where[] = 'status = ?';
            $p[] = $f['status'];
        } elseif (($f['status'] ?? '') === 'missing') {
            $where[] = "status <> 'installed'";
        }
        if (isset($f['kind']) && in_array($f['kind'], PatchProtocol::KINDS, true)) {
            $where[] = 'kind = ?';
            $p[] = $f['kind'];
        }
        if (isset($f['q']) && $f['q'] !== '') {
            $where[] = "(title LIKE ? ESCAPE '!' OR update_id LIKE ? ESCAPE '!' OR kb LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            array_push($p, $like, $like, $like);
        }
        $w = implode(' AND ', $where);
        $limit = max(1, min(500, $limit));
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM rmm_patch_available WHERE $w", $p);
        $rows = $this->sql->all("SELECT * FROM rmm_patch_available WHERE $w ORDER BY FIELD(kind, 'critical', 'security', 'important', 'optional', 'driver', 'other'), first_seen_at, id LIMIT $limit OFFSET " . max(0, $offset), $p);

        return ['items' => array_map(self::updateRow(...), $rows), 'total' => $total];
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function updateRow(array $r): array
    {
        return ['update_id' => (string) $r['update_id'], 'kb' => (string) $r['kb'], 'title' => (string) $r['title'], 'kind' => (string) $r['kind'], 'severity' => (string) $r['severity'],
            'size_bytes' => (int) $r['size_bytes'], 'reboot_required' => (int) $r['reboot_required'] === 1, 'released_at' => Sql::iso($r['released_at'] === null ? null : (string) $r['released_at']),
            'eula_accepted' => (int) $r['eula_accepted'] === 1, 'version' => (string) $r['version'], 'current_version' => (string) $r['current_version'], 'status' => (string) $r['status'],
            'status_source' => (string) $r['status_source'], 'status_by' => $r['status_by'] === null ? null : (int) $r['status_by'], 'status_at' => Sql::iso($r['status_at'] === null ? null : (string) $r['status_at']),
            'decline_reason' => (string) $r['decline_reason'], 'first_seen_at' => Sql::iso((string) $r['first_seen_at']), 'last_seen_at' => Sql::iso((string) $r['last_seen_at']),
            'fail_count' => (int) $r['fail_count'], 'last_attempt_at' => Sql::iso($r['last_attempt_at'] === null ? null : (string) $r['last_attempt_at']), 'last_job_id' => $r['last_job_id'] === null ? null : (string) $r['last_job_id']];
    }

    /** @return array{items:list<array<string,mixed>>,total:int} */
    public function deviceHistory(int $deviceId, int $limit = 100, int $offset = 0): array
    {
        $page = $this->patchHistory->forDevice($deviceId, $limit, $offset);
        $rows = $page['rows'];

        return ['items' => array_map(static fn (array $r): array => ['update_id' => (string) $r['update_id'], 'title' => (string) $r['title'], 'action' => (string) $r['action'], 'result' => (string) $r['result'],
            'code' => (string) $r['code'], 'detail' => (string) $r['detail'], 'at' => Sql::iso((string) $r['at']), 'job_id' => $r['job_id'] === null ? null : (string) $r['job_id'], 'user_id' => $r['user_id'] === null ? null : (int) $r['user_id']], $rows),
            'total' => $page['total']];
    }

    // ------------------------------------------------------------------ the fleet

    /**
     * Compliance of a set of devices.
     *
     * Non-compliant: at least one critical or security update (not declined) missing for more than `$overdueDays` days. Never scanned and stale (no good scan for three
     * scan intervals) are counted apart. `group_by` is client (the default), site or group; a device in two groups counts in both.
     *
     * @param list<int>|null $visible client ids the viewer may see (null = all)
     * @param array{client_id?:?int,site_id?:?int,group_id?:?int,group_by?:string,overdue_days?:?int,limit?:int,offset?:int,os?:string} $f
     * @return array<string,mixed>
     */
    public function patchCompliance(?array $visible, array $f = []): array
    {
        $limits = $this->settings->limits();
        $overdueDays = isset($f['overdue_days']) && $f['overdue_days'] >= 0 ? min(365, (int) $f['overdue_days']) : (int) $limits['patch_overdue_days'];
        $staleBefore = $this->sql->utcAt(-3 * (int) $limits['patch_scan_interval_s']);
        $cut = $this->sql->utcAt(-$overdueDays * 86400);
        $by = in_array($f['group_by'] ?? 'client', ['client', 'site', 'group'], true) ? ($f['group_by'] ?? 'client') : 'client';

        [$where, $p] = $this->scope($visible, $f);
        if ($where === null) {
            return $this->emptyCompliance($overdueDays, $by);
        }
        $scopeA = $visible === null ? '' : ' WHERE client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
        $pa = $visible === null ? [] : $visible;
        $base = "FROM endpoint_agent_devices d
            LEFT JOIN rmm_patch_scans s ON s.device_id = d.device_id
            LEFT JOIN (SELECT device_id,
                    SUM(kind IN ('critical','security') AND status IN ('available','approved','failed') AND first_seen_at <= ?) AS overdue,
                    SUM(kind = 'critical' AND status IN ('available','approved','failed') AND first_seen_at <= ?) AS overdue_critical,
                    SUM(status = 'failed') AS failed,
                    SUM(status <> 'installed') AS missing
                FROM rmm_patch_available$scopeA GROUP BY device_id) a ON a.device_id = d.device_id";
        $baseParams = [$cut, $cut, ...$pa];
        $cases = "SUM(s.scanned_at IS NULL) AS never_scanned,
            SUM(s.scanned_at IS NOT NULL AND s.scanned_at <= ?) AS stale,
            SUM(COALESCE(a.overdue, 0) > 0) AS noncompliant,
            SUM(s.reboot_pending = 1) AS reboot_pending,
            SUM(COALESCE(a.failed, 0) > 0) AS failed_installs,
            SUM(COALESCE(a.overdue_critical, 0) > 0) AS missing_critical,
            SUM(COALESCE(a.missing, 0)) AS missing_total";
        $keyExpr = match ($by) {
            'site' => 'd.location_id',
            'group' => 'gm.group_id',
            default => 'd.client_id',
        };
        $join = $by === 'group' ? ' JOIN (SELECT group_id, device_id FROM rmm_group_devices UNION SELECT gt.group_id, dt.device_id FROM rmm_group_tags gt JOIN rmm_device_tags dt ON dt.tag_id = gt.tag_id) gm ON gm.device_id = d.device_id' : '';
        $groups = [];
        foreach ($this->sql->all("SELECT $keyExpr AS k, COUNT(DISTINCT d.device_id) AS devices, $cases $base$join WHERE $where GROUP BY $keyExpr ORDER BY noncompliant DESC, devices DESC, k LIMIT 500",
            [$staleBefore, ...$baseParams, ...$p]) as $r) {
            $key = (int) $r['k'];
            $groups[] = ['key' => $key, 'name' => $by === 'client' ? ($this->tenancy?->clientName($key) ?? ('Client ' . $key)) : null, 'devices' => (int) $r['devices'], 'never_scanned' => (int) $r['never_scanned'],
                'stale' => (int) $r['stale'], 'noncompliant' => (int) $r['noncompliant'], 'compliant' => max(0, (int) $r['devices'] - (int) $r['noncompliant'] - (int) $r['never_scanned']),
                'reboot_pending' => (int) $r['reboot_pending'], 'failed_installs' => (int) $r['failed_installs'], 'missing_critical' => (int) $r['missing_critical'], 'missing_total' => (int) $r['missing_total']];
        }
        $t = $this->sql->one("SELECT COUNT(*) AS devices, $cases $base WHERE $where", [$staleBefore, ...$baseParams, ...$p]) ?? [];
        $summary = ['devices' => (int) ($t['devices'] ?? 0), 'never_scanned' => (int) ($t['never_scanned'] ?? 0), 'stale' => (int) ($t['stale'] ?? 0), 'noncompliant' => (int) ($t['noncompliant'] ?? 0),
            'reboot_pending' => (int) ($t['reboot_pending'] ?? 0), 'failed_installs' => (int) ($t['failed_installs'] ?? 0), 'missing_critical' => (int) ($t['missing_critical'] ?? 0), 'missing_total' => (int) ($t['missing_total'] ?? 0)];
        $summary['compliant'] = max(0, $summary['devices'] - $summary['noncompliant'] - $summary['never_scanned']);
        $summary['compliance_pct'] = $summary['devices'] > 0 ? round(100 * $summary['compliant'] / $summary['devices'], 1) : null;

        $limit = max(1, min(500, (int) ($f['limit'] ?? 100)));
        $offset = max(0, (int) ($f['offset'] ?? 0));
        $devices = [];
        foreach ($this->sql->all("SELECT d.device_id, d.client_id, d.location_id, d.hostname, d.os, s.scanned_at, s.reboot_pending, COALESCE(a.overdue, 0) AS overdue, COALESCE(a.failed, 0) AS failed, COALESCE(a.missing, 0) AS missing
            $base WHERE $where AND (COALESCE(a.overdue, 0) > 0 OR COALESCE(a.failed, 0) > 0) ORDER BY a.overdue DESC, d.hostname, d.device_id LIMIT $limit OFFSET $offset", [...$baseParams, ...$p]) as $r) {
            $devices[] = ['device_id' => (int) $r['device_id'], 'client_id' => (int) $r['client_id'], 'site_id' => (int) $r['location_id'], 'hostname' => (string) $r['hostname'], 'os' => (string) $r['os'],
                'scanned_at' => Sql::iso($r['scanned_at'] === null ? null : (string) $r['scanned_at']), 'overdue' => (int) $r['overdue'], 'failed' => (int) $r['failed'], 'missing' => (int) $r['missing'], 'reboot_pending' => (int) $r['reboot_pending'] === 1];
        }

        return ['overdue_days' => $overdueDays, 'group_by' => $by, 'summary' => $summary, 'groups' => $groups, 'devices' => $devices, 'rings' => $this->ringDistribution($where, $p)];
    }

    /**
     * @param list<int>|null $visible
     * @param array<string,mixed> $f
     * @return array{0:?string,1:list<mixed>} where clause on the alias d (null: nothing can match)
     */
    private function scope(?array $visible, array $f): array
    {
        $w = ['d.revoked_at IS NULL', 'd.retired_at IS NULL'];
        $p = [];
        if ($visible !== null) {
            if ($visible === []) {
                return [null, []];
            }
            $w[] = 'd.client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($p, ...$visible);
        }
        if (!empty($f['client_id'])) {
            $w[] = 'd.client_id = ?';
            $p[] = (int) $f['client_id'];
        }
        if (!empty($f['site_id'])) {
            $w[] = 'd.location_id = ?';
            $p[] = (int) $f['site_id'];
        }
        if (!empty($f['group_id'])) {
            $w[] = 'd.device_id IN (SELECT device_id FROM rmm_group_devices WHERE group_id = ? UNION SELECT dt.device_id FROM rmm_group_tags gt JOIN rmm_device_tags dt ON dt.tag_id = gt.tag_id WHERE gt.group_id = ?)';
            array_push($p, (int) $f['group_id'], (int) $f['group_id']);
        }
        if (!empty($f['os']) && in_array($f['os'], ['windows', 'linux'], true)) {
            $w[] = 'd.os = ?';
            $p[] = $f['os'];
        }

        return [implode(' AND ', $w), $p];
    }

    /**
     * The ring each scanned device ends up in. Exact up to {@see RING_SAMPLE} devices, a sample beyond.
     *
     * @param list<mixed> $p
     * @return array{counts:array<string,int>,exact:bool}
     */
    private function ringDistribution(string $where, array $p): array
    {
        $rows = $this->sql->all("SELECT d.* FROM endpoint_agent_devices d JOIN rmm_patch_scans s ON s.device_id = d.device_id WHERE $where ORDER BY d.device_id LIMIT " . (self::RING_SAMPLE + 1), $p);
        $exact = count($rows) <= self::RING_SAMPLE;
        $out = [];
        foreach (array_slice($rows, 0, self::RING_SAMPLE) as $d) {
            $ring = $this->policies->effectiveFor($d)['ring'] ?? '(none)';
            $out[$ring] = ($out[$ring] ?? 0) + 1;
        }
        arsort($out);

        return ['counts' => $out, 'exact' => $exact];
    }

    /** @return array<string,mixed> */
    private function emptyCompliance(int $days, string $by): array
    {
        return ['overdue_days' => $days, 'group_by' => $by, 'summary' => ['devices' => 0, 'never_scanned' => 0, 'stale' => 0, 'noncompliant' => 0, 'reboot_pending' => 0, 'failed_installs' => 0,
            'missing_critical' => 0, 'missing_total' => 0, 'compliant' => 0, 'compliance_pct' => null], 'groups' => [], 'devices' => [], 'rings' => ['counts' => [], 'exact' => true]];
    }

    /**
     * Fleet counts of updates by kind and status, optionally of one update id across devices (for the approvals page).
     *
     * @param list<int>|null $visible
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function fleetUpdates(?array $visible, ?int $clientId, string $status = 'available', int $limit = 100, int $offset = 0): array
    {
        if ($visible !== null && $visible === []) {
            return ['items' => [], 'total' => 0];
        }
        $w = ['status = ?'];
        $p = [in_array($status, PatchProtocol::STATUSES, true) ? $status : 'available'];
        if ($visible !== null) {
            $w[] = 'client_id IN (' . implode(',', array_fill(0, count($visible), '?')) . ')';
            array_push($p, ...$visible);
        }
        if ($clientId !== null && $clientId > 0) {
            $w[] = 'client_id = ?';
            $p[] = $clientId;
        }
        $ws = implode(' AND ', $w);
        $total = (int) $this->sql->val("SELECT COUNT(*) FROM (SELECT 1 FROM rmm_patch_available WHERE $ws GROUP BY update_id) t", $p);
        $limit = max(1, min(500, $limit));
        $rows = $this->sql->all("SELECT update_id, MIN(title) AS title, MIN(kb) AS kb, MIN(kind) AS kind, MIN(severity) AS severity, COUNT(*) AS devices, MIN(first_seen_at) AS first_seen_at, MAX(reboot_required) AS reboot_required
            FROM rmm_patch_available WHERE $ws GROUP BY update_id ORDER BY FIELD(MIN(kind), 'critical', 'security', 'important', 'optional', 'driver', 'other'), devices DESC, update_id LIMIT $limit OFFSET " . max(0, $offset), $p);

        return ['items' => array_map(static fn (array $r): array => ['update_id' => (string) $r['update_id'], 'title' => (string) $r['title'], 'kb' => (string) $r['kb'], 'kind' => (string) $r['kind'], 'severity' => (string) $r['severity'],
            'devices' => (int) $r['devices'], 'first_seen_at' => Sql::iso((string) $r['first_seen_at']), 'reboot_required' => (int) $r['reboot_required'] === 1], $rows), 'total' => $total];
    }
}
