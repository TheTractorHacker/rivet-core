<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Panel;

use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PanelHarness;
use RivetCore\Tests\Support\PanelTestCase;
use RivetCore\Tests\Support\RmmHarness;

final class PanelEnrollmentTest extends PanelTestCase
{
    public function testAContactDeviceEnrollsAndOnlyTheHashIsStored(): void
    {
        $contact = $this->p->gateway->addClientContact($this->p->clientA, 'Jane Doe');
        $tok = $this->p->mint($this->p->clientA, $contact);
        $this->assertMatchesRegularExpression('/^rvtp1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', $tok['token']);
        [$c, $h, $j] = $this->p->enroll($tok['token'], ['hostname' => 'JANE-PC']);
        $this->assertSame(201, $c);
        $this->assertSame('no-store', $h['Cache-Control']);
        $this->assertMatchesRegularExpression('/^rvpd_[0-9a-f]{64}$/', $j['device_token']);
        $this->assertSame(['device_id', 'device_token', 'client', 'contact', 'can_create_tickets', 'limits', 'server_time'], array_keys($j));
        $this->assertSame($this->p->clientA, $j['client']['client_id']);
        $this->assertSame(['contact_id' => $contact, 'name' => 'Jane Doe'], $j['contact']);
        $this->assertTrue($j['can_create_tickets']);
        $row = $this->p->rmm->rows('SELECT * FROM endpoint_agent_devices WHERE device_id=' . (int) $j['device_id'])[0];
        $this->assertSame(hash('sha256', $j['device_token']), $row['token_hash']);
        $this->assertSame(['panel', 'panel', (string) $contact, null], [$row['kind'], $row['link_state'], $row['contact_id'], $row['asset_id']]);
        $this->assertSame('JANE-PC', $row['hostname']);
        $trow = $this->p->rmm->rows('SELECT * FROM endpoint_agent_enrollment_tokens')[0];
        $this->assertSame(['panel', '1', '1', hash('sha256', explode('.', $tok['token'])[2])], [$trow['kind'], $trow['max_uses'], $trow['use_count'], $trow['token_hash']]);
        $this->assertContains('Panel Enrolled', $this->p->actions());
        $this->assertContains('Panel Enrollment Token Created', $this->p->actions());
        foreach ($this->p->rmm->audit->records() as $r) {
            $this->assertStringNotContainsString(explode('.', $tok['token'])[2], $r['description']);
            $this->assertStringNotContainsString($j['device_token'], $r['description']);
        }
    }

    public function testAFleetTokenEnrolsUpToItsCapAndEachDeviceGetsItsOwnCredential(): void
    {
        $contact = $this->p->gateway->addContact($this->p->clientA, 'Jane Doe');
        $tok = $this->p->mint($this->p->clientA, $contact, 24, 3);
        $creds = [];
        for ($i = 0; $i < 3; $i++) {
            [$c, , $j] = $this->p->enroll($tok['token']);
            $this->assertSame(201, $c);
            $creds[] = $j['device_token'];
        }
        $this->assertCount(3, array_unique($creds));
        [$c, , $j] = $this->p->enroll($tok['token']);
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        $trow = $this->p->rmm->rows('SELECT * FROM endpoint_agent_enrollment_tokens')[0];
        $this->assertSame(['3', '3'], [$trow['max_uses'], $trow['use_count']]);
    }

    public function testAFleetTokenIsCappedAndASingleUseTokenStaysTheDefault(): void
    {
        $this->p->mint($this->p->clientA, null, 24, 1000000);
        $this->p->mint($this->p->clientA, null, 24, 0);
        $this->p->mint($this->p->clientA, null);
        $uses = array_map('intval', array_column($this->p->rmm->rows('SELECT max_uses FROM endpoint_agent_enrollment_tokens ORDER BY token_id'), 'max_uses'));
        $this->assertSame([500, 1, 1], $uses);
    }

