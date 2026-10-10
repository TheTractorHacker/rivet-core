<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Crypto\RewrapState;
use RivetCore\Crypto\RewrapStateStore;

/**
 * @api
 */
final class InMemoryRewrapStateStore implements RewrapStateStore
{
    /** @var array<string,RewrapState> */
    private array $states = [];

    public function load(string $job): ?RewrapState
    {
        return $this->states[$job] ?? null;
    }

    public function save(RewrapState $state): void
    {
        $this->states[$state->job] = $state;
    }

    public function clear(string $job): void
    {
        unset($this->states[$job]);
    }
}
