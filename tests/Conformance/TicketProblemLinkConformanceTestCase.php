<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use PHPUnit\Framework\TestCase;
use RivetCore\ITSM\TicketProblemLinkInterface;

/**
 * Behaviour every TicketProblemLinkInterface adapter must have. Implement the two hooks over the edition's tickets.
 */
abstract class TicketProblemLinkConformanceTestCase extends TestCase
{
    /** Make sure ticket $id exists and is not linked. */
    abstract protected function givenTicket(int $id): void;

    /** The problem id the ticket is linked to, or null. */
    abstract protected function linkedProblem(int $ticketId): ?int;

    abstract protected function link(): TicketProblemLinkInterface;

    public function testLinkThenUnlink(): void
    {
        $this->givenTicket(9201);
        $this->link()->link(9201, 55);
        $this->assertSame(55, $this->linkedProblem(9201));
        $this->link()->unlink(9201, 55);
        $this->assertNull($this->linkedProblem(9201));
    }

    public function testUnlinkOnlyRemovesTheLinkToThatProblem(): void
    {
        $this->givenTicket(9202);
        $this->link()->link(9202, 55);
        $this->link()->unlink(9202, 56);
        $this->assertSame(55, $this->linkedProblem(9202), 'unlinking a different problem must leave the link alone');
    }

    public function testLinkingTwiceIsHarmless(): void
    {
        $this->givenTicket(9203);
        $this->link()->link(9203, 57);
        $this->link()->link(9203, 57);
        $this->assertSame(57, $this->linkedProblem(9203));
    }

    public function testUnlinkingAnUnlinkedTicketIsHarmless(): void
    {
        $this->givenTicket(9204);
        $this->link()->unlink(9204, 58);
        $this->assertNull($this->linkedProblem(9204));
    }
}
