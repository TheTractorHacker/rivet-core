<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\ITSM\TicketProblemLinkInterface;

/** Reference TicketProblemLinkInterface: a ticket id => problem id map. */
final class InMemoryTicketProblemLink implements TicketProblemLinkInterface
{
    /** @var array<int, ?int> */
    public array $problemByTicket = [];

    public function link(int $ticketId, int $problemId): void
    {
        $this->problemByTicket[$ticketId] = $problemId;
    }

    public function unlink(int $ticketId, int $problemId): void
    {
        if (($this->problemByTicket[$ticketId] ?? null) === $problemId) {
            $this->problemByTicket[$ticketId] = null;
        }
    }
}
