<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately broken variant) of {@see RmmTenancyInterface} over arrays, for Core's own tests and as the model the conformance
 * case was written against.
 *
 * @internal
 */
class InMemoryRmmTenancy implements RmmTenancyInterface
{
    /** @var array<int,string> */
    protected array $clients = [];
    /** @var array<int,int> location id => client id */
    protected array $locations = [];
    /** @var array<int,list<int>> */
    protected array $scope = [];
    protected int $next = 1;

    public function addClient(string $name): int
    {
        $this->clients[$this->next] = $name;

        return $this->next++;
    }

    public function addLocation(int $clientId): int
    {
        $this->locations[$this->next] = $clientId;

        return $this->next++;
    }

    /** @param list<int>|null $clientIds null removes the restriction */
    public function restrictUser(int $userId, ?array $clientIds): void
    {
        if ($clientIds === null) {
            unset($this->scope[$userId]);
        } else {
            $this->scope[$userId] = $clientIds;
        }
    }

    public function visibleClientIds(int $userId): ?array
    {
        return $this->scope[$userId] ?? null;
    }

    public function clientName(int $clientId): ?string
    {
        return $this->clients[$clientId] ?? null;
    }

    public function locationInClient(int $locationId, int $clientId): bool
    {
        return isset($this->locations[$locationId]) && $this->locations[$locationId] === $clientId;
    }
}
