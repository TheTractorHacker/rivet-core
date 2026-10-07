<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Link;

use RivetCore\Rmm\Contracts\RmmAssetsInterface;
use RivetCore\Rmm\Contracts\RmmBridgeInterface;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Rmm\Settings\RmmSettings;
use RivetCore\Rmm\Support\Sql;

/**
 * Orchestrates the bridge from an agent device to the edition's existing RMM data model: one asset link row per linked asset
 * (integration = the synthetic "rivetit_agent" integration), so devices appear in the edition's RMM views and automations. Every
 * statement against the edition's tables is behind {@see RmmBridgeInterface} / {@see RmmAssetsInterface}; only the endpoint_agent_*
 * reads happen here.
 *
 * @api
 */
final class RmmLinker
{
    public function __construct(
        private readonly Sql $sql,
        private readonly RmmSettings $settings,
        private readonly RmmBridgeInterface $bridge,
        private readonly RmmAssetsInterface $assets,
    ) {
    }

    /** The key a device carries in the edition's link table ("rivetit:<device id>"). Frozen. */
    public static function agentKey(int $deviceId): string
    {
        return RmmProtocol::AGENT_KEY_PREFIX . $deviceId;
    }

    /** Create or update the link row for a device that is linked to an asset, and fill blanks on the asset (never overwrite). */
    public function ensure(int $deviceId, int $assetId): void
    {
        $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$deviceId]);
        if ($dev === null) {
            return;
        }
        $this->bridge->upsertLink($this->settings->integrationId(), $assetId, self::agentKey($deviceId), [
            'hostname' => (string) $dev['hostname'],
            'os_name' => self::osName($dev),
            'os_version' => (string) $dev['os_version'],
            'manufacturer' => (string) $dev['manufacturer'],
            'model' => (string) $dev['model'],
        ]);
        $this->assets->fillBlanks($assetId, [
            'serial' => $dev['serial'] === null ? null : (string) $dev['serial'],
            'model' => $dev['model'] === null ? null : (string) $dev['model'],
            'manufacturer' => $dev['manufacturer'] === null ? null : (string) $dev['manufacturer'],
            'os' => self::osName($dev) . ' ' . $dev['os_version'],
        ]);
    }

    public function drop(int $deviceId): void
    {
        $this->bridge->removeLink($this->settings->integrationId(), self::agentKey($deviceId));
    }

    /**
     * Push a check-in's live data into the link row.
     *
     * @param array<string,mixed> $dev the device row after this check-in's inventory was applied
     * @param array{cpu:?float,mem:?float,disk:?float} $health percentages; null stays null
     */
    public function applyCheckin(array $dev, array $health): void
    {
        if (empty($dev['asset_id']) || $dev['link_state'] !== 'linked') {
            return;
        }
        $integration = $this->settings->integrationId();
        $boot = ($dev['uptime_s'] !== null && (int) $dev['uptime_s'] >= 0) ? gmdate('Y-m-d H:i:s', $this->sql->time() - (int) $dev['uptime_s']) : null;
        $cpuStr = '';
        $ramGb = '';
        $inv = !empty($dev['inventory_json']) ? json_decode((string) $dev['inventory_json'], true) : null;
        if (is_array($inv)) {
            $cpu = is_array($inv['cpu'] ?? null) ? $inv['cpu'] : [];
            $cpuStr = mb_substr((string) ($cpu['model'] ?? ''), 0, 300);
            if (!empty($inv['memory_total_bytes']) && is_numeric($inv['memory_total_bytes'])) {
                $ramGb = (string) round(((float) $inv['memory_total_bytes']) / 1073741824, 1);
            }
        }
        $r = static fn (?float $v): ?int => $v === null ? null : (int) round($v);
        $data = [
            'hostname' => (string) $dev['hostname'],
            'os_version' => (string) $dev['os_version'],
            'manufacturer' => (string) $dev['manufacturer'],
            'model' => (string) $dev['model'],
            'cpu' => $cpuStr,
            'ram_gb' => $ramGb,
            'logged_in_user' => (string) $dev['logged_in_user'],
            'cpu_pct' => $r($health['cpu']),
            'ram_pct' => $r($health['mem']),
            'disk_pct' => $r($health['disk']),
            'needs_reboot' => (bool) (int) ($dev['pending_reboot'] ?? 0),
            'last_boot' => $boot,
        ];
        $assetId = (int) $dev['asset_id'];
        if (!$this->bridge->applyHealth($integration, $assetId, $data)) {
            $this->ensure((int) $dev['device_id'], $assetId);
            $this->bridge->applyHealth($integration, $assetId, $data);
        }
    }

    /** @param array<string,mixed> $dev */
    private static function osName(array $dev): string
    {
        return ($dev['os'] ?? 'windows') === 'linux' ? 'Linux' : 'Windows';
    }
}
