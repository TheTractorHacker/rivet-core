<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * One validated, sanitised delivery from a monitoring source. Every string is already stripped of control characters and truncated to
 * the limits in {@see UptimeKumaPayload}; nothing here is HTML-escaped (the edition escapes when it renders). A "test" event (the
 * source's "Test" button: no heartbeat) has no status and never touches an incident.
 *
 * @api
 */
final class InboundEvent
{
    /**
     * @param list<string> $tags "name" or "name:value", at most {@see UptimeKumaPayload::MAX_TAGS}
     */
    public function __construct(
        public readonly string $source,
        public readonly bool $isTest,
        public readonly ?MonitorStatus $status,
        public readonly string $monitorKey,
        public readonly string $monitorName,
        public readonly ?int $time,
        public readonly string $message = '',
        public readonly string $url = '',
        public readonly string $hostname = '',
        public readonly string $type = '',
        public readonly array $tags = [],
    ) {
    }
}
