<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * What a rewrap run did (this run's counters plus those carried over from a resumed run).
 *
 * @api
 */
final class RewrapReport
{
    /** @param list<string> $failedIds first 100 ids that could not be read (ids only, never values) */
    public function __construct(
        public readonly string $job,
        public readonly bool $dryRun,
        public readonly bool $completed,
        public readonly int $scanned,
        public readonly int $rewrapped,
        public readonly int $skipped,
        public readonly int $conflicts,
        public readonly int $failed,
        public readonly ?string $cursor,
        public readonly array $failedIds = [],
    ) {
    }

    /** Reached the end of the source with nothing unreadable and no lost races: the column is fully on the current key. */
    public function isClean(): bool
    {
        return $this->completed && $this->failed === 0 && $this->conflicts === 0;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
