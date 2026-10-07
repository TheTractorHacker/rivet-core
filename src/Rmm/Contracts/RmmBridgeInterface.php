<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Contracts;

/**
 * The edition's RMM tables: the integration row, per-asset link rows, alerts (with ticket auto-close), the saved-script
 * library and the remote-session log. Implement it on the SAME database connection as the DatabaseInterface Core is given, so
 * its writes take part in Core's transactions.
 *
 * @api
 */
interface RmmBridgeInterface
{
    /** Find or create the synthetic integration row (rmm_integrations.type = $type). Idempotent. Returns its id. */
    public function ensureIntegration(string $type, string $name): int;

    public function integrationExists(int $integrationId, string $type): bool;

    /**
     * Create or update the asset's link row for this integration (unique per asset + integration), deleting a link this agentKey
     * had on a different asset. New rows start with rmm_status 'unknown'.
     *
     * @param array{hostname:string, os_name:string, os_version:string, manufacturer:string, model:string} $facts
     */
    public function upsertLink(int $integrationId, int $assetId, string $agentKey, array $facts): void;

    /** Delete the link row of this agentKey (device retired). */
    public function removeLink(int $integrationId, string $agentKey): void;

    /**
     * Push a check-in into the link: status 'online', last_seen/last_sync now, and rmm_status_changed_at = now when the previous status was not 'online'.
     * Returns false when the asset has no link yet (Core then calls upsertLink() and retries once).
     *
     * @param array{hostname:string, os_version:string, manufacturer:string, model:string, cpu:string, ram_gb:string, logged_in_user:string,
     *              cpu_pct:?int, ram_pct:?int, disk_pct:?int, needs_reboot:bool, last_boot:?string} $health  last_boot is 'Y-m-d H:i:s' or null
     */
    public function applyHealth(int $integrationId, int $assetId, array $health): bool;

    /**
     * Flip these agents' links from 'online' to 'offline' and set rmm_status_changed_at (this feeds the asset_offline automation). Returns rows changed.
     *
     * @param list<string> $agentKeys at most 500 per call
     */
    public function markOffline(int $integrationId, array $agentKeys): int;

    /**
     * Open an alert (status 'new') or return the existing one: (integrationId, alertKey) is unique, so a re-delivered check-in never creates a second row.
     * $severity is 'warning' or 'error'. Returns the alert id.
     *
     * @param array<string, mixed> $raw stored as JSON in raw_data_json
     */
    public function openAlert(int $integrationId, string $alertKey, ?int $assetId, int $clientId, string $severity, string $message, array $raw): int;

    /** Mark the alert resolved and run the edition's existing conservative auto-close of its linked ticket (a no-op when already resolved). */
    public function resolveAlert(int $integrationId, int $alertId): void;

    /** Device transferred: open alerts of this asset and integration follow the new client. */
    public function reassignAlerts(int $integrationId, int $assetId, int $clientId): void;

    /** Body of an enabled PowerShell script in the saved library, or null. */
    public function savedPowerShellScript(int $scriptId): ?string;

    /** One row in the remote-session log. $reference is 'meshcentral:session:<id>'; no URL or token is ever stored. */
    public function recordRemoteSession(int $assetId, int $clientId, int $userId, string $connectionType, string $reference, ?string $ipAddress, ?string $userAgent): void;
}
