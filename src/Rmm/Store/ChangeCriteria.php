<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * A change-record search, already validated by the caller. Text filters are plain substrings: the store escapes them.
 *
 * @api
 */
final class ChangeCriteria
{
    /**
     * @param string $fromUtc UTC database datetime, inclusive
     * @param string $toUtc UTC database datetime, inclusive
     * @param int $deviceId 0 = any device
     * @param int $clientId 0 = any client
     * @param list<int>|null $visibleClientIds null = every client; the caller returns an empty result itself for an empty list
     * @param int|null $changeType the numeric change type ({@see \RivetCore\Rmm\Change\ChangeProtocol::changeNumber()})
     * @param string|null $anyText matched against target, item, old value, new value and summary
     */
    public function __construct(
        public readonly string $fromUtc,
        public readonly string $toUtc,
        public readonly int $deviceId = 0,
        public readonly int $clientId = 0,
        public readonly ?array $visibleClientIds = null,
        public readonly ?string $kind = null,
        public readonly ?string $watch = null,
        public readonly ?int $changeType = null,
        public readonly ?string $targetContains = null,
        public readonly ?string $itemContains = null,
        public readonly ?string $actorContains = null,
        public readonly ?string $anyText = null,
    ) {
    }
}
