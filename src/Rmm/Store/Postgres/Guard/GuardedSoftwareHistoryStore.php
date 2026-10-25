<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\SoftwareHistoryStore;

/**
 * {@see SoftwareHistoryStore} with the degraded behaviour of {@see HotStoreGuard}: an append that fails is dropped and counted (the current inventory in the
 * control database is already right; the change log misses the entry); reads throw {@see \RivetCore\Rmm\Store\HotStoreUnavailableException}.
 *
 * @internal
 */
final class GuardedSoftwareHistoryStore implements SoftwareHistoryStore
{
    public function __construct(private readonly SoftwareHistoryStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function append(int $deviceId, array $changes, string $atUtc): void
    {
        $this->guard->lossy('software_history', 'append', fn () => $this->inner->append($deviceId, $changes, $atUtc), null, count($changes));
    }

    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array
    {
        return $this->guard->read('software_history', 'forDevice', fn (): array => $this->inner->forDevice($deviceId, $nameLike, $limit, $offset));
    }

    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->guard->read('software_history', 'between', fn (): array => $this->inner->between($deviceId, $fromUtc, $toUtc, $limit));
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->guard->maintenance('software_history', 'deleteOlderThan', fn (): int => $this->inner->deleteOlderThan($cutoffUtc, $limit), 0);
    }
}
