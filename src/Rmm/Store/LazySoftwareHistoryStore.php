<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see SoftwareHistoryStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazySoftwareHistoryStore implements SoftwareHistoryStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): SoftwareHistoryStore
    {
        return ($this->resolve)()->softwareHistory();
    }

    public function append(int $deviceId, array $changes, string $atUtc): void
    {
        $this->inner()->append($deviceId, $changes, $atUtc);
    }

    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array
    {
        return $this->inner()->forDevice($deviceId, $nameLike, $limit, $offset);
    }

    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->inner()->between($deviceId, $fromUtc, $toUtc, $limit);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->inner()->deleteOlderThan($cutoffUtc, $limit);
    }
}
