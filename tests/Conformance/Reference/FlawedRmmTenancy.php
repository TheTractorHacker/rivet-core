<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\Testing\InMemoryRmmTenancy;

/** The reference tenancy with an optional named flaw: location_any_client, unknown_client_empty, unrestricted_empty, empty_null, throws_odd, includes_zero. */
final class FlawedRmmTenancy extends InMemoryRmmTenancy
{
    public function __construct(private ?string $flaw = null)
    {
    }

    public function visibleClientIds(int $userId): ?array
    {
        if ($this->flaw === 'throws_odd' && $userId <= 0) {
            throw new \RuntimeException('bad user');
        }
        $ids = parent::visibleClientIds($userId);
        if ($this->flaw === 'unrestricted_empty' && $ids === null) {
            return [];
        }
        if ($this->flaw === 'empty_null' && $ids === []) {
            return null;
        }
        if ($this->flaw === 'includes_zero' && $ids !== null) {
            $ids[] = 0;
        }
        return $ids;
    }

    public function clientName(int $clientId): ?string
    {
        if ($this->flaw === 'throws_odd' && $clientId <= 0) {
            throw new \RuntimeException('bad client');
        }
        $name = parent::clientName($clientId);

        return $name ?? ($this->flaw === 'unknown_client_empty' ? '' : null);
    }

    public function locationInClient(int $locationId, int $clientId): bool
    {
        if ($this->flaw === 'location_any_client') {
            return isset($this->locations[$locationId]);
        }

        return parent::locationInClient($locationId, $clientId);
    }
}
