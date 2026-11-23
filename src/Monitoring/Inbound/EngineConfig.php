<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Tunables of the {@see IncidentEngine}. Defaults: open on the first DOWN delivery (Uptime Kuma already retries before it reports
 * DOWN), a repeat note at most every 30 minutes, at most 6 ticket opens per monitor per hour, at most 300 ticket opens per client and 600 in all per hour.
 *
 * @api
 */
final class EngineConfig
{
    public const DEFAULT_MAX_OPENS_PER_HOUR_PER_CLIENT = 300;
    public const DEFAULT_MAX_OPENS_PER_HOUR_ALL = 600;

    /**
     * @param int $minConsecutiveDown open the ticket after this many DOWN deliveries (>= 1)
     * @param int $minDownSeconds     ...or once the outage has lasted this long (0 = off; only the count applies)
     * @param int $noteIntervalSeconds repeat DOWNs add at most one note per interval (0 = a note on every repeat)
     * @param int $maxOpensPerHour    flapping guard: more opens than this per monitor in 3600 s become notes only (0 = guard off)
     * @param MonitorTarget|null $defaultTarget where unmapped monitors go; null = "unassigned"
     * @param int $maxOpensPerHourPerClient CORE-API-5: ticket opens per client (unassigned counts as one client) in an hour, across every monitor and sender;
     *        past it a DOWN opens no ticket of its own but is listed on ONE summary ticket for that client (0 = no cap)
     * @param int $maxOpensPerHourAll CORE-API-5: the same across every client; its summary ticket is unassigned (0 = no cap)
     */
    public function __construct(
        public readonly int $minConsecutiveDown = 1,
        public readonly int $minDownSeconds = 0,
        public readonly int $noteIntervalSeconds = 1800,
        public readonly int $maxOpensPerHour = 6,
        public readonly ?MonitorTarget $defaultTarget = null,
        public readonly string $priority = 'High',
        public readonly string $category = '',
        public readonly string $sourceLabel = 'Uptime Kuma',
        public readonly int $maxOpensPerHourPerClient = self::DEFAULT_MAX_OPENS_PER_HOUR_PER_CLIENT,
        public readonly int $maxOpensPerHourAll = self::DEFAULT_MAX_OPENS_PER_HOUR_ALL,
    ) {
        if ($minConsecutiveDown < 1 || $minConsecutiveDown > 1000) {
            throw new \InvalidArgumentException('minConsecutiveDown must be 1..1000.');
        }
        if ($minDownSeconds < 0 || $noteIntervalSeconds < 0 || $maxOpensPerHour < 0 || $maxOpensPerHourPerClient < 0 || $maxOpensPerHourAll < 0) {
            throw new \InvalidArgumentException('Thresholds must not be negative.');
        }
    }
}
