<?php

declare(strict_types=1);

namespace RivetCore\Panel;

use RivetCore\Database\DatabaseException;
use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;
use RivetCore\Rmm\Contracts\RmmAuditInterface;
use RivetCore\Rmm\Contracts\RmmTenancyInterface;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Support\DailyBudget;
use RivetCore\Rmm\Support\Sql;

/**
 * What a panel device may do, with every rule in one place.
 *
 *  - client and contact come from the {@see PanelPrincipal} (the credential); nothing in a request body or query is ever read for them,
 *  - a device with a contact sees and uses the tickets of that contact plus the tickets its own device created; a client-level device only the ones it created,
 *  - a client-level device may create tickets, list them and chat only while the client's setting allows it ({@see PanelSettings}); until then it reads categories only,
 *  - a ticket outside that scope is answered exactly like a ticket that does not exist (404 not_found), so ids cannot be probed,
 *  - everything echoed back goes through {@see PanelText::output()},
 *  - ownership is the pair (device, contact): a ticket a device created is visible only while the device is bound to the contact it was bound to when it created it
 *    (rc.38, security review RC36 M2). A device re-enrolled for another person, or between client level and a contact, does not see the earlier person's tickets or
 *    chat; nothing is deleted, and re-enrolling for the original contact brings them back,
 *  - chat is budgeted per device and UTC day, in posts and in bytes ({@see PanelLimits::$chatPostsPerDayMax}), on top of the per-minute limit.
 *
 * @api
 */
final class PanelService
{
    /** The {@see DailyBudget} scope of a device's chat posts and bytes. */
    public const BUDGET_CHAT = 'panel_chat';

    private readonly DailyBudget $budget;

    public function __construct(
        private readonly Sql $sql,
        private readonly PanelTicketGatewayInterface $gateway,
        private readonly PanelSettings $settings,
        private readonly RmmTenancyInterface $tenancy,
        private readonly RmmAuditInterface $audit,
        private readonly PanelLimits $limits,
    ) {
        $this->budget = new DailyBudget($sql);
    }

    /** @return array<string,mixed> */
    public function me(PanelPrincipal $p): array
    {
        $contact = $p->contactId === null ? null : $this->gateway->contact($p->contactId, $p->clientId);
        $may = $this->mayUseTickets($p);

        return [
            'device_id' => $p->deviceId,
            'client' => ['client_id' => $p->clientId, 'name' => PanelText::output($this->tenancy->clientName($p->clientId) ?? '', false)],
            'contact' => $contact === null ? null : ['contact_id' => $p->contactId, 'name' => PanelText::output($contact['name'], false)],
            'can_create_tickets' => $may,
            'can_view_tickets' => $may,
            'limits' => $this->limits->describe(),
        ];
    }

    /** @return list<array{category_id:int,name:string}> */
    public function categories(PanelPrincipal $p): array
    {
        $out = [];
        foreach ($this->gateway->categories($p->clientId) as $c) {
            $out[] = ['category_id' => (int) $c['category_id'], 'name' => PanelText::output($c['name'], false)];
        }

        return $out;
    }

