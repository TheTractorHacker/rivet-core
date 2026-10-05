<?php

declare(strict_types=1);

namespace RivetCore\ITSM;

/**
 * How an edition attaches its tickets (incidents) to a problem. Tickets are edition-owned, so Core never
 * touches the tickets table: the edition keeps the link wherever it stores tickets.
 */
interface TicketProblemLinkInterface
{
    public function link(int $ticketId, int $problemId): void;

    /** Only removes the link if the ticket is currently linked to this problem. */
    public function unlink(int $ticketId, int $problemId): void;
}
