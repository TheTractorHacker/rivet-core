<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\PatchHistoryStore;

/**
 * {@see PatchHistoryStore} with the degraded behaviour of {@see HotStoreGuard}: a record that fails is dropped and counted (the job result is still accepted).
 *
 * @internal
 */
final class GuardedPatchHistoryStore implements PatchHistoryStore
{
    public function __construct(private readonly PatchHistoryStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function record(array $entry): void
    {
        $this->guard->lossy('patch_history', 'record', fn () => $this->inner->record($entry));
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return $this->guard->read('patch_history', 'forDevice', fn (): array => $this->inner->forDevice($deviceId, $limit, $offset));
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->guard->maintenance('patch_history', 'deleteOlderThan', fn (): int => $this->inner->deleteOlderThan($cutoffUtc, $limit), 0);
    }
}
