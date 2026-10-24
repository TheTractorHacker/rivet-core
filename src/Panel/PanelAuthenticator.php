<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Support\Sql;

/**
 * Authenticates a panel device bearer credential. The ONLY thing it accepts is a `rvpd_` token whose device row has kind 'panel'; an RMM agent token fails the
 * format check, and an agent row reached by any other route fails the kind check, both as the same generic invalid_token.
 *
 * 401 codes: invalid_token (unknown, rotated, or not a panel credential), revoked, expired. 403 contact_invalid: the contact the device is bound to was archived,
 * deleted or moved to another client, so the device stops working until an administrator re-enrolls it. 403 client_invalid (1.0.0-rc.38, security review RC36 L6): the
 * client of the device no longer exists for the edition (`RmmTenancyInterface::clientName()` is null: deleted or archived), so a client-level device of an offboarded
 * client cannot go on creating tickets.
 *
 * @api
 */
final class PanelAuthenticator
{
    public function __construct(private readonly Sql $sql, private readonly PanelTicketGatewayInterface $gateway, private readonly ?RmmTenancyInterface $tenancy = null)
    {
    }

    /** @throws ApiError */
    public function authenticate(?string $authHeader, string $ip): PanelPrincipal
    {
        $token = null;
        if ($authHeader !== null && preg_match(PanelProtocol::DEVICE_TOKEN_BEARER_RE, $authHeader, $m) === 1) {
            $token = $m[1];
        }
        $hash = $token === null ? '' : hash('sha256', $token);
        $dev = $hash === '' ? null : $this->sql->one('SELECT * FROM endpoint_agent_devices WHERE token_hash = ?', [$hash]);
        if ($dev === null || !hash_equals((string) $dev['token_hash'], $hash) || ($dev['kind'] ?? PanelProtocol::KIND_AGENT) !== PanelProtocol::KIND) {
            throw new ApiError(401, 'invalid_token', 'Invalid device credential.');
        }
        if ($dev['revoked_at'] !== null || $dev['retired_at'] !== null) {
            throw new ApiError(401, 'revoked', 'This device credential was revoked.');
        }
        if ($dev['token_expires_at'] !== null && Sql::ts((string) $dev['token_expires_at']) <= $this->sql->time()) {
            throw new ApiError(401, 'expired', 'This device credential has expired. Enroll the panel again.');
        }
        $clientId = (int) $dev['client_id'];
        $contactId = $dev['contact_id'] !== null ? (int) $dev['contact_id'] : null;
        if ($this->tenancy !== null && $this->tenancy->clientName($clientId) === null) {
            throw new ApiError(403, 'client_invalid', 'The client this panel belongs to is no longer available.');
        }
        if ($contactId !== null && $this->gateway->contact($contactId, $clientId) === null) {
            throw new ApiError(403, 'contact_invalid', 'The contact this panel belongs to is no longer available.');
        }
        if ((string) ($dev['last_ip'] ?? '') !== substr($ip, 0, 64)) {
            $this->sql->run('UPDATE endpoint_agent_devices SET last_ip = ? WHERE device_id = ? AND kind = ?', [substr($ip, 0, 64), $dev['device_id'], PanelProtocol::KIND]);
        }

        return new PanelPrincipal((int) $dev['device_id'], $clientId, $contactId, (string) $dev['hostname']);
    }
}
