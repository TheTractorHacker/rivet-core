<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Device;

use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Support\Sql;

/**
 * The small per-device state Phase 1 adds next to endpoint_agent_devices (table rmm_device_state): what the agent announced it can do
 * (capabilities), whether the module last saw the device online or offline (only tracked while the edition listens to events) and the
 * software inventory bookkeeping (hash, resync flag). Kept in its own table so the ten original endpoint_agent_* tables stay exactly
 * as an edition mirrors them.
 *
 * @api
 */
final class DeviceState
{
    public const CAP_SOFTWARE_INVENTORY = 'software_inventory';
    private const CAP_RE = '/^[A-Za-z0-9_.:-]{1,64}$/';
    private const MAX_CAPS = 64;

    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * Normalise the `capabilities` a check-in announced: strings of the allowed alphabet, at most 64 of at most 64 characters, unique and
     * sorted. Anything else is ignored (an announcement can never fail a check-in).
     *
     * @return list<string>|null null when the request had no usable list (the stored value is then left alone)
     */
    public static function cleanCapabilities(mixed $raw): ?array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $c) {
            if (is_string($c) && preg_match(self::CAP_RE, $c) === 1) {
                $out[$c] = $c;
                if (count($out) >= self::MAX_CAPS) {
                    break;
                }
            }
        }
        $out = array_values($out);
        sort($out, SORT_STRING);

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function get(int $deviceId): ?array
    {
        return $this->sql->one('SELECT * FROM rmm_device_state WHERE device_id = ?', [$deviceId]);
    }

    /**
     * @param array<string,mixed>|null $row a row from {@see get()}
     * @return list<string>
     */
    public static function capabilitiesOf(?array $row): array
    {
        $d = $row === null || $row['capabilities_json'] === null ? null : json_decode((string) $row['capabilities_json'], true);

        return is_array($d) ? array_values(array_filter($d, 'is_string')) : [];
    }

    /**
     * Record the announced platform and capabilities when they changed. One PK read; a write only on a change.
     *
     * @param list<string> $caps
     * @return array<string,mixed>|null the state row as it is now (null when nothing is stored)
     */
    public function note(int $deviceId, ?string $platform, array $caps): ?array
    {
        $row = $this->get($deviceId);
        $platform = $platform !== null && in_array($platform, RmmProtocol::PLATFORMS, true) ? $platform : null;
        $json = (string) json_encode($caps);
        if ($row !== null && $row['capabilities_json'] === $json && ($platform === null || $row['platform'] === $platform)) {
            return $row;
        }
        $this->sql->run('INSERT INTO rmm_device_state (device_id, platform, capabilities_json) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE platform = COALESCE(VALUES(platform), platform), capabilities_json = VALUES(capabilities_json)', [$deviceId, $platform, $json]);

        return $this->get($deviceId);
    }

    /**
     * True when the device announced the capability.
     *
     * @param array<string,mixed>|null $row a row from {@see get()}
     */
    public static function announces(?array $row, string $capability): bool
    {
        return in_array($capability, self::capabilitiesOf($row), true);
    }

    /**
     * The device checked in. Returns the time it went offline when this ends an offline period the module had reported, else null.
     *
     * @param array<string,mixed>|null $row the state row when the caller just read it
     */
    public function markOnline(int $deviceId, ?array $row = null): ?string
    {
        $row ??= $this->get($deviceId);
        if ($row === null || $row['presence'] !== 'offline') {
            return null;
        }
        $this->sql->run("UPDATE rmm_device_state SET presence = 'online', presence_at = ? WHERE device_id = ? AND presence = 'offline'", [$this->sql->utcNow(), $deviceId]);

        return Sql::iso($row['presence_at'] === null ? null : (string) $row['presence_at']);
    }

    /** The module reported the device offline (one row per device; idempotent). */
    public function markOffline(int $deviceId): void
    {
        $this->sql->run("INSERT INTO rmm_device_state (device_id, presence, presence_at) VALUES (?, 'offline', ?)
            ON DUPLICATE KEY UPDATE presence = 'offline', presence_at = VALUES(presence_at)", [$deviceId, $this->sql->utcNow()]);
    }

    /** Ask the device for a full software list at its next check-in. */
    public function requestSoftwareResync(int $deviceId): void
    {
        $this->sql->run('INSERT INTO rmm_device_state (device_id, software_resync) VALUES (?, 1) ON DUPLICATE KEY UPDATE software_resync = 1', [$deviceId]);
    }
}
