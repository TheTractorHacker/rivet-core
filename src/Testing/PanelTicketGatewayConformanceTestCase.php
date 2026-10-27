<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Panel\Contracts\PanelTicketGatewayInterface;

/**
 * Conformance kit for {@see PanelTicketGatewayInterface}. The edition tells the case how to create clients, contacts and categories in its own tables and how to
 * add a staff reply and an internal note the way its own screens do.
 *
 * Checks: contact() answers only for an active contact of THAT client; ticket() is null for an unknown id and carries the creating client and contact;
 * createTicket() files the ticket under exactly the client and contact it was given (null contact = none); listTickets() never returns another client's ticket and
 * returns a contact's tickets plus the listed owned ids only, newest activity first, honouring limit and offset; messages() returns the requester's message, a
 * staff reply, never an internal note, in order, after a cursor; postMessage() appends to the ticket and returns a new id; nothing throws for unknown ids;
 * createTicket() never merges two calls (two identical calls are two tickets: de-duplication of retries is Core's job, see PANEL_API.md "Idempotent creation");
 * every time stamp the gateway returns (created_at and updated_at of a ticket, created_at of a message) is UTC and current (rc.38): within {@see self::TIME_TOLERANCE_S}
 * seconds of {@see self::conformanceNow()} for something that was just created, written as "Y-m-d H:i:s" or as ISO 8601 ending in Z. A gateway that stores local time and labels
 * it UTC, or applies the UTC offset twice, shows tickets hours in the future or the past to the panel.
 *
 * @api
 */
abstract class PanelTicketGatewayConformanceTestCase extends TestCase
{
    /** How far a stamp of something created a moment ago may be from {@see self::conformanceNow()}. */
    public const TIME_TOLERANCE_S = 120;

    /** The current Unix time the stamps are compared with. An edition that runs its gateway on a fake clock overrides this with that clock. */
    protected function conformanceNow(): int
    {
        return time();
    }

    abstract protected function gateway(): PanelTicketGatewayInterface;

    /** Create a client; return its id. */
    abstract protected function createClient(string $name): int;

    /** Create an ACTIVE contact of the client; return its id. */
    abstract protected function createContact(int $clientId, string $name): int;

    /** Archive/deactivate the contact the way the edition does. */
    abstract protected function archiveContact(int $contactId): void;

    /** Create a category usable by the client; return its id. */
    abstract protected function createCategory(int $clientId, string $name): int;

    /** A staff reply visible to the requester, made in the edition's own UI. */
    abstract protected function addStaffReply(int $ticketId, string $body): void;

    /** An internal (staff only) note, made in the edition's own UI. */
    abstract protected function addInternalNote(int $ticketId, string $body): void;

    public function testContactIsOnlyAnActiveContactOfTheSameClient(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $b = $this->createClient('B ' . bin2hex(random_bytes(3)));
        $c = $this->createContact($a, 'Jane');
        $got = $this->gateway()->contact($c, $a);
        $this->assertNotNull($got);
        $this->assertSame([$c, 'Jane'], [$got['contact_id'], $got['name']]);
        $this->assertNull($this->gateway()->contact($c, $b), "another client's contact");
        $this->assertNull($this->gateway()->contact(2_000_000_000, $a));
        $this->assertNull($this->gateway()->contact(-1, $a));
        $this->archiveContact($c);
        $this->assertNull($this->gateway()->contact($c, $a), 'an archived contact');
    }

    public function testCategoriesAreTheClientsOwn(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $cat = $this->createCategory($a, 'Hardware');
        $ids = array_column($this->gateway()->categories($a), 'category_id');
        $this->assertContains($cat, $ids);
        $this->assertSame([], $this->gateway()->categories(2_000_000_000));
    }

