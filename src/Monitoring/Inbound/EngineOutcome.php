<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * What one delivery did. {@see self::$action} is one of the constants (a fixed token, safe to put in a response). {@see self::$ticketId} is for the
 * edition's own log in-process only: {@see InboundHandler} never sends it to the sender, and a stale or duplicate delivery carries none.
 *
 * @api
 */
final class EngineOutcome
{
    public const OPENED = 'opened';
    public const NOTED = 'noted';
    public const RESOLVED = 'resolved';
    /** A person already resolved/closed the ticket: the incident is closed, nothing reopened. */
    public const CLOSED_BY_HUMAN = 'closed_by_human';
    /** A DOWN below the open threshold. */
    public const WAITING = 'waiting';
    /** Flap guard: no new ticket. */
    public const FLAP_SUPPRESSED = 'flap_suppressed';
    /** A DOWN for an open incident inside the note interval, or an UP/DOWN that changes nothing. */
    public const UNCHANGED = 'unchanged';
    public const DUPLICATE = 'duplicate';
    public const STALE = 'stale';
    public const IGNORED_PENDING = 'ignored_pending';
    public const IGNORED_MAINTENANCE = 'ignored_maintenance';
    public const TEST = 'test';
    /** CORE-API-5: the per-client or overall ticket cap is reached: no ticket of its own, listed on (or counted against) the summary ticket instead. */
    public const CAPPED = 'capped';
    /** Chain A: a sender limited to some clients reported a monitor the map sends to another client; nothing was opened ({@see InboundHandler} answers 403). */
    public const OUT_OF_SCOPE = 'out_of_scope';

    public function __construct(public readonly string $action, public readonly ?int $ticketId = null)
    {
    }
}
