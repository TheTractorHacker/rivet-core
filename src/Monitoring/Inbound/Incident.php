<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The persisted state of ONE monitor of one source (one row per source + monitor, kept after recovery so a late stale delivery is
 * recognised). Mutable, plain data; stores persist it with {@see self::toArray()} / {@see self::fromArray()}.
 *
 * state: "healthy" (no ticket, nothing counting), "pending" (DOWN seen but the open threshold is not reached or the flap guard holds it
 * back), "open" (a ticket is open: ticketId is set).
 *
 * @api
 */
final class Incident
{
    public const HEALTHY = 'healthy';
    public const PENDING = 'pending';
    public const OPEN = 'open';

    /** @param list<int> $opens Unix times this monitor opened a ticket, newest last, pruned to the flap window by the engine */
    public function __construct(
        public readonly string $source,
        public readonly string $monitorKey,
        public string $state = self::HEALTHY,
        public ?int $ticketId = null,
        public ?int $lastTicketId = null,
        public int $consecutiveDown = 0,
        public ?int $downSince = null,
        public ?int $lastEventAt = null,
        public ?int $lastStatus = null,
        public ?int $lastNoteAt = null,
        public array $opens = [],
        public ?int $clientId = null,
        public ?int $assetId = null,
        public string $monitorName = '',
    ) {
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /** @param array<string,mixed> $a */
    public static function fromArray(array $a): self
    {
        $int = static fn (mixed $v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        $state = in_array($a['state'] ?? null, [self::HEALTHY, self::PENDING, self::OPEN], true) ? (string) $a['state'] : self::HEALTHY;
        $opens = [];
        foreach (is_array($a['opens'] ?? null) ? $a['opens'] : [] as $o) {
            if ($int($o) !== null) {
                $opens[] = (int) $o;
            }
        }

        return new self(
            (string) ($a['source'] ?? ''),
            (string) ($a['monitorKey'] ?? ''),
            $state,
            $int($a['ticketId'] ?? null),
            $int($a['lastTicketId'] ?? null),
            $int($a['consecutiveDown'] ?? 0) ?? 0,
            $int($a['downSince'] ?? null),
            $int($a['lastEventAt'] ?? null),
            $int($a['lastStatus'] ?? null),
            $int($a['lastNoteAt'] ?? null),
            $opens,
            $int($a['clientId'] ?? null),
            $int($a['assetId'] ?? null),
            (string) ($a['monitorName'] ?? ''),
        );
    }
}
