<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Patch\PatchPolicyStore;
use RivetCore\Rmm\Support\Sql;

/**
 * What the assignments say a device should have, and keeping that as one row per (device, package) in `rmm_package_installs` so the reports and the scheduler can
 * work with SQL instead of resolving every device again.
 *
 * RESOLUTION. For one device and one package the MOST SPECIFIC assignment wins (global < client < site < group < tag < device; the newest assignment inside one level).
 * An assignment counts only when it is enabled and has been activated (an assignment waiting for a second person's approval has neither). The wanted version is the one the
 * assignment names, or the newest version that is not retired and fits the device (its operating system and, on Linux, its package manager).
 *
 * RINGS. A device's ring is the ring its effective patch policy names ({@see PatchPolicyStore}). The ring's delay in days is added to the activation time of the assignment:
 * the pilot ring gets a package at once, `broad` a week later. A device without a ring waits for nothing.
 *
 * @api
 */
final class DeployExpectations
{
    private const RANK = ['global' => 1, 'client' => 2, 'site' => 3, 'group' => 4, 'tag' => 5, 'device' => 6];

    public function __construct(private readonly Sql $sql, private readonly PackageCatalog $catalog, private readonly ?PatchPolicyStore $patchPolicies = null)
    {
    }

    /**
     * The package manager a Linux device uses, as far as it is known: apt, dnf or null. From the last patch scan when there is one, else from the OS description.
     *
     * @param array<string,mixed> $dev
     */
    public function managerFor(array $dev): ?string
    {
        if (($dev['os'] ?? '') !== 'linux') {
            return null;
        }
        $m = $this->sql->val('SELECT manager FROM rmm_patch_scans WHERE device_id = ?', [(int) $dev['device_id']]);
        if (is_string($m) && $m !== '') {
            return $m === 'apt' ? 'apt' : (in_array($m, ['dnf', 'yum'], true) ? 'dnf' : null);
        }
        $d = (string) ($dev['os_version'] ?? '');
        if (preg_match('/ubuntu|debian|mint|raspbian|kali|pop!?_?os/i', $d) === 1) {
            return 'apt';
        }
        if (preg_match('/red hat|rhel|centos|rocky|alma|fedora|oracle linux|amazon linux/i', $d) === 1) {
            return 'dnf';
        }

        return null;
    }