    /**
     * @param array<string,string> $query limit, offset
     * @return array<string,mixed>
     */
    public function listTickets(PanelPrincipal $p, array $query): array
    {
        $this->requireTicketAccess($p);
        $limit = $this->intQuery($query, 'limit', min(20, $this->limits->pageMax), 1, $this->limits->pageMax);
        $offset = $this->intQuery($query, 'offset', 0, 0, 100000);
        $owned = $this->ownedIds($p);
        $rows = $this->gateway->listTickets($p->clientId, $p->contactId, $owned, $limit + 1, $offset);
        $out = [];
        $more = false;
        $ownedSet = array_flip($owned);
        foreach ($rows as $r) {
            if (!$this->inScope($p, $r, isset($ownedSet[(int) $r['ticket_id']]))) {
                continue;   // a lax adapter cannot widen access
            }
            if (count($out) >= $limit) {
                $more = true;
                break;
            }
            $out[] = $this->summary($r, isset($ownedSet[(int) $r['ticket_id']]));
        }

        return ['tickets' => $out, 'limit' => $limit, 'offset' => $offset, 'has_more' => $more];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{replayed:bool,ticket_id:int}
     */
    public function createTicket(PanelPrincipal $p, array $body): array
    {
        $this->requireTicketAccess($p);
        $requestId = null;
        if (isset($body['client_request_id'])) {
            if (!is_string($body['client_request_id']) || preg_match(PanelProtocol::REQUEST_ID_RE, strtolower($body['client_request_id'])) !== 1) {
                throw new ApiError(422, 'invalid', 'client_request_id must be a UUID.');
            }
            $requestId = strtolower($body['client_request_id']);
            $done = $this->sql->one('SELECT ticket_id FROM panel_ticket_owner WHERE device_id = ? AND request_id = ? AND ticket_id IS NOT NULL AND contact_id <=> ?', [$p->deviceId, $requestId, $p->contactId]);
            if ($done !== null) {
                return ['replayed' => true, 'ticket_id' => (int) $done['ticket_id']];
            }
        }
        $subject = PanelText::input($body['subject'] ?? null, 'subject', $this->limits->subjectMax, false);
        $text = PanelText::input($body['body'] ?? null, 'body', $this->limits->ticketBodyMax, true);
        $hint = PanelText::input($body['display_name'] ?? '', 'display_name', $this->limits->displayNameMax, false, true);
        $category = $this->category($p, $body['category_id'] ?? null);
        $attachments = PanelAttachments::validate($body['attachments'] ?? null, $this->limits);

        $today = (int) $this->sql->val('SELECT COUNT(*) FROM panel_ticket_owner WHERE device_id = ? AND created_at > ?', [$p->deviceId, $this->sql->utcAt(-86400)]);
        if ($today >= $this->limits->ticketsPerDayMax) {
            throw new ApiError(429, 'rate_limited', 'This device has created too many tickets today.', ['Retry-After' => '3600']);
        }

        // Reserve (device, request id) BEFORE the gateway is called: the unique key lets exactly one of several concurrent retries through.
        $reservation = $this->reserve($p, $requestId);
        if (is_int($reservation['replayed'] ?? null)) {
            return ['replayed' => true, 'ticket_id' => $reservation['replayed']];
        }
        $ownerId = (int) $reservation['owner_id'];
        try {
            $id = $this->gateway->createTicket($p->clientId, $p->contactId, $subject, $text, $category, $hint, $attachments);
            if ($id <= 0) {
                throw new \RuntimeException('The ticket gateway returned no ticket id.');
            }
        } catch (\Throwable $e) {
            $this->release($ownerId);   // nothing was created: a retry must be able to proceed
            throw $e;
        }
        $this->complete($p, $ownerId, $id);
        $this->audit->record('Panel Ticket Created', "Ticket $id created by panel device {$p->deviceId}" . ($p->contactId !== null ? " for contact {$p->contactId}" : ' (client-level)')
            . ($attachments !== [] ? ', ' . count($attachments) . ' attachment(s)' : ''), $p->clientId, $id);

        return ['replayed' => false, 'ticket_id' => $id];
    }

    /**
     * @param array<string,string> $query after, limit
     * @return array<string,mixed>
     */
    public function chat(PanelPrincipal $p, int $ticketId, array $query): array
    {
        $this->requireTicketAccess($p);
        $t = $this->authorizeTicket($p, $ticketId);
        $after = $this->intQuery($query, 'after', 0, 0, PHP_INT_MAX);
        $limit = $this->intQuery($query, 'limit', min(50, $this->limits->chatPageMax), 1, $this->limits->chatPageMax);
        $out = [];
        foreach ($this->gateway->messages($ticketId, $after, $limit) as $m) {
            if ($m['internal'] || (int) $m['message_id'] <= $after) {
                continue;
            }
            $mine = $m['author'] === 'requester';
            $atts = [];
            foreach ($m['attachments'] as $a) {
                $atts[] = ['filename' => PanelText::output($a['filename'], false), 'content_type' => PanelText::output($a['content_type'], false), 'size' => (int) $a['size']];
            }
            $out[] = [
                'message_id' => (int) $m['message_id'],
                'from' => $mine ? 'you' : 'staff',
                'author' => $mine ? 'You' : PanelText::output($m['author_name'], false),
                'body' => PanelText::output($m['body']),
                'created_at' => Sql::iso((string) $m['created_at']),
                'attachments' => $atts,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return ['ticket' => ['ticket_id' => $ticketId, 'subject' => PanelText::output($t['subject'], false), 'status' => PanelText::output($t['status'], false), 'closed' => (bool) $t['closed']],
            'messages' => $out, 'next_after' => $out === [] ? $after : $out[array_key_last($out)]['message_id']];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{ticket_id:int,message_id:int}
     */
    public function postChat(PanelPrincipal $p, int $ticketId, array $body): array
    {
        $this->requireTicketAccess($p);
        $t = $this->authorizeTicket($p, $ticketId);
        $text = PanelText::input($body['body'] ?? null, 'body', $this->limits->chatBodyMax, true);
        $hint = PanelText::input($body['display_name'] ?? '', 'display_name', $this->limits->displayNameMax, false, true);
        $attachments = PanelAttachments::validate($body['attachments'] ?? null, $this->limits);
        if ($t['closed']) {
            throw new ApiError(409, 'ticket_closed', 'This ticket is closed.');
        }
        $bytes = strlen($text);
        foreach ($attachments as $a) {
            $bytes += strlen($a['bytes']);
        }
        // Spend the day's budget BEFORE the gateway is called (it stores the bytes); a gateway failure hands it back.
        if (!$this->budget->charge(self::BUDGET_CHAT, $p->deviceId, 1, $bytes, $this->limits->chatPostsPerDayMax, $this->limits->chatBytesPerDayMax)) {
            if ($this->budget->firstRefusalToday(self::BUDGET_CHAT, $p->deviceId)) {
                $this->audit->record('Panel Chat Limit Reached', "Panel device {$p->deviceId} reached its daily chat budget ({$this->limits->chatPostsPerDayMax} posts or {$this->limits->chatBytesPerDayMax} bytes); further posts today are refused", $p->clientId, $ticketId);
            }
            throw new ApiError(429, 'rate_limited', 'This device has posted too much chat today.', ['Retry-After' => (string) $this->budget->secondsUntilReset()]);
        }
        try {
            $id = $this->gateway->postMessage($ticketId, $p->clientId, $p->contactId, $text, $hint, $attachments);
            if ($id <= 0) {
                throw new \RuntimeException('The ticket gateway returned no message id.');
            }
        } catch (\Throwable $e) {
            try {
                $this->budget->refund(self::BUDGET_CHAT, $p->deviceId, 1, $bytes);
            } catch (\Throwable) {
                // keep the gateway's error as the one that surfaces
            }
            throw $e;
        }
        $this->audit->record('Panel Chat Posted', "Message $id posted on ticket $ticketId by panel device {$p->deviceId}" . ($attachments !== [] ? ', ' . count($attachments) . ' attachment(s)' : ''), $p->clientId, $ticketId);

        return ['ticket_id' => $ticketId, 'message_id' => $id];
    }

    // ------------------------------------------------------------------ idempotent creation

    /**
     * Claim (device, request id). Returns ['owner_id' => n] when this call won the right to create, or ['replayed' => ticketId] when an earlier call already created it.
     * A claim still pending (first call in flight) is 409 `request_in_progress`; a claim pending for longer than {@see PanelProtocol::RESERVATION_TTL_S} is taken over
     * (the first call must have died between the claim and its end), atomically, so two retries still cannot both take it.
     *
     * @return array{owner_id?:int,replayed?:int}
     */
    private function reserve(PanelPrincipal $p, ?string $requestId): array
    {
        $now = $this->sql->utcNow();
        try {
            return ['owner_id' => $this->sql->insert('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, contact_id, request_id, created_at) VALUES (NULL, ?, ?, ?, ?, ?)',
                [$p->deviceId, $p->clientId, $p->contactId, $requestId, $now])];
        } catch (DatabaseException $e) {
            $row = $requestId === null ? null : $this->sql->one('SELECT owner_id, ticket_id, contact_id, created_at FROM panel_ticket_owner WHERE device_id = ? AND request_id = ?', [$p->deviceId, $requestId]);
            if ($row === null) {
                throw $e;   // not the duplicate key
            }
            if ($row['contact_id'] === null ? $p->contactId !== null : (int) $row['contact_id'] !== $p->contactId) {
                // the request id belongs to the identity this device had before it was re-enrolled: never hand that ticket to the new one
                throw new ApiError(409, 'conflict', 'This client_request_id was used before this device was set up for its current person. Use a new one.');
            }
            if ($row['ticket_id'] !== null) {
                return ['replayed' => (int) $row['ticket_id']];
            }
            if ($this->sql->run('UPDATE panel_ticket_owner SET created_at = ? WHERE owner_id = ? AND ticket_id IS NULL AND created_at = ? AND created_at < ?',
                [$now, $row['owner_id'], $row['created_at'], $this->sql->utcAt(-PanelProtocol::RESERVATION_TTL_S)]) === 1) {
                return ['owner_id' => (int) $row['owner_id']];
            }
            // the other call may have finished between our read and now
            $again = $this->sql->one('SELECT ticket_id FROM panel_ticket_owner WHERE owner_id = ?', [$row['owner_id']]);
            if ($again !== null && $again['ticket_id'] !== null) {
                return ['replayed' => (int) $again['ticket_id']];
            }
            throw new ApiError(409, 'request_in_progress', 'A request with this client_request_id is still being processed. Retry shortly.', ['Retry-After' => (string) PanelProtocol::IN_PROGRESS_RETRY_AFTER_S]);
        }
    }

    /** Drop a claim whose gateway call failed. Best effort: if even this fails, the claim expires by itself after the TTL. */
    private function release(int $ownerId): void
    {
        try {
            $this->sql->run('DELETE FROM panel_ticket_owner WHERE owner_id = ? AND ticket_id IS NULL', [$ownerId]);
        } catch (\Throwable) {
            // keep the gateway's error as the one that surfaces
        }
    }

    /** Attach the created ticket to the claim. If the claim was taken over meanwhile (a call slower than the TTL), the ticket is still recorded so the device can use it. */
    private function complete(PanelPrincipal $p, int $ownerId, int $ticketId): void
    {
        if ($this->sql->run('UPDATE panel_ticket_owner SET ticket_id = ? WHERE owner_id = ? AND ticket_id IS NULL', [$ticketId, $ownerId]) === 1) {
            return;
        }
        $this->sql->run('INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, contact_id, request_id, created_at) VALUES (?, ?, ?, ?, NULL, ?)',
            [$ticketId, $p->deviceId, $p->clientId, $p->contactId, $this->sql->utcNow()]);
        $this->audit->record('Panel Ticket Duplicate', "Ticket $ticketId was created after its request claim expired (panel device {$p->deviceId}); an earlier or later attempt may have created another", $p->clientId, $ticketId);
    }

    // ------------------------------------------------------------------ access rules

    /** A device with a contact always may; a client-level device only while the client's setting is on. */
    public function mayUseTickets(PanelPrincipal $p): bool
    {
        return $p->contactId !== null || $this->settings->allowClientLevelTickets($p->clientId);
    }

    private function requireTicketAccess(PanelPrincipal $p): void
    {
        if (!$this->mayUseTickets($p)) {
            throw new ApiError(403, 'client_level_disabled', 'Tickets from panels that are not tied to a person are turned off for this client.');
        }
    }

    /**
     * @return array{ticket_id:int,client_id:int,contact_id:?int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string}
     * @throws ApiError 404 when the ticket is missing OR outside this device's scope
     */
    private function authorizeTicket(PanelPrincipal $p, int $ticketId): array
    {
        $t = $ticketId > 0 ? $this->gateway->ticket($ticketId) : null;
        if ($t === null || (int) $t['ticket_id'] !== $ticketId) {
            throw new ApiError(404, 'not_found', 'Ticket not found.');
        }
        $mine = $this->sql->one('SELECT 1 AS x FROM panel_ticket_owner WHERE ticket_id = ? AND device_id = ? AND client_id = ? AND contact_id <=> ?', [$ticketId, $p->deviceId, $p->clientId, $p->contactId]) !== null;
        if (!$this->inScope($p, $t, $mine)) {
            throw new ApiError(404, 'not_found', 'Ticket not found.');
        }

        return $t;
    }

    /** @param array{client_id:int,contact_id:?int} $t */
    private function inScope(PanelPrincipal $p, array $t, bool $createdByThisDevice): bool
    {
        if ((int) $t['client_id'] !== $p->clientId) {
            return false;
        }

        return $createdByThisDevice || ($p->contactId !== null && $t['contact_id'] !== null && (int) $t['contact_id'] === $p->contactId);
    }

    /** @return list<int> */
    private function ownedIds(PanelPrincipal $p): array
    {
        $rows = $this->sql->all('SELECT ticket_id FROM panel_ticket_owner WHERE device_id = ? AND client_id = ? AND contact_id <=> ? AND ticket_id IS NOT NULL ORDER BY ticket_id DESC LIMIT ' . $this->limits->ownedWindow, [$p->deviceId, $p->clientId, $p->contactId]);

        return array_map(static fn (array $r): int => (int) $r['ticket_id'], $rows);
    }

    private function category(PanelPrincipal $p, mixed $given): ?int
    {
        if ($given === null || $given === '') {
            return null;
        }
        if (!is_int($given)) {
            throw new ApiError(422, 'invalid', 'category_id must be an integer.');
        }
        foreach ($this->gateway->categories($p->clientId) as $c) {
            if ((int) $c['category_id'] === $given) {
                return $given;
            }
        }
        throw new ApiError(422, 'invalid', 'Unknown category.');
    }

    /**
     * @param array{ticket_id:int,subject:string,status:string,closed:bool,category_id:?int,created_at:string,updated_at:string} $r
     * @return array<string,mixed>
     */
    private function summary(array $r, bool $mine): array
    {
        return [
            'ticket_id' => (int) $r['ticket_id'],
            'subject' => PanelText::output($r['subject'], false),
            'status' => PanelText::output($r['status'], false),
            'closed' => (bool) $r['closed'],
            'category_id' => $r['category_id'] === null ? null : (int) $r['category_id'],
            'created_at' => Sql::iso((string) $r['created_at']),
            'updated_at' => Sql::iso((string) $r['updated_at']),
            'mine' => $mine,
        ];
    }

    /** @param array<string,string> $q */
    private function intQuery(array $q, string $key, int $default, int $min, int $max): int
    {
        if (!isset($q[$key]) || $q[$key] === '') {
            return $default;
        }
        if (preg_match('/^\d{1,18}$/', $q[$key]) !== 1) {
            throw new ApiError(422, 'invalid', "$key must be a non-negative integer.");
        }

        return max($min, min($max, (int) $q[$key]));
    }
}
