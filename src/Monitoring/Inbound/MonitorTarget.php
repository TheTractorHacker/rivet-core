<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Where a monitor's tickets go: exactly one client (and optionally one asset), or "unassigned". A monitor is never mapped to two
 * clients and a target is never guessed: see {@see MonitorClientMapInterface}.
 *
 * @api
 */
final class MonitorTarget
{
    private function __construct(public readonly ?int $clientId, public readonly ?int $assetId)
    {
    }

    public static function client(int $clientId, ?int $assetId = null): self
    {
        if ($clientId < 1) {
            throw new \InvalidArgumentException('A client id must be positive.');
        }

        return new self($clientId, $assetId !== null && $assetId > 0 ? $assetId : null);
    }

    /** No client: the edition files the ticket under its "unassigned" bucket (or its internal queue), never under a customer. */
    public static function unassigned(): self
    {
        return new self(null, null);
    }

    public function isUnassigned(): bool
    {
        return $this->clientId === null;
    }
}
