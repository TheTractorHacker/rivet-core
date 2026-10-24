<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;

/**
 * Reference implementation (not API; tests may extend it to build a deliberately lax variant) of {@see PanelTicketGatewayInterface} over arrays, for Core's own
 * tests and as the model the conformance case was written against. The helper methods (addClientContact, addCategory, addStaffReply, addInternalNote,
 * closeTicket, addTicketFor) play the part of the edition's own screens.
 *
 * @internal
 */
class InMemoryPanelTicketGateway implements PanelTicketGatewayInterface
{
    /** @var array<int,array{contact_id:int,client_id:int,name:string,active:bool}> */
    protected array $contacts = [];
    /** @var array<int,list<array{category_id:int,name:string}>> client id => categories */
    protected array $categories = [];
    /** @var array<int,array<string,mixed>> */
    protected array $tickets = [];
    /** @var array<int,list<array<string,mixed>>> ticket id => messages */
    protected array $messages = [];
    protected int $nextId = 1000;
    /** @var list<array<string,mixed>> every createTicket call, as received (for assertions) */
    public array $created = [];
    /** @var list<array<string,mixed>> every postMessage call, as received */
    public array $posted = [];
    /** @var (\Closure(): void)|null called at the start of every createTicket (a test uses it to interleave another request, or to throw and simulate a failing store) */
    public ?\Closure $duringCreate = null;
    /** @var (\Closure(): int)|null Unix time for the tickets and messages created through createTicket() and postMessage(); null keeps the fixed 2026-10-01 stamps the older tests assert on (the conformance kit sets it, because it checks that the stamps are UTC and current) */
    public ?\Closure $clock = null;

    public function addClientContact(int $clientId, string $name, bool $active = true): int
    {
        $id = $this->nextId++;
        $this->contacts[$id] = ['contact_id' => $id, 'client_id' => $clientId, 'name' => $name, 'active' => $active];

        return $id;
    }

    public function archiveContact(int $contactId): void
    {
        $this->contacts[$contactId]['active'] = false;
    }

    public function addCategory(int $clientId, string $name): int
    {
        $id = $this->nextId++;
        $this->categories[$clientId][] = ['category_id' => $id, 'name' => $name];

        return $id;
    }

    /** A ticket opened in the edition's own UI (not through the panel). */
    public function addTicketFor(int $clientId, ?int $contactId, string $subject, string $body = 'text'): int
    {
        return $this->store($clientId, $contactId, $subject, $body, null, '', []);
    }

    public function addStaffReply(int $ticketId, string $body, string $name = 'Tech', string $at = '2026-10-01 10:00:00'): int
    {
        return $this->push($ticketId, 'staff', $name, $body, false, [], $at);
    }

    public function addInternalNote(int $ticketId, string $body): int
    {
        return $this->push($ticketId, 'staff', 'Tech', $body, true, [], '2026-10-01 10:05:00');
    }

    public function closeTicket(int $ticketId): void
    {
        $this->tickets[$ticketId]['closed'] = true;
        $this->tickets[$ticketId]['status'] = 'Closed';
    }

    /** @return array<string,mixed>|null */
    public function ticketRow(int $ticketId): ?array
    {
        return $this->tickets[$ticketId] ?? null;
    }

    public function categories(int $clientId): array
    {
        return $this->categories[$clientId] ?? [];
    }

    public function contact(int $contactId, int $clientId): ?array
    {
        $c = $this->contacts[$contactId] ?? null;

        return $c !== null && $c['active'] && $c['client_id'] === $clientId ? ['contact_id' => $contactId, 'name' => $c['name']] : null;
    }

    public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int
    {
        if ($this->duringCreate !== null) {
            ($this->duringCreate)();
        }
        $this->created[] = compact('clientId', 'contactId', 'subject', 'body', 'categoryId', 'requesterHint', 'attachments');

        return $this->store($clientId, $contactId, $subject, $body, $categoryId, $requesterHint, $attachments);
    }

