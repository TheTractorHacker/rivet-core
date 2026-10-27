<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Alerting;

use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Device dependencies (table rmm_device_parents): a device may name one parent, typically the router, switch, hypervisor or gateway it
 * reaches the network through. While a parent is OFFLINE (no check-in for `offline_after_s`) alerts of its children do not open: their
 * network-dependent checks fail because of the parent, and the parent's own offline state is the alert that matters. Ancestors count up to
 * {@see MAX_DEPTH} levels. A parent that never checked in, was revoked or was retired is not "down" for this purpose (nothing is known, or it
 * is gone on purpose). A device and its parent must belong to the same client, and a loop is refused.
 *
 * @api
 */
final class DependencyService
{
    public const MAX_DEPTH = 5;

    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings)
    {
    }

    /**
     * @throws \InvalidArgumentException with a message safe to show
     */
    public function setParent(int $deviceId, int $parentId, int $userId): void
    {
        if ($deviceId === $parentId) {
            throw new \InvalidArgumentException('A device cannot be its own parent.');
        }
        $dev = $this->sql->one('SELECT device_id, client_id FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        $par = $this->sql->one('SELECT device_id, client_id FROM endpoint_agent_devices WHERE device_id = ?', [$parentId]);
        if ($dev === null || $par === null) {
            throw new \InvalidArgumentException('Device not found.');
        }
        if ((int) $dev['client_id'] !== (int) $par['client_id']) {
            throw new \InvalidArgumentException('A device and its parent must belong to the same client.');
        }
        // Walk up from the new parent: reaching the device again would close a loop.
        $cur = $parentId;
        for ($i = 0; $i < 20 && $cur > 0; ++$i) {
            if ($cur === $deviceId) {
                throw new \InvalidArgumentException('That would make the devices depend on each other.');
            }
            $cur = (int) ($this->sql->val('SELECT parent_device_id FROM rmm_device_parents WHERE device_id = ?', [$cur]) ?? 0);
        }
        $this->sql->run('INSERT INTO rmm_device_parents (device_id, parent_device_id, created_by, created_at) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE parent_device_id = VALUES(parent_device_id), created_by = VALUES(created_by), created_at = VALUES(created_at)', [$deviceId, $parentId, $userId, $this->sql->utcNow()]);
    }

    public function clearParent(int $deviceId): bool
    {
        return $this->sql->run('DELETE FROM rmm_device_parents WHERE device_id = ?', [$deviceId]) > 0;
    }

    /** @return array<string,mixed>|null {device_id, parent_device_id, parent_hostname} */
    public function parentOf(int $deviceId): ?array
    {
        $r = $this->sql->one('SELECT p.device_id, p.parent_device_id, d.hostname AS parent_hostname FROM rmm_device_parents p
            LEFT JOIN endpoint_agent_devices d ON d.device_id = p.parent_device_id WHERE p.device_id = ?', [$deviceId]);

        return $r === null ? null : ['device_id' => (int) $r['device_id'], 'parent_device_id' => (int) $r['parent_device_id'], 'parent_hostname' => (string) ($r['parent_hostname'] ?? '')];
    }

    /** @return list<array{device_id:int,hostname:string}> */
    public function childrenOf(int $deviceId): array
    {
        $out = [];
        foreach ($this->sql->all('SELECT d.device_id, d.hostname FROM rmm_device_parents p JOIN endpoint_agent_devices d ON d.device_id = p.device_id WHERE p.parent_device_id = ? ORDER BY d.hostname LIMIT 500', [$deviceId]) as $r) {
            $out[] = ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname']];
        }

        return $out;
    }

    /**
     * The nearest ancestor that is down, or null. One indexed read when the device has no parent (the usual case).
     *
     * @return array{device_id:int,hostname:string}|null
     */
    public function downAncestor(int $deviceId): ?array
    {
        $before = $this->sql->utcAt(-(int) $this->settings->get()['offline_after_s']);
        $cur = $deviceId;
        for ($i = 0; $i < self::MAX_DEPTH; ++$i) {
            $r = $this->sql->one('SELECT d.device_id, d.hostname, d.last_checkin_at, d.revoked_at, d.retired_at FROM rmm_device_parents p
                JOIN endpoint_agent_devices d ON d.device_id = p.parent_device_id WHERE p.device_id = ?', [$cur]);
            if ($r === null) {
                return null;
            }
            if ($r['revoked_at'] === null && $r['retired_at'] === null && $r['last_checkin_at'] !== null && (string) $r['last_checkin_at'] < $before) {
                return ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname']];
            }
            $cur = (int) $r['device_id'];
        }

        return null;
    }
}
