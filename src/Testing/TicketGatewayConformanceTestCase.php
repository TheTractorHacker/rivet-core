<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Monitoring\Inbound\MonitorTarget;
use RivetCore\Monitoring\Inbound\TicketGatewayInterface;
use RivetCore\Monitoring\Inbound\TicketRequest;

/**
 * Conformance kit for {@see TicketGatewayInterface}. The edition says how to create a client, how a person closes a ticket in its UI and
 * which client a ticket belongs to.
 *
 * Checks: openTicket() returns a positive id, an open ticket under exactly the requested client, and two calls make two tickets;
 * isStillOpen() is false for an unknown id; addNote() keeps the ticket open and never throws for unknown or closed tickets; resolveTicket()
 * closes it; a ticket closed by a person is not open, and neither addNote() nor resolveTicket() reopens it; hostile text (quotes, HTML,
 * NUL-free control-looking text, long strings) is stored without breaking and does not throw.
 *
 * @api
 */
abstract class TicketGatewayConformanceTestCase extends TestCase
{
    abstract protected function gateway(): TicketGatewayInterface;

    /** Create a client; return its id. */
    abstract protected function createClient(string $name): int;

    /** A technician resolves/closes the ticket in the edition's own UI. */
    abstract protected function closeTicketAsPerson(int $ticketId): void;

    /** The client the ticket was filed under (null = unassigned). */
    abstract protected function ticketClientId(int $ticketId): ?int;

    private function request(?int $clientId, string $subject = 'Monitor DOWN: web', string $body = "Monitor: web\nMessage: timeout"): TicketRequest
    {
        return new TicketRequest($clientId === null ? MonitorTarget::unassigned() : MonitorTarget::client($clientId), $subject, $body, 'High', '', 'Uptime Kuma', 'uptime-kuma:7');
    }

    public function testOpenTicketIsOpenUnderTheRequestedClientAndNeverMerges(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $b = $this->createClient('B ' . bin2hex(random_bytes(3)));
        $t1 = $this->gateway()->openTicket($this->request($a));
        $t2 = $this->gateway()->openTicket($this->request($a));
        $t3 = $this->gateway()->openTicket($this->request($b));
        self::assertGreaterThan(0, $t1);
        self::assertCount(3, array_unique([$t1, $t2, $t3]));
        self::assertTrue($this->gateway()->isStillOpen($t1));
        self::assertSame($a, $this->ticketClientId($t1));
        self::assertSame($b, $this->ticketClientId($t3));
    }

    public function testUnassignedIsNotAnyCustomer(): void
    {
        $c = $this->createClient('C ' . bin2hex(random_bytes(3)));
        $t = $this->gateway()->openTicket($this->request(null));
        self::assertTrue($this->gateway()->isStillOpen($t));
        self::assertNotSame($c, $this->ticketClientId($t));
    }

    public function testUnknownTicketIsNotOpenAndNothingThrows(): void
    {
        $g = $this->gateway();
        self::assertFalse($g->isStillOpen(999999991));
        $g->addNote(999999991, 'x');
        $g->resolveTicket(999999991, 'x');
        self::assertFalse($g->isStillOpen(999999991));
    }

    public function testNoteKeepsItOpenAndResolveClosesIt(): void
    {
        $g = $this->gateway();
        $t = $g->openTicket($this->request($this->createClient('N ' . bin2hex(random_bytes(3)))));
        $g->addNote($t, 'Still down');
        self::assertTrue($g->isStillOpen($t));
        $g->resolveTicket($t, 'Recovered');
        self::assertFalse($g->isStillOpen($t));
    }

    public function testATicketAPersonClosedIsNeverReopened(): void
    {
        $g = $this->gateway();
        $t = $g->openTicket($this->request($this->createClient('P ' . bin2hex(random_bytes(3)))));
        $this->closeTicketAsPerson($t);
        self::assertFalse($g->isStillOpen($t));
        $g->addNote($t, 'Still down');
        self::assertFalse($g->isStillOpen($t));
        $g->resolveTicket($t, 'Recovered');
        self::assertFalse($g->isStillOpen($t));
    }

    public function testHostileTextIsStoredWithoutBreakingAnything(): void
    {
        $g = $this->gateway();
        $evil = "'; DROP TABLE tickets; -- <script>alert(1)</script> \" \\ \u{1F600} " . str_repeat('x', 180);
        $t = $g->openTicket($this->request($this->createClient('H ' . bin2hex(random_bytes(3))), $evil, $evil));
        $g->addNote($t, $evil);
        self::assertTrue($g->isStillOpen($t));
        $g->resolveTicket($t, $evil);
        self::assertFalse($g->isStillOpen($t));
    }
}
