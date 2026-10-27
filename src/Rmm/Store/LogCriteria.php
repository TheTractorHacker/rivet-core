<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * A log search, already validated by the caller (bounds, levels, text lengths). Text filters are plain substrings: the store escapes them.
 *
 * @api
 */
final class LogCriteria
{
    /**
     * @param string $fromUtc UTC database datetime, inclusive
     * @param string $toUtc UTC database datetime, inclusive
     * @param int $deviceId 0 = any device
     * @param int $clientId 0 = any client
     * @param list<int>|null $visibleClientIds null = every client; the caller returns an empty result itself for an empty list
     * @param int|null $maxLevel this severity number or a more severe (lower) one
     */
    public function __construct(
        public readonly string $fromUtc,
        public readonly string $toUtc,
        public readonly int $deviceId = 0,
        public readonly int $clientId = 0,
        public readonly ?array $visibleClientIds = null,
        public readonly ?int $maxLevel = null,
        public readonly ?string $sourceContains = null,
        public readonly ?int $code = null,
        public readonly ?string $messageContains = null,
    ) {
    }
}
