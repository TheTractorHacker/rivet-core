<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\ITSM\TicketProblemLinkInterface;
use RivetCore\Testing\TicketProblemLinkConformanceTestCase;
use RivetCore\Tests\Conformance\Reference\InMemoryTicketProblemLink;

/** The kit against an in-memory tickets store (and the harness target for the ticket-link mutants). */
final class TicketProblemLinkKitTest extends TicketProblemLinkConformanceTestCase
{
    use Flaw;

    private ?InMemoryTicketProblemLink $store = null;

    private function store(): InMemoryTicketProblemLink
    {
        return $this->store ??= new InMemoryTicketProblemLink(self::$flaw);
    }

    protected function links(): TicketProblemLinkInterface
    {
        return $this->store();
    }

    protected function createTicket(): int
    {
        return $this->store()->createTicket();
    }

    protected function linkedProblemId(int $ticketId): ?int
    {
        return $this->store()->linkedProblemId($ticketId);
    }
}
