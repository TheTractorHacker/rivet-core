<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\CheckHistoryStore;

/**
 * {@see CheckHistoryStore} with the degraded behaviour of {@see HotStoreGuard}: an append that fails is dropped and counted (the check state in the control
 * database is already written; only the trend dot is lost); reads throw {@see \RivetCore\Rmm\Store\HotStoreUnavailableException}.
 *
 * @internal
 */
final class GuardedCheckHistoryStore implements CheckHistoryStore
{
    public function __construct(private readonly CheckHistoryStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function append(int $deviceId, array $entries): void
    {
        $this->guard->lossy('check_history', 'append', fn () => $this->inner->append($deviceId, $entries), null, count($entries));
    }

    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array
    {
        return $this->guard->read('check_history', 'since', fn (): array => $this->inner->since($deviceId, $checkKey, $sinceUtc, $limit));
    }

    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array
    {
        return $this->guard->read('check_history', 'lastBefore', fn (): ?array => $this->inner->lastBefore($deviceId, $checkKey, $beforeUtc));
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->guard->maintenance('check_history', 'deleteOlderThan', fn (): int => $this->inner->deleteOlderThan($cutoffUtc, $limit), 0);
    }
}
