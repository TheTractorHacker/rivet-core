<?php

declare(strict_types=1);

namespace RivetCore\Panel\Contracts;

/**
 * The edition's ticket storage behind the RivetPanel API (RivetIT: tickets, ticket_replies, categories; RivetMSP the same). Core decides WHO may do WHAT
 * (device, client, contact, ownership, limits, text hygiene); the gateway only stores and fetches. It is handed the client and contact Core derived from the
 * device credential and must not look anywhere else for them.
 *
 * Text arriving here is PLAIN TEXT (never HTML): the adapter must escape or convert it as its own renderer requires. Text leaving here is sanitised again by Core,
 * but the adapter must never return internal notes, other clients' data or staff-only fields.
 *
 * Every method must be safe to call with an id that does not exist or does not belong to the client (return null/empty, never throw for that).
 *
 * @api
 */
interface PanelTicketGatewayInterface
{
    /**
     * The categories a panel user of this client may pick.
     *
     * @return list<array{category_id:int,name:string}>
     */
    public function categories(int $clientId): array;

    /**
     * The contact when it exists, is active (not archived) and belongs to the client; null otherwise.
     *
     * @return array{contact_id:int,name:string}|null
     */
    public function contact(int $contactId, int $clientId): ?array;

    /**
     * Create a ticket. $contactId is null for a client-level device: the ticket then belongs to the client only. $requesterHint is an UNVERIFIED display name
     * (the OS user name the panel reported, possibly empty): show it as "reported as", never as identity. $categoryId has been checked against {@see self::categories()}.
     * Returns the new ticket id.
     *
     * Idempotency is Core's job, not the gateway's: Core reserves (device, client_request_id) in its own ledger BEFORE this call, so for one accepted request id
     * it calls this method once (a concurrent retry is answered 409 `request_in_progress` without reaching the gateway; a retry after success replays the stored id).
     * The gateway must therefore (a) create exactly one ticket per call and never merge calls by content, (b) either create the ticket and return its id, or
     * throw having created nothing: Core then releases the reservation and a retry creates the ticket. A gateway that creates a ticket and then throws leaves a ticket
     * Core cannot see, and the retry creates a second one, so do the work in one transaction. The one residual duplicate window is a call that outlives
     * {@see \RivetCore\Panel\PanelProtocol::RESERVATION_TTL_S} (300 s) and is overtaken by a retry; Core then records both tickets for the device and writes a
     * `Panel Ticket Duplicate` audit entry.
     *
     * @param list<array{filename:string,content_type:string,bytes:string}> $attachments already validated by Core
     */
    public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int;

    /**
     * The tickets in $clientId that are (a) requested by $contactId (skip when null) or (b) in $ownedTicketIds, newest activity first, $limit rows from $offset.
     * Core re-checks every returned row against the client and the same rule, so a lax adapter cannot widen access, but it should filter in SQL.
     *
     * @param list<int> $ownedTicketIds
     * @return list<array{ticket_id:int,client_id:int,contact_id:?int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string}> times are UTC "Y-m-d H:i:s"
     */
    public function listTickets(int $clientId, ?int $contactId, array $ownedTicketIds, int $limit, int $offset): array;

    /**
     * One ticket header regardless of owner (Core decides access) or null when it does not exist.
     *
     * @return array{ticket_id:int,client_id:int,contact_id:?int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string}|null
     */
    public function ticket(int $ticketId): ?array;

    /**
     * The visible conversation of a ticket after $afterMessageId (0 = from the start), oldest first, at most $limit. NEVER internal notes: rows flagged internal
     * are dropped by Core, but a conforming adapter does not return them.
     *
     * @return list<array{message_id:int,author:string,author_name:string,body:string,internal:bool,created_at:string,attachments:list<array{filename:string,content_type:string,size:int}>}> author is 'requester' or 'staff'
     */
    public function messages(int $ticketId, int $afterMessageId, int $limit): array;

    /**
     * Append a requester message to the ticket and return its message id. $contactId null = client-level device.
     *
     * @param list<array{filename:string,content_type:string,bytes:string}> $attachments already validated by Core
     */
    public function postMessage(int $ticketId, int $clientId, ?int $contactId, string $body, string $requesterHint, array $attachments): int;
}
