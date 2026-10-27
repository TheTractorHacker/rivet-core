<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration\DualWrite;

use RivetCore\Rmm\Store\PatchHistoryStore;

/** @internal */
final class DualWritePatchHistoryStore implements PatchHistoryStore
{
    public function __construct(private readonly PatchHistoryStore $primary, private readonly ?PatchHistoryStore $secondary, private readonly DualWriteContext $ctx)
    {
    }

    public function record(array $entry): void
    {
        $this->primary->record($entry);
        if ($this->secondary !== null) {
            $this->ctx->secondary('patch_history.record', fn () => $this->secondary->record($entry));
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
