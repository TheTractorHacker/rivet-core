<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Contracts\BackgroundGroupMembershipInterface;
use RivetCore\Rmm\Contracts\BackgroundModeConfigInterface;

/** Background Mode configuration and group membership held in plain properties. `$throw` makes every lookup fail, to prove a failing lookup denies. */
final class StubBackgroundConfig implements BackgroundModeConfigInterface, BackgroundGroupMembershipInterface
{
    public bool $throw = false;

    /**
     * @param array<int,string> $clientPolicies client id => policy text (any text, so an unknown value can be tested)
     * @param array<string,list<int>> $members group name => user ids
     */
    public function __construct(public string $mode = '', public string $group = '', public array $clientPolicies = [], public array $members = [])
    {
    }

    public function policyMode(): string
    {
        $this->maybeThrow();

        return $this->mode;
    }

    public function internalGroup(): string
    {
        $this->maybeThrow();

        return $this->group;
    }

    public function clientPolicy(int $clientId): ?string
    {
        $this->maybeThrow();

        return $this->clientPolicies[$clientId] ?? null;
    }

    public function isMember(int $userId, string $group): bool
    {
        $this->maybeThrow();

        return in_array($userId, $this->members[$group] ?? [], true);
    }

    private function maybeThrow(): void
    {
        if ($this->throw) {
            throw new \RuntimeException('lookup failed');
        }
    }
}
