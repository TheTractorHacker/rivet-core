<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * One validated, sanitised delivery from a monitoring source. Every string is already stripped of control characters and truncated to
 * the limits in {@see UptimeKumaPayload}; nothing here is HTML-escaped (the edition escapes when it renders; the engine also strips markup from
 * everything it hands the ticket gateway). A "test" event (the source's "Test" button: no heartbeat) has no status and never touches an incident.
 *
 * `$sender` is the id of the {@see InboundSender} that delivered it ('' = the shared sender of single-secret mode). The parser leaves it empty; the
 * engine sets it ({@see withSender()}) before it asks the {@see MonitorClientMapInterface}, so a map may keep per-sender monitor ids.
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
        public readonly string $sender = '',
    ) {
    }

    /** The same delivery attributed to a sender (see {@see InboundSender::$id}). */
    public function withSender(string $senderId): self
    {
        return $senderId === $this->sender ? $this : new self($this->source, $this->isTest, $this->status, $this->monitorKey, $this->monitorName, $this->time,
            $this->message, $this->url, $this->hostname, $this->type, $this->tags, $senderId);
    }
}
