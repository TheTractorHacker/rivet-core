<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\BackgroundGroupMembershipInterface;
use RivetCore\Rmm\Contracts\BackgroundModeConfigInterface;

/**
 * Who may open a Background Mode session on a device of a given client, edition-neutral. It answers only the POLICY question; the `background_mode` switch and the
 * `rmm.remote.background` ability are checked beside it by {@see BackgroundSessionService}, and a policy "allow" never replaces them.
 *
 *  - `internal` (RivetIT): no end-user prompt. Allowed for site administrators and for members of the one configured role or group; everybody else is denied. With no group
 *    configured only site administrators pass.
 *  - `per_client` (RivetMSP): the stored policy of the device's client. `allow` = granted, `notify` = granted and the person at the device must be told (the console
 *    shows the notice; Core records it), `approve` = the person must approve, which Core cannot arrange yet, so it is a refusal. A client with no stored policy is DENIED.
 *
 * Deny by default: an unknown or empty mode, an unknown policy value, a client of 0, a missing configuration and any exception from the configuration or the group lookup
 * all deny. Nothing here ever grants to "any signed-in user".
 *
 * @api
 */
final class BackgroundModePolicy
{
    public const MODE_INTERNAL = 'internal';
    public const MODE_PER_CLIENT = 'per_client';
    public const MODES = [self::MODE_INTERNAL, self::MODE_PER_CLIENT];

    public const CLIENT_ALLOW = 'allow';
    public const CLIENT_NOTIFY = 'notify';
    public const CLIENT_APPROVE = 'approve';
    public const CLIENT_POLICIES = [self::CLIENT_ALLOW, self::CLIENT_NOTIFY, self::CLIENT_APPROVE];

    public function __construct(
        private readonly BackgroundModeConfigInterface $config,
        private readonly BackgroundGroupMembershipInterface $groups,
    ) {
    }

    /**
     * @param bool $isSiteAdmin the caller already established that this user holds `rmm.admin` (the policy does not ask the edition itself)
     */
    public function decide(int $userId, int $clientId, bool $isSiteAdmin): BackgroundDecision
    {
        if ($userId <= 0) {
            return BackgroundDecision::deny('not_allowed', 'Background Mode is not allowed for you.');
        }
        try {
            $mode = $this->config->policyMode();
            if ($mode === self::MODE_INTERNAL) {
                return $this->internal($userId, $isSiteAdmin);
            }
            if ($mode === self::MODE_PER_CLIENT) {
                return $this->perClient($clientId);
            }
        } catch (\Throwable) {
            // fall through: a configuration or lookup that fails denies
        }

        return BackgroundDecision::deny('not_configured', 'Background Mode is not configured.');
    }

    private function internal(int $userId, bool $isSiteAdmin): BackgroundDecision
    {
        if ($isSiteAdmin) {
            return BackgroundDecision::allow('internal_admin');
        }
        $group = trim($this->config->internalGroup());
        if ($group !== '' && $this->groups->isMember($userId, $group)) {
            return BackgroundDecision::allow('internal_group');
        }

        return BackgroundDecision::deny('not_allowed', 'Background Mode is not allowed for you.');
    }

    private function perClient(int $clientId): BackgroundDecision
    {
        if ($clientId <= 0) {
            return BackgroundDecision::deny('not_configured', 'Background Mode is not configured for this device.');
        }

        return match ($this->config->clientPolicy($clientId)) {
            self::CLIENT_ALLOW => BackgroundDecision::allow('client_allow'),
            self::CLIENT_NOTIFY => BackgroundDecision::allow('client_notify', BackgroundDecision::NOTICE_NOTIFY),
            self::CLIENT_APPROVE => BackgroundDecision::deny('approval_required', 'This client requires the person at the device to approve a Background Mode session, which is not available yet.', BackgroundDecision::NOTICE_APPROVE),
            default => BackgroundDecision::deny('not_configured', 'Background Mode is not configured for this device.'),
        };
    }
}
