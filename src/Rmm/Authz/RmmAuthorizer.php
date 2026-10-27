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
 * Memo: the answers of the policy (`can`) and of the tenancy (`visibleClientIds`) are remembered per user, ability and client for the life
 * of this RmmAuthorizer object, which is one request in a PHP-FPM edition, so a page that asks the same question fifteen times asks the
 * edition once. The module switch is never memoized. What this means for the edition: a role or scope change made after the first
 * answer is not seen by this object until {@see forget()} (or a new object); an edition that keeps the module alive across requests
 * (a worker, a test) calls `forget()` per request or builds the authorizer with `$memoize = false`. The policy and tenancy adapters
 * themselves still take effect immediately (their conformance contract): only this object's remembered copy can lag. A policy that
 * throws is never remembered.
 *
 * @api
 */
final class RmmAuthorizer
{
    public const NOT_ENABLED = 'The endpoint agent is not enabled.';
    public const NOT_ACTIVE = 'Your account is not active.';
    /** The out-of-scope reason with the default terminology; see {@see noClientAccess()} for the configured one. */
    public const NO_CLIENT_ACCESS = 'You do not have access to this device\'s client.';

    /**
     * @param \Closure():bool $moduleEnabled the effective module switch (edition kill switch AND master switch): RmmModule::enabled()
     * @param string $clientLabel what the edition calls a client in user-facing text ("client", RivetIT: "department")
     * @param array<string,string> $reasons optional per-ability denial text (ability constant => sentence) that replaces the generic
     *        {@see RmmAbility::denial()} wording, e.g. to name the role or setting that grants it; unknown keys are ignored. Keep it free of
     *        anything that reveals more than the generic text does (the reason is shown to the caller)
     * @param bool $memoize remember policy and tenancy answers on this object, see the class comment; false asks the edition every time
     */
    public function __construct(
        private readonly AccessPolicyInterface $policy,
        private readonly RmmTenancyInterface $tenancy,
        private readonly \Closure $moduleEnabled,
        private readonly string $clientLabel = 'client',
        private readonly array $reasons = [],
        private readonly bool $memoize = true,
    ) {
    }

    /** @var array<string,bool> "user|ability|client" => the policy's answer */
    private array $canMemo = [];
    /** @var array<int,list<int>|null> user => the tenancy's visible clients */
    private array $visibleMemo = [];

    /**
     * Drop the remembered answers (all, or one user's). Call it after changing a role, a permission or a client scope inside the same
     * request when something later in that request must see it.
     */
    public function forget(?int $userId = null): void
    {
        if ($userId === null) {
            $this->canMemo = [];
            $this->visibleMemo = [];

            return;
        }
        unset($this->visibleMemo[$userId]);
        $prefix = $userId . '|';
        foreach (array_keys($this->canMemo) as $k) {
            if (str_starts_with((string) $k, $prefix)) {
                unset($this->canMemo[$k]);
            }
        }
    }

    /** The reason shown when a device's client is outside the caller's scope, in the configured terminology. */
    public function noClientAccess(): string
    {
        return 'You do not have access to this device\'s ' . $this->clientLabel . '.';
    }

    /** The denial text of one ability: the edition's override when it gave one, the generic {@see RmmAbility::denial()} otherwise. */
    public function denial(string $ability): string
    {
        $custom = $this->reasons[$ability] ?? null;

        return is_string($custom) && trim($custom) !== '' ? $custom : RmmAbility::denial($ability);
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
            return $this->denial($ability);
        }
        $administrative = RmmAbility::isAdministrative($ability);
        if (!$administrative && !$this->can($userId, RmmAbility::DEVICE_VIEW, 0)) {
            return $this->denial(RmmAbility::DEVICE_VIEW);
        }
        if ($ability !== RmmAbility::ADMIN && !$this->clientOk($userId, $clientId)) {
            return $this->noClientAccess();
        }
        if ($ability !== RmmAbility::DEVICE_VIEW && !$this->can($userId, $ability, $clientId)) {
            return $this->denial($ability);
        }
        if ($ability === RmmAbility::DEVICE_VIEW && $clientId !== 0 && !$this->can($userId, $ability, $clientId)) {
            return $this->noClientAccess();
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
        $visible = $this->visibleClientIds($userId);

        return $visible === null || in_array($clientId, $visible, true);
    }

    /**
     * Client ids a list may show, or null for "all".
     *
     * @return list<int>|null
     */
    public function visibleClientIds(int $userId): ?array
    {
        if (!$this->memoize) {
            return $this->tenancy->visibleClientIds($userId);
        }
        if (!array_key_exists($userId, $this->visibleMemo)) {
            $this->visibleMemo[$userId] = $this->tenancy->visibleClientIds($userId);
        }

        return $this->visibleMemo[$userId];
    }

    private function can(int $userId, string $ability, int $clientId): bool
    {
        $key = $userId . '|' . $ability . '|' . $clientId;
        if ($this->memoize && isset($this->canMemo[$key])) {
            return $this->canMemo[$key];
        }
        try {
            $answer = $this->policy->can($userId, $ability, 'client', $clientId);
            if ($this->memoize) {
                $this->canMemo[$key] = $answer;
            }

            return $answer;
        } catch (\Throwable) {
            return false;   // a policy that throws denies (it must not, but a technician action must never pass on an error)
        }
    }
}