    public function testCreateFilesTheTicketUnderTheGivenClientAndContact(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $c = $this->createContact($a, 'Jane');
        $withContact = $this->gateway()->createTicket($a, $c, 'Printer', 'It is jammed', null, 'jsmith', []);
        $clientLevel = $this->gateway()->createTicket($a, null, 'Wifi', 'Down', null, '', []);
        $this->assertGreaterThan(0, $withContact);
        $this->assertNotSame($withContact, $clientLevel);
        $t = $this->gateway()->ticket($withContact);
        $this->assertNotNull($t);
        $this->assertSame([$withContact, $a, $c, 'Printer'], [$t['ticket_id'], $t['client_id'], $t['contact_id'], $t['subject']]);
        $this->assertFalse($t['closed']);
        $cl = $this->gateway()->ticket($clientLevel);
        $this->assertNotNull($cl);
        $this->assertSame([$a, null], [$cl['client_id'], $cl['contact_id']]);
        $this->assertNull($this->gateway()->ticket(2_000_000_000));
        $this->assertNull($this->gateway()->ticket(-4));
    }

    public function testCreateTicketNeverMergesIdenticalCalls(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $one = $this->gateway()->createTicket($a, null, 'Same', 'same body', null, '', []);
        $two = $this->gateway()->createTicket($a, null, 'Same', 'same body', null, '', []);
        $this->assertGreaterThan(0, $one);
        $this->assertGreaterThan(0, $two);
        $this->assertNotSame($one, $two, 'Core reserves the client_request_id itself and calls the gateway once per accepted request; the gateway must not de-duplicate by content');
    }

    public function testListIsScopedToTheClientTheContactAndTheOwnedIds(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $b = $this->createClient('B ' . bin2hex(random_bytes(3)));
        $jane = $this->createContact($a, 'Jane');
        $joe = $this->createContact($a, 'Joe');
        $janes = $this->gateway()->createTicket($a, $jane, 'Jane 1', 'x', null, '', []);
        $joes = $this->gateway()->createTicket($a, $joe, 'Joe 1', 'x', null, '', []);
        $owned = $this->gateway()->createTicket($a, null, 'Owned', 'x', null, '', []);
        $other = $this->gateway()->createTicket($b, null, 'Other client', 'x', null, '', []);

        $ids = static fn (array $rows): array => array_map('intval', array_column($rows, 'ticket_id'));
        $rows = $this->gateway()->listTickets($a, $jane, [$owned], 50, 0);
        $got = $ids($rows);
        sort($got);
        $this->assertSame([$janes, $owned], $got);
        $this->assertSame([$owned], $ids($this->gateway()->listTickets($a, null, [$owned], 50, 0)));
        $this->assertSame([], $this->gateway()->listTickets($a, null, [], 50, 0));
        $this->assertNotContains($other, $ids($this->gateway()->listTickets($a, $jane, [$other, $joes], 50, 0)), "another client's id in the owned list");
        $this->assertNotContains($joes, $ids($this->gateway()->listTickets($a, $jane, [], 50, 0)));
        foreach ($rows as $r) {
            $this->assertSame($a, $r['client_id']);
        }
        $this->assertCount(1, $this->gateway()->listTickets($a, $jane, [$owned], 1, 0));
        $this->assertCount(1, $this->gateway()->listTickets($a, $jane, [$owned], 1, 1));
        $this->assertSame([], $this->gateway()->listTickets($a, $jane, [$owned], 5, 2));
    }

