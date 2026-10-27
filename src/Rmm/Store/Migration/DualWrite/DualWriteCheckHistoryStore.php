<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\CheckHistoryStore;

/** @internal */
final class DualWriteCheckHistoryStore implements CheckHistoryStore
{
    public function __construct(private readonly CheckHistoryStore $primary, private readonly ?CheckHistoryStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function append(int $deviceId, array $entries): void
    {
        $this->primary->append($deviceId, $entries);
        if ($this->secondary !== null) {
            $this->ctx->secondary('check_history.append', fn () => $this->secondary->append($deviceId, $entries));
        }
    }

    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array
    {
        return $this->primary->since($deviceId, $checkKey, $sinceUtc, $limit);
    }

    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array
    {
        return $this->primary->lastBefore($deviceId, $checkKey, $beforeUtc);
    }

    public function deleteOlderThan(string $cutoffUtc, int $limit): int
    {
        return $this->ctx->paused ? 0 : $this->primary->deleteOlderThan($cutoffUtc, $limit);
    }
}
