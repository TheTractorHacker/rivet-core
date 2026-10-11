<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\ExternalMetricStoreInterface;
use RivetCore\Testing\ExternalMetricStoreConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedExternalMetricStore;

/** The external store kit against the in-memory store (and the harness target for the store mutants). */
final class ExternalMetricStoreKitTest extends ExternalMetricStoreConformanceTestCase
{
    use Flaw;

    private ?FlawedExternalMetricStore $store = null;

    private function flawed(): FlawedExternalMetricStore
    {
        return $this->store ??= new FlawedExternalMetricStore(self::$flaw);
    }

    protected function store(): ExternalMetricStoreInterface
    {
        return $this->flawed();
    }

    protected function breakStore(): ?callable
    {
        return $this->flawed()->breaker();
    }
}
