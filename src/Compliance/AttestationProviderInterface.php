<?php

declare(strict_types=1);

namespace RivetCore\Compliance;

interface AttestationProviderInterface
{
    /**
     * The most recent review of each item, keyed by item id.
     *
     * @return array<string, array{reviewed_on:string, next_due_on:?string, reviewer_name:string, note:?string}>
     */
    public function latestPerItem(): array;
}
