<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * What the engine asks the edition to open. Plain text: subject and body contain sender-controlled strings and must be escaped by the
 * edition for its own storage (the edition's ticket functions usually expect sanitised input). Contract since red team 2026-11-21 (Chain A): the
 * engine never hands over markup in a subject, a body or a note (tag-shaped runs are removed, a stray '<' or '>' becomes '‹' or '›'), so a renderer
 * that forgets to escape shows text, not elements; escaping on output stays the edition's job. Sender-chosen wording (a phishing line in a monitor
 * name or message) is still text the sender chose: a sender limited to some clients can only put it in front of its own clients.
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
