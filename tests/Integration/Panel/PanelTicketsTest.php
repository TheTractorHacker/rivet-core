<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Panel;

use RivetCore\Tests\Support\PanelTestCase;

final class PanelTicketsTest extends PanelTestCase
{
    /** @return array{token:string,device_id:int,contact:int} */
    private function contactDevice(string $name = 'Jane', ?int $client = null): array
    {
        $client ??= $this->p->clientA;
        $contact = $this->p->gateway->addClientContact($client, $name);

        return $this->p->device_for($client, $contact) + ['contact' => $contact];
    }

    private function create(string $token, array $body = []): array
    {
        return $this->p->call('POST', 'panel_tickets', $body + ['subject' => 'Printer down', 'body' => 'It does not print'], $token);
    }

    public function testCreateUsesTheClientAndContactOfTheCredentialAndIgnoresWhatTheCallerSends(): void
    {
        $d = $this->contactDevice();
        $evil = $this->p->gateway->addClientContact($this->p->clientB, 'Victim');
        [$c, , $j] = $this->create($d['token'], ['client_id' => $this->p->clientB, 'contact_id' => $evil, 'clientId' => $this->p->clientB, 'contact' => $evil]);
        $this->assertSame(201, $c);
        $made = $this->p->gateway->created[0];
        $this->assertSame([$this->p->clientA, $d['contact']], [$made['clientId'], $made['contactId']]);
        $this->assertSame($this->p->clientA, $this->p->gateway->ticketRow($j['ticket_id'])['client_id']);
        // the same on the query string
        [$c] = $this->p->call('GET', 'panel_tickets', null, $d['token'], ['client_id' => (string) $this->p->clientB, 'contact_id' => (string) $evil]);
        $this->assertSame(200, $c);
    }

    public function testAClientLevelDeviceIsBlockedFromEverythingButCategoriesWhileTheSettingIsOff(): void
    {
        $dev = $this->p->device_for();
        $cat = $this->p->gateway->addCategory($this->p->clientA, 'Hardware');
        [$c, , $j] = $this->p->call('GET', 'panel_categories', null, $dev['token']);
        $this->assertSame([200, [['category_id' => $cat, 'name' => 'Hardware']]], [$c, $j['categories']]);
        [$c, , $j] = $this->p->call('GET', 'panel_me', null, $dev['token']);
        $this->assertSame([200, false, false, null], [$c, $j['can_create_tickets'], $j['can_view_tickets'], $j['contact']]);
        $existing = $this->p->gateway->addTicketFor($this->p->clientA, null, 'Existing');
        foreach ([$this->p->call('GET', 'panel_tickets', null, $dev['token']), $this->create($dev['token']),
            $this->p->call('GET', 'panel_ticket_chat', null, $dev['token'], [], [(string) $existing]),
            $this->p->call('POST', 'panel_ticket_chat', ['body' => 'hi'], $dev['token'], [], [(string) $existing])] as [$c, , $j]) {
            $this->assertSame([403, 'client_level_disabled'], [$c, $j['code']]);
        }
        $this->assertSame([], $this->p->gateway->created);
        $this->assertSame([], $this->p->gateway->posted);
    }

