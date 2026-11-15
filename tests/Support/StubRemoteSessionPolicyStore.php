<?php

declare(strict_types=1);

namespace RivetCore\Tests\Support;

use RivetCore\Rmm\Contracts\RemoteSessionPolicyStoreInterface;

/** The per-client remote-session policy held in a plain property. `$throw` makes every lookup fail, to prove a failing lookup denies. */
final class StubRemoteSessionPolicyStore implements RemoteSessionPolicyStoreInterface
{
    public bool $throw = false;

    /** @param array<int,string> $clientPolicies client id => policy text (any text, so an unknown value can be tested) */
    public function __construct(public array $clientPolicies = [])
    {
    }

    public function clientPolicy(int $clientId): ?string
    {
        if ($this->throw) {
            throw new \RuntimeException('lookup failed');
        }

        return $this->clientPolicies[$clientId] ?? null;
    }
}