    /**
     * Assignments that reach a device, one per package (the most specific).
     *
     * @param array<string,mixed> $dev
     * @return list<array{assignment_id:int,package_id:int,version_id:?int,action:string,activated_at:string}>
     */
    public function assignmentsFor(array $dev): array
    {
        $id = (int) $dev['device_id'];
        $loc = (int) ($dev['location_id'] ?? 0);
        $rows = $this->sql->all("SELECT a.assignment_id, a.package_id, a.version_id, a.action, a.scope_type, a.activated_at
            FROM rmm_package_assignments a JOIN rmm_packages p ON p.package_id = a.package_id AND p.enabled = 1
            WHERE a.enabled = 1 AND a.activated_at IS NOT NULL AND (a.scope_type = 'global'
               OR (a.scope_type = 'client' AND a.scope_id = ?)
               OR (a.scope_type = 'site' AND a.scope_id = ? AND ? > 0)
               OR (a.scope_type = 'device' AND a.scope_id = ?)
               OR (a.scope_type = 'tag' AND a.scope_id IN (SELECT dt.tag_id FROM rmm_device_tags dt WHERE dt.device_id = ?))
               OR (a.scope_type = 'group' AND (EXISTS (SELECT 1 FROM rmm_group_devices gd WHERE gd.group_id = a.scope_id AND gd.device_id = ?)
                    OR EXISTS (SELECT 1 FROM rmm_group_tags gt JOIN rmm_device_tags dt2 ON dt2.tag_id = gt.tag_id WHERE gt.group_id = a.scope_id AND dt2.device_id = ?))))
            ORDER BY a.assignment_id", [(int) $dev['client_id'], $loc, $loc, $id, $id, $id, $id]);
        $best = [];
        foreach ($rows as $r) {
            $p = (int) $r['package_id'];
            $rank = self::RANK[(string) $r['scope_type']] ?? 0;
            if (!isset($best[$p]) || $rank >= $best[$p]['rank']) {   // equal rank: the later (higher id) wins
                $best[$p] = ['rank' => $rank, 'assignment_id' => (int) $r['assignment_id'], 'package_id' => $p, 'version_id' => $r['version_id'] === null ? null : (int) $r['version_id'],
                    'action' => (string) $r['action'], 'activated_at' => (string) $r['activated_at']];
            }
        }
        $out = [];
        foreach ($best as $b) {
            unset($b['rank']);
            $out[] = $b;
        }

        return $out;
    }

    /**
     * The version of a package a device should have (install) or have removed (uninstall), or null when the catalog has nothing that fits.
     *
     * @param array<string,mixed> $dev
     * @return array<string,mixed>|null a catalog version row ({@see PackageCatalog::version()})
     */
    public function versionFor(array $dev, int $packageId, ?int $versionId, string $action): ?array
    {
        $os = (string) ($dev['os'] ?? '');
        if ($versionId !== null) {
            $v = $this->catalog->version($versionId);
            if ($v === null || $v['package_id'] !== $packageId || $v['os'] !== $os || ($action === 'install' && $v['retired'])) {
                return null;
            }
            $mgr = $this->managerFor($dev);
            if ($os === 'linux' && $mgr !== null && $v['fmt'] !== $mgr) {
                return null;
            }

            return $v;
        }
        $mgr = $this->managerFor($dev);
        $rows = $this->sql->all('SELECT version_id FROM rmm_package_versions WHERE package_id = ? AND os = ?' . ($action === 'install' ? ' AND retired = 0' : '') . ($os === 'linux' && $mgr !== null ? ' AND fmt = ?' : '')
            . ' ORDER BY version_id DESC LIMIT 1', $os === 'linux' && $mgr !== null ? [$packageId, $os, $mgr] : [$packageId, $os]);

        return $rows === [] ? null : $this->catalog->version((int) $rows[0]['version_id']);
    }

    /**
     * Re-resolve one device and write its expectation rows. Rows for packages that are no longer expected keep their last results but lose the expectation.
     *
     * @param array<string,mixed> $dev
     * @return int packages expected on the device
     */
    public function evaluate(array $dev): int
    {
        $deviceId = (int) $dev['device_id'];
        $client = (int) $dev['client_id'];
        $now = $this->sql->utcNow();
        $ring = '';
        $delay = 0;
        $assignments = $this->assignmentsFor($dev);
        if ($assignments !== [] && $this->patchPolicies !== null) {
            try {
                $eff = $this->patchPolicies->effectiveFor($dev);
                $ring = (string) ($eff['ring'] ?? '');
                $delay = (int) ($eff['ring_delay_days'] ?? 0);
            } catch (\Throwable) {
                // no patch policy tables: no ring
            }
        }
        $kept = [];
        foreach ($assignments as $a) {
            $v = $this->versionFor($dev, $a['package_id'], $a['version_id'], $a['action']);
            if ($v === null) {
                continue;   // nothing in the catalog fits this device: not expected here (the report shows it as a gap in the catalog, not as a failure)
            }
            $since = gmdate('Y-m-d H:i:s', (int) strtotime($a['activated_at'] . ' UTC') + $delay * 86400);
            $this->sql->run('INSERT INTO rmm_package_installs (device_id, package_id, client_id, expected_action, expected_version_id, assignment_id, ring, expected_since, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), expected_action = VALUES(expected_action), expected_version_id = VALUES(expected_version_id),
                assignment_id = VALUES(assignment_id), ring = VALUES(ring), expected_since = VALUES(expected_since), updated_at = VALUES(updated_at)',
                [$deviceId, $a['package_id'], $client, $a['action'], $v['version_id'], $a['assignment_id'], $ring, $since, $now]);
            $kept[] = $a['package_id'];
        }
        $this->sql->run('UPDATE rmm_package_installs SET expected_action = NULL, expected_version_id = NULL, assignment_id = NULL, expected_since = NULL, updated_at = ? WHERE device_id = ? AND expected_action IS NOT NULL'
            . ($kept === [] ? '' : ' AND package_id NOT IN (' . implode(',', array_fill(0, count($kept), '?')) . ')'), [$now, $deviceId, ...$kept]);
        $this->sql->run('INSERT INTO rmm_deploy_state (device_id, client_id, evaluated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE client_id = VALUES(client_id), evaluated_at = VALUES(evaluated_at)', [$deviceId, $client, $now]);

        return count($kept);
    }

    /** Make every device be looked at again by the next pass (after an assignment, a package or a version changed). */
    public function invalidate(): void
    {
        $this->sql->run('UPDATE rmm_deploy_state SET evaluated_at = NULL');
    }
}