    public function listTickets(int $clientId, ?int $contactId, array $ownedTicketIds, int $limit, int $offset): array
    {
        $rows = array_values(array_filter($this->tickets, static fn (array $t): bool => $t['client_id'] === $clientId
            && (($contactId !== null && $t['contact_id'] === $contactId) || in_array($t['ticket_id'], $ownedTicketIds, true))));
        usort($rows, static fn (array $a, array $b): int => [$b['updated_at'], $b['ticket_id']] <=> [$a['updated_at'], $a['ticket_id']]);

        return array_map(fn (array $t): array => $this->header($t), array_slice($rows, $offset, $limit));
    }

    public function ticket(int $ticketId): ?array
    {
        return isset($this->tickets[$ticketId]) ? $this->header($this->tickets[$ticketId]) : null;
    }

    public function messages(int $ticketId, int $afterMessageId, int $limit): array
    {
        $out = [];
        foreach ($this->messages[$ticketId] ?? [] as $m) {
            if ($m['message_id'] > $afterMessageId && !$m['internal']) {
                $out[] = ['message_id' => $m['message_id'], 'author' => $m['author'], 'author_name' => $m['author_name'], 'body' => $m['body'], 'internal' => false,
                    'created_at' => $m['created_at'], 'attachments' => array_map(static fn (array $a): array => ['filename' => $a['filename'], 'content_type' => $a['content_type'], 'size' => strlen($a['bytes'])], $m['attachments'])];
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    public function postMessage(int $ticketId, int $clientId, ?int $contactId, string $body, string $requesterHint, array $attachments): int
    {
        $this->posted[] = compact('ticketId', 'clientId', 'contactId', 'body', 'requesterHint', 'attachments');

        return $this->push($ticketId, 'requester', $requesterHint !== '' ? $requesterHint : 'Requester', $body, false, $attachments, $this->stamp('2026-10-01 11:00:00'));
    }

    /**
     * @param list<array{filename:string,content_type:string,bytes:string}> $attachments
     */
    protected function store(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $hint, array $attachments): int
    {
        $id = $this->nextId++;
        $at = $this->stamp('2026-10-01 09:00:00');
        $this->tickets[$id] = ['ticket_id' => $id, 'client_id' => $clientId, 'contact_id' => $contactId, 'subject' => $subject, 'status' => 'Open', 'closed' => false,
            'category_id' => $categoryId, 'created_at' => $at, 'updated_at' => $at];
        $this->push($id, 'requester', $hint !== '' ? $hint : 'Requester', $body, false, $attachments, $at);

        return $id;
    }

    /**
     * @param list<array{filename:string,content_type:string,bytes:string}> $attachments
     */
    protected function push(int $ticketId, string $author, string $name, string $body, bool $internal, array $attachments, string $at): int
    {
        $id = $this->nextId++;
        $this->messages[$ticketId][] = ['message_id' => $id, 'author' => $author, 'author_name' => $name, 'body' => $body, 'internal' => $internal, 'created_at' => $at, 'attachments' => $attachments];
        if (isset($this->tickets[$ticketId])) {
            $this->tickets[$ticketId]['updated_at'] = $at;
        }

        return $id;
    }

    /** The time stamp for a new ticket or message: the fixed default, or the current UTC time of {@see self::$clock}. */
    protected function stamp(string $default): string
    {
        return $this->clock === null ? $default : gmdate('Y-m-d H:i:s', ($this->clock)());
    }

    /**
     * @param array<string,mixed> $t
     * @return array{ticket_id:int,client_id:int,contact_id:?int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string}
     */
    protected function header(array $t): array
    {
        return ['ticket_id' => $t['ticket_id'], 'client_id' => $t['client_id'], 'contact_id' => $t['contact_id'], 'subject' => $t['subject'], 'status' => $t['status'],
            'closed' => $t['closed'], 'category_id' => $t['category_id'], 'created_at' => $t['created_at'], 'updated_at' => $t['updated_at']];
    }
}