    public function testAClientLevelDeviceHasNoContactAndCannotCreateUntilTheSettingIsOn(): void
    {
        [$c, , $j] = $this->p->enroll($this->p->mint($this->p->clientA, null)['token']);
        $this->assertSame(201, $c);
        $this->assertNull($j['contact']);
        $this->assertFalse($j['can_create_tickets']);
        $this->assertNull($this->p->rmm->rows('SELECT contact_id FROM endpoint_agent_devices')[0]['contact_id']);
    }

    public function testTheTokenIsSingleUseAndAReplayIsRefused(): void
    {
        $t = $this->p->mint()['token'];
        $this->assertSame(201, $this->p->enroll($t)[0]);
        [$c, , $j] = $this->p->enroll($t);
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        $this->assertSame(1, (int) $this->p->rmm->one('SELECT COUNT(*) FROM endpoint_agent_devices'));
    }

    public function testAnExpiredTokenIsRefused(): void
    {
        $t = $this->p->mint(null, null, 1)['token'];
        /** @var MutableClock $clock */
        $clock = $this->p->rmm->clock;
        $clock->advance(3601);
        [$c, , $j] = $this->p->enroll($t);
        $this->assertSame([401, 'expired'], [$c, $j['code']]);
    }

    public function testTheTtlIsCappedAtTheConfiguredMaximum(): void
    {
        $this->p->mint(null, null, 100000);
        $exp = strtotime((string) $this->p->rmm->one('SELECT expires_at FROM endpoint_agent_enrollment_tokens') . ' UTC');
        $this->assertLessThanOrEqual(time() + 72 * 3600 + 5, $exp);
    }

    public function testARevokedTokenIsRefusedAndOnlyPanelTokensCanBeRevokedHere(): void
    {
        $tok = $this->p->mint();
        $this->assertTrue($this->p->module->enrollment()->revokeToken($tok['token_id'], 1));
        $this->assertFalse($this->p->module->enrollment()->revokeToken($tok['token_id'], 1));
        [$c, , $j] = $this->p->enroll($tok['token']);
        $this->assertSame([401, 'revoked'], [$c, $j['code']]);
        $agentTok = $this->p->rmm->token();
        $agentId = (int) $this->p->rmm->one('SELECT token_id FROM endpoint_agent_enrollment_tokens WHERE kind=\'agent\'');
        $this->assertFalse($this->p->module->enrollment()->revokeToken($agentId, 1));
        $this->assertSame(201, $this->p->rmm->enroll($agentTok, RmmHarness::device())[0]);
    }

    public function testMalformedAndWrongSecretTokensAre401InvalidToken(): void
    {
        $good = $this->p->mint()['token'];
        $parts = explode('.', $good);
        foreach (['', 'garbage', $parts[0] . '.' . $parts[1] . '.' . str_repeat('0', 40), 'rvtp1.' . str_repeat('0', 12) . '.' . $parts[2], $parts[0] . '.' . $parts[1]] as $bad) {
            [$c, , $j] = $this->p->call('POST', 'panel_enroll', ['enrollment_token' => $bad, 'device' => PanelHarness::device()]);
            $this->assertSame([401, 'invalid_token'], [$c, $j['code']], $bad);
        }
        $this->assertSame(201, $this->p->enroll($good)[0], 'failed guesses do not burn the real token');
    }

