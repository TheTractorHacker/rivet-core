<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Monitoring\Inbound\TicketGatewayInterface;
use RivetCore\Monitoring\Inbound\TicketRequest;

/**
 * Reference implementation (not API) of {@see TicketGatewayInterface}. closeByPerson() plays the part of a technician resolving the ticket in the edition's UI.
 *
 * @internal
 */
class InMemoryTicketGateway implements TicketGatewayInterface
{
    /** @var array<int,array{request:TicketRequest,open:bool,notes:list<string>,resolution:?string}> */
    public array $tickets = [];
    protected int $nextId = 500;
    /** @var \Closure():void|null runs at the start of every call: a test throws here to simulate a failing ticket system */
    public ?\Closure $beforeCall = null;

    public function openTicket(TicketRequest $request): int
    {
        $this->beforeCall?->__invoke();
        $id = $this->nextId++;
        $this->tickets[$id] = ['request' => $request, 'open' => true, 'notes' => [], 'resolution' => null];

        return $id;
    }

    public function addNote(int $ticketId, string $note): void
    {
        $this->beforeCall?->__invoke();
        if (($this->tickets[$ticketId]['open'] ?? false) === true) {
            $this->tickets[$ticketId]['notes'][] = $note;
        }
    }

    public function resolveTicket(int $ticketId, string $note): void
    {
        $this->beforeCall?->__invoke();
        if (($this->tickets[$ticketId]['open'] ?? false) === true) {
            $this->tickets[$ticketId]['notes'][] = $note;
            $this->tickets[$ticketId]['open'] = false;
            $this->tickets[$ticketId]['resolution'] = 'auto';
        }
    }

    public function isStillOpen(int $ticketId): bool
    {
        return ($this->tickets[$ticketId]['open'] ?? false) === true;
    }

    public function closeByPerson(int $ticketId): void
    {
        $this->tickets[$ticketId]['open'] = false;
        $this->tickets[$ticketId]['resolution'] = 'person';
    }

    public function clientOf(int $ticketId): ?int
    {
        return ($this->tickets[$ticketId]['request'] ?? null)?->target->clientId;
    }
}
