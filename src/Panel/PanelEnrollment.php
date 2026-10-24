<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Enrollment\AttemptLog;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Support\Sql;

/**
 * RivetPanel enrollment: the RMM enrollment-token mechanism (`endpoint_agent_enrollment_tokens`, hashed secret, expiry, atomic use counting, revocation, the
 * DB-backed attempt log and its rate limit) with `kind = 'panel'`, tied to a client and optionally a contact, issuing a per-device bearer credential stored hashed in
 * `endpoint_agent_devices`. A panel enrollment token never parses as an RMM one (prefix `rvtp1`), and the RMM enrollment refuses any token whose kind is not 'agent'.
 *
 * @api
 */
final class PanelEnrollment
{
    private const EXHAUSTED = 'This enrollment token has no uses left.';

    public function __construct(
        private readonly Sql $sql,
        private readonly RmmTenancyInterface $tenancy,
        private readonly PanelTicketGatewayInterface $gateway,
        private readonly PanelSettings $settings,
        private readonly RmmAuditInterface $audit,
        private readonly AttemptLog $attempts,
        private readonly PanelLimits $limits,
    ) {
    }

    // ------------------------------------------------------------------ enrollment tokens (administrator side)

    /**
     * Mint an enrollment token: single use by default (one machine), or a capped multi-use "fleet" token for a mass deployment
     * (an RMM push of one installer to many PCs). A fleet token is short-lived, scoped to one client (and optionally one contact),
     * and every device it enrolls is still individually revocable.
     *
     * @param int|null $contactId the person this panel belongs to; null = a client-level device
     * @param int $maxUses machines this token may enrol (1 = single use), clamped to 1..PanelLimits::$enrollMaxUses
     * @return array{token_id:int,token:string,expires_at:string} the plaintext token is shown once and never stored
     * @throws \InvalidArgumentException when the client does not exist or the contact is not an active contact of the client
     */
    public function createToken(int $clientId, ?int $contactId, int $ttlHours, string $label, int $userId, int $maxUses = 1): array
    {
        if ($this->tenancy->clientName($clientId) === null) {
            throw new \InvalidArgumentException('Choose the client the panel belongs to.');
        }
        if ($contactId !== null && $this->gateway->contact($contactId, $clientId) === null) {
            throw new \InvalidArgumentException('That contact does not belong to this client.');
        }
        $ttlHours = max(1, min($ttlHours > 0 ? $ttlHours : PanelProtocol::ENROLL_DEFAULT_TTL_H, $this->limits->enrollMaxTtlH));
        $maxUses = max(1, min($maxUses, $this->limits->enrollMaxUses));
        $selector = bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(20));
        $expires = $this->sql->utcAt($ttlHours * 3600);
        $id = $this->sql->insert('INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, label, client_id, location_id, ring, expires_at, max_uses, created_by, created_at, kind, contact_id)
            VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)',
            [$selector, hash('sha256', $secret), mb_substr(PanelText::output($label, false), 0, 100), $clientId, 'stable', $expires, $maxUses, $userId, $this->sql->utcNow(), PanelProtocol::KIND, $contactId]);
        $this->audit->record('Panel Enrollment Token Created', "Panel enrollment token $id for client $clientId" . ($contactId !== null ? " and contact $contactId" : ' (client-level)') . ($maxUses > 1 ? " (fleet token, up to $maxUses devices)" : '') . " created by user $userId", $clientId, $id);

        return ['token_id' => $id, 'token' => PanelProtocol::ENROLL_TOKEN_PREFIX . '.' . $selector . '.' . $secret, 'expires_at' => $expires];
    }

    /** Revoke an unused panel enrollment token. An RMM token id is not touched. */
    public function revokeToken(int $tokenId, int $userId): bool
    {
        $row = $this->sql->one('SELECT client_id FROM endpoint_agent_enrollment_tokens WHERE token_id = ? AND kind = ?', [$tokenId, PanelProtocol::KIND]);
        if ($row === null) {
            return false;
        }
        $changed = $this->sql->run('UPDATE endpoint_agent_enrollment_tokens SET revoked_at = ?, revoked_by = ? WHERE token_id = ? AND kind = ? AND revoked_at IS NULL',
            [$this->sql->utcNow(), $userId, $tokenId, PanelProtocol::KIND]) > 0;
        if ($changed) {
            $this->audit->record('Panel Enrollment Token Revoked', "Panel enrollment token $tokenId revoked by user $userId", (int) $row['client_id'], $tokenId);
        }

        return $changed;
    }

    // ------------------------------------------------------------------ the endpoint

    /**
     * @param array<string,mixed> $body the decoded request body
     * @return array<string,mixed> the 201 body
     * @throws ApiError
     */
    public function enroll(array $body, string $ip): array
    {
        $this->checkRate($ip);
        $tokenStr = $body['enrollment_token'] ?? null;
        $dev = $body['device'] ?? null;
        if (!is_string($tokenStr) || !is_array($dev) || ($dev !== [] && array_is_list($dev))) {
            $this->recordAttempt($ip, false, 'malformed', '');
            throw new ApiError(422, 'invalid', 'enrollment_token and device are required');
        }
        $token = $this->authenticateToken($tokenStr, $ip);
        $selector = (string) $token['token_selector'];
        try {
            $d = $this->validateDevice($dev);
        } catch (ApiError $e) {
            $this->recordAttempt($ip, false, 'invalid_device', $selector);
            throw $e;
        }
        $clientId = (int) $token['client_id'];
        $contactId = $token['contact_id'] !== null ? (int) $token['contact_id'] : null;
        $contact = null;
        if ($contactId !== null) {
            $contact = $this->gateway->contact($contactId, $clientId);
            if ($contact === null) {
                $this->recordAttempt($ip, false, 'contact_invalid', $selector);
                throw new ApiError(403, 'contact_invalid', 'The contact this enrollment token was issued for is no longer available.');
            }
        }

        try {
            $result = $this->sql->transaction(function () use ($d, $token, $ip): array {
                $now = $this->sql->utcNow();
                $used = $this->sql->run('UPDATE endpoint_agent_enrollment_tokens SET use_count = use_count + 1, last_used_at = ?
                    WHERE token_id = ? AND kind = ? AND use_count < max_uses AND revoked_at IS NULL AND expires_at > ?', [$now, $token['token_id'], PanelProtocol::KIND, $now]);
                if ($used !== 1) {
                    throw new ApiError(403, 'forbidden', self::EXHAUSTED);
                }

                return $this->placeDevice($d, $token, $ip);
            });
        } catch (ApiError $e) {
            $this->recordAttempt($ip, false, $e->errCode === 'forbidden' && $e->getMessage() === self::EXHAUSTED ? 'exhausted' : $e->errCode, $selector);
            throw $e;
        }
        $this->recordAttempt($ip, true, $result['event'], $selector);
        $this->audit->record('Panel Enrolled', 'Panel device ' . $result['device_id'] . ' (' . $d['hostname'] . ') ' . $result['event'] . ' with token ' . $selector
            . ($contactId !== null ? ", contact $contactId" : ', client-level'), $clientId, (int) $result['device_id']);

        $allow = $contactId !== null || $this->settings->allowClientLevelTickets($clientId);

        return [
            'device_id' => $result['device_id'],
            'device_token' => $result['device_token'],
            'client' => ['client_id' => $clientId, 'name' => PanelText::output($this->tenancy->clientName($clientId) ?? '', false)],
            'contact' => $contact === null ? null : ['contact_id' => $contactId, 'name' => PanelText::output($contact['name'], false)],
            'can_create_tickets' => $allow,
            'limits' => $this->limits->describe(),
            'server_time' => $this->sql->isoNow(),
        ];
    }

    private function checkRate(string $ip): void
    {
        $r = $this->attempts->byAddress(PanelProtocol::ENROLL_RATE_SALT, $ip, PanelProtocol::ENROLL_RATE_WINDOW_S);
        if ($r['failures'] >= PanelProtocol::ENROLL_RATE_MAX_FAILURES || $r['total'] >= PanelProtocol::ENROLL_RATE_MAX_ATTEMPTS) {
            throw new ApiError(429, 'rate_limited', 'Too many enrollment attempts. Try again later.', ['Retry-After' => (string) PanelProtocol::ENROLL_RATE_WINDOW_S]);
        }
    }

    private function recordAttempt(string $ip, bool $ok, string $reason, string $selector): void
    {
        $this->attempts->record(PanelProtocol::ENROLL_RATE_SALT, $ip, $ok, 'panel_' . $reason, $selector);
        if (!$ok) {
            $this->audit->record('Panel Enrollment Failed', 'Panel enrollment attempt rejected (' . $reason . ') from ' . substr($ip, 0, 64) . ($selector !== '' ? ' using token ' . substr($selector, 0, 12) : ''), 0, 0);
        }
    }

    /** @return array<string,mixed> the token row; every failure is a 401 and is recorded */
    private function authenticateToken(string $tokenStr, string $ip): array
    {
        $parts = explode('.', $tokenStr);
        $selector = '';
        $row = null;
        if (count($parts) === 3 && $parts[0] === PanelProtocol::ENROLL_TOKEN_PREFIX && preg_match(PanelProtocol::ENROLL_TOKEN_SELECTOR_RE, $parts[1]) === 1 && preg_match(PanelProtocol::ENROLL_TOKEN_SECRET_RE, $parts[2]) === 1) {
            $selector = $parts[1];
            $row = $this->sql->one('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector = ?', [$selector]);
        }
        $stored = (string) ($row['token_hash'] ?? str_repeat('0', 64));
        $secretOk = hash_equals($stored, hash('sha256', $parts[2] ?? ''));   // always compared, so a miss costs what a near miss costs
        if ($row === null || !$secretOk || ($row['kind'] ?? PanelProtocol::KIND_AGENT) !== PanelProtocol::KIND) {
            $this->recordAttempt($ip, false, 'invalid_token', $selector);
            throw new ApiError(401, 'invalid_token', 'Invalid enrollment token.');
        }
        if ($row['revoked_at'] !== null) {
            $this->recordAttempt($ip, false, 'revoked', $selector);
            throw new ApiError(401, 'revoked', 'This enrollment token was revoked.');
        }
        if (Sql::ts((string) $row['expires_at']) <= $this->sql->time()) {
            $this->recordAttempt($ip, false, 'expired', $selector);
            throw new ApiError(401, 'expired', 'This enrollment token has expired.');
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $dev
     * @return array{install_id:string,hostname:string,os:string,os_version:string,app_version:string}
     * @throws ApiError
     */
    private function validateDevice(array $dev): array
    {
        $install = $dev['install_id'] ?? null;
        if (!is_string($install) || preg_match(PanelProtocol::INSTALL_ID_RE, strtolower($install)) !== 1) {
            throw new ApiError(422, 'invalid', 'device.install_id must be a UUID.');
        }
        $os = $dev['os'] ?? null;
        if (!is_string($os) || !in_array($os, PanelProtocol::OSES, true)) {
            throw new ApiError(422, 'invalid', 'device.os must be one of ' . implode(', ', PanelProtocol::OSES) . '.');
        }

        return [
            'install_id' => strtolower($install),
            'hostname' => PanelText::input($dev['hostname'] ?? null, 'device.hostname', 200, false),
            'os' => $os,
            'os_version' => PanelText::input($dev['os_version'] ?? '', 'device.os_version', 200, false, true),
            'app_version' => PanelText::input($dev['app_version'] ?? '', 'device.app_version', 40, false, true),
        ];
    }

    /**
     * @param array{install_id:string,hostname:string,os:string,os_version:string,app_version:string} $d
     * @param array<string,mixed> $token
     * @return array{device_id:int,device_token:string,event:string}
     */
    private function placeDevice(array $d, array $token, string $ip): array
    {
        $now = $this->sql->utcNow();
        $plain = PanelProtocol::DEVICE_TOKEN_PREFIX . bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $expires = $this->sql->utcAt(PanelProtocol::DEVICE_TOKEN_VALID_DAYS * 86400);
        $clientId = (int) $token['client_id'];
        $contactId = $token['contact_id'] !== null ? (int) $token['contact_id'] : null;

        $dev = $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE install_id = ? FOR UPDATE', [$d['install_id']]);
        if ($dev !== null) {
            // An RMM agent's install id, or another client's panel, is never taken over by a panel enrollment.
            if (($dev['kind'] ?? PanelProtocol::KIND_AGENT) !== PanelProtocol::KIND || (int) $dev['client_id'] !== $clientId) {
                throw new ApiError(409, 'conflict', 'This install id is already bound to a different machine.');
            }
            if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
                throw new ApiError(403, 'forbidden', 'This device was ' . ($dev['revoked_at'] !== null ? 'revoked' : 'retired') . '. An administrator must allow re-enrollment first.');
            }
            $this->sql->run('UPDATE endpoint_agent_devices SET hostname = ?, os = ?, os_version = ?, agent_version = ?, token_hash = ?, token_issued_at = ?, token_expires_at = ?,
                enroll_count = enroll_count + 1, enrolled_via_token_id = ?, last_ip = ?, contact_id = ? WHERE device_id = ? AND kind = ?',
                [$d['hostname'], $d['os'], $d['os_version'], $d['app_version'], $hash, $now, $expires, $token['token_id'], substr($ip, 0, 64), $contactId, $dev['device_id'], PanelProtocol::KIND]);

            return ['device_id' => (int) $dev['device_id'], 'device_token' => $plain, 'event' => 're-enrolled'];
        }
        $id = $this->sql->insert('INSERT INTO endpoint_agent_devices (install_id, hostname, os, os_version, arch, agent_version, asset_id, client_id, location_id, ring, link_state, match_reason,
            token_hash, token_issued_at, token_expires_at, enrolled_via_token_id, first_seen_at, last_ip, created_at, kind, contact_id)
            VALUES (?, ?, ?, ?, \'\', ?, NULL, ?, 0, \'stable\', ?, \'panel\', ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['install_id'], $d['hostname'], $d['os'], $d['os_version'], $d['app_version'], $clientId, PanelProtocol::LINK_STATE, $hash, $now, $expires, $token['token_id'], $now, substr($ip, 0, 64), $now, PanelProtocol::KIND, $contactId]);

        return ['device_id' => $id, 'device_token' => $plain, 'event' => 'enrolled'];
    }
}
