<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\SoftwareHistoryStore;

/** @internal */
final class DualWriteSoftwareHistoryStore implements SoftwareHistoryStore
{
    public function __construct(private readonly SoftwareHistoryStore $primary, private readonly ?SoftwareHistoryStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function append(int $deviceId, array $changes, string $atUtc): void
    {
        $this->primary->append($deviceId, $changes, $atUtc);
        if ($this->secondary !== null) {
            $this->ctx->secondary('software_history.append', fn () => $this->secondary->append($deviceId, $changes, $atUtc));
        }
    }

    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array
    {
        return $this->primary->forDevice($deviceId, $nameLike, $limit, $offset);
    }

    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array
    {
        return $this->primary->between($deviceId, $fromUtc, $toUtc, $limit);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->ctx->paused ? 0 : $this->primary->deleteOlderThan($cutoffUtc, $limit);
    }
}
