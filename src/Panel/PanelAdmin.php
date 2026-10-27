<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Support\Sql;

/**
 * The administrator side for an edition's Deploy page: list panel devices and tokens of a client, revoke a panel device.
 *
 * Panel devices are ordinary rows of `endpoint_agent_devices` (kind 'panel'), so they also appear in the RMM device list and the RMM actions (revoke, allow
 * re-enrollment, rotate credential, retire) work on them unchanged; {@see self::revokeDevice()} is the same state change for an edition that has no RMM
 * module wired. A revoked credential is refused at once.
 *
 * @api
 */
final class PanelAdmin
{
    public function __construct(private readonly Sql $sql, private readonly RmmAuditInterface $audit)
    {
    }

    /**
     * @return list<array{device_id:int,hostname:string,os:string,client_id:int,contact_id:?int,first_seen_at:string,last_ip:?string,revoked_at:?string,token_expires_at:?string,tickets:int}>
     */
    public function devices(int $clientId): array
    {
        $rows = $this->sql->all("SELECT d.device_id, d.hostname, d.os, d.client_id, d.contact_id, d.first_seen_at, d.last_ip, d.revoked_at, d.token_expires_at,
                (SELECT COUNT(*) FROM panel_ticket_owner o WHERE o.device_id = d.device_id) AS tickets
            FROM endpoint_agent_devices d WHERE d.kind = ? AND d.client_id = ? ORDER BY d.device_id DESC LIMIT 1000", [PanelProtocol::KIND, $clientId]);

        return array_map(static fn (array $r): array => [
            'device_id' => (int) $r['device_id'], 'hostname' => (string) $r['hostname'], 'os' => (string) $r['os'], 'client_id' => (int) $r['client_id'],
            'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'], 'first_seen_at' => (string) $r['first_seen_at'], 'last_ip' => $r['last_ip'] === null ? null : (string) $r['last_ip'],
            'revoked_at' => $r['revoked_at'] === null ? null : (string) $r['revoked_at'], 'token_expires_at' => $r['token_expires_at'] === null ? null : (string) $r['token_expires_at'], 'tickets' => (int) $r['tickets'],
        ], $rows);
    }

    /**
     * @return list<array{token_id:int,label:string,contact_id:?int,expires_at:string,use_count:int,revoked_at:?string,created_at:string}>
     */
    public function tokens(int $clientId): array
    {
        $rows = $this->sql->all('SELECT token_id, label, contact_id, expires_at, use_count, revoked_at, created_at FROM endpoint_agent_enrollment_tokens WHERE kind = ? AND client_id = ? ORDER BY token_id DESC LIMIT 500',
            [PanelProtocol::KIND, $clientId]);

        return array_map(static fn (array $r): array => [
            'token_id' => (int) $r['token_id'], 'label' => (string) $r['label'], 'contact_id' => $r['contact_id'] === null ? null : (int) $r['contact_id'], 'expires_at' => (string) $r['expires_at'],
            'use_count' => (int) $r['use_count'], 'revoked_at' => $r['revoked_at'] === null ? null : (string) $r['revoked_at'], 'created_at' => (string) $r['created_at'],
        ], $rows);
    }

    /** Revoke a PANEL device (an RMM agent id is refused). False when it does not exist, is not a panel device or is already revoked. */
    public function revokeDevice(int $deviceId, string $reason, int $userId): bool
    {
        $dev = $this->sql->one('SELECT device_id, client_id FROM endpoint_agent_devices WHERE device_id = ? AND kind = ? AND revoked_at IS NULL', [$deviceId, PanelProtocol::KIND]);
        if ($dev === null) {
            return false;
        }
        $this->sql->run('UPDATE endpoint_agent_devices SET revoked_at = ?, revoked_reason = ? WHERE device_id = ? AND kind = ?', [$this->sql->utcNow(), mb_substr($reason, 0, 100), $deviceId, PanelProtocol::KIND]);
        $this->audit->record('Panel Device Revoked', "Panel device $deviceId revoked by user $userId: " . mb_substr($reason, 0, 100), (int) $dev['client_id'], $deviceId);

        return true;
    }
}
