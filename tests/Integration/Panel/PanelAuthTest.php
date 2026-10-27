<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Panel;

use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PanelHarness;
use RivetCore\Tests\Support\PanelTestCase;
use RivetCore\Tests\Support\RmmHarness;

final class PanelAuthTest extends PanelTestCase
{
    private const PANEL_ENDPOINTS = [['GET', 'panel_me'], ['GET', 'panel_categories'], ['GET', 'panel_tickets'], ['POST', 'panel_tickets'], ['GET', 'panel_ticket_chat'], ['POST', 'panel_ticket_chat']];
    private const AGENT_ENDPOINTS = ['agent_checkin', 'agent_jobs', 'agent_update', 'agent_transfer', 'agent_logs', 'agent_changes'];

    public function testEveryPanelEndpointRequiresACredential(): void
    {
        foreach (self::PANEL_ENDPOINTS as [$m, $e]) {
            [$c, , $j] = $this->p->call($m, $e, $m === 'POST' ? ['subject' => 's', 'body' => 'b'] : null, null, [], ['1']);
            $this->assertSame([401, 'invalid_token'], [$c, $j['code']], "$m $e");
        }
        foreach (['', 'Bearer', 'Bearer x', 'Basic abc', 'bearer rvpd_' . str_repeat('0', 64) . 'x', 'Bearer rvpd_' . str_repeat('G', 64), 'Bearer ' . str_repeat('a', 64)] as $h) {
            $r = $this->p->api->handle($this->p->request('GET', 'panel_me', null, null, [], [], '127.0.0.1', true, $h === '' ? [] : ['authorization' => $h]));
            $this->assertSame(401, $r->status, $h);
        }
    }

    public function testAPanelTokenNeverWorksOnAnAgentEndpointAndAnAgentTokenNeverOnAPanelEndpoint(): void
    {
        $this->p->rmm->enable();
        $panel = $this->p->device_for($this->p->clientA, $this->p->gateway->addClientContact($this->p->clientA, 'Jane'));
        [, , $agent] = $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device());
        $agentToken = $agent['device_token'];
        $this->assertSame(64, strlen($agentToken));

        foreach (self::AGENT_ENDPOINTS as $e) {
            foreach (['GET', 'POST'] as $m) {
                [$c, , $j] = $this->p->rmm->call($m, $e, $m === 'POST' ? ['seq' => 1, 'collected_at' => RmmHarness::ts()] : null, $panel['token']);
                $this->assertContains($c, [401, 405, 503], "$m $e with a panel token");   // 503: that agent feature is switched off here, before any credential is looked at
                if ($c === 401) {
                    $this->assertSame('invalid_token', $j['code']);
                }
            }
        }
        $this->assertSame(401, $this->p->rmm->checkin($panel['token'])[0]);
        $this->assertSame(200, $this->p->rmm->checkin($agentToken)[0], 'control: the agent token works on the agent endpoint');