    public function testMessagesAreTheVisibleConversationInOrderAfterACursor(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $c = $this->createContact($a, 'Jane');
        $t = $this->gateway()->createTicket($a, $c, 'Subject', 'First message', null, '', []);
        $this->addInternalNote($t, 'INTERNAL-NOTE-' . $t);
        $this->addStaffReply($t, 'Staff answer');
        $m3 = $this->gateway()->postMessage($t, $a, $c, 'Follow up', '', [['filename' => 'a.txt', 'content_type' => 'text/plain', 'bytes' => 'hello']]);
        $this->assertGreaterThan(0, $m3);
        $msgs = $this->gateway()->messages($t, 0, 50);
        $bodies = array_column($msgs, 'body');
        $this->assertStringContainsString('First message', $bodies[0]);
        $this->assertStringNotContainsString('INTERNAL-NOTE-' . $t, implode('|', $bodies), 'internal notes are never returned');
        $this->assertContains('Staff answer', $bodies);
        $this->assertSame($m3, end($msgs)['message_id']);
        $this->assertSame('requester', end($msgs)['author']);
        $this->assertFalse(end($msgs)['internal']);
        $this->assertSame([['filename' => 'a.txt', 'content_type' => 'text/plain', 'size' => 5]], end($msgs)['attachments']);
        $ids = array_map('intval', array_column($msgs, 'message_id'));
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);
        $this->assertSame([$m3], array_map('intval', array_column($this->gateway()->messages($t, $ids[count($ids) - 2], 50), 'message_id')));
        $this->assertCount(1, $this->gateway()->messages($t, 0, 1));
        $this->assertSame([], $this->gateway()->messages($t, $m3, 50));
        $this->assertSame([], $this->gateway()->messages(2_000_000_000, 0, 50));
    }

    /** Unix time of a stamp that must be UTC, as "Y-m-d H:i:s" or ISO 8601 with a trailing Z; null when it is neither. */
    private function utcStamp(mixed $v): ?int
    {
        if (!is_string($v)) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $v) === 1) {
            $t = strtotime($v . ' UTC');
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $v) === 1) {
            $t = strtotime($v);
        } else {
            return null;
        }

        return $t === false ? null : $t;
    }

    private function assertJustNow(mixed $stamp, string $what): void
    {
        $t = $this->utcStamp($stamp);
        $this->assertNotNull($t, "$what must be UTC written as 'Y-m-d H:i:s' or ISO 8601 ending in Z, got " . json_encode($stamp));
        $this->assertLessThanOrEqual(self::TIME_TOLERANCE_S, abs((int) $t - $this->conformanceNow()),
            "$what is " . ((int) $t - $this->conformanceNow()) . ' s away from now: the gateway must write UTC (not local time, and not the UTC offset applied twice)');
    }

    public function testTimestampsAreUtcAndCurrent(): void
    {
        $a = $this->createClient('A ' . bin2hex(random_bytes(3)));
        $c = $this->createContact($a, 'Jane');
        $t = $this->gateway()->createTicket($a, $c, 'Clock check', 'First message', null, '', []);
        $header = $this->gateway()->ticket($t);
        $this->assertNotNull($header);
        $this->assertJustNow($header['created_at'], 'ticket created_at');
        $this->assertJustNow($header['updated_at'], 'ticket updated_at');
        $listed = $this->gateway()->listTickets($a, $c, [], 50, 0);
        $this->assertNotEmpty($listed);
        foreach ($listed as $row) {
            if ($row['ticket_id'] === $t) {
                $this->assertJustNow($row['created_at'], 'listed ticket created_at');
                $this->assertJustNow($row['updated_at'], 'listed ticket updated_at');
            }
        }
        $m = $this->gateway()->postMessage($t, $a, $c, 'Follow up', '', []);
        $this->assertGreaterThan(0, $m);
        $msgs = $this->gateway()->messages($t, 0, 50);
        $this->assertNotEmpty($msgs);
        foreach ($msgs as $msg) {
            if ((int) $msg['message_id'] === $m || $msg['author'] === 'requester') {
                $this->assertJustNow($msg['created_at'], 'message created_at');
            }
        }
        $after = $this->gateway()->ticket($t);
        $this->assertNotNull($after);
        $this->assertJustNow($after['updated_at'], 'ticket updated_at after a reply');
        $this->assertGreaterThanOrEqual((int) $this->utcStamp($header['updated_at']), (int) $this->utcStamp($after['updated_at']), 'a reply does not move updated_at backwards');
    }
}
