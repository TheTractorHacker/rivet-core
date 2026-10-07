<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Port of RivetIT tests/endpoint_agent_enroll.php: tokens (new / invalid / expired / revoked / max uses / scope), rate limiting,
 * asset matching, re-enrollment and identity, admin resolution of pending devices. Real tables, strict SQL, in-memory edition adapters.
 */
final class EnrollmentTest extends RmmTestCase
{
    public function testDisabledServiceRefusesEnrollmentAndIsOffByDefault(): void
    {
        [$c, , $j] = $this->h->enroll('rvte1.000000000000.' . str_repeat('0', 40), $this->h::device());
        $this->assertSame(403, $c);
        $this->assertSame('forbidden', $j['code']);
        $this->assertSame(0, (int) $this->h->one('SELECT enabled FROM endpoint_agent_settings WHERE id=1'));
    }

    public function testEnablingMintsTheSigningKeyOnceAndTheIntegrationOnce(): void
    {
        $this->h->token();
        $row = $this->h->rows('SELECT * FROM endpoint_agent_settings')[0];
        $this->assertNotSame('', $row['signing_public_key']);
        $this->assertSame(Signer::keyId((string) $row['signing_public_key']), $row['signing_key_id']);
        // the private key is stored sealed with the edition's SecretBox, never as the raw base64 key
        $sec = $this->h->box->decrypt((string) $row['signing_private_key_enc']);
        $this->assertSame(SODIUM_CRYPTO_SIGN_SECRETKEYBYTES, strlen((string) base64_decode($sec, true)));
        $this->assertNotSame($sec, $row['signing_private_key_enc']);
        $this->assertGreaterThan(0, $this->h->integrationId());
        $kid = $row['signing_key_id'];
        $this->h->module->settings()->enable();   // again: no new key
        $this->assertSame($kid, $this->h->rows('SELECT signing_key_id FROM endpoint_agent_settings')[0]['signing_key_id']);
        $this->assertSame($this->h->integrationId(), $this->h->module->settings()->integrationId());
    }

    public function testTransportAndBodyGuards(): void
    {
        $this->h->token();
        [$c, $hd, $j] = $this->h->call('GET', 'agent_enroll');
        $this->assertSame(405, $c);
        $this->assertSame('method_not_allowed', $j['code']);
        $this->assertSame('POST', $hd['Allow']);
        [$c, , $j] = $this->h->call('POST', 'agent_enroll', 'not json');
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $this->h->call('POST', 'agent_enroll', str_repeat('x', 20000));
        $this->assertSame(413, $c);
        [$c, , $j] = $this->h->call('POST', 'agent_enroll', '[]');
        $this->assertSame([422, 'enrollment_token and device are required'], [$c, $j['error']]);
    }

