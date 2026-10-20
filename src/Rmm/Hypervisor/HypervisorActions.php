<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Hypervisor;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmAuthorizer;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Device\DeviceRepository;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Rmm\Technician\ActionResult;

/**
 * Reading hypervisor hosts and VMs and managing hypervisor monitoring, shared by the REST API and an edition's pages.
 *
 * Reading needs `rmm.hypervisor.view` on top of `rmm.device.view`, and only the caller's own clients are shown. Ignoring a VM or a host and changing the alert thresholds need
 * `rmm.hypervisor.manage`; the `hypervisor` switch itself needs `rmm.admin`. A device or VM outside the caller's clients is the same 404 as a missing one. Every change is written
 * to the audit log with who did it.
 *
 * @api
 */
final class HypervisorActions
{
    public function __construct(
        private readonly Sql $sql,
        private readonly DeviceRepository $devices,
        private readonly RmmAuthorizer $authz,
        private readonly RmmSettings $settings,
        private readonly HypervisorSettings $hypervisor,
        private readonly HypervisorReader $reader,
        private readonly HypervisorAlerts $alerts,
        private readonly ?HypervisorLinker $linker,
        private readonly RmmAuditInterface $audit,
    ) {
    }

    private function role(RmmPrincipal $who, string $ability): ?ActionResult
    {
        $denied = $this->authz->check($who->userId, RmmAbility::DEVICE_VIEW, 0);
        if ($denied === null && $ability !== RmmAbility::DEVICE_VIEW) {
            $denied = $this->authz->check($who->userId, $ability, 0);
        }

        return $denied === null ? null : ActionResult::fail(403, 'forbidden', $denied);
    }

    /** @return list<int>|null the clients where the caller holds the ability (null = all) */
    private function visible(RmmPrincipal $who, string $ability): ?array
    {
        $visible = $this->authz->visibleClientIds($who->userId);
        if ($visible !== null) {
            $visible = array_values(array_filter($visible, fn (int $c): bool => $this->authz->check($who->userId, $ability, $c) === null));
        }

        return $visible;
    }

    private function notFound(string $what = 'Not found.'): ActionResult
    {
        return ActionResult::fail(404, 'not_found', $what);
    }

