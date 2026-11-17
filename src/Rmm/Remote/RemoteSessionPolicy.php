<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

use RivetCore\Rmm\Contracts\RemoteSessionPolicyStoreInterface;

/**
 * What the person at a client's devices is owed when a technician opens a remote session on one of them (RivetMSP's per-client policy). It adds a requirement on top of the
 * ability and switch checks; it never replaces them and never grants anything by itself.
 *
 *  - `allow`: nothing extra.
 *  - `notify`: allowed, and the person at the device must be told (the Rivet Console shows the notice; Core records it).
 *  - `approve`: the person must approve, which Core cannot arrange yet, so it is a refusal.
 *  - no stored policy (or a client of 0): no extra requirement, exactly as before the policy existed.
 *
 * Fail closed: a stored value that is not one of the three, and any exception from the store, deny.
 *
 * @api
 */
final class RemoteSessionPolicy
{
    public const CLIENT_ALLOW = 'allow';
    public const CLIENT_NOTIFY = 'notify';
    public const CLIENT_APPROVE = 'approve';
    public const CLIENT_POLICIES = [self::CLIENT_ALLOW, self::CLIENT_NOTIFY, self::CLIENT_APPROVE];

    public function __construct(private readonly RemoteSessionPolicyStoreInterface $store)
    {
    }

    public function decide(int $clientId): RemoteSessionDecision
    {
        if ($clientId <= 0) {
            return RemoteSessionDecision::allow('no_policy');
        }
        try {
            $p = $this->store->clientPolicy($clientId);
        } catch (\Throwable) {
            return RemoteSessionDecision::deny('not_configured', 'The remote session policy for this client could not be read.');
        }

        return match ($p) {
            null => RemoteSessionDecision::allow('no_policy'),
            self::CLIENT_ALLOW => RemoteSessionDecision::allow('client_allow'),
            self::CLIENT_NOTIFY => RemoteSessionDecision::allow('client_notify', RemoteSessionDecision::NOTICE_NOTIFY),
            self::CLIENT_APPROVE => RemoteSessionDecision::deny('approval_required', 'This client requires the person at the device to approve a remote session, which is not available yet.', RemoteSessionDecision::NOTICE_APPROVE),
            default => RemoteSessionDecision::deny('not_configured', 'The remote session policy for this client is not valid.'),
        };
    }
}
