<?php

declare(strict_types=1);

namespace RivetCore\ITSM;

/**
 * How an edition attaches its tickets (incidents) to a problem. Tickets are edition-owned, so Core never
 * touches the tickets table: the edition keeps the link wherever it stores tickets.
 *
 * Contract (checked by Testing\TicketProblemLinkConformanceTestCase): a ticket belongs to at most one problem, so link() on an
 * already linked ticket replaces the link, and linking twice to the same problem is harmless; neither method throws for a
 * ticket that does not exist or is not linked (they do nothing) and neither touches any other ticket.
 *
 * @api
 */
interface TicketProblemLinkInterface
{
    public function link(int $ticketId, int $problemId): void;

    /** Only removes the link if the ticket is currently linked to this problem. */
    public function unlink(int $ticketId, int $problemId): void;
}