    /** @param array<string,mixed> $f q, platform, client_id, problems */
    public function hosts(RmmPrincipal $who, array $f, int $limit, int $offset): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0 && !$this->authz->clientOk($who->userId, (int) $f['client_id'])) {
            return $this->notFound('Client not found.');
        }
        $visible = $this->visible($who, RmmAbility::HYPERVISOR_VIEW);

        return ActionResult::ok('ok', 200, 'ok', $this->reader->hosts($visible, $f, $limit, $offset) + ['summary' => $this->reader->summary($visible), 'enabled' => $this->hypervisor->enabled()]);
    }

    /** @param array<string,mixed> $f q, state, type, template, ignored, missing */
    public function host(RmmPrincipal $who, int $deviceId, array $f, int $limit, int $offset): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }
        $dev = $this->devices->find($deviceId);
        if ($dev === null || !$this->authz->clientOk($who->userId, (int) $dev['client_id']) || $this->authz->check($who->userId, RmmAbility::HYPERVISOR_VIEW, (int) $dev['client_id']) !== null) {
            return $this->notFound('Device not found.');
        }
        $host = $this->reader->host($deviceId, null, $f, $limit, $offset);

        return $host === null ? $this->notFound('This device has not reported a hypervisor.') : ActionResult::ok('ok', 200, 'ok', $host);
    }

    /** @param array<string,mixed> $f device_id, client_id, q, state, type, template, ignored, missing, snapshots, backup */
    public function vms(RmmPrincipal $who, array $f, int $limit, int $offset): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }
        if (isset($f['client_id']) && (int) $f['client_id'] > 0 && !$this->authz->clientOk($who->userId, (int) $f['client_id'])) {
            return $this->notFound('Client not found.');
        }

        return ActionResult::ok('ok', 200, 'ok', $this->reader->vms($this->visible($who, RmmAbility::HYPERVISOR_VIEW), $f, $limit, $offset));
    }

    public function vm(RmmPrincipal $who, int $vmId): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }
        $vm = $this->reader->vm($vmId, $this->visible($who, RmmAbility::HYPERVISOR_VIEW));

        return $vm === null ? $this->notFound('VM not found.') : ActionResult::ok('ok', 200, 'ok', $vm);
    }

    /**
     * Asset suggestions for a VM (no link is made).
     *
     * @param list<string> $macs
     */
    public function suggestAssets(RmmPrincipal $who, int $vmId, array $macs = [], ?string $guestName = null): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }
        $vm = $this->reader->vm($vmId, $this->visible($who, RmmAbility::HYPERVISOR_VIEW));
        if ($vm === null) {
            return $this->notFound('VM not found.');
        }
        if ($this->linker === null) {
            return ActionResult::ok('ok', 200, 'ok', ['data' => []]);
        }
        $visible = $this->authz->visibleClientIds($who->userId);
        $list = array_values(array_filter($this->linker->suggest($vm, $macs, $guestName), static fn (array $s): bool => $visible === null || in_array($s['client_id'], $visible, true)));

        return ActionResult::ok('ok', 200, 'ok', ['data' => $list]);
    }

    /** "Ignore this VM" (a template or dev guest): no alerts for it and an open alert of it resolves. */
    public function setVmIgnored(RmmPrincipal $who, int $vmId, bool $ignored, string $reason = ''): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_MANAGE)) !== null) {
            return $e;
        }
        $vm = $this->reader->vm($vmId, $this->visible($who, RmmAbility::HYPERVISOR_MANAGE));
        if ($vm === null) {
            return $this->notFound('VM not found.');
        }
        $reason = $ignored ? HypervisorProtocol::text($reason, 200) : '';
        $this->sql->run('UPDATE rmm_hypervisor_vms SET ignored = ?, ignored_reason = ?, ignored_by = ?, ignored_at = ? WHERE vm_id = ?',
            [$ignored ? 1 : 0, $reason, $ignored ? $who->userId : 0, $ignored ? $this->sql->utcNow() : null, $vmId]);
        $this->reevaluate((int) $vm['device_id']);
        $this->audit->record('Hypervisor VM ' . ($ignored ? 'Ignored' : 'Un-ignored'), "{$who->userName}: VM " . HypervisorProtocol::alertText((string) $vm['name']) . ' on device ' . (int) $vm['device_id'] . ($reason === '' ? '' : ' (' . HypervisorProtocol::alertText($reason, 200) . ')'), (int) $vm['client_id'], $vmId);

        return ActionResult::ok('Saved.', 200, 'saved', $this->reader->vm($vmId, null) ?? []);
    }

    /** Stop (or resume) storing what a host reports; its alerts resolve. */
    public function setHostIgnored(RmmPrincipal $who, int $deviceId, bool $ignored): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_MANAGE)) !== null) {
            return $e;
        }
        $dev = $this->devices->find($deviceId);
        if ($dev === null || !$this->authz->clientOk($who->userId, (int) $dev['client_id']) || $this->authz->check($who->userId, RmmAbility::HYPERVISOR_MANAGE, (int) $dev['client_id']) !== null
            || $this->sql->one('SELECT device_id FROM rmm_hypervisor_hosts WHERE device_id = ?', [$deviceId]) === null) {
            return $this->notFound('Device not found.');
        }
        $this->sql->run('UPDATE rmm_hypervisor_hosts SET ignored = ? WHERE device_id = ?', [$ignored ? 1 : 0, $deviceId]);
        if ($ignored) {
            $this->sql->run('UPDATE rmm_hypervisor_vms SET ignored = 1, ignored_reason = ?, ignored_by = ?, ignored_at = ? WHERE device_id = ? AND ignored = 0', ['Host ignored', $who->userId, $this->sql->utcNow(), $deviceId]);
        }
        $this->reevaluate($deviceId);
        $this->audit->record('Hypervisor Host ' . ($ignored ? 'Ignored' : 'Un-ignored'), "{$who->userName}: device $deviceId", (int) $dev['client_id'], $deviceId);

        return ActionResult::ok('Saved.', 200, 'saved', ['device_id' => $deviceId, 'ignored' => $ignored]);
    }

    private function reevaluate(int $deviceId): void
    {
        $dev = $this->devices->find($deviceId);
        if ($dev !== null) {
            try {
                $this->alerts->evaluate($dev, true, ['transitions' => [], 'vm_ids' => []]);
            } catch (\Throwable $e) {
                error_log('rmm hypervisor re-evaluation: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }
    }

    /** @return array<string,mixed> */
    public function settingsView(): array
    {
        $defaults = [];
        foreach (HypervisorSettings::LIMIT_KEYS as $k) {
            $defaults[$k] = RmmSettings::LIMIT_DEFAULTS[$k];
        }

        return ['enabled' => $this->hypervisor->enabled(), 'limits' => $this->hypervisor->limits(), 'defaults' => $defaults, 'ranges' => array_intersect_key(RmmSettings::LIMIT_KEYS, array_flip(HypervisorSettings::LIMIT_KEYS)),
            'platforms' => ['Proxmox VE (Linux agent on the host)', 'Hyper-V (Windows agent on the host)'], 'events' => [\RivetCore\Rmm\RmmEvent::HYPERVISOR_VM_DOWN, \RivetCore\Rmm\RmmEvent::HYPERVISOR_BACKUP_FAILED,
                \RivetCore\Rmm\RmmEvent::HYPERVISOR_SNAPSHOT_OLD, \RivetCore\Rmm\RmmEvent::HYPERVISOR_STORAGE_LOW, \RivetCore\Rmm\RmmEvent::HYPERVISOR_POOL_DEGRADED, \RivetCore\Rmm\RmmEvent::HYPERVISOR_CLUSTER_QUORUM_LOST]];
    }

    public function settingsRead(RmmPrincipal $who): ActionResult
    {
        if (($e = $this->role($who, RmmAbility::HYPERVISOR_VIEW)) !== null) {
            return $e;
        }

        return ActionResult::ok('ok', 200, 'ok', $this->settingsView());
    }

    /**
     * @param array<string,mixed> $in features {hypervisor: bool} (needs `rmm.admin`), limits {hypervisor_*: int} (needs `rmm.hypervisor.manage`)
     */
    public function updateSettings(RmmPrincipal $who, array $in): ActionResult
    {
        foreach (array_keys($in) as $k) {
            if (!in_array($k, ['features', 'limits'], true)) {
                return ActionResult::fail(422, 'invalid', 'Unknown setting ' . mb_substr((string) $k, 0, 40) . '.');
            }
        }
        if (array_key_exists('features', $in) && ($denied = $this->authz->check($who->userId, RmmAbility::ADMIN, 0, false)) !== null) {
            $this->audit->record('Hypervisor Settings Denied', "User {$who->userId} denied a hypervisor switch change: $denied", 0, 0);

            return ActionResult::fail(403, 'forbidden', $denied);
        }
        if (array_key_exists('limits', $in)) {
            if (($e = $this->role($who, RmmAbility::HYPERVISOR_MANAGE)) !== null) {
                $this->audit->record('Hypervisor Settings Denied', "User {$who->userId} denied a hypervisor settings change", 0, 0);

                return $e;
            }
            if ($this->authz->visibleClientIds($who->userId) !== null) {
                return ActionResult::fail(403, 'forbidden', 'The thresholds apply to every client, so changing them needs access to all clients.');
            }
        }
        $what = [];
        $update = [];
        if (array_key_exists('features', $in)) {
            $f = $in['features'];
            if (!is_array($f) || array_diff(array_keys($f), [HypervisorProtocol::FEATURE]) !== [] || !is_bool($f[HypervisorProtocol::FEATURE] ?? null)) {
                return ActionResult::fail(422, 'invalid', 'features takes hypervisor, true or false.');
            }
            $features = $this->settings->features();
            if ($features[HypervisorProtocol::FEATURE] !== $f[HypervisorProtocol::FEATURE]) {
                $update['features_json'] = array_merge($features, [HypervisorProtocol::FEATURE => $f[HypervisorProtocol::FEATURE]]);
                $what[] = 'hypervisor ' . ($f[HypervisorProtocol::FEATURE] ? 'ON' : 'off');
            }
        }
        if (array_key_exists('limits', $in)) {
            $l = $in['limits'];
            if (!is_array($l) || ($l !== [] && array_is_list($l)) || array_diff(array_keys($l), HypervisorSettings::LIMIT_KEYS) !== []) {
                return ActionResult::fail(422, 'invalid', 'limits takes hypervisor_* limit names and whole numbers.');
            }
            $merged = array_merge($this->settings->explicitLimits(), $l);
            [$json, $err] = RmmSettings::validateLimits($merged);
            if ($err !== null) {
                return ActionResult::fail(422, 'invalid', $err);
            }
            $update['limits_json'] = $json;
            $what[] = 'limits ' . implode(', ', array_keys($l));
        }
        if ($update !== []) {
            $errors = $this->settings->update($update);
            if ($errors !== []) {
                return ActionResult::fail(422, 'invalid', implode(' ', $errors));
            }
        }
        $this->audit->record('Hypervisor Settings Changed', "{$who->userName}: " . ($what === [] ? 'no change' : implode('; ', $what)), 0, 0);
        $this->settings->refresh();

        return ActionResult::ok('Saved.', 200, 'saved', $this->settingsView());
    }
}
