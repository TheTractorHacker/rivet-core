<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Deploy;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * What a technician or administrator does with software deployment, shared by the REST API ({@see \RivetCore\Rmm\Http\DeployApi}) and an edition's pages.
 *
 * ORDER OF DECISIONS (a denial reveals nothing): the role cannot view devices or software -> 403; the device is missing OR outside the caller's clients -> the same 404; the
 * caller lacks the ability for that client -> 403 with the edition's generic text, audited; the `software_deploy` sub-switch is off -> 409 `feature_disabled` (reads still
 * answer, with `enabled` false); then strict validation. The catalog and the host list are fleet-wide: only a caller whose scope is not restricted to some clients changes
 * them; an assignment reaches the fleet, a client or a device, and a restricted caller may only assign to their own clients and devices.
 *
 * ABILITIES: `rmm.software.view` reads; `rmm.software.manage` edits the catalog, asks for a detection and runs a DRY RUN; `rmm.software.deploy` creates and removes
 * assignments (they change machines by themselves later) and runs a REAL install or removal; the host list needs `rmm.admin`.
 *
 * @api
 */
final class DeployActions
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly Sql $sql,
        private readonly PackageCatalog $catalog,
        private readonly DeployService $service,
        private readonly DeployExpectations $expectations,
        private readonly DeployApprovalService $approvals,
        private readonly DeployReader $reader,
        private readonly RmmAuditInterface $audit,
        private readonly ?RmmTenancyInterface $tenancy = null,
    ) {
    }

    // ------------------------------------------------------------------ plumbing

    /** @return array{0:?array<string,mixed>,1:?ActionResult} */
    private function deviceFor(RmmPrincipal $who, int $deviceId, string $ability): array
    {
        foreach ([RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW] as $a) {
            $role = $this->authz->check($who->userId, $a, 0);
            if ($role !== null) {
                return [null, ActionResult::fail(403, 'forbidden', $role)];
            }
        }
        $dev = $this->devices->find($deviceId);
        $client = $dev === null ? 0 : (int) $dev['client_id'];
        if ($dev === null || !$this->authz->clientOk($who->userId, $client) || $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, $client) !== null
            || $this->authz->check($who->userId, RmmAbility::SOFTWARE_VIEW, $client) !== null) {
            return [null, ActionResult::fail(404, 'not_found', 'Device not found.')];
        }
        if ($ability !== RmmAbility::SOFTWARE_VIEW) {
            $denied = $this->authz->check($who->userId, $ability, $client);
            if ($denied !== null) {
                $this->audit->record('Software Denied', "User {$who->userId} denied $ability on device $deviceId: $denied", $client, (int) ($dev['asset_id'] ?? 0));

                return [null, ActionResult::fail(403, 'forbidden', $denied)];
            }
        }

        return [$dev, null];
    }

    private function featureOff(): ?ActionResult
    {
        return $this->settings->featureOn(DeployProtocol::FEATURE) ? null : ActionResult::fail(409, 'feature_disabled',
            'Software deployment is switched off. An administrator can turn it on in the RMM settings: it keeps a package catalog and, once you assign packages, asks devices what they have about once a day and installs only what you assigned.');
    }

    /** Fleet-wide reads and writes: the viewer's role, and for a write also the ability. */
    private function fleetRole(RmmPrincipal $who, string $ability): ?ActionResult
    {
        foreach ([RmmAbility::DEVICE_VIEW, RmmAbility::SOFTWARE_VIEW, $ability] as $a) {
            $d = $this->authz->check($who->userId, $a, 0);
            if ($d !== null) {
                if ($a === $ability && $ability !== RmmAbility::SOFTWARE_VIEW) {
                    $this->audit->record('Software Denied', "User {$who->userId} denied $ability: $d", 0, 0);
                }

                return ActionResult::fail(403, 'forbidden', $d);
            }
        }

        return null;
    }

    private function unrestricted(RmmPrincipal $who): ?ActionResult
    {
        return $this->authz->visibleClientIds($who->userId) === null ? null : ActionResult::fail(403, 'forbidden', 'The software catalog applies to every client; ask an administrator whose access is not limited to some clients.');
    }

    /** @return array{0:?list<int>,1:?ActionResult} */
    private function scope(RmmPrincipal $who): array
    {
        $e = $this->fleetRole($who, RmmAbility::SOFTWARE_VIEW);

        return $e !== null ? [null, $e] : [$this->authz->visibleClientIds($who->userId), null];
    }

    /** @param \Closure():mixed $f */
    private function guard(\Closure $f): ActionResult
    {
        try {
            $r = $f();

            return $r instanceof ActionResult ? $r : ActionResult::ok('ok', 200, 'ok', is_array($r) ? $r : []);
        } catch (\InvalidArgumentException $e) {
            return ActionResult::fail(422, 'invalid', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ catalog

    public function catalog(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE), 'packages' => $this->catalog->packages()]);
    }

    public function package(RmmPrincipal $who, int $packageId): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }
        $p = $this->catalog->package($packageId);
        if ($p === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }
        // The installer address is part of what an administrator controls: a reader without the manage grant (or limited to some clients) sees where it points, not how.
        $sees = $this->authz->check($who->userId, RmmAbility::SOFTWARE_MANAGE, 0) === null && $this->authz->visibleClientIds($who->userId) === null;
        $versions = array_map(static function (array $v) use ($sees): array {
            if (!$sees && $v['url'] !== '') {
                $v['url'] = preg_replace('#^(https://[^/?]+).*$#D', '$1/...', $v['url']) ?? '';
            }

            return $v;
        }, $this->catalog->versions($packageId));

        return ActionResult::ok('ok', 200, 'ok', $p + ['version_list' => $versions]);
    }

    /**
     * @param array<string,mixed> $in
     */
    public function savePackage(RmmPrincipal $who, ?int $packageId, array $in): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $current = $packageId === null ? null : $this->catalog->package($packageId);
        if ($packageId !== null && $current === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }
        // Lowering the two-person flag would defeat the control for everyone who relies on it: only an administrator may (raising it is open to the catalog manager).
        if ($current !== null && $current['two_person'] && array_key_exists('two_person', $in) && !($in['two_person'] === true || $in['two_person'] === 1)) {
            $denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0);
            if ($denied !== null) {
                $this->audit->record('Software Denied', "User {$who->userId} denied lowering two_person on package #$packageId: $denied", 0, 0);

                return ActionResult::fail(403, 'forbidden', $denied);
            }
        }

        return $this->guard(function () use ($who, $packageId, $in): ActionResult {
            $p = $this->catalog->savePackage($packageId, $in, $who->userId);
            $this->expectations->invalidate();
            $this->audit->record($packageId === null ? 'Software Package Created' : 'Software Package Changed', "{$who->userName} saved software package #{$p['package_id']} ({$p['name']})", 0, 0);

            return ActionResult::ok('Saved.', $packageId === null ? 201 : 200, 'saved', $p);
        });
    }

    public function deletePackage(RmmPrincipal $who, int $packageId): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $why = $this->catalog->deletePackage($packageId);
        if ($why === 'not_found') {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }
        if ($why !== null) {
            return ActionResult::fail(409, 'conflict', 'The package is assigned. Remove its assignments first (or switch the package off).');
        }
        $this->audit->record('Software Package Deleted', "{$who->userName} deleted software package #$packageId", 0, 0);

        return ActionResult::ok('Deleted.', 200, 'deleted');
    }

    /**
     * A version is created once and never edited ({@see PackageCatalog}).
     *
     * @param array<string,mixed> $in
     */
    public function addVersion(RmmPrincipal $who, int $packageId, array $in): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        if ($this->catalog->package($packageId) === null) {
            return ActionResult::fail(404, 'not_found', 'Package not found.');
        }

        return $this->guard(function () use ($who, $packageId, $in): ActionResult {
            $v = $this->catalog->addVersion($packageId, $in, $who->userId);
            $this->expectations->invalidate();
            $this->audit->record('Software Version Added', "{$who->userName} added version {$v['version']} ({$v['fmt']}, " . ($v['sha256'] !== '' ? 'sha256 ' . substr($v['sha256'], 0, 16) : 'package manager') . ") to package #$packageId", 0, 0);

            return ActionResult::ok('Saved. A version cannot be changed afterwards; add a new one instead.', 201, 'saved', $v);
        });
    }

    public function retireVersion(RmmPrincipal $who, int $versionId, bool $retired): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_MANAGE) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        if (!$this->catalog->retireVersion($versionId, $retired)) {
            return ActionResult::fail(404, 'not_found', 'Version not found.');
        }
        $this->expectations->invalidate();
        $this->audit->record($retired ? 'Software Version Retired' : 'Software Version Restored', "{$who->userName} " . ($retired ? 'retired' : 'restored') . " software version #$versionId", 0, 0);

        return ActionResult::ok($retired ? 'Retired. It is no longer installed anywhere new.' : 'Restored.', 200, 'saved', $this->catalog->version($versionId) ?? []);
    }

    // ------------------------------------------------------------------ hosts

    public function hosts(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', ['hosts' => $this->catalog->hosts()]);
    }

    /**
     * @param array<string,mixed> $in {host, note?}
     */
    public function addHost(RmmPrincipal $who, array $in): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::ADMIN) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }

        return $this->guard(function () use ($who, $in): ActionResult {
            $host = $this->catalog->addHost($in['host'] ?? null, $in['note'] ?? '', $who->userId);
            $this->audit->record('Software Host Allowed', "{$who->userName} allowed downloads from $host", 0, 0);

            return ActionResult::ok('Saved.', 201, 'saved', ['host' => $host]);
        });
    }

    public function removeHost(RmmPrincipal $who, string $host): ActionResult
    {
        if (($e = $this->fleetRole($who, RmmAbility::ADMIN) ?? $this->unrestricted($who) ?? $this->featureOff()) !== null) {
            return $e;
        }
        if (!$this->catalog->removeHost($host)) {
            return ActionResult::fail(404, 'not_found', 'Host not found.');
        }
        $this->audit->record('Software Host Removed', "{$who->userName} no longer allows downloads from " . mb_substr($host, 0, 100), 0, 0);

        return ActionResult::ok('Removed. Packages that download from it are refused until it is allowed again.', 200, 'deleted');
    }

    // ------------------------------------------------------------------ assignments

    public function assignments(RmmPrincipal $who): ActionResult
    {
        [$visible, $err] = $this->scope($who);
        if ($err !== null) {
            return $err;
        }
        $rows = $this->sql->all('SELECT a.*, p.name AS package FROM rmm_package_assignments a JOIN rmm_packages p ON p.package_id = a.package_id ORDER BY p.name, a.assignment_id');
        $out = [];
        foreach ($rows as $r) {
            if ($visible !== null && !$this->assignmentVisible($r, $visible)) {
                continue;
            }
            $out[] = self::assignmentRow($r);
        }

        return ActionResult::ok('ok', 200, 'ok', ['assignments' => $out]);
    }

    /**
     * @param array<string,mixed> $r
     * @param list<int> $visible
     */
    private function assignmentVisible(array $r, array $visible): bool
    {
        return match ((string) $r['scope_type']) {
            'client' => in_array((int) $r['scope_id'], $visible, true),
            'device' => ($d = $this->devices->find((int) $r['scope_id'])) !== null && in_array((int) $d['client_id'], $visible, true),
            default => false,
        };
    }

    /**
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private static function assignmentRow(array $r): array
    {
        return ['assignment_id' => (int) $r['assignment_id'], 'package_id' => (int) $r['package_id'], 'package' => (string) ($r['package'] ?? ''), 'version_id' => $r['version_id'] === null ? null : (int) $r['version_id'],
            'scope_type' => (string) $r['scope_type'], 'scope_id' => (int) $r['scope_id'], 'action' => (string) $r['action'], 'enabled' => (int) $r['enabled'] === 1,
            'waiting_for_approval' => (int) $r['enabled'] === 0 && $r['activated_at'] === null, 'approval_id' => $r['approval_id'] === null ? null : (int) $r['approval_id'],
            'activated_at' => Sql::iso($r['activated_at']), 'created_at' => Sql::iso((string) $r['created_at'])];
    }

    /**
     * Assign a package to a scope: every device there should have it installed (or removed). Changes machines on their own later, so it needs the deploy grant.
     *
     * @param array<string,mixed> $in {package_id, scope_type, scope_id?, action?: install|uninstall, version_id?}
     */
    public function assign(RmmPrincipal $who, array $in): ActionResult
    {
        [$visible, $err] = $this->scope($who);
        if ($err !== null) {
            return $err;
        }
        if (($e = $this->fleetRole($who, RmmAbility::SOFTWARE_DEPLOY) ?? $this->featureOff()) !== null) {
            return $e;
        }
        $packageId = $in['package_id'] ?? null;
        $pkg = is_int($packageId) ? $this->catalog->package($packageId) : null;
        if ($pkg === null) {
            return ActionResult::fail(422, 'invalid', 'package_id must name a package.');
        }
        $type = $in['scope_type'] ?? null;
        if (!is_string($type) || !in_array($type, DeployProtocol::SCOPES, true)) {
            return ActionResult::fail(422, 'invalid', 'scope_type must be global, client, site, group, tag or device.');
        }
        $sid = $in['scope_id'] ?? 0;
        $scopeId = $type === 'global' ? 0 : (is_int($sid) ? $sid : 0);
        if ($type !== 'global' && $scopeId <= 0) {
            return ActionResult::fail(422, 'invalid', 'scope_id is required for a ' . $type . ' assignment.');
        }
        $action = $in['action'] ?? 'install';
        if (!is_string($action) || !in_array($action, DeployProtocol::ACTIONS, true)) {
            return ActionResult::fail(422, 'invalid', 'action must be install or uninstall.');
        }
        $versionId = $in['version_id'] ?? null;
        if ($versionId !== null) {
            $v = is_int($versionId) ? $this->catalog->version($versionId) : null;
            if ($v === null || $v['package_id'] !== $pkg['package_id'] || ($action === 'install' && $v['retired'])) {
                return ActionResult::fail(422, 'invalid', 'version_id must be a version of that package (not retired, for an install).');
            }
        }
        // Scope rules. A restricted caller assigns only to their own clients and devices; the fleet-wide scopes (global, site, group, tag) need an unrestricted caller.
        $clientOfScope = 0;
        if ($type === 'client') {
            $clientOfScope = $scopeId;
            if (($this->tenancy !== null && $this->tenancy->clientName($scopeId) === null) || ($visible !== null && !in_array($scopeId, $visible, true))) {
                return ActionResult::fail(404, 'not_found', 'No such client.');
            }
        } elseif ($type === 'device') {
            $d = $this->devices->find($scopeId);
            if ($d === null || ($visible !== null && !in_array((int) $d['client_id'], $visible, true))) {
                return ActionResult::fail(404, 'not_found', 'Device not found.');
            }
            $clientOfScope = (int) $d['client_id'];
        } else {
            if ($visible !== null) {
                return ActionResult::fail(403, 'forbidden', 'An assignment to everyone, a site, a group or a tag reaches every client; ask an administrator whose access is not limited to some clients.');
            }
            $ok = match ($type) {
                'group' => $this->sql->one('SELECT group_id FROM rmm_groups WHERE group_id = ?', [$scopeId]) !== null,
                'tag' => $this->sql->one('SELECT tag_id FROM rmm_tags WHERE tag_id = ?', [$scopeId]) !== null,
                default => true,
            };
            if (!$ok) {
                return ActionResult::fail(422, 'invalid', "That $type does not exist.");
            }
        }
        $denied = $this->authz->check($who->userId, RmmAbility::SOFTWARE_DEPLOY, $clientOfScope);
        if ($denied !== null) {
            $this->audit->record('Software Denied', "User {$who->userId} denied assigning software: $denied", $clientOfScope, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        $now = $this->sql->utcNow();
        $needsSecond = $pkg['two_person'];
        $res = $this->sql->transaction(function () use ($pkg, $type, $scopeId, $action, $versionId, $who, $now, $needsSecond): array|ActionResult {
            $cur = $this->sql->one('SELECT * FROM rmm_package_assignments WHERE package_id = ? AND scope_type = ? AND scope_id = ? FOR UPDATE', [$pkg['package_id'], $type, $scopeId]);
            if ($cur !== null && ((string) $cur['action'] !== $action || ($cur['version_id'] === null ? null : (int) $cur['version_id']) !== $versionId)) {
                return ActionResult::fail(409, 'conflict', 'That scope already has an assignment for this package. Remove it first, then assign again.');
            }
            if ($cur !== null) {
                return $cur;   // idempotent: the same assignment again changes nothing
            }
            $id = $this->sql->insert('INSERT INTO rmm_package_assignments (package_id, version_id, scope_type, scope_id, action, enabled, activated_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$pkg['package_id'], $versionId, $type, $scopeId, $action, $needsSecond ? 0 : 1, $needsSecond ? null : $now, $who->userId, $now]);

            return $this->sql->one('SELECT * FROM rmm_package_assignments WHERE assignment_id = ?', [$id]) ?? [];
        });
        if ($res instanceof ActionResult) {
            return $res;
        }
        $row = self::assignmentRow($res + ['package' => $pkg['name']]);
        if ($row['waiting_for_approval']) {
            if ($row['approval_id'] === null) {
                $a = $this->approvals->requestAssignment($res, $pkg['name'], $who->userId);
                $this->sql->run('UPDATE rmm_package_assignments SET approval_id = ? WHERE assignment_id = ?', [$a['approval_id'], $res['assignment_id']]);
                $row['approval_id'] = (int) $a['approval_id'];
                $this->audit->record('Software Assignment Requested', "{$who->userName} asked to {$action} {$pkg['name']} on $type #$scopeId (approval #{$a['approval_id']})", $clientOfScope, 0);
            }

            return ActionResult::ok('A second person must approve this assignment before it takes effect.', 202, 'pending_approval', ['approval_id' => $row['approval_id'], 'assignment' => $row]);
        }
        $this->expectations->invalidate();
        $this->audit->record('Software Assigned', "{$who->userName} assigned {$pkg['name']} ({$action}) to $type #$scopeId", $clientOfScope, 0);

        return ActionResult::ok('Assigned. Devices there are looked at by the next housekeeping pass.', 201, 'saved', ['assignment' => $row]);
    }

    public function unassign(RmmPrincipal $who, int $assignmentId): ActionResult
    {
        [$visible, $err] = $this->scope($who);
        if ($err !== null) {
            return $err;
        }
        $r = $this->sql->one('SELECT a.*, p.name AS package FROM rmm_package_assignments a JOIN rmm_packages p ON p.package_id = a.package_id WHERE a.assignment_id = ?', [$assignmentId]);
        if ($r === null || ($visible !== null && !$this->assignmentVisible($r, $visible))) {
            return ActionResult::fail(404, 'not_found', 'Assignment not found.');
        }
        $client = (string) $r['scope_type'] === 'client' ? (int) $r['scope_id'] : 0;
        $denied = $this->authz->check($who->userId, RmmAbility::SOFTWARE_DEPLOY, $client);
        if ($denied !== null) {
            $this->audit->record('Software Denied', "User {$who->userId} denied removing an assignment: $denied", $client, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        $this->sql->run('DELETE FROM rmm_package_assignments WHERE assignment_id = ?', [$assignmentId]);
        $this->expectations->invalidate();
        $this->audit->record('Software Unassigned', "{$who->userName} removed the assignment of {$r['package']} from {$r['scope_type']} #{$r['scope_id']}. Nothing is uninstalled by this.", $client, 0);

        return ActionResult::ok('Removed. Software already installed stays installed.', 200, 'deleted');
    }

    // ------------------------------------------------------------------ one device

    public function deviceStatus(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::SOFTWARE_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->deviceStatus($dev));
    }

    public function deviceHistory(RmmPrincipal $who, int $deviceId, int $limit, int $offset): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::SOFTWARE_VIEW);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->history($deviceId, $limit, $offset));
    }

    /** Ask the device which of its expected packages it has (read only on the device). */
    public function detect(RmmPrincipal $who, int $deviceId): ActionResult
    {
        [$dev, $err] = $this->deviceFor($who, $deviceId, RmmAbility::SOFTWARE_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can be asked. Approve it first.');
        }
        $this->expectations->evaluate($dev);
        $r = $this->service->queueDetect($dev, $who->userId);
        if (!$r['ok']) {
            $http = ($r['code'] ?? '') === 'unsupported' ? 409 : 422;

            return ActionResult::fail($http, $http === 409 ? 'unsupported' : 'invalid', (string) ($r['error'] ?? 'The detection could not be queued.'));
        }
        if (isset($r['skipped'])) {
            $msg = $r['skipped'] === 'nothing_to_detect' ? 'No package is assigned to this device.' : 'A detection is already waiting for this device.';

            return ActionResult::ok($msg, 200, (string) $r['skipped'], ['skipped' => $r['skipped'], 'job_id' => $r['job_id'] ?? null]);
        }
        $this->audit->record('Software Detect Queued', "{$who->userName} asked device $deviceId which assigned software it has (job {$r['job_id']})", (int) $dev['client_id'], (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Queued. The device picks it up at its next check-in.', 202, 'queued', ['job_id' => $r['job_id']]);
    }

    /**
     * Install or remove a catalog package on one device (a dry run unless it says `mode: "run"` and `confirm: true`).
     *
     * @param array<string,mixed> $in {package_id, action?: install (default)|uninstall, version_id?, mode?: dry_run (default)|run, confirm?, max_minutes?}
     */
    public function run(RmmPrincipal $who, int $deviceId, array $in): ActionResult
    {
        $mode = $in['mode'] ?? 'dry_run';
        if (!is_string($mode) || !in_array($mode, DeployProtocol::MODES, true)) {
            [$d] = $this->deviceFor($who, $deviceId, RmmAbility::SOFTWARE_VIEW);

            return $d === null ? ActionResult::fail(404, 'not_found', 'Device not found.') : ActionResult::fail(422, 'invalid', 'mode must be dry_run or run.');
        }
        [$dev, $err] = $this->deviceFor($who, $deviceId, $mode === 'run' ? RmmAbility::SOFTWARE_DEPLOY : RmmAbility::SOFTWARE_MANAGE);
        if ($err !== null || $dev === null) {
            return $err ?? ActionResult::fail(404, 'not_found', 'Device not found.');
        }
        if (($off = $this->featureOff()) !== null) {
            return $off;
        }
        if ($dev['link_state'] !== 'linked') {
            return ActionResult::fail(409, 'conflict', 'Only a device linked to an asset can receive jobs. Approve it first.');
        }
        $client = (int) $dev['client_id'];
        $pid = $in['package_id'] ?? null;
        $pkg = is_int($pid) ? $this->catalog->package($pid) : null;
        if ($pkg === null || !$pkg['enabled']) {
            return ActionResult::fail(422, 'invalid', 'package_id must name a package that is switched on.');
        }
        $action = $in['action'] ?? 'install';
        if (!is_string($action) || !in_array($action, DeployProtocol::ACTIONS, true)) {
            return ActionResult::fail(422, 'invalid', 'action must be install or uninstall.');
        }
        $vid = $in['version_id'] ?? null;
        if ($vid !== null && !is_int($vid)) {
            return ActionResult::fail(422, 'invalid', 'version_id must be a whole number.');
        }
        $mins = $in['max_minutes'] ?? DeployProtocol::MAX_MINUTES_DEFAULT;
        if (!is_int($mins) || $mins < 1 || $mins > DeployProtocol::MAX_MINUTES_MAX) {
            return ActionResult::fail(422, 'invalid', 'max_minutes must be 1 to ' . DeployProtocol::MAX_MINUTES_MAX . '.');
        }
        $v = $this->expectations->versionFor($dev, $pkg['package_id'], $vid, $action);
        if ($v === null) {
            return ActionResult::fail(422, 'invalid', 'The catalog has no ' . ($vid === null ? 'version' : 'such version') . ' of ' . $pkg['name'] . ' for this device.');
        }
        if ($mode === 'run') {
            if (empty($in['confirm'])) {
                return ActionResult::fail(422, 'confirmation_required', 'This ' . ($action === 'install' ? 'installs' : 'removes') . ' software on the device. Confirm it explicitly.');
            }
            if ($pkg['two_person']) {
                $a = $this->approvals->requestRun($dev, $v, $action, $who->userId, $mins);
                $this->audit->record('Software Run Requested', "{$who->userName} asked to $action {$pkg['name']} on device $deviceId (approval #{$a['approval_id']})", $client, (int) ($dev['asset_id'] ?? 0));

                return ActionResult::ok('A second person must approve this before it runs.', 202, 'pending_approval', ['approval_id' => $a['approval_id']]);
            }
        }
        $r = $this->service->queueInstall($dev, $v, $action, $mode, ['source' => 'manual', 'user_id' => $who->userId, 'max_minutes' => $mins]);
        if (!$r['ok']) {
            $code = (string) ($r['code'] ?? 'invalid');
            $http = ['unsupported' => 409, 'source_refused' => 422][$code] ?? 422;

            return ActionResult::fail($http, $http === 409 ? 'unsupported' : 'invalid', (string) ($r['error'] ?? 'The job could not be queued.'));
        }
        if (isset($r['skipped'])) {
            return ActionResult::ok('A software job is already waiting or running on this device.', 200, (string) $r['skipped'], ['skipped' => $r['skipped']]);
        }
        $this->audit->record($mode === 'run' ? 'Software Job Queued' : 'Software Dry Run Queued', "{$who->userName} queued a software " . ($mode === 'run' ? strtoupper($action) : 'dry run of ' . $action) . " of {$pkg['name']} {$v['version']} on device $deviceId (job {$r['job_id']})", $client, (int) ($dev['asset_id'] ?? 0));

        return ActionResult::ok('Queued. The device picks it up at its next check-in.', 202, 'queued', ['job_id' => $r['job_id'], 'mode' => $mode, 'action' => $action, 'version_id' => $v['version_id']]);
    }

    // ------------------------------------------------------------------ fleet reads

    /**
     * @param array<string,mixed> $filters {client_id?, package_id?, ring?, state?, group_by?, limit?, offset?}
     */
    public function report(RmmPrincipal $who, array $filters): ActionResult
    {
        [$visible, $err] = $this->scope($who);
        if ($err !== null) {
            return $err;
        }
        $client = $filters['client_id'] ?? 0;
        if (is_int($client) && $client > 0 && $visible !== null && !in_array($client, $visible, true)) {
            return ActionResult::fail(404, 'not_found', 'No such client.');
        }

        return ActionResult::ok('ok', 200, 'ok', ['enabled' => $this->settings->featureOn(DeployProtocol::FEATURE)] + $this->reader->report($visible, $filters));
    }

    /**
     * The same report as CSV text in `data.csv`.
     *
     * @param array<string,mixed> $filters
     */
    public function reportCsv(RmmPrincipal $who, array $filters): ActionResult
    {
        $r = $this->report($who, $filters);
        if (!$r->ok) {
            return $r;
        }
        $this->audit->record('Software Report Exported', "{$who->userName} exported the software deployment report (grouped by " . ($r->data['group_by'] ?? '?') . ')', 0, 0);

        return ActionResult::ok('ok', 200, 'ok', ['csv' => DeployReader::toCsv($r->data), 'group_by' => $r->data['group_by'] ?? 'package']);
    }

    // ------------------------------------------------------------------ approvals

    /**
     * The second person's decision on a software approval (called by the approvals endpoint).
     *
     * @param array<string,mixed> $approval the approval row
     * @param array<string,mixed> $request the frozen request
     */
    public function decideApproval(RmmPrincipal $who, array $approval, array $request, bool $approve, \Closure $decide): ActionResult
    {
        $approvalId = (int) $approval['approval_id'];
        $clients = $this->approvals->clientsOf($request);
        foreach ($clients ?: [0] as $c) {
            $denied = $this->authz->check($who->userId, RmmAbility::SOFTWARE_DEPLOY, $c);
            if ($denied !== null || ($c > 0 && !$this->authz->clientOk($who->userId, $c)) || ($c === 0 && $this->authz->visibleClientIds($who->userId) !== null)) {
                return ActionResult::fail($denied !== null ? 403 : 404, $denied !== null ? 'forbidden' : 'not_found', $denied ?? 'Approval not found.');
            }
        }
        /** @var array{ok:bool,code?:string,error?:string} $d */
        $d = $decide();
        if (!$d['ok']) {
            $http = ['not_found' => 404, 'conflict' => 409, 'expired' => 409, 'own_request' => 403][$d['code'] ?? ''] ?? 409;

            return ActionResult::fail($http, (string) ($d['code'] ?? 'conflict'), (string) ($d['error'] ?? 'Could not decide.'));
        }
        $data = ['approval_id' => $approvalId, 'state' => $approve ? 'approved' : 'rejected'];
        if ($approve) {
            $data['result'] = $this->approvals->applyApproved($request, $approvalId, $who->userId, $approval);
        } else {
            $this->approvals->rejected($request);
        }
        $this->audit->record($approve ? 'Approval Granted' : 'Approval Rejected', "{$who->userName} " . ($approve ? 'approved' : 'rejected') . " software request #$approvalId ({$approval['summary']}) made by user {$approval['requested_by']}", 0, 0);

        return ActionResult::ok($approve ? 'Approved.' : 'Rejected.', 200, $data['state'], $data);
    }
}
