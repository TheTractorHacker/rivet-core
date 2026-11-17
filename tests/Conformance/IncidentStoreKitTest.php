<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Monitoring\Inbound\IncidentStoreInterface;
use RivetCore\Testing\IncidentStoreConformanceTestCase;
use RivetCore\Testing\InMemoryIncidentStore;

/** The kit against the in-memory reference store. */
final class IncidentStoreKitTest extends IncidentStoreConformanceTestCase
{
    private ?InMemoryIncidentStore $store = null;

    protected function store(): IncidentStoreInterface
    {
        return $this->store ??= new InMemoryIncidentStore();
    }
}