    public function testAnRmmEnrollmentTokenIsNotAPanelTokenAndViceVersa(): void
    {
        $agent = $this->p->rmm->token();
        [$c, , $j] = $this->p->enroll($agent);
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
        $panel = $this->p->mint()['token'];
        [$c, , $j] = $this->p->rmm->enroll($panel, RmmHarness::device());
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->p->rmm->one('SELECT use_count FROM endpoint_agent_enrollment_tokens WHERE kind=\'panel\''));
        // even a panel-kind row dressed with the RMM prefix (hand-built) is refused by the RMM enrollment
        $sel = bin2hex(random_bytes(6));
        $sec = bin2hex(random_bytes(20));
        $this->p->rmm->q("INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, client_id, expires_at, max_uses, kind) VALUES ('$sel', '" . hash('sha256', $sec) . "', {$this->p->clientA}, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), 5, 'panel')");
        [$c, , $j] = $this->p->rmm->enroll("rvte1.$sel.$sec", RmmHarness::device());
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
        // and an agent-kind row dressed with the panel prefix is refused by the panel enrollment
        $sel2 = bin2hex(random_bytes(6));
        $sec2 = bin2hex(random_bytes(20));
        $this->p->rmm->q("INSERT INTO endpoint_agent_enrollment_tokens (token_selector, token_hash, client_id, expires_at, max_uses, kind) VALUES ('$sel2', '" . hash('sha256', $sec2) . "', {$this->p->clientA}, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY), 5, 'agent')");
        [$c, , $j] = $this->p->enroll("rvtp1.$sel2.$sec2");
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
    }

    public function testEnrollmentFailuresAreRateLimitedPerAddress(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(401, $this->p->enroll('rvtp1.' . bin2hex(random_bytes(6)) . '.' . bin2hex(random_bytes(20)), [], '203.0.113.9')[0]);
        }
        [$c, $h, $j] = $this->p->enroll($this->p->mint()['token'], [], '203.0.113.9');
        $this->assertSame([429, 'rate_limited'], [$c, $j['code']]);
        $this->assertSame('600', $h['Retry-After']);
        $this->assertSame(201, $this->p->enroll($this->p->mint()['token'], [], '203.0.113.10')[0], 'another address is unaffected');
        $this->assertContains('Panel Enrollment Failed', $this->p->actions());
    }

    public function testMintingValidatesClientAndContact(): void
    {
        $other = $this->p->gateway->addClientContact($this->p->clientB, 'Bob');
        try {
            $this->p->mint($this->p->clientA, $other);
            $this->fail('a contact of another client must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('contact', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->p->mint(999999);
    }

    public function testAContactArchivedBeforeEnrollmentRefusesAndKeepsTheToken(): void
    {
        $contact = $this->p->gateway->addClientContact($this->p->clientA, 'Gone');
        $t = $this->p->mint($this->p->clientA, $contact)['token'];
        $this->p->gateway->archiveContact($contact);
        [$c, , $j] = $this->p->enroll($t);
        $this->assertSame([403, 'contact_invalid'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->p->rmm->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'));
    }

    public function testReEnrollmentRotatesTheCredentialAndKeepsTheDevice(): void
    {
        $install = RmmHarness::uuid();
        [, , $a] = $this->p->enroll($this->p->mint()['token'], ['install_id' => $install]);
        [$c, , $b] = $this->p->enroll($this->p->mint()['token'], ['install_id' => strtoupper($install)]);
        $this->assertSame(201, $c);
        $this->assertSame($a['device_id'], $b['device_id']);
        $this->assertNotSame($a['device_token'], $b['device_token']);
        $this->assertSame(401, $this->p->call('GET', 'panel_me', null, $a['device_token'])[0], 'the old credential is dead');
        $this->assertSame(200, $this->p->call('GET', 'panel_me', null, $b['device_token'])[0]);
        $this->assertSame(1, (int) $this->p->rmm->one('SELECT COUNT(*) FROM endpoint_agent_devices'));
        $this->assertSame(2, (int) $this->p->rmm->one('SELECT enroll_count FROM endpoint_agent_devices'));
    }

    public function testARevokedDeviceCannotReEnrollUntilAnAdministratorAllowsIt(): void
    {
        $install = RmmHarness::uuid();
        [, , $a] = $this->p->enroll($this->p->mint()['token'], ['install_id' => $install]);
        $this->assertTrue($this->p->module->admin()->revokeDevice($a['device_id'], 'lost laptop', 1));
        [$c, , $j] = $this->p->enroll($this->p->mint()['token'], ['install_id' => $install]);
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        $this->assertTrue($this->p->rmm->module->deviceService()->allowReenroll($a['device_id'], 1));
        $this->assertSame(201, $this->p->enroll($this->p->mint()['token'], ['install_id' => $install])[0]);
        $this->assertSame('panel', $this->p->rmm->one('SELECT link_state FROM endpoint_agent_devices'));
    }

    public function testAnInstallIdOfAnAgentOrAnotherClientIsNeverTakenOver(): void
    {
        $this->p->rmm->enable();
        $agentInstall = RmmHarness::uuid();
        $this->assertSame(201, $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device(['install_id' => $agentInstall]))[0]);
        [$c, , $j] = $this->p->enroll($this->p->mint()['token'], ['install_id' => $agentInstall]);
        $this->assertSame([409, 'conflict'], [$c, $j['code']]);
        $own = RmmHarness::uuid();
        $this->assertSame(201, $this->p->enroll($this->p->mint($this->p->clientA)['token'], ['install_id' => $own])[0]);
        [$c, , $j] = $this->p->enroll($this->p->mint($this->p->clientB)['token'], ['install_id' => $own]);
        $this->assertSame([409, 'conflict'], [$c, $j['code']]);
        // and the other way round: an RMM enrollment with a panel install id
        [$c, , $j] = $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device(['install_id' => $own]));
        $this->assertSame([409, 'conflict'], [$c, $j['code']]);
        $this->assertSame('panel', $this->p->rmm->one("SELECT kind FROM endpoint_agent_devices WHERE install_id='$own'"));
    }

    public function testInvalidDevicePayloadsAreRejectedWithoutBurningTheToken(): void
    {
        $t = $this->p->mint()['token'];
        foreach ([['install_id' => 'nope'], ['os' => 'plan9'], ['hostname' => ''], ['hostname' => str_repeat('a', 201)], ['os_version' => ['x']]] as $over) {
            [$c, , $j] = $this->p->enroll($t, $over);
            $this->assertSame([422, 'invalid'], [$c, $j['code']], json_encode($over));
        }
        foreach ([[], 'x', ['enrollment_token' => 5, 'device' => []], ['enrollment_token' => $t, 'device' => 'x'], ['enrollment_token' => $t, 'device' => [1, 2]]] as $body) {
            $this->assertSame(422, $this->p->call('POST', 'panel_enroll', $body)[0]);
        }
        $this->assertSame(201, $this->p->enroll($t)[0]);
    }

    public function testHostnameBidiAndControlCharactersAreNeutralised(): void
    {
        [, , $j] = $this->p->enroll($this->p->mint()['token'], ['hostname' => "PC\u{202E}evil\x00\u{200B}txt"]);
        $this->assertSame('PCeviltxt', $this->p->rmm->one('SELECT hostname FROM endpoint_agent_devices WHERE device_id=' . (int) $j['device_id']));
    }

    public function testPanelDevicesAreVisibleInTheDeviceListButDoNotCountTowardsTheDeviceLimit(): void
    {
        $this->p->device_for();
        $this->assertSame(0, $this->p->rmm->module->devices()->liveCount());
        $this->p->rmm->enable();
        $this->p->rmm->q('UPDATE endpoint_agent_settings SET max_devices = 1');
        $this->assertSame(201, $this->p->rmm->enroll($this->p->rmm->token(), RmmHarness::device())[0], 'the panel device does not use the licence');
        $this->assertSame(1, $this->p->rmm->module->devices()->liveCount());
        $row = $this->p->rmm->rows("SELECT * FROM endpoint_agent_devices WHERE kind='panel'")[0];
        $this->assertSame('panel', $row['link_state']);
        $list = $this->p->module->admin()->devices($this->p->clientA);
        $this->assertCount(1, $list);
        $this->assertSame(['panel', null], [$row['kind'], $row['asset_id']]);
    }

    public function testThePanelDeviceListAndTokenListAreScopedToTheClient(): void
    {
        $this->p->device_for($this->p->clientA);
        $this->p->device_for($this->p->clientB);
        $this->assertCount(1, $this->p->module->admin()->devices($this->p->clientA));
        $this->assertCount(1, $this->p->module->admin()->tokens($this->p->clientB));
        $this->assertSame(1, $this->p->module->admin()->tokens($this->p->clientB)[0]['use_count']);
        $this->assertSame([], $this->p->module->admin()->devices(424242));
    }
}
