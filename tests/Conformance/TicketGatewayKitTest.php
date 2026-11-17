<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Monitoring\Inbound\TicketGatewayInterface;
use RivetCore\Testing\InMemoryTicketGateway;
use RivetCore\Testing\TicketGatewayConformanceTestCase;

/** The kit against the in-memory reference gateway. */
final class TicketGatewayKitTest extends TicketGatewayConformanceTestCase
{
    private ?InMemoryTicketGateway $gw = null;
    private int $clients = 0;

    protected function gateway(): TicketGatewayInterface
    {
        return $this->gw ??= new InMemoryTicketGateway();
    }

    protected function createClient(string $name): int
    {
        return ++$this->clients;
    }

    protected function closeTicketAsPerson(int $ticketId): void
    {
        ($this->gw ??= new InMemoryTicketGateway())->closeByPerson($ticketId);
    }

    protected function ticketClientId(int $ticketId): ?int
    {
        return ($this->gw ??= new InMemoryTicketGateway())->clientOf($ticketId);
    }
}
