<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see CheckHistoryStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyCheckHistoryStore implements CheckHistoryStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): CheckHistoryStore
    {
        return ($this->resolve)()->checkHistory();
    }

    public function append(int $deviceId, array $entries): void
    {
        $this->inner()->append($deviceId, $entries);
    }

    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array
    {
        return $this->inner()->since($deviceId, $checkKey, $sinceUtc, $limit);
    }

    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array
    {
        return $this->inner()->lastBefore($deviceId, $checkKey, $beforeUtc);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->inner()->deleteOlderThan($cutoffUtc, $limit);
    }
}
