<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * Maps a monitor to the one client (and optionally asset) its tickets belong to, by monitor id first, then by tag.
 *
 * Contract:
 *  - Return null when the monitor is not mapped. The engine then uses the configured default target, or "unassigned"; it never guesses
 *    from the monitor name or URL.
 *  - A monitor maps to AT MOST ONE client. If its tags match entries for two different clients (or an id entry and a tag entry disagree
 *    on the client) the mapping is ambiguous and the implementation must return null, not pick one. The edition's settings page should
 *    refuse to save a map that makes this possible for the same monitor id.
 *  - Only look at {@see InboundEvent::$monitorKey} and {@see InboundEvent::$tags}; both are sender-controlled (the sender is authenticated
 *    but may be a shared monitor), so only an exact match against the admin's own entries counts.
 *
 * @api
 */
interface MonitorClientMapInterface
{
    public function resolve(InboundEvent $event): ?MonitorTarget;
}
