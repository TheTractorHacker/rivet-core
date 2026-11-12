<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Enrollment;

use RivetCore\Rmm\Support\Sql;

/**
 * Read side of the pending reinstalls of CORE-17: an enrollment that matched a LINKED device by MachineGuid or serial only becomes a pending device of its own
 * (`match_reason` `reinstall_identity`, the device it claims to replace in `match_candidates_json[0].device_id`). Approving it by linking it to that device's
 * asset retires that device. These helpers tell an approval screen what the "link" action will do, and list the pending reinstalls of one device.
 *
 * @api
 */
final class PendingReinstalls
{
    public function __construct(private readonly Sql $sql)
    {
    }

    /**
     * The device id a pending row claims to replace, or null for any other row.
     *
     * @param array<string,mixed> $dev
     */
    public static function claimedDevice(array $dev): ?int
    {
        if ((string) ($dev['match_reason'] ?? '') !== 'reinstall_identity') {
            return null;
        }
        $c = json_decode((string) ($dev['match_candidates_json'] ?? ''), true);
        $id = is_array($c) && isset($c[0]['device_id']) ? (int) $c[0]['device_id'] : 0;

        return $id > 0 ? $id : null;
    }

    /**
     * The linked device a pending reinstall replaces when linking it to `$assetId` is exactly that replacement (the asset is that device's own, same client, the
     * device still linked and not retired or revoked); otherwise null and linking retires nothing.
     *
     * @param array<string,mixed> $new the pending row
     * @return array<string,mixed>|null
     */
    public function replacedDeviceFor(array $new, int $assetId): ?array
    {
        $oldId = self::claimedDevice($new);
        if ($oldId === null || (string) ($new['link_state'] ?? '') !== 'pending_approval') {
            return null;
        }
        $old = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$oldId]);
        if ($old === null || $old['retired_at'] !== null || $old['revoked_at'] !== null || $old['link_state'] !== 'linked' || (int) $old['asset_id'] !== $assetId || (int) $old['client_id'] !== (int) $new['client_id']) {
            return null;
        }

        return $old;
    }

    /**
     * The sentence an approval screen shows next to the "link" action of a pending reinstall, or null when linking retires nothing. Built from ids and a hostname
     * reduced to [A-Za-z0-9._-], never from markup.
     *
     * @param array<string,mixed> $new a pending row
     */
    public function linkConsequence(array $new): ?string
    {
        $oldId = self::claimedDevice($new);
        $old = $oldId === null ? null : $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE device_id = ?', [$oldId]);
        if ($old === null || $this->replacedDeviceFor($new, (int) ($old['asset_id'] ?? 0)) === null) {
            return null;
        }
        $host = (string) preg_replace('/[^A-Za-z0-9._-]/', '', (string) $old['hostname']);

        return 'Linking to asset #' . (int) $old['asset_id'] . ' will retire device ' . (int) $old['device_id'] . ($host !== '' ? " ($host)" : '') . ' and revoke its credential; its queued jobs are cancelled and its other pending reinstalls are rejected.';
    }

    /**
     * The open pending reinstalls of one linked device, oldest first, each with when it first enrolled and from which address.
     *
     * @return list<array{device_id:int,hostname:string,first_seen_at:?string,source_ip:?string}>
     */
    public function of(int $deviceId): array
    {
        $out = [];
        foreach ($this->sql->all("SELECT device_id, hostname, first_seen_at, last_ip FROM endpoint_agent_devices WHERE link_state = 'pending_approval' AND match_reason = 'reinstall_identity'
            AND revoked_at IS NULL AND JSON_VALUE(match_candidates_json, '$[0].device_id') = ? ORDER BY first_seen_at, device_id", [(string) $deviceId]) as $r) {
            $out[] = ['device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'], 'first_seen_at' => Sql::iso($r['first_seen_at'] === null ? null : (string) $r['first_seen_at']), 'source_ip' => $r['last_ip'] === null ? null : (string) $r['last_ip']];
        }

        return $out;
    }
}
