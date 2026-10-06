<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\ITSM\TicketProblemLinkInterface;
use RivetCore\Tests\Conformance\Reference\InMemoryTicketProblemLink;

final class InMemoryTicketProblemLinkConformanceTest extends TicketProblemLinkConformanceTestCase
{
    private InMemoryTicketProblemLink $link;

    protected function setUp(): void
    {
        $this->link = new InMemoryTicketProblemLink();
    }

    protected function givenTicket(int $id): void
    {
        $this->link->problemByTicket[$id] = null;
    }

    protected function linkedProblem(int $ticketId): ?int
    {
        return $this->link->problemByTicket[$ticketId] ?? null;
    }

    protected function link(): TicketProblemLinkInterface
    {
        return $this->link;
    }
}