        foreach (self::PANEL_ENDPOINTS as [$m, $e]) {
            [$c, , $j] = $this->p->call($m, $e, $m === 'POST' ? ['subject' => 's', 'body' => 'b'] : null, $agentToken, [], ['1']);
            $this->assertSame([401, 'invalid_token'], [$c, $j['code']], "$m $e with an agent token");
        }
        // even a panel device row reached with a hash that matches (hand-built bearer in the agent format) is refused by the agent repository
        $plain = bin2hex(random_bytes(32));
        $this->p->rmm->q("UPDATE endpoint_agent_devices SET token_hash='" . hash('sha256', $plain) . "' WHERE device_id=" . (int) $panel['device_id']);
        $this->assertSame(401, $this->p->rmm->checkin($plain)[0], 'kind=panel is not an agent');
        $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $plain)[0], 'and the panel API wants the rvpd_ shape');
        // an agent row dressed with the panel shape is refused by the panel authenticator
        $fake = 'rvpd_' . bin2hex(random_bytes(32));
        $this->p->rmm->q("UPDATE endpoint_agent_devices SET token_hash='" . hash('sha256', $fake) . "' WHERE device_id=" . (int) $agent['device_id']);
        $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $fake)[0], 'kind=agent is not a panel');
    }

    public function testARevokedDeviceIsRefusedImmediatelyByEveryRevocationRoute(): void
    {
        $this->p->rmm->enable();
        $a = $this->p->device_for();
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $a['token'])[0]);
        $this->assertTrue($this->p->rmm->module->deviceService()->revoke($a['device_id'], 'via the RMM device page', 1), 'the existing mechanism works on a panel device');
        [$c, , $j] = $this->p->call('GET', 'panel_me', null, $a['token']);
        $this->assertSame([401, 'revoked'], [$c, $j['code']]);
        $this->assertContains('Panel Device Revoked', $this->p->actions());
        $this->assertNotContains('Device Revoked', $this->p->actions());

        $b = $this->p->device_for();
        $this->assertTrue($this->p->module->admin()->revokeDevice($b['device_id'], 'via the panel admin', 1));
        $this->assertFalse($this->p->module->admin()->revokeDevice($b['device_id'], 'again', 1));
        $this->assertSame([401, 'revoked'], [$this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $b['token'])[0], $this->p->call('GET', 'panel_categories', null, $b['token'])[2]['code']]);
        $this->assertSame([], $this->p->gateway->created);

        $c2 = $this->p->device_for();
        $this->assertTrue($this->p->rmm->module->deviceService()->retire($c2['device_id'], 1));
        $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $c2['token'])[0]);

        $d = $this->p->device_for();
        $this->assertTrue($this->p->rmm->module->deviceService()->rotate($d['device_id'], 1));
        $this->assertSame('invalid_token', $this->p->call('GET', 'panel_me', null, $d['token'])[2]['code']);
    }

    public function testRevokingAnAgentThroughThePanelAdminIsRefused(): void
    {
        $this->p->rmm->enable();
        [, , $agent] = $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device());
        $this->assertFalse($this->p->module->admin()->revokeDevice($agent['device_id'], 'oops', 1));
        $this->assertSame(200, $this->p->rmm->checkin($agent['device_token'])[0]);
    }

    public function testAnExpiredDeviceCredentialIsRefused(): void
    {
        $a = $this->p->device_for();
        /** @var MutableClock $clock */
        $clock = $this->p->rmm->clock;
        $clock->advance(366 * 86400);
        [$c, , $j] = $this->p->call('GET', 'panel_me', null, $a['token']);
        $this->assertSame([401, 'expired'], [$c, $j['code']]);
    }

    public function testAContactArchivedAfterEnrollmentStopsTheDevice(): void
    {
        $contact = $this->p->gateway->addClientContact($this->p->clientA, 'Leaver');
        $a = $this->p->device_for($this->p->clientA, $contact);
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $a['token'])[0]);
        $this->p->gateway->archiveContact($contact);
        [$c, , $j] = $this->p->call('GET', 'panel_categories', null, $a['token']);
        $this->assertSame([403, 'contact_invalid'], [$c, $j['code']]);
    }

    /** rc.38 (security review RC36 L6): a client-level device of a client that is gone no longer works. */
    public function testADeviceOfADeletedOrArchivedClientStopsWorkingEvenWithoutAContact(): void
    {
        $client = $this->p->rmm->tenancy->addClient('Offboarded Ltd');
        $a = $this->p->device_for($client);
        $this->p->module->settings()->setAllowClientLevelTickets($client, true, 1);
        $this->assertSame(201, $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $a['token'])[0]);
        $this->p->rmm->tenancy->removeClient($client);
        foreach (['GET' => 'panel_me', 'POST' => 'panel_tickets'] as $method => $endpoint) {
            [$c, , $j] = $this->p->call($method, $endpoint, $method === 'POST' ? ['subject' => 's', 'body' => 'b'] : null, $a['token']);
            $this->assertSame([403, 'client_invalid'], [$c, $j['code']], $endpoint);
        }
        $this->assertCount(1, $this->p->gateway->created, 'nothing more was created');
        // another client\'s device is untouched
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $this->p->device_for()['token'])[0]);
    }

    public function testPerDeviceRateLimitsAreIndependent(): void
    {
        $c1 = $this->p->gateway->addClientContact($this->p->clientA, 'One');
        $a = $this->p->device_for($this->p->clientA, $c1);
        $b = $this->p->device_for($this->p->clientA, $c1);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(201, $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $a['token'])[0]);
        }
        [$c, $h, $j] = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $a['token']);
        $this->assertSame([429, 'rate_limited', '60'], [$c, $j['code'], $h['Retry-After']]);
        $this->assertCount(10, $this->p->gateway->created);
        $this->assertSame(201, $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $b['token'])[0], 'another device has its own budget');
        $this->assertSame(200, $this->p->call('GET', 'panel_categories', null, $a['token'])[0], 'reads have their own budget');
        $this->p->rateOverride["panel_chat:{$a['device_id']}"] = 1;
        $t = $this->p->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $b['token'])[2]['ticket_id'];
        $this->assertSame(201, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x'], $a['token'], [], [(string) $t])[0]);
        $this->assertSame(429, $this->p->call('POST', 'panel_ticket_chat', ['body' => 'x'], $a['token'], [], [(string) $t])[0]);
        $this->p->rateOverride["panel_read:{$a['device_id']}"] = 2;
        $this->p->call('GET', 'panel_me', null, $a['token']);
        $this->p->call('GET', 'panel_me', null, $a['token']);
        $this->assertSame(429, $this->p->call('GET', 'panel_me', null, $a['token'])[0]);
    }

    public function testFailedCredentialChecksAreRateLimitedPerAddress(): void
    {
        $bad = 'rvpd_' . str_repeat('1', 64);
        for ($i = 0; $i < 30; $i++) {
            $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $bad, [], [], '198.51.100.7')[0]);
        }
        $this->assertSame(429, $this->p->call('GET', 'panel_me', null, $bad, [], [], '198.51.100.7')[0]);
        $a = $this->p->device_for();
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $a['token'], [], [], '198.51.100.7')[0], 'a valid credential is not counted as a failure');
    }

    public function testTransportMethodsBodiesAndTheModuleSwitch(): void
    {
        $contact = $this->p->gateway->addClientContact($this->p->clientA, 'J');
        $a = $this->p->device_for($this->p->clientA, $contact);
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $a['token'], [], [], '127.0.0.1', false)[0], 'insecure transport is allowed only when the edition says so (loopback tests)');
        [$c, $h, $j] = $this->p->call('DELETE', 'panel_tickets', null, $a['token']);
        $this->assertSame([405, 'method_not_allowed', 'GET, POST'], [$c, $j['code'], $h['Allow']]);
        $this->assertSame(405, $this->p->call('POST', 'panel_me', [], $a['token'])[0]);
        $this->assertSame(405, $this->p->call('GET', 'panel_enroll')[0]);
        $this->assertSame(404, $this->p->call('GET', 'panel_unknown', null, $a['token'])[0]);
        $this->assertSame(422, $this->p->call('POST', 'panel_tickets', 'not json', $a['token'])[0]);
        $this->assertSame(422, $this->p->call('POST', 'panel_tickets', '[1,2]', $a['token'])[0]);
        $this->assertSame(422, $this->p->call('POST', 'panel_tickets', '"str"', $a['token'])[0]);
        $limit = $this->p->module->limits()->postBodyMax();
        $this->assertSame(413, $this->p->call('POST', 'panel_tickets', str_repeat('x', $limit + 1), $a['token'])[0]);
        $r = $this->p->api->handle($this->p->request('POST', 'panel_tickets', '{}', $a['token'], [], [], '127.0.0.1', true, [], $limit + 1));
        $this->assertSame(413, $r->status, 'a declared length over the cap is refused before reading');
        $this->assertSame(413, $this->p->call('POST', 'panel_enroll', str_repeat('x', 9000))[0]);

        $on = true;
        $off = new PanelHarness(['enabled' => static function () use (&$on): bool {
            return $on;
        }]);
        $d = $off->device_for();
        $on = false;
        [$c, $h, $j] = $off->call('GET', 'panel_me', null, $d['token']);
        $this->assertSame([503, 'module_disabled', '3600'], [$c, $j['code'], $h['Retry-After']]);
        $this->assertSame(503, $off->call('POST', 'panel_enroll', [])[0]);
    }

    public function testTlsIsRequiredUnlessTheEditionAllowsPlainHttp(): void
    {
        $strict = new PanelHarness(['allow_insecure_http' => false]);
        $d = $strict->device_for();
        [$c, , $j] = $strict->call('GET', 'panel_me', null, $d['token'], [], [], '127.0.0.1', false);
        $this->assertSame([426, 'tls_required'], [$c, $j['code']]);
        $this->assertSame(426, $strict->call('POST', 'panel_enroll', [], null, [], [], '127.0.0.1', false)[0]);
        $this->assertSame(200, $strict->call('GET', 'panel_me', null, $d['token'])[0]);
    }

    public function testAnInternalErrorIsAGeneric500(): void
    {
        $gw = new class extends \RivetCore\Testing\InMemoryPanelTicketGateway {
            public function categories(int $clientId): array
            {
                throw new \RuntimeException('secret detail /var/www/db password=hunter2');
            }
        };
        $h = new PanelHarness([], $gw);
        $d = $h->device_for();
        [$c, , $j, $r] = $h->call('GET', 'panel_categories', null, $d['token']);
        $this->assertSame([500, 'internal'], [$c, $j['code']]);
        $this->assertStringNotContainsString('hunter2', (string) $r->body);
        $this->assertArrayHasKey('server_time', $j);
    }

    public function testAGatewayThatReturnsNoIdIsAnInternalErrorNotASilentSuccess(): void
    {
        $gw = new class extends \RivetCore\Testing\InMemoryPanelTicketGateway {
            public function createTicket(int $clientId, ?int $contactId, string $subject, string $body, ?int $categoryId, string $requesterHint, array $attachments): int
            {
                return 0;
            }
        };
        $h = new PanelHarness([], $gw);
        $d = $h->device_for($h->clientA, $gw->addClientContact($h->clientA, 'J'));
        $this->assertSame(500, $h->call('POST', 'panel_tickets', ['subject' => 's', 'body' => 'b'], $d['token'])[0]);
        $this->assertSame(0, (int) $h->rmm->one('SELECT COUNT(*) FROM panel_ticket_owner'));
    }

    public function testALaxGatewayCannotWidenAccessThroughTheList(): void
    {
        $gw = new class extends \RivetCore\Testing\InMemoryPanelTicketGateway {
            public function listTickets(int $clientId, ?int $contactId, array $ownedTicketIds, int $limit, int $offset): array
            {
                return array_values(array_map(fn (array $t): array => $this->header($t), $this->tickets));   // ignores every filter
            }
        };
        $h = new PanelHarness([], $gw);
        $mine = $gw->addClientContact($h->clientA, 'Me');
        $d = $h->device_for($h->clientA, $mine);
        $t = $h->call('POST', 'panel_tickets', ['subject' => 'mine', 'body' => 'b'], $d['token'])[2]['ticket_id'];
        $gw->addTicketFor($h->clientB, null, 'other client');
        $gw->addTicketFor($h->clientA, $gw->addClientContact($h->clientA, 'Someone'), 'other contact');
        $this->assertSame([$t], array_column($h->call('GET', 'panel_tickets', null, $d['token'])[2]['tickets'], 'ticket_id'));
    }
}
