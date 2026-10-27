<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\ITSM\TicketProblemLinkInterface;

/**
 * Conformance kit for {@see TicketProblemLinkInterface}. The interface only writes; the edition therefore also tells the
 * case how to READ the link ({@see self::linkedProblemId()}) from wherever it keeps tickets.
 *
 * Checks: link() attaches a ticket to a problem and is idempotent; a ticket belongs to at most one problem, so linking it to
 * another problem replaces the link; unlink() removes the link only when the ticket is linked to THAT problem; neither call
 * touches other tickets or throws for a ticket that does not exist or is not linked.
 *
 * @api
 */
abstract class TicketProblemLinkConformanceTestCase extends TestCase
{
    /** @var list<int> */
    private array $tickets = [];

    abstract protected function links(): TicketProblemLinkInterface;

    /** Create a ticket in the edition's store and return its id. */
    abstract protected function createTicket(): int;

    /** Which problem is this ticket linked to right now (null for none), read from the edition's store, not through the adapter. */
    abstract protected function linkedProblemId(int $ticketId): ?int;

    /** Remove a ticket created by {@see self::createTicket()}. Default: leave it (scratch databases are disposable). */
    protected function deleteTicket(int $ticketId): void
    {
    }

    /** A problem id for the tests. Override when the edition has a foreign key to problems. */
    protected function newProblemId(): int
    {
        return random_int(1_000_000, 2_000_000_000);
    }

    final protected function ticket(): int
    {
        return $this->tickets[] = $this->createTicket();
    }

    protected function tearDown(): void
    {
        foreach ($this->tickets as $id) {
            try {
                $this->deleteTicket($id);
            } catch (\Throwable) {
                // best effort
            }
        }
        $this->tickets = [];
    }

    public function testLinkAttachesTheTicketToTheProblem(): void
    {
        $t = $this->ticket();
        $this->assertNull($this->linkedProblemId($t));
        $p = $this->newProblemId();
        $this->links()->link($t, $p);
        $this->assertSame($p, $this->linkedProblemId($t));
    }

    public function testLinkingTwiceIsHarmless(): void
    {
        $t = $this->ticket();
        $p = $this->newProblemId();
        $this->links()->link($t, $p);
        $this->links()->link($t, $p);
        $this->assertSame($p, $this->linkedProblemId($t));
    }

    public function testLinkingToAnotherProblemReplacesTheLink(): void
    {
        $t = $this->ticket();
        $p1 = $this->newProblemId();
        $p2 = $p1 + 1;
        $this->links()->link($t, $p1);
        $this->links()->link($t, $p2);
        $this->assertSame($p2, $this->linkedProblemId($t), 'a ticket belongs to at most one problem: the newest link wins');
    }

    public function testUnlinkRemovesTheLink(): void
    {
        $t = $this->ticket();
        $p = $this->newProblemId();
        $this->links()->link($t, $p);
        $this->links()->unlink($t, $p);
        $this->assertNull($this->linkedProblemId($t));
    }

    public function testUnlinkOnlyRemovesTheLinkToThatProblem(): void
    {
        $t = $this->ticket();
        $p = $this->newProblemId();
        $this->links()->link($t, $p);
        $this->links()->unlink($t, $p + 1);
        $this->assertSame($p, $this->linkedProblemId($t), 'unlink() for a different problem must leave the link alone');
    }

    public function testUnlinkOfAnUnlinkedTicketIsHarmless(): void
    {
        $t = $this->ticket();
        $this->links()->unlink($t, $this->newProblemId());
        $this->assertNull($this->linkedProblemId($t));
    }

    public function testLinksAreIsolatedPerTicket(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $c = $this->ticket();
        $p = $this->newProblemId();
        $this->links()->link($a, $p);
        $this->links()->link($b, $p);
        $this->assertNull($this->linkedProblemId($c), 'linking other tickets must not touch this one');
        $this->links()->unlink($a, $p);
        $this->assertNull($this->linkedProblemId($a));
        $this->assertSame($p, $this->linkedProblemId($b), 'unlinking one ticket must not unlink another');
    }

    public function testUnknownTicketNeverThrows(): void
    {
        $this->links()->link(2_000_000_000, $this->newProblemId());
        $this->links()->unlink(2_000_000_000, $this->newProblemId());
        $this->links()->unlink(0, 0);
        $this->addToAssertionCount(1);
        $this->assertNull($this->linkedProblemId(2_000_000_000), 'linking a ticket that does not exist must not create one');
    }

    public function testLinkAfterUnlinkWorksAgain(): void
    {
        $t = $this->ticket();
        $p = $this->newProblemId();
        $this->links()->link($t, $p);
        $this->links()->unlink($t, $p);
        $this->links()->link($t, $p);
        $this->assertSame($p, $this->linkedProblemId($t));
    }
}