    public function testAClientLevelDeviceCanCreateOnceTheSettingIsOnAndSeesOnlyItsOwnTickets(): void
    {
        $a = $this->p->device_for();
        $b = $this->p->device_for();
        $this->p->module->settings()->setAllowClientLevelTickets($this->p->clientA, true, 1);
        $this->assertContains('Panel Setting Changed', $this->p->actions());
        $foreign = $this->p->gateway->addTicketFor($this->p->clientA, null, 'Created in the UI');
        [$c, , $made] = $this->create($a['token'], ['display_name' => 'jsmith']);
        $this->assertSame(201, $c);
        $this->assertNull($this->p->gateway->created[0]['contactId']);
        $this->assertSame('jsmith', $this->p->gateway->created[0]['requesterHint']);
        [, , $list] = $this->p->call('GET', 'panel_tickets', null, $a['token']);
        $this->assertSame([$made['ticket_id']], array_column($list['tickets'], 'ticket_id'));
        $this->assertTrue($list['tickets'][0]['mine']);
        [, , $listB] = $this->p->call('GET', 'panel_tickets', null, $b['token']);
        $this->assertSame([], $listB['tickets'], 'a sibling device of the same client sees nothing of it');
        foreach ([$foreign, $made['ticket_id']] as $tid) {
            $who = $tid === $made['ticket_id'] ? $b : $a;
            $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $who['token'], [], [(string) $tid])[0]);
            $this->assertSame(404, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x'], $who['token'], [], [(string) $tid])[0]);
        }
        $this->assertSame(200, $this->p->call('GET', 'panel_ticket_chat', null, $a['token'], [], [(string) $made['ticket_id']])[0]);
        $this->p->module->settings()->setAllowClientLevelTickets($this->p->clientA, false, 1);
        $this->assertSame(403, $this->create($a['token'])[0], 'turning the setting off takes effect at once');
        $this->assertSame(403, $this->p->call('GET', 'panel_tickets', null, $a['token'])[0]);
    }

    public function testTheSettingIsPerClient(): void
    {
        $a = $this->p->device_for($this->p->clientA);
        $b = $this->p->device_for($this->p->clientB);
        $this->p->module->settings()->setAllowClientLevelTickets($this->p->clientA, true, 1);
        $this->assertSame(201, $this->create($a['token'])[0]);
        $this->assertSame(403, $this->create($b['token'])[0]);
    }

    public function testNoCrossClientAccessToTicketsOrChat(): void
    {
        $a = $this->contactDevice('Ann', $this->p->clientA);
        $b = $this->contactDevice('Bob', $this->p->clientB);
        [, , $ta] = $this->create($a['token']);
        [, , $tb] = $this->create($b['token']);
        $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $a['token'], [], [(string) $tb['ticket_id']])[0]);
        $this->assertSame(404, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x'], $a['token'], [], [(string) $tb['ticket_id']])[0]);
        $this->assertSame([$ta['ticket_id']], array_column($this->p->call('GET', 'panel_tickets', null, $a['token'])[2]['tickets'], 'ticket_id'));
        // an adapter that wrongly files a ticket of another client under this contact still cannot widen access
        $stray = $this->p->gateway->addTicketFor($this->p->clientB, $a['contact'], 'Wrong client, right contact id');
        $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $a['token'], [], [(string) $stray])[0]);
        $this->assertNotContains($stray, array_column($this->p->call('GET', 'panel_tickets', null, $a['token'])[2]['tickets'], 'ticket_id'));
        // forged ownership row in the other client does not help either
        $this->p->rmm->q("UPDATE panel_ticket_owner SET device_id = {$a['device_id']} WHERE ticket_id = $tb[ticket_id]");
        $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $a['token'], [], [(string) $tb['ticket_id']])[0]);
    }

    public function testAContactDeviceSeesItsContactsTicketsButNotAnotherContactsOfTheSameClient(): void
    {
        $jane = $this->contactDevice('Jane');
        $jane2 = $this->p->device_for($this->p->clientA, $jane['contact']);
        $joe = $this->contactDevice('Joe');
        $fromUi = $this->p->gateway->addTicketFor($this->p->clientA, $jane['contact'], 'Opened by phone');
        [, , $mine] = $this->create($jane['token']);
        [, , $joes] = $this->create($joe['token']);
        $ids = array_column($this->p->call('GET', 'panel_tickets', null, $jane['token'])[2]['tickets'], 'ticket_id');
        sort($ids);
        $this->assertSame([$fromUi, $mine['ticket_id']], $ids);
        $this->assertSame(200, $this->p->call('GET', 'panel_ticket_chat', null, $jane2['token'], [], [(string) $mine['ticket_id']])[0], "Jane's second PC shares her tickets");
        $this->assertSame(201, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'hello'], $jane['token'], [], [(string) $fromUi])[0]);
        $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $jane['token'], [], [(string) $joes['ticket_id']])[0]);
        $this->assertSame(404, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x'], $jane['token'], [], [(string) $joes['ticket_id']])[0]);
        $this->assertSame($this->p->clientA . ':' . $jane['contact'], $this->p->gateway->posted[0]['clientId'] . ':' . $this->p->gateway->posted[0]['contactId']);
    }

    public function testAMissingTicketLooksLikeAForbiddenOne(): void
    {
        $d = $this->contactDevice();
        $other = $this->contactDevice('Other');
        [, , $t] = $this->create($other['token']);
        $a = $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], ['999999']);
        $b = $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], [(string) $t['ticket_id']]);
        $this->assertSame([404, 'not_found'], [$a[0], $a[2]['code']]);
        $this->assertSame([$a[0], $a[2]['error'], $a[2]['code']], [$b[0], $b[2]['error'], $b[2]['code']]);
        foreach (['0', '-1', 'abc', '1e3', '1 OR 1=1', ''] as $bad) {
            $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], [$bad])[0], $bad);
        }
        // the ticket id may also come from the query string, with the same checks
        $this->assertSame(404, $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], ['ticket_id' => (string) $t['ticket_id']])[0]);
    }

    public function testChatReturnsOnlyPublicMessagesWithNeutralisedText(): void
    {
        $d = $this->contactDevice();
        [, , $t] = $this->create($d['token']);
        $tid = $t['ticket_id'];
        $this->p->gateway->addInternalNote($tid, 'internal: customer is difficult');
        $this->p->gateway->addStaffReply($tid, "<script>alert(1)</script><b>Hi</b> a < b &amp; \u{202E}gnp.exe\u{200B}", "Tech\u{202E}<i>Tom</i>");
        [$c, , $j] = $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], [(string) $tid]);
        $this->assertSame(200, $c);
        $bodies = array_column($j['messages'], 'body');
        $this->assertCount(2, $j['messages']);
        $this->assertStringNotContainsString('internal', implode('|', $bodies));
        $this->assertSame(['you', 'staff'], array_column($j['messages'], 'from'));
        $staff = $j['messages'][1];
        $this->assertSame('alert(1)Hi a &lt; b & gnp.exe', $staff['body']);
        $this->assertSame('TechTom', $staff['author']);
        $this->assertDoesNotMatchRegularExpression('/[<>]|\x{202E}|\x{200B}/u', $staff['body'] . $staff['author']);
        $this->assertSame('You', $j['messages'][0]['author']);
        $this->assertSame($j['messages'][1]['message_id'], $j['next_after']);
        [, , $next] = $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], ['after' => (string) $j['next_after']], [(string) $tid]);
        $this->assertSame([], $next['messages']);
        $this->assertSame($j['next_after'], $next['next_after']);
        $this->assertSame(422, $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], ['after' => '-5'], [(string) $tid])[0]);
    }

    public function testChatPostValidationClosedTicketsAndDisplayName(): void
    {
        $d = $this->contactDevice();
        [, , $t] = $this->create($d['token']);
        $path = [(string) $t['ticket_id']];
        [$c, , $j] = $this->p->call('POST', 'panel_ticket_chat', ['body' => "ok\u{202E}\u{0000}", 'display_name' => 'jsmith'], $d['token'], [], $path);
        $this->assertSame(201, $c);
        $this->assertSame(['ticket_id', 'message_id', 'server_time'], array_keys($j));
        $this->assertSame('ok', $this->p->gateway->posted[0]['body']);
        foreach ([[], ['body' => ''], ['body' => '   '], ['body' => ['a']], ['body' => str_repeat('x', 5001)], ['body' => "\xC3\x28"]] as $bad) {
            $this->assertSame(422, $this->p->call('POST', 'panel_ticket_chat', $bad, $d['token'], [], $path)[0], (string) json_encode($bad));
        }
        $this->p->gateway->closeTicket($t['ticket_id']);
        [$c, , $j] = $this->p->call('POST', 'panel_ticket_chat', ['body' => 'late'], $d['token'], [], $path);
        $this->assertSame([409, 'ticket_closed'], [$c, $j['code']]);
        $this->assertSame(200, $this->p->call('GET', 'panel_ticket_chat', null, $d['token'], [], $path)[0], 'a closed ticket can still be read');
    }

    public function testCreateValidationAndLengthLimits(): void
    {
        $d = $this->contactDevice();
        $this->p->rateOverride["panel_create:{$d['device_id']}"] = 0;
        $cat = $this->p->gateway->addCategory($this->p->clientA, 'Hardware');
        $foreignCat = $this->p->gateway->addCategory($this->p->clientB, 'Secret');
        foreach ([['subject' => ''], ['subject' => str_repeat('s', 201)], ['subject' => ['x']], ['body' => ''], ['body' => str_repeat('b', 20001)], ['body' => 5],
            ['category_id' => $foreignCat], ['category_id' => '1'], ['category_id' => 99999999], ['client_request_id' => 'nope'], ['display_name' => str_repeat('n', 101)]] as $bad) {
            $this->assertSame(422, $this->create($d['token'], $bad)[0], (string) json_encode($bad));
        }
        $this->assertSame([], $this->p->gateway->created);
        [$c, , $j] = $this->create($d['token'], ['subject' => str_repeat('s', 200), 'body' => str_repeat("b\n", 9000), 'category_id' => $cat]);
        $this->assertSame(201, $c);
        $this->assertSame($cat, $this->p->gateway->created[0]['categoryId']);
        $this->assertSame(['ticket_id', 'replayed', 'server_time'], array_keys($j));
        [, , $t] = $this->create($d['token'], ['subject' => "Bidi\u{202E}Subject\x07"]);
        $this->assertSame('BidiSubject', $this->p->gateway->ticketRow($t['ticket_id'])['subject']);
    }

    public function testCreateIsIdempotentPerClientRequestId(): void
    {
        $d = $this->contactDevice();
        $other = $this->contactDevice('Other');
        $rid = '11111111-2222-4333-8444-555555555555';
        [$c1, , $a] = $this->create($d['token'], ['client_request_id' => $rid]);
        [$c2, , $b] = $this->create($d['token'], ['client_request_id' => strtoupper($rid)]);
        $this->assertSame([201, 200, false, true], [$c1, $c2, $a['replayed'], $b['replayed']]);
        $this->assertSame($a['ticket_id'], $b['ticket_id']);
        $this->assertCount(1, $this->p->gateway->created);
        [, , $c] = $this->create($other['token'], ['client_request_id' => $rid]);
        $this->assertNotSame($a['ticket_id'], $c['ticket_id'], 'the key is per device');
    }

    public function testListPaginationIsCappedAndOrdered(): void
    {
        $d = $this->contactDevice();
        for ($i = 0; $i < 7; $i++) {
            $this->p->gateway->addTicketFor($this->p->clientA, $d['contact'], "T$i");
        }
        [, , $j] = $this->p->call('GET', 'panel_tickets', null, $d['token'], ['limit' => '3']);
        $this->assertSame([3, true, 3], [count($j['tickets']), $j['has_more'], $j['limit']]);
        [, , $j2] = $this->p->call('GET', 'panel_tickets', null, $d['token'], ['limit' => '3', 'offset' => '6']);
        $this->assertSame([1, false], [count($j2['tickets']), $j2['has_more']]);
        [, , $big] = $this->p->call('GET', 'panel_tickets', null, $d['token'], ['limit' => '100000']);
        $this->assertSame(50, $big['limit']);
        [, , $zero] = $this->p->call('GET', 'panel_tickets', null, $d['token'], ['limit' => '0']);
        $this->assertSame(1, $zero['limit']);
        foreach (['abc', '-1', '1.5', '99999999999999999999'] as $bad) {
            $this->assertSame(422, $this->p->call('GET', 'panel_tickets', null, $d['token'], ['limit' => $bad])[0], $bad);
        }
        $this->assertSame(['ticket_id', 'subject', 'status', 'closed', 'category_id', 'created_at', 'updated_at', 'mine'], array_keys($j['tickets'][0]));
        $this->assertFalse($j['tickets'][0]['mine']);
    }

    public function testEveryAnswerCarriesServerTimeAndAFutureTimestampIsNotAnError(): void
    {
        $d = $this->contactDevice();
        $now = time();
        foreach ([$this->p->call('GET', 'panel_me', null, $d['token']), $this->p->call('GET', 'panel_categories', null, $d['token']), $this->p->call('GET', 'panel_me', null, 'rvpd_' . str_repeat('0', 64)),
            $this->p->call('GET', 'panel_nothing', null, $d['token']), $this->p->call('DELETE', 'panel_me', null, $d['token'])] as [, , $j]) {
            $this->assertArrayHasKey('server_time', $j);
            $this->assertLessThan(5, abs(strtotime($j['server_time']) - $now));
        }
        [$c] = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b', 'collected_at' => '2099-01-01T00:00:00Z', 'client_time' => '2099-01-01T00:00:00Z'], $d['token']);
        $this->assertSame(201, $c, 'the RMM "collected_at in the future" rule does not apply');
        [, , $j] = $this->p->call('POST', 'panel_enroll', ['enrollment_token' => 'x', 'device' => ['a']]);
        $this->assertArrayHasKey('server_time', $j);
    }

    public function testAuditRowsForTicketChatRevokeAndTokens(): void
    {
        $d = $this->contactDevice();
        [, , $t] = $this->create($d['token'], ['attachments' => [self::pngAttachment()]]);
        $this->p->call('POST', 'panel_ticket_chat', ['body' => 'hello'], $d['token'], [], [(string) $t['ticket_id']]);
        $this->p->module->admin()->revokeDevice($d['device_id'], 'test', 1);
        $by = [];
        foreach ($this->p->rmm->audit->records() as $r) {
            $by[$r['action']][] = $r;
        }
        $this->assertSame([$this->p->clientA, $t['ticket_id']], [$by['Panel Ticket Created'][0]['client_id'], $by['Panel Ticket Created'][0]['entity_id']]);
        $this->assertStringContainsString('1 attachment', $by['Panel Ticket Created'][0]['description']);
        $this->assertSame($t['ticket_id'], $by['Panel Chat Posted'][0]['entity_id']);
        $this->assertSame($d['device_id'], $by['Panel Device Revoked'][0]['entity_id']);
        $this->assertArrayHasKey('Panel Enrolled', $by);
        foreach ($this->p->rmm->audit->records() as $r) {
            $this->assertStringNotContainsString('Printer down', $r['description'], 'ticket text stays out of the audit trail');
            $this->assertStringNotContainsString($d['token'], $r['description']);
        }
    }

    public function testADailyCapLimitsTicketsPerDevice(): void
    {
        $small = new \RivetCore\Tests\Support\PanelHarness(['limits' => ['ticketsPerDayMax' => 2]]);
        $contact = $small->gateway->addClientContact($small->clientA, 'X');
        $dev = $small->device_for($small->clientA, $contact);
        $r = fn () => $small->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $dev['token']);
        $this->assertSame(201, $r()[0]);
        $this->assertSame(201, $r()[0]);
        [$code, $h, $j] = $r();
        $this->assertSame([429, 'rate_limited', '3600'], [$code, $j['code'], $h['Retry-After']]);
        $this->assertCount(2, $small->gateway->created);
    }

    // ------------------------------------------------------------------ idempotent creation

    private const RID = '11111111-2222-4333-8444-555555555555';

    private function owners(): int
    {
        return (int) $this->p->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner');
    }

    public function testARetryAfterSuccessReturnsTheSameTicketWithoutCallingTheGateway(): void
    {
        $d = $this->contactDevice();
        [$c1, , $a] = $this->create($d['token'], ['client_request_id' => self::RID]);
        [$c2, , $b] = $this->create($d['token'], ['client_request_id' => strtoupper(self::RID)]);
        $this->assertSame([201, false, 200, true], [$c1, $a['replayed'], $c2, $b['replayed']]);
        $this->assertSame($a['ticket_id'], $b['ticket_id']);
        $this->assertCount(1, $this->p->gateway->created);
        $this->assertSame(1, $this->owners());
    }

    public function testTwoInterleavedRetriesCreateExactlyOneTicket(): void
    {
        $d = $this->contactDevice();
        $inner = null;
        $this->p->gateway->duringCreate = function () use ($d, &$inner): void {
            $this->p->gateway->duringCreate = null;   // only the nested retry runs while the first is "in the gateway"
            $inner = $this->create($d['token'], ['client_request_id' => self::RID]);
        };
        [$c, , $first] = $this->create($d['token'], ['client_request_id' => self::RID]);
        $this->assertSame(201, $c);
        $this->assertNotNull($inner);
        $this->assertSame([409, 'request_in_progress'], [$inner[0], $inner[2]['code']]);
        $this->assertSame('2', $inner[1]['Retry-After'] ?? null);
        $this->assertCount(1, $this->p->gateway->created, 'the gateway was called once');
        // once the first has finished, the same retry is a plain replay of its ticket
        [$c, , $again] = $this->create($d['token'], ['client_request_id' => self::RID]);
        $this->assertSame([200, true, $first['ticket_id']], [$c, $again['replayed'], $again['ticket_id']]);
        $this->assertSame(1, $this->owners());
    }

    public function testAGatewayFailureReleasesTheClaimSoTheRetryProceeds(): void
    {
        $d = $this->contactDevice();
        $this->p->gateway->duringCreate = static function (): void {
            throw new \RuntimeException('the ticket store is down');
        };
        $this->assertSame(500, $this->create($d['token'], ['client_request_id' => self::RID])[0]);
        $this->assertSame(0, $this->owners(), 'no orphan reservation is left behind');
        $this->p->gateway->duringCreate = null;
        [$c, , $j] = $this->create($d['token'], ['client_request_id' => self::RID]);
        $this->assertSame([201, false], [$c, $j['replayed']]);
        $this->assertSame(1, $this->owners());
        $this->assertCount(1, $this->p->gateway->created);
    }

    public function testAGatewayThatReturnsNoIdAlsoReleasesTheClaim(): void
    {
        $d = $this->contactDevice();
        $gw = new class extends \RivetCore\Testing\InMemoryPanelTicketGateway {
            public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int
            {
                return 0;
            }
        };
        $h = new \RivetCore\Tests\Support\PanelHarness([], $gw, false);
        $dev = $h->device_for($h->clientA, $h->gateway->addClientContact($h->clientA, 'Zed'));
        $this->assertSame(500, $h->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b', 'client_request_id' => self::RID], $dev['token'])[0]);
        $this->assertSame(0, (int) $h->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner'));
    }

    public function testTheSameRequestIdFromDifferentDevicesDoesNotCollide(): void
    {
        $a = $this->contactDevice('Ann');
        $b = $this->contactDevice('Bob');
        [$ca, , $ta] = $this->create($a['token'], ['client_request_id' => self::RID]);
        [$cb, , $tb] = $this->create($b['token'], ['client_request_id' => self::RID]);
        $this->assertSame([201, 201], [$ca, $cb]);
        $this->assertNotSame($ta['ticket_id'], $tb['ticket_id']);
        $this->assertSame($ta['ticket_id'], $this->create($a['token'], ['client_request_id' => self::RID])[2]['ticket_id']);
        $this->assertSame($tb['ticket_id'], $this->create($b['token'], ['client_request_id' => self::RID])[2]['ticket_id']);
    }

    public function testAnAbandonedClaimBlocksOnlyUntilItsTtlThenARetryTakesItOver(): void
    {
        $d = $this->contactDevice();
        // a call that died after its claim: pending row, no ticket
        $this->p->rmm->q("INSERT INTO panel_ticket_owner (ticket_id, device_id, client_id, contact_id, request_id, created_at) VALUES (NULL, {$d['device_id']}, {$this->p->clientA}, {$d['contact']}, '" . self::RID . "', '" . gmdate('Y-m-d H:i:s') . "')");
        $this->assertSame(409, $this->create($d['token'], ['client_request_id' => self::RID])[0]);
        $this->p->rmm->q("UPDATE panel_ticket_owner SET created_at = '" . gmdate('Y-m-d H:i:s', time() - 400) . "'");
        [$c, , $j] = $this->create($d['token'], ['client_request_id' => self::RID]);
        $this->assertSame([201, false], [$c, $j['replayed']]);
        $this->assertSame(1, $this->owners());
        // and the pending row never showed up as a ticket of the device
        $this->assertSame([$j['ticket_id']], array_column($this->p->call('GET', 'panel_tickets', null, $d['token'])[2]['tickets'], 'ticket_id'));
    }

    public function testRequestsWithoutAnIdAreNeverMergedAndLeaveNoPendingRows(): void
    {
        $d = $this->contactDevice();
        $one = $this->create($d['token'])[2]['ticket_id'];
        $two = $this->create($d['token'])[2]['ticket_id'];
        $this->assertNotSame($one, $two);
        $this->assertSame(0, (int) $this->p->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner WHERE ticket_id IS NULL'));
    }
}
