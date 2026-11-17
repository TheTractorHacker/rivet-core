<?php

declare(strict_types=1);

namespace RivetCore\Monitoring\Inbound;

/**
 * The edition's ticket system as the {@see IncidentEngine} sees it. Throw on a real failure (the engine does not change its state when
 * a call throws, so the next delivery retries); never throw for an unknown or closed ticket id.
 *
 * Contract (see {@see \RivetCore\Testing\TicketGatewayConformanceTestCase}):
 *  - openTicket() creates exactly one new open ticket under the request's target client and returns its positive id. Two calls are two tickets.
 *  - isStillOpen() is true only for an existing ticket that nobody has resolved, closed, archived or deleted. Unknown id: false.
 *  - addNote() appends an internal (staff-only) note. On a ticket that is not open it does nothing (it never reopens).
 *  - resolveTicket() appends the note and resolves/closes the ticket the way the edition does for "resolved"; no-op if it is not open.
 *
 * @api
 */
interface TicketGatewayInterface
{
    public function openTicket(TicketRequest $request): int;

    public function addNote(int $ticketId, string $note): void;

    public function resolveTicket(int $ticketId, string $note): void;

    public function isStillOpen(int $ticketId): bool;
}
