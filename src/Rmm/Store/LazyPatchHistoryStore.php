<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * {@see PatchHistoryStore} that picks the real store on first use ({@see RmmDataStores::lazy()}): building the module must not read the settings row, because an
 * off module answers from its state file without touching the database. Every method delegates unchanged. Generated shape; keep it a plain delegate.
 *
 * @internal
 */
final class LazyPatchHistoryStore implements PatchHistoryStore
{
    /** @param \Closure(): RmmDataStores $resolve */
    public function __construct(private readonly \Closure $resolve)
    {
    }

    private function inner(): PatchHistoryStore
    {
        return ($this->resolve)()->patchHistory();
    }

    public function record(array $entry): void
    {
        $this->inner()->record($entry);
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return $this->inner()->forDevice($deviceId, $limit, $offset);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->inner()->deleteOlderThan($cutoffUtc, $limit);
    }
}
