<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Capacity\TokenBucketStoreInterface;
use RivetCore\Testing\TokenBucketStoreConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\FlawedTokenBucketStore;

/** The kit against the in-memory reference store (and the harness target for the token bucket mutants). */
final class TokenBucketStoreKitTest extends TokenBucketStoreConformanceTestCase
{
    use Flaw;

    private ?FlawedTokenBucketStore $store = null;

    protected function store(): TokenBucketStoreInterface
    {
        return $this->store ??= new FlawedTokenBucketStore(self::$flaw);
    }

    protected function breakStore(): ?callable
    {
        $store = $this->store();
        \assert($store instanceof FlawedTokenBucketStore);

        return $store->breakIt();
    }
}