    public function testNewEnrollmentIssuesACredentialAndStoresOnlyItsHash(): void
    {
        $tok = $this->h->token(null, 24, 60);
        $d1 = $this->h::device(['hostname' => 'WS-ONE', 'serial' => 'SER-ONE-1', 'mac_addresses' => ['AA-BB-CC-00-00-01']]);
        [$c, , $j] = $this->h->enroll($tok, $d1);
        $this->assertSame(201, $c);
        $this->assertSame(64, strlen($j['device_token']));
        $this->assertSame(['device_id', 'device_token', 'check_in_interval_s', 'server_time', 'status', 'matched_asset_id', 'signing_public_key', 'signing_key_id', 'config'], array_keys($j));
        $this->assertNotEmpty($j['config']['checks'][0]['signature']);
        $this->assertSame('pending_approval', $j['status']);
        $this->assertNull($j['matched_asset_id']);
        $id = (int) $j['device_id'];
        $this->assertSame(hash('sha256', $j['device_token']), $this->h->one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id=$id"));
        $this->assertSame(1, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'));
        $actions = array_column($this->h->audit->records(), 'action');
        $this->assertContains('Enrolled', $actions);
        $secret = explode('.', $tok)[2];
        foreach ($this->h->audit->records() as $r) {
            $this->assertStringNotContainsString($secret, $r['description']);
        }
        // the checks in the response verify with the key returned at enrollment
        foreach ($j['config']['checks'] as $check) {
            $this->assertTrue(Signer::verify(Signer::checkMessage($check), $check['signature'], $j['signing_public_key']));
        }
    }

    public function testInvalidTokensAndDevicesAreRefusedAndRecorded(): void
    {
        $tok = $this->h->token();
        foreach (['rvte1.' . str_repeat('a', 12) . '.' . str_repeat('b', 40), 'garbage'] as $bad) {
            [$c, , $j] = $this->h->enroll($bad, $this->h::device());
            $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
        }
        foreach ([['arch' => 'sparc'], ['install_id' => 'nope'], ['os' => 'plan9']] as $over) {
            [$c, , $j] = $this->h->enroll($tok, $this->h::device($over));
            $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        }
        $this->assertGreaterThanOrEqual(5, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE success=0'));
        $this->assertGreaterThanOrEqual(5, count(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Enrollment Failed')));
        $this->assertSame(0, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'));
        // a wrong secret with a known selector costs the same and is refused the same
        $wrong = 'rvte1.' . explode('.', $tok)[1] . '.' . str_repeat('e', 40);
        [$c, , $j] = $this->h->enroll($wrong, $this->h::device());
        $this->assertSame([401, 'invalid_token'], [$c, $j['code']]);
    }

    public function testExpiredRevokedAndExhaustedTokens(): void
    {
        $this->h->enable();
        $svc = $this->h->module->enrollment();
        $exp = $svc->createToken($this->h->clientA, 0, 'stable', 1, 5, 'exp', 1)['token'];
        $this->h->q("UPDATE endpoint_agent_enrollment_tokens SET expires_at = '" . gmdate('Y-m-d H:i:s', time() - 60) . "' WHERE label='exp'");
        [$c, , $j] = $this->h->enroll($exp, $this->h::device());
        $this->assertSame([401, 'expired'], [$c, $j['code']]);
        $rev = $svc->createToken($this->h->clientA, 0, 'stable', 1, 5, 'rev', 1);
        $this->assertTrue($svc->revokeToken($rev['token_id'], 1));
        $this->assertFalse($svc->revokeToken($rev['token_id'], 1));
        [$c, , $j] = $this->h->enroll($rev['token'], $this->h::device());
        $this->assertSame([401, 'revoked'], [$c, $j['code']]);
        $this->assertGreaterThanOrEqual(1, count(array_filter($this->h->audit->records(), static fn (array $r): bool => $r['action'] === 'Enrollment Failed' && str_contains($r['description'], 'revoked'))));
        $one = $svc->createToken($this->h->clientA, 0, 'stable', 1, 1, 'single', 1)['token'];
        [$c] = $this->h->enroll($one, $this->h::device());
        $this->assertSame(201, $c);
        [$c, , $j] = $this->h->enroll($one, $this->h::device());
        $this->assertSame([403, 'forbidden'], [$c, $j['code']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_enroll_attempts WHERE reason='exhausted'"));
    }

    public function testTokenTtlAndMaxUsesAreCappedAndTheClientMustExist(): void
    {
        $this->h->enable();
        $t = $this->h->module->enrollment()->createToken($this->h->clientA, 0, 'weird-ring', 100000, 99999, 'cap', 1);
        $row = $this->h->rows('SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_id=' . $t['token_id'])[0];
        $this->assertLessThanOrEqual(5000, (int) $row['max_uses']);
        $this->assertLessThanOrEqual(time() + 72 * 3600 + 5, strtotime($row['expires_at'] . ' UTC'));
        $this->assertSame('stable', $row['ring']);
        $this->assertMatchesRegularExpression('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', $t['token']);
        $this->assertSame(hash('sha256', explode('.', $t['token'])[2]), $row['token_hash']);
        $this->expectException(\InvalidArgumentException::class);
        $this->h->module->enrollment()->createToken(999, 0, 'stable', 1, 1, 'x', 1);
    }

    public function testReEnrollmentKeepsTheDeviceAndRotatesTheCredential(): void
    {
        $tok = $this->h->token(null, 24, 60);
        $d1 = $this->h::device(['hostname' => 'WS-ONE', 'serial' => 'SER-ONE-1']);
        [, , $j1] = $this->h->enroll($tok, $d1);
        [$c, , $j2] = $this->h->enroll($tok, $d1);
        $this->assertSame(201, $c);
        $this->assertSame($j1['device_id'], $j2['device_id']);
        $this->assertNotSame($j1['device_token'], $j2['device_token']);
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1, 'collected_at' => $this->h::ts(), 'agent_version' => '1.0.0'], $j1['device_token']);
        $this->assertSame([401, 'invalid_token'], [$c, $r['code']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE hostname='WS-ONE'"));
        $this->assertSame(2, (int) $this->h->one('SELECT enroll_count FROM endpoint_agent_devices WHERE device_id=' . $j1['device_id']));
        // same install id on a different machine: never merged
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['install_id' => $d1['install_id'], 'machine_guid' => 'ffffffffffffffff', 'serial' => 'OTHER-SERIAL']));
        $this->assertSame([409, 'conflict'], [$c, $j['code']]);
        // a 409 rolls the whole enrollment back: the token use it took is given back
        $this->assertSame(2, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'));
    }

    public function testReinstallByMachineGuidAndBySerial(): void
    {
        $tok = $this->h->token(null, 24, 60);
        $d1 = $this->h::device(['hostname' => 'WS-ONE', 'serial' => 'SER-ONE-1']);
        [, , $j1] = $this->h->enroll($tok, $d1);
        $dev = (int) $j1['device_id'];
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['machine_guid' => $d1['machine_guid'], 'serial' => $d1['serial'], 'hostname' => 'WS-ONE-RENAMED']));
        $this->assertSame([201, $dev], [$c, $j['device_id']]);
        $this->assertSame('WS-ONE-RENAMED', $this->h->one("SELECT hostname FROM endpoint_agent_devices WHERE device_id=$dev"));
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['machine_guid' => 'aaaaaaaaaaaaaaaa', 'serial' => $d1['serial']]));
        $this->assertSame([201, $dev], [$c, $j['device_id']]);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_devices'));
    }

    public function testAssetMatchingRules(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $link = $this->h->integrationId();
        $aSerial = $this->h->asset(['name' => 'Reception PC', 'serial' => 'SER-MATCH-1']);
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-MATCH-1', 'hostname' => 'totally-different']));
        $this->assertSame([201, 'linked', $aSerial], [$c, $j['status'], $j['matched_asset_id']]);
        $this->assertNotNull($this->h->bridge->link($link, 'rivetit:' . $j['device_id']));
        $this->assertSame('SER-MATCH-1', $this->h->assets->row($aSerial)['serial']);

        $aMac = $this->h->asset(['name' => 'Boardroom PC', 'serial' => 'SER-OTHER-2', 'macs' => ['AA:BB:CC:11:22:33']]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => null, 'mac_addresses' => ['aa-bb-cc-11-22-33'], 'hostname' => 'x']));
        $this->assertSame(['linked', $aMac], [$j['status'], $j['matched_asset_id']]);
        $cand = json_decode((string) $this->h->one('SELECT match_candidates_json FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']), true);
        $this->assertSame(['mac'], $cand[0]['matched_by']);

        $aHost = $this->h->asset(['name' => 'HOSTONLY-PC', 'serial' => 'SER-H']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'NOPE-1', 'hostname' => 'hostonly-pc']));
        $this->assertSame(['pending_approval', null], [$j['status'], $j['matched_asset_id']]);
        $cand = json_decode((string) $this->h->one('SELECT match_candidates_json FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']), true);
        $this->assertSame([$aHost, ['hostname']], [$cand[0]['asset_id'], $cand[0]['matched_by']]);
        $this->assertSame('hostname_only', $this->h->one('SELECT match_reason FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));

        $a1 = $this->h->asset(['name' => 'Amb 1', 'serial' => 'SER-AMB']);
        $a2 = $this->h->asset(['name' => 'Amb 2', 'serial' => 'SER-AMB-X', 'macs' => ['AA:00:00:00:00:99']]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-AMB', 'mac_addresses' => ['aa:00:00:00:00:99']]));
        $this->assertSame(['ambiguous', null], [$j['status'], $j['matched_asset_id']]);
        $this->assertNull($this->h->bridge->link($link, 'rivetit:' . $j['device_id']));

        $this->h->asset(['name' => 'Other dept PC', 'serial' => 'SER-OTHERDEPT', 'client_id' => $this->h->clientB]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-OTHERDEPT']));
        $this->assertSame('pending_approval', $j['status']);
        $this->assertSame('scope_mismatch', $this->h->one('SELECT match_reason FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));

        // a different machine claiming an asset a live device already owns is not merged
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'DIFFERENT-BOX', 'mac_addresses' => ['aa:bb:cc:11:22:33']]));
        $this->assertSame(['ambiguous', null], [$j['status'], $j['matched_asset_id']]);
        $this->assertSame('asset_already_linked', $this->h->one('SELECT match_reason FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
        // a new install of the SAME serial is a reinstall: same device, same asset
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-MATCH-1']));
        $this->assertSame(['linked', $aSerial], [$j['status'], $j['matched_asset_id']]);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(DISTINCT device_id) FROM endpoint_agent_devices WHERE asset_id=$aSerial"));
        foreach (['00', '', 'To be filled by O.E.M.', 'Default string'] as $junk) {
            [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => $junk, 'hostname' => 'JUNK-' . bin2hex(random_bytes(2))]));
            $this->assertSame(['pending_approval', null], [$j['status'], $j['matched_asset_id']], "junk serial '$junk'");
        }
        $this->assertGreaterThan(0, $a1 + $a2);
    }

    public function testAutoCreatePolicyCreatesTheAssetInTheTokenClient(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $this->h->module->settings()->set(['unmatched_policy' => 'auto_create']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'BRAND-NEW-1', 'hostname' => 'NEWBOX', 'os_version' => 'Windows Server 2022']));
        $this->assertSame('linked', $j['status']);
        $row = $this->h->assets->row((int) $j['matched_asset_id']);
        $this->assertSame([$this->h->clientA, 'Server', 'NEWBOX'], [$row['client_id'], $row['type'], $row['name']]);
        $this->assertSame('auto_created', $this->h->one('SELECT match_reason FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
    }

    public function testAdminResolutionOfPendingDevices(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $svc = $this->h->module->enrollment();
        $a1 = $this->h->asset(['name' => 'Amb 1', 'serial' => 'SER-AMB']);
        $a2 = $this->h->asset(['name' => 'Amb 2', 'serial' => 'SER-AMB-X', 'macs' => ['AA:00:00:00:00:99']]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-AMB', 'mac_addresses' => ['aa:00:00:00:00:99']]));
        $amb = (int) $j['device_id'];
        $r = $svc->resolvePending($amb, 'link', $a1, 1);
        $this->assertTrue($r['ok']);
        $this->assertSame([$a1, 'linked'], [(int) $this->h->one("SELECT asset_id FROM endpoint_agent_devices WHERE device_id=$amb"), $this->h->one("SELECT link_state FROM endpoint_agent_devices WHERE device_id=$amb")]);
        $this->assertNotNull($this->h->bridge->link($this->h->integrationId(), "rivetit:$amb"));
        $this->assertFalse($svc->resolvePending($amb, 'link', $a2, 1)['ok'], 'no longer pending');
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'ZZZ-PENDING', 'hostname' => 'PEND']));
        $pend = (int) $j['device_id'];
        $r = $svc->resolvePending($pend, 'link', $a1, 1);
        $this->assertFalse($r['ok']);
        $this->assertStringContainsString('already belongs', $r['message']);
        $this->assertFalse($svc->resolvePending($pend, 'link', 987654, 1)['ok']);
        $this->assertFalse($svc->resolvePending($pend, 'bogus', null, 1)['ok']);
        $r = $svc->resolvePending($pend, 'create_asset', null, 1);
        $this->assertTrue($r['ok']);
        $this->assertNotNull($this->h->bridge->link($this->h->integrationId(), "rivetit:$pend"));
        // reject revokes the credential
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'ZZZ-REJECT']));
        $this->assertTrue($svc->resolvePending((int) $j['device_id'], 'reject', null, 1)['ok']);
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1], $j['device_token']);
        $this->assertSame(401, $c);
    }

    public function testApprovalAcrossClientsMovesTheDeviceToTheAssetsClient(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $aB = $this->h->asset(['name' => 'B asset', 'serial' => 'SER-B', 'client_id' => $this->h->clientB]);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-B']));
        $this->assertSame('pending_approval', $j['status']);
        $this->assertTrue($this->h->module->enrollment()->resolvePending((int) $j['device_id'], 'link', $aB, 1)['ok']);
        $this->assertSame($this->h->clientB, (int) $this->h->one('SELECT client_id FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
    }

    public function testAnArchivedAssetSendsAReEnrollingDeviceBackToApproval(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $a = $this->h->asset(['name' => 'Soon gone', 'serial' => 'SER-GONE']);
        $d = $this->h::device(['serial' => 'SER-GONE']);
        [, , $j] = $this->h->enroll($tok, $d);
        $this->assertSame('linked', $j['status']);
        $this->h->archiveAsset($a);
        [$c, , $j2] = $this->h->enroll($tok, $d);
        $this->assertSame([201, 'pending_approval', null], [$c, $j2['status'], $j2['matched_asset_id']]);
        $this->assertSame('asset_retired', $this->h->one('SELECT match_reason FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
    }

    public function testRevokedDevicesCannotSimplyReEnroll(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $d = $this->h::device();
        [, , $j] = $this->h->enroll($tok, $d);
        $this->h->module->deviceService()->revoke((int) $j['device_id'], 'test', 1);
        [$c, , $r] = $this->h->enroll($tok, $d);
        $this->assertSame([403, 'forbidden'], [$c, $r['code']]);
        $this->assertStringContainsString('revoked', $r['error']);
        $this->h->module->deviceService()->allowReenroll((int) $j['device_id'], 1);
        [$c, , $r] = $this->h->enroll($tok, $d);
        $this->assertSame([201, $j['device_id']], [$c, $r['device_id']]);
    }

    public function testEnrollmentRateLimitIsDatabaseBackedWithRetryAfter(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $bad = 'rvte1.' . str_repeat('c', 12) . '.' . str_repeat('d', 40);
        $last = 0;
        for ($i = 0; $i < 14; ++$i) {
            [$last] = $this->h->enroll($bad, $this->h::device());
            if ($last === 429) {
                break;
            }
        }
        $this->assertSame(429, $last);
        $this->assertSame(10, $i, 'the 11th request is the first refused');
        [$c, $hd, $j] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame([429, 'rate_limited', '600'], [$c, $j['code'], $hd['Retry-After']]);
        // another address is unaffected
        [$c] = $this->h->enroll($tok, $this->h::device(), '10.9.9.9');
        $this->assertSame(201, $c);
        // after the window (the attempts age out) the address works again
        $this->h->q("UPDATE endpoint_agent_enroll_attempts SET attempted_at = '" . gmdate('Y-m-d H:i:s', time() - 700) . "'");
        [$c] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame(201, $c);
    }

    public function testTotalAttemptsAlsoLimitAt60(): void
    {
        $tok = $this->h->token(null, 24, 500);
        for ($i = 0; $i < 60; ++$i) {
            [$c] = $this->h->enroll($tok, $this->h::device());
            $this->assertSame(201, $c, "attempt $i");
        }
        [$c] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame(429, $c);
    }

    public function testTheDeviceLimitRefusesNewDevicesButNotReEnrollment(): void
    {
        $tok = $this->h->token(null, 24, 50);
        $this->h->module->settings()->set(['max_devices' => 2]);
        $d1 = $this->h::device();
        [$c] = $this->h->enroll($tok, $d1);
        [$c2] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame([201, 201], [$c, $c2]);
        [$c, , $j] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame([403, 'device_limit'], [$c, $j['code']]);
        $this->assertSame(2, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'), 'the refused enrollment gave its token use back');
        [$c] = $this->h->enroll($tok, $d1);
        $this->assertSame(201, $c, 're-enrollment of a known device is not a new device');
        // a retired device frees a slot
        $this->h->module->deviceService()->retire((int) $this->h->one('SELECT device_id FROM endpoint_agent_devices ORDER BY device_id LIMIT 1'), 1);
        [$c] = $this->h->enroll($tok, $this->h::device());
        $this->assertSame(201, $c);
    }

    public function testLinuxAgentsAreRefusedUnlessTheOptionIsSet(): void
    {
        $tok = $this->h->token();
        [$c] = $this->h->enroll($tok, $this->h::device(['os' => 'linux']));
        $this->assertSame(422, $c);
        $h2 = new \RivetCore\Tests\Support\RmmHarness(null, null, ['allow_linux' => true]);
        $h2->wipe();
        $t2 = $h2->token();
        [$c, , $j] = $h2->enroll($t2, $h2::device(['os' => 'linux']));
        $this->assertSame(201, $c);
        $this->assertSame('linux', $h2->one('SELECT os FROM endpoint_agent_devices WHERE device_id=' . $j['device_id']));
    }

    public function testAFailureWhileLinkingRollsTheWholeEnrollmentBack(): void
    {
        $tok = $this->h->token(null, 24, 5);
        $this->h->asset(['name' => 'Linkable', 'serial' => 'SER-ROLLBACK']);
        $this->h->bridge->failMethod = 'upsertLink';
        $this->h->bridge->failOn = new \RuntimeException('edition link table unavailable');
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-ROLLBACK']));
        $this->assertSame([500, 'internal'], [$c, $j['code']]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_devices'), 'no half-enrolled device');
        $this->assertSame(0, (int) $this->h->one('SELECT use_count FROM endpoint_agent_enrollment_tokens'), 'the token use is given back');
        $this->h->bridge->failOn = null;
        [$c, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-ROLLBACK']));
        $this->assertSame([201, 'linked'], [$c, $j['status']]);
    }
}
