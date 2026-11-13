<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * What the engine asks the edition to open. Plain text: subject and body contain sender-controlled strings and must be escaped by the
 * edition for its own storage (the edition's ticket functions usually expect sanitised input).
 *
 * @api
 */
final class TicketRequest
{
    public function __construct(
        public readonly MonitorTarget $target,
        public readonly string $subject,
        public readonly string $body,
        /** Priority NAME (resolve to the edition's own id by name, ids differ per install). */
        public readonly string $priority,
        /** Category NAME or '' for none (resolve by name). */
        public readonly string $category,
        /** Ticket source label, e.g. "Uptime Kuma". */
        public readonly string $sourceLabel,
        /** "source:monitorKey", stable per monitor; the edition may store it for its admin view. */
        public readonly string $incidentKey,
    ) {
    }
}
