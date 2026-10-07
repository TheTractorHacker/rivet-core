<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Authz;

use RivetCore\Contracts\AccessPolicyInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;

/**
 * One authorization decision for the technician REST API, the web handlers and the administration operations, so they cannot
 * drift apart. It composes four things and never an edition's roles: the module switch, the edition's
 * {@see AccessPolicyInterface} (the role-level answer, asked as `can($userId, $ability, 'client', $clientId)`), the edition's
 * {@see RmmTenancyInterface} (which clients the user may see) and the generic reason text of {@see RmmAbility}.
 *
 * The module switch is injected as a closure so the authorizer reads whatever the module switch reads (state file first, database second).
 *
 * Order of the checks, so a denial reveals as little as possible: module on, role may view (any client), the client is inside the
 * user's scope, the specific ability for that client. Administrative abilities skip the view step. A client id of 0 ("no client")
 * is always inside every scope; `$clientId = 0` asks the role-level question.
 *
 * @api
 */
final class RmmAuthorizer
{
    public const NOT_ENABLED = 'The endpoint agent is not enabled.';
    public const NOT_ACTIVE = 'Your account is not active.';
    public const NO_CLIENT_ACCESS = 'You do not have access to this device\'s client.';

    /**
     * @param \Closure():bool $moduleEnabled the effective module switch (edition kill switch AND master switch): RmmModule::enabled()
     */
    public function __construct(
        private readonly AccessPolicyInterface $policy,
        private readonly RmmTenancyInterface $tenancy,
        private readonly \Closure $moduleEnabled,
    ) {
    }

    /** The edition kill switch AND the master switch. */
    public function moduleEnabled(): bool
    {
        return ($this->moduleEnabled)();
    }

    /**
     * @param bool $requireEnabled false for the operations that must work while the module is off (turning it on, saving settings)
     * @return string|null null when allowed, otherwise the reason (safe to show to the caller)
     */
    public function check(int $userId, string $ability, int $clientId, bool $requireEnabled = true): ?string
    {
        if ($requireEnabled && !$this->moduleEnabled()) {
            return self::NOT_ENABLED;
        }
        if ($userId <= 0) {
            return self::NOT_ACTIVE;
        }
        if (!in_array($ability, RmmAbility::all(), true)) {
            return RmmAbility::denial($ability);
        }
        $administrative = RmmAbility::isAdministrative($ability);
        if (!$administrative && !$this->can($userId, RmmAbility::DEVICE_VIEW, 0)) {
            return RmmAbility::denial(RmmAbility::DEVICE_VIEW);
        }
        if ($ability !== RmmAbility::ADMIN && !$this->clientOk($userId, $clientId)) {
            return self::NO_CLIENT_ACCESS;
        }
        if ($ability !== RmmAbility::DEVICE_VIEW && !$this->can($userId, $ability, $clientId)) {
            return RmmAbility::denial($ability);
        }
        if ($ability === RmmAbility::DEVICE_VIEW && $clientId !== 0 && !$this->can($userId, $ability, $clientId)) {
            return self::NO_CLIENT_ACCESS;
        }

        return null;
    }

    public function allowed(int $userId, string $ability, int $clientId, bool $requireEnabled = true): bool
    {
        return $this->check($userId, $ability, $clientId, $requireEnabled) === null;
    }

    /** True when the client is inside the user's scope (no restriction, no client, or listed). */
    public function clientOk(int $userId, int $clientId): bool
    {
        if ($clientId === 0) {
            return true;
        }
        $visible = $this->tenancy->visibleClientIds($userId);

        return $visible === null || in_array($clientId, $visible, true);
    }

    /**
     * Client ids a list may show, or null for "all".
     *
     * @return list<int>|null
     */
    public function visibleClientIds(int $userId): ?array
    {
        return $this->tenancy->visibleClientIds($userId);
    }

    private function can(int $userId, string $ability, int $clientId): bool
    {
        try {
            return $this->policy->can($userId, $ability, 'client', $clientId);
        } catch (\Throwable) {
            return false;   // a policy that throws denies (it must not, but a technician action must never pass on an error)
        }
    }
}
