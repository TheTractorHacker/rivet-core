<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\DeploymentHistoryStore;

/** @internal */
final class DualWriteDeploymentHistoryStore implements DeploymentHistoryStore
{
    public function __construct(private readonly DeploymentHistoryStore $primary, private readonly ?DeploymentHistoryStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function record(array $entry): void
    {
        $this->primary->record($entry);
        if ($this->secondary !== null) {
            $this->ctx->secondary('deployment_history.record', fn () => $this->secondary->record($entry));
        }
    }

    public function forDevice(int $deviceId, int $limit, int $offset): array
    {
        return $this->primary->forDevice($deviceId, $limit, $offset);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->ctx->paused ? 0 : $this->primary->deleteOlderThan($cutoffUtc, $limit);
    }
}
