<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * What one delivery did. {@see self::$action} is one of the constants; safe to put in a response (fixed tokens and a ticket id).
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

    public function __construct(public readonly string $action, public readonly ?int $ticketId = null)
    {
    }
}
