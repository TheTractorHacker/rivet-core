<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Testing\RmmTenancyConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedRmmTenancy;

/** The kit against the in-memory tenancy (and the harness target for the tenancy mutants). */
final class RmmTenancyKitTest extends RmmTenancyConformanceTestCase
{
    use Flaw;

    private ?FlawedRmmTenancy $store = null;

    private function store(): FlawedRmmTenancy
    {
        return $this->store ??= new FlawedRmmTenancy(self::$flaw);
    }

    protected function tenancy(): RmmTenancyInterface
    {
        return $this->store();
    }

    protected function createClient(string $name): int
    {
        return $this->store()->addClient($name);
    }

    protected function createLocation(int $clientId): int
    {
        return $this->store()->addLocation($clientId);
    }

    protected function restrictUser(int $userId, ?array $clientIds): void
    {
        $this->store()->restrictUser($userId, $clientIds);
    }
}
