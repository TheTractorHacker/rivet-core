<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Where a rewrap job keeps its progress. Core ships a file store and an in-memory one; an edition with a settings table can store the
 * serialised {@see RewrapState::toArray()} there instead (no Core table is needed).
 *
 * @api
 */
interface RewrapStateStore
{
    public function load(string $job): ?RewrapState;

    public function save(RewrapState $state): void;

    public function clear(string $job): void;
}
