<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance\Reference;

use RivetCore\ITSM\TicketProblemLinkInterface;

/**
 * A tickets "table" in memory, or one with a named flaw: unlink_any, no_replace, throws_unknown, creates_unknown,
 * unlink_all, link_all.
 */
final class InMemoryTicketProblemLink implements TicketProblemLinkInterface
{
    /** @var array<int,int|null> ticket id => problem id */
    private array $tickets = [];
    private int $next = 500;

    public function __construct(private ?string $flaw = null)
    {
    }

    public function createTicket(): int
    {
        $this->tickets[$this->next] = null;

        return $this->next++;
    }

    public function linkedProblemId(int $ticketId): ?int
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function link(int $ticketId, int $problemId): void
    {
        if (!array_key_exists($ticketId, $this->tickets)) {
            if ($this->flaw === 'throws_unknown') {
                throw new \RuntimeException('No such ticket');
            }
            if ($this->flaw === 'creates_unknown') {
                $this->tickets[$ticketId] = $problemId;
            }

            return;
        }
        if ($this->flaw === 'link_all') {
            foreach ($this->tickets as $id => $_) {
                $this->tickets[$id] = $problemId;
            }

            return;
        }
        if ($this->flaw === 'no_replace' && $this->tickets[$ticketId] !== null) {
            return;
        }
        $this->tickets[$ticketId] = $problemId;
    }

    public function unlink(int $ticketId, int $problemId): void
    {
        if (!array_key_exists($ticketId, $this->tickets)) {
            if ($this->flaw === 'throws_unknown') {
                throw new \RuntimeException('No such ticket');
            }

            return;
        }
        if ($this->flaw === 'unlink_all') {
            foreach ($this->tickets as $id => $p) {
                if ($p === $problemId) {
                    $this->tickets[$id] = null;
                }
            }

            return;
        }
        if ($this->flaw === 'unlink_any' || $this->tickets[$ticketId] === $problemId) {
            $this->tickets[$ticketId] = null;
        }
    }
}
