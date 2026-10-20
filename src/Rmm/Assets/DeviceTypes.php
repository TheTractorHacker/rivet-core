<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Assets;

use RivetCore\Rmm\Support\Sql;

/**
 * The database side of the device type: the installer's choice (kept by token) and the type detected from the agent's hardware facts (kept on the
 * device). Every read and write tolerates an installation where migration 0036 has not been applied yet (the columns are then simply absent: the
 * old behaviour), because an optional feature must never break enrollment or a check-in.
 *
 * @api
 */
final class DeviceTypes
{
    public function __construct(private readonly Sql $sql)
    {
    }

    /** The label an installer was issued with, or null for "auto" / unknown token / column missing. */
    public function hintForToken(?int $tokenId): ?string
    {
        if ($tokenId === null || $tokenId <= 0) {
            return null;
        }
        try {
            $v = $this->sql->val('SELECT device_type FROM endpoint_agent_installer_options WHERE token_id = ?', [$tokenId]);
        } catch (\Throwable) {
            return null;
        }

        return DeviceTypeClassifier::validLabel($v) ? (string) $v : null;
    }

    /** Store a device's detected type (a valid label only). */
    public function saveDetected(int $deviceId, string $type): void
    {
        if (!DeviceTypeClassifier::validLabel($type)) {
            return;
        }
        try {
            $this->sql->run('UPDATE endpoint_agent_devices SET detected_type = ? WHERE device_id = ?', [$type, $deviceId]);
        } catch (\Throwable) {
            // migration 0036 not applied yet
        }
    }

    /**
     * The type to give an asset made for (or filled from) this device: the installer's choice when there is one, else the detected type, else the
     * classifier's verdict on the stored os_version alone (the pre-rc.29 rule).
     *
     * @param array<string,mixed> $dev an endpoint_agent_devices row
     * @return array{hint:?string,detected:string,type:string}
     */
    public function forDevice(array $dev): array
    {
        $hint = $this->hintForToken(isset($dev['enrolled_via_token_id']) ? (int) $dev['enrolled_via_token_id'] : null);
        $detected = isset($dev['detected_type']) && DeviceTypeClassifier::validLabel($dev['detected_type'])
            ? (string) $dev['detected_type']
            : DeviceTypeClassifier::classify(['os' => $dev['os'] ?? null, 'os_version' => $dev['os_version'] ?? '']);

        return ['hint' => $hint, 'detected' => $detected, 'type' => DeviceTypeClassifier::choose($hint, $detected)];
    }
}
