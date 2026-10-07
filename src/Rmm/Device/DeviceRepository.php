<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Device;

use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Device lookup and authentication by bearer token hash, and the online/offline/stale/never status computation.
 *
 * @api
 */
final class DeviceRepository
{
    public function __construct(private readonly Sql $sql, private readonly RmmSettings $settings)
    {
    }

    /**
     * Authenticate a device bearer token. 401 codes: invalid_token (unknown or rotated), revoked, expired. A valid credential on a
     * server whose master switch is off is a 403 (the pre-state-file behaviour; DeviceApi answers 503 earlier when it is given the
     * module state).
     *
     * @return array<string,mixed> the device row
     * @throws ApiError
     */
    public function authenticate(?string $authHeader): array
    {
        $token = null;
        if ($authHeader !== null && preg_match(RmmProtocol::DEVICE_TOKEN_BEARER_RE, $authHeader, $m) === 1) {
            $token = $m[1];
        }
        $hash = $token === null ? '' : hash('sha256', $token);
        $dev = $hash === '' ? null : $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE token_hash = ?', [$hash]);
        // Constant-time confirmation of what the index lookup found.
        if ($dev === null || !hash_equals((string) $dev['token_hash'], $hash)) {
            throw new ApiError(401, 'invalid_token', 'Invalid device credential.');
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            throw new ApiError(401, 'revoked', 'This device credential was revoked.');
        }
        if ($dev['token_expires_at'] !== null && Sql::ts((string) $dev['token_expires_at']) <= $this->sql->time()) {
            throw new ApiError(401, 'expired', 'This device credential has expired. Re-enroll the agent.');
        }
        if (!$this->settings->enabled()) {
            throw new ApiError(403, 'forbidden', 'The endpoint agent service is disabled.');
        }

        return $dev;
    }

    /**
     * online = checked in within offline_after_s; offline = quiet longer, up to stale_after_s; stale = longer still; never = no check-in.
     *
     * @param array<string,mixed> $dev
     * @param array<string,mixed>|null $cfg
     * @return array{state:string,last_checkin_at:?string,offline_since:?string,age_s:?int}
     */
    public function status(array $dev, ?array $cfg = null): array
    {
        $cfg ??= $this->settings->get();
        $last = $dev['last_checkin_at'] ?? null;
        if ($last === null) {
            return ['state' => 'never', 'last_checkin_at' => null, 'offline_since' => null, 'age_s' => null];
        }
        $lastTs = Sql::ts((string) $last);
        $age = max(0, $this->sql->time() - $lastTs);
        $state = $age <= (int) $cfg['offline_after_s'] ? 'online' : ($age <= (int) $cfg['stale_after_s'] ? 'offline' : 'stale');

        return [
            'state' => $state,
            'last_checkin_at' => Sql::iso((string) $last),
            'offline_since' => $state === 'online' ? null : gmdate('Y-m-d\TH:i:s\Z', $lastTs + (int) $cfg['offline_after_s']),
            'age_s' => $age,
        ];
    }

    /** @return array<string,mixed>|null */
    public function find(int $deviceId): ?array
    {
        return $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
    }

    /** Devices that count against max_devices: not revoked, not retired. */
    public function liveCount(): int
    {
        return (int) $this->sql->val('SELECT COUNT(*) FROM endpoint_agent_devices WHERE revoked_at IS NULL AND retired_at IS NULL');
    }

    public static function validMeshNodeId(string $id): bool
    {
        return preg_match('#^node//[A-Za-z0-9@$_-]{16,100}$#', $id) === 1;
    }

    /** Set (manual or agent) or clear the MeshCentral node association. Separate from the asset name by design. */
    public function setMeshNode(int $deviceId, ?string $nodeId, int $userId, string $source = 'manual'): bool
    {
        if ($nodeId === null || $nodeId === '') {
            $this->sql->run('DELETE FROM endpoint_agent_mesh_nodes WHERE device_id = ?', [$deviceId]);

            return true;
        }
        if (!self::validMeshNodeId($nodeId)) {
            return false;
        }
        $this->sql->run('INSERT INTO endpoint_agent_mesh_nodes (device_id, mesh_node_id, source, updated_at, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE mesh_node_id = VALUES(mesh_node_id), source = VALUES(source), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)',
            [$deviceId, $nodeId, $source, $this->sql->utcNow(), $userId]);

        return true;
    }
}
