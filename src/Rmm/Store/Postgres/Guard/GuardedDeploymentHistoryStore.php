<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres\Guard;

use RivetCore\Rmm\Store\DeploymentHistoryStore;

/**
 * {@see DeploymentHistoryStore} with the degraded behaviour of {@see HotStoreGuard}: a record that fails is dropped and counted (the job result is still accepted).
 *
 * @internal
 */
final class GuardedDeploymentHistoryStore implements DeploymentHistoryStore
{
    public function __construct(private readonly DeploymentHistoryStore $inner, private readonly HotStoreGuard $guard)
    {
    }

    public function record(array $entry): void
    {
        $this->guard->lossy('deployment_history', 'record', fn () => $this->inner->record($entry));
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return $this->guard->read('deployment_history', 'forDevice', fn (): array => $this->inner->forDevice($deviceId, $limit, $offset));
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->guard->maintenance('deployment_history', 'deleteOlderThan', fn (): int => $this->inner->deleteOlderThan($cutoffUtc, $limit), 0);
    }
}
