<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RmmTestCase;

/** RmmReadModel: list filters, pagination and scope, fleet counters, approval queue, device view, tokens, releases, binaries. */
final class ReadModelTest extends RmmTestCase
{
    /**
     * Insert bare device rows (a read-model fixture, not an enrollment).
     *
     * @param list<array<string,mixed>> $rows hostname, client_id, last_checkin_at (UTC string or null), link_state, revoked, retired, serial
     * @return list<int> the device ids
     */
    private function seed(array $rows): array
    {
        $ids = [];
        foreach ($rows as $r) {
            $this->h->mysqli->query(sprintf(
                "INSERT INTO endpoint_agent_devices (install_id, hostname, serial, client_id, link_state, last_checkin_at, revoked_at, retired_at, agent_version, ring) VALUES ('%s', '%s', %s, %d, '%s', %s, %s, %s, '1.0.0', 'stable')",
                $this->h::uuid(), $this->h->mysqli->real_escape_string((string) $r['hostname']), isset($r['serial']) ? "'" . $r['serial'] . "'" : 'NULL', (int) ($r['client_id'] ?? $this->h->clientA),
                $r['link_state'] ?? 'linked', isset($r['last_checkin_at']) ? "'" . $r['last_checkin_at'] . "'" : 'NULL',
                !empty($r['revoked']) ? 'UTC_TIMESTAMP()' : 'NULL', !empty($r['retired']) ? 'UTC_TIMESTAMP()' : 'NULL'));
            $ids[] = (int) $this->h->mysqli->insert_id;
        }

        return $ids;
    }

    private function ago(int $s): string
    {
        return gmdate('Y-m-d H:i:s', time() - $s);
    }

    public function testListPaginatesFiltersAndReportsTheRealTotal(): void
    {
        $this->h->enable();
        $this->seed([
            ['hostname' => 'a-never'],
            ['hostname' => 'b-online', 'last_checkin_at' => $this->ago(30)],
            ['hostname' => 'c-online', 'last_checkin_at' => $this->ago(100)],
            ['hostname' => 'd-offline', 'last_checkin_at' => $this->ago(2000)],
            ['hostname' => 'e-stale', 'last_checkin_at' => $this->ago(700000)],
            ['hostname' => 'f-pending', 'link_state' => 'pending_approval', 'last_checkin_at' => $this->ago(10)],
        ]);
        $rm = $this->h->module->readModel();
        $all = $rm->listDevices();
        $this->assertSame(6, $all['total']);
        $this->assertSame(['a-never', 'b-online', 'c-online', 'd-offline', 'e-stale', 'f-pending'], array_column($all['items'], 'hostname'), 'ordered by hostname');
        $page = $rm->listDevices([], null, 2, 2);
        $this->assertSame(['c-online', 'd-offline'], array_column($page['items'], 'hostname'));
        $this->assertSame(6, $page['total'], 'the total is the number of matches, not of the page (the original reported the page size)');
        $by = static fn (array $l): array => array_column($l['items'], 'hostname');
        $this->assertSame(['b-online', 'c-online', 'f-pending'], $by($rm->listDevices(['status' => 'online'])));
        $this->assertSame(['d-offline'], $by($rm->listDevices(['status' => 'offline'])));
        $this->assertSame(['e-stale'], $by($rm->listDevices(['status' => 'stale'])));
        $this->assertSame(['a-never'], $by($rm->listDevices(['status' => 'never'])));
        $this->assertSame(['f-pending'], $by($rm->listDevices(['status' => 'pending_approval'])));
        $this->assertSame([], $by($rm->listDevices(['status' => 'bogus'])), 'a value that is neither a status nor a link state matches nothing');
        // the filter is applied BEFORE the page is cut (the original filtered after, so a page could come back short or empty)
        $second = $rm->listDevices(['status' => 'online'], null, 1, 1);
        $this->assertSame(['c-online'], $by($second));
        $this->assertSame(3, $second['total']);
        $this->assertSame(['c-online', 'f-pending'], $by($rm->listDevices(['status' => 'online'], null, 5, 1)));
        // summaries carry the computed status
        $this->assertSame(['never', 'online', 'online', 'offline', 'stale', 'online'], array_column($all['items'], 'status'));
        $this->assertSame(0, count($rm->listDevices([], null, 5, 99)['items']));
    }

    public function testListSearchRingClientAndRetiredFilters(): void
    {
        $this->h->enable();
        [$a, $b, $c] = $this->seed([['hostname' => 'srv-100%', 'serial' => 'SN-1'], ['hostname' => 'srv-100x', 'serial' => 'SN-2', 'client_id' => $this->h->clientB], ['hostname' => 'old-box', 'retired' => true]]);
        $this->h->mysqli->query("UPDATE endpoint_agent_devices SET ring='pilot' WHERE device_id=$b");
        $rm = $this->h->module->readModel();
        $names = static fn (array $l): array => array_column($l['items'], 'hostname');
        $this->assertSame(['srv-100%'], $names($rm->listDevices(['q' => '100%'])), 'LIKE wildcards in the search text are literal');
        $this->assertSame(['srv-100%', 'srv-100x'], $names($rm->listDevices(['q' => 'srv-100'])));
        $this->assertSame(['srv-100x'], $names($rm->listDevices(['q' => 'SN-2'])), 'searches the serial too');
        $this->assertSame(['srv-100x'], $names($rm->listDevices(['ring' => 'pilot'])));
        $this->assertSame(['srv-100x'], $names($rm->listDevices(['client_id' => $this->h->clientB])));
        $this->assertSame(['srv-100%', 'srv-100x'], $names($rm->listDevices()), 'retired devices are hidden by default');
        $this->assertSame(['old-box'], $names($rm->listDevices(['retired' => 'only'])));
        $this->assertSame(3, $rm->listDevices(['retired' => 'all'])['total']);
        $this->assertSame(['srv-100%', 'srv-100x'], $names($rm->listDevices(['ring' => 'beta'])), 'an unknown ring is ignored, not an error');
        $this->assertSame($a, $rm->listDevices(['q' => '100%'])['items'][0]['device_id']);
        $this->assertSame($c, $rm->listDevices(['retired' => 'only'])['items'][0]['device_id']);
    }

    public function testListHonoursTheClientScope(): void
    {
        $this->h->enable();
        $this->seed([['hostname' => 'a1', 'client_id' => $this->h->clientA], ['hostname' => 'b1', 'client_id' => $this->h->clientB], ['hostname' => 'none', 'client_id' => 0]]);
        $rm = $this->h->module->readModel();
        $names = static fn (array $l): array => array_column($l['items'], 'hostname');
        $this->assertSame(['a1', 'b1', 'none'], $names($rm->listDevices([], null)));
        $this->assertSame(['b1', 'none'], $names($rm->listDevices([], [$this->h->clientB])), 'client 0 is always visible');
        $this->assertSame(['none'], $names($rm->listDevices([], [])), 'restricted to nothing');
        $this->assertSame(['b1'], $names($rm->listDevices(['client_id' => $this->h->clientB], [$this->h->clientB])));
        $this->assertSame([], $names($rm->listDevices(['client_id' => $this->h->clientA], [$this->h->clientB])), 'a filter cannot widen the scope');
        $this->assertSame(2, $rm->listDevices([], [$this->h->clientB])['total']);
    }

    public function testFleetCountsAreExactBeyondFiveHundredDevices(): void
    {
        $this->h->enable();
        $rows = [];
        for ($i = 0; $i < 520; ++$i) {
            $rows[] = ['hostname' => 'bulk-' . $i, 'last_checkin_at' => $this->ago(60)];
        }
        $this->seed($rows);
        $this->seed([['hostname' => 'x-offline', 'last_checkin_at' => $this->ago(5000)], ['hostname' => 'x-stale', 'last_checkin_at' => $this->ago(800000)], ['hostname' => 'x-never'],
            ['hostname' => 'x-pending', 'link_state' => 'pending_approval'], ['hostname' => 'x-revoked', 'revoked' => true, 'last_checkin_at' => $this->ago(60)], ['hostname' => 'x-retired', 'retired' => true, 'last_checkin_at' => $this->ago(60)]]);
        $c = $this->h->module->readModel()->fleetCounts();
        $this->assertSame(['online' => 520, 'offline' => 1, 'stale' => 1, 'never' => 2, 'total' => 524, 'pending_approval' => 1], $c, 'revoked and retired devices are not counted; no 500-row ceiling');
        $scoped = $this->h->module->readModel()->fleetCounts([$this->h->clientB]);
        $this->assertSame(0, $scoped['total']);
    }

    public function testPendingApprovalsCarryReasonsAndCandidates(): void
    {
        $tok = $this->h->token(null, 24, 20);
        $other = $this->h->asset(['name' => 'OTHER-DEPT', 'serial' => 'SER-OTHER', 'client_id' => $this->h->clientB]);
        $this->h->asset(['name' => 'HOSTONLY', 'serial' => 'SER-DIFFERENT']);
        [, , $none] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-NOPE', 'hostname' => 'NOMATCH-PC']));
        [, , $mis] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-OTHER', 'hostname' => 'SCOPE-PC']));
        [, , $host] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-UNKNOWN', 'hostname' => 'HOSTONLY']));
        $this->h->asset(['name' => 'LINKABLE', 'serial' => 'SER-OK']);
        [, , $ok] = $this->h->enroll($tok, $this->h::device(['serial' => 'SER-OK', 'hostname' => 'LINKABLE']));
        $this->assertSame('linked', $ok['status']);
        $pending = $this->h->module->readModel()->pendingApprovals();
        $this->assertSame([(int) $none['device_id'], (int) $mis['device_id'], (int) $host['device_id']], array_column($pending, 'device_id'), 'oldest first, linked devices are not in the queue');
        $byId = array_column($pending, null, 'device_id');
        $this->assertSame('no_match', $byId[(int) $none['device_id']]['match_reason']);
        $this->assertStringContainsString('No asset has this serial', $byId[(int) $none['device_id']]['match_reason_text']);
        $this->assertSame([], $byId[(int) $none['device_id']]['candidates']);
        $this->assertSame('scope_mismatch', $byId[(int) $mis['device_id']]['match_reason']);
        $this->assertStringContainsString('different client', $byId[(int) $mis['device_id']]['match_reason_text']);
        $cand = $byId[(int) $mis['device_id']]['candidates'][0];
        $this->assertSame([$other, 'OTHER-DEPT', ['serial'], false], [$cand['asset_id'], $cand['asset_name'], $cand['matched_by'], $cand['in_scope']]);
        $this->assertSame('hostname_only', $byId[(int) $host['device_id']]['match_reason']);
        $this->assertSame(['hostname'], $byId[(int) $host['device_id']]['candidates'][0]['matched_by']);
        foreach ($pending as $p) {
            $this->assertArrayNotHasKey('token_hash', $p);
        }
        $this->assertSame([], $this->h->module->readModel()->pendingApprovals([$this->h->clientB]), 'the queue honours the client scope');
        // a rejected device leaves the queue
        $this->h->module->technician()->resolvePending($this->h->principal(), (int) $none['device_id'], 'reject');
        $this->assertCount(2, $this->h->module->readModel()->pendingApprovals());
    }

    public function testDeviceViewIsCompleteAndSecretFree(): void
    {
        $this->h->asset(['name' => 'VIEW-PC', 'serial' => 'SER-VIEW']);
        [, , $j] = $this->h->enroll($this->h->token(), $this->h::device(['serial' => 'SER-VIEW', 'hostname' => 'VIEW-PC', 'mac_addresses' => ['AA-BB-CC-00-00-01']]));
        $id = (int) $j['device_id'];
        $this->h->checkin($j['device_token'], ['inventory' => ['hostname' => 'VIEW-PC', 'cpu' => ['model' => 'Test CPU', 'cores' => 4]], 'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '99%']]]);
        $this->h->module->devices()->setMeshNode($id, 'node//' . str_repeat('N', 24), 1);
        $this->h->module->jobs()->create($this->h->module->devices()->find($id) ?? [], 'powershell', 'Get-Date', [], null, false, 1);
        $this->h->publishBinary('1.5.0', 'amd64', 4096, true, 'stable', 100);
        $v = $this->h->module->readModel()->deviceView($id, true);
        $this->assertNotNull($v);
        $this->assertSame(['VIEW-PC', 'online', 'linked', 'node//' . str_repeat('N', 24)], [$v['hostname'], $v['status'], $v['link_state'], $v['mesh']['node_id']]);
        $this->assertTrue($v['mesh']['mapped']);
        $this->assertSame('rivetit:' . $id, $v['agent_key']);
        $this->assertSame('Test CPU', $v['inventory']['cpu']['model']);
        $this->assertSame(['aa:bb:cc:00:00:01'], $v['mac_addresses']);
        $this->assertSame('disk_c', $v['checks'][0]['key']);
        $this->assertSame('fail', $v['checks'][0]['status']);
        $this->assertCount(1, $v['jobs']);
        $this->assertSame('queued', $v['jobs'][0]['state']);
        $this->assertSame(['version' => '1.5.0', 'ring' => 'stable', 'rollout_pct' => 100, 'arch' => 'amd64'], $v['offered_release']);
        $this->assertArrayHasKey('output', $v['jobs'][0]);
        $this->assertArrayNotHasKey('output', $this->h->module->readModel()->deviceView($id, false)['jobs'][0], 'job output only with the grant');
        $dump = (string) json_encode($v);
        $this->assertStringNotContainsString('token_hash', $dump);
        $this->assertStringNotContainsString((string) $this->h->one("SELECT token_hash FROM endpoint_agent_devices WHERE device_id=$id"), $dump);
        $this->assertStringNotContainsString($j['device_token'], $dump);
        $this->assertNull($this->h->module->readModel()->deviceView(999999, true));
        $this->h->module->technician()->retire($this->h->principal(), $id);
        $this->assertNull($this->h->module->readModel()->deviceView($id, true)['offered_release'], 'a retired device is offered nothing');
        $this->assertNotNull($this->h->module->readModel()->deviceView($id, true)['retired_at']);
    }

    public function testTokensAttemptsReleasesAndBinaries(): void
    {
        $this->h->enable();
        $e = $this->h->module->enrollment();
        $active = $e->createToken($this->h->clientA, 0, 'stable', 24, 5, 'active', 1);
        $revoked = $e->createToken($this->h->clientA, 0, 'pilot', 24, 5, 'revoked', 1);
        $e->revokeToken($revoked['token_id'], 1);
        $expired = $e->createToken($this->h->clientB, 0, 'stable', 24, 5, 'expired', 1);
        $this->h->mysqli->query("UPDATE endpoint_agent_enrollment_tokens SET expires_at = '" . $this->ago(10) . "' WHERE token_id = {$expired['token_id']}");
        $used = $e->createToken($this->h->clientA, 0, 'stable', 24, 1, 'used', 1);
        $this->h->mysqli->query("UPDATE endpoint_agent_enrollment_tokens SET use_count = 1 WHERE token_id = {$used['token_id']}");
        $list = $this->h->module->readModel()->tokens();
        $this->assertSame(['used', 'expired', 'revoked', 'active'], array_column($list, 'label'), 'newest first');
        $this->assertSame(['used_up', 'expired', 'revoked', 'active'], array_column($list, 'state'));
        foreach ($list as $t) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $t['selector']);
            $this->assertArrayNotHasKey('token_hash', $t);
        }
        $this->assertStringNotContainsString(explode('.', $active['token'])[2], (string) json_encode($list));
        // rejected attempts: only failures
        $this->h->call('POST', 'agent_enroll', ['enrollment_token' => 'rvte1.' . str_repeat('0', 12) . '.' . str_repeat('1', 40), 'device' => $this->h::device()], null, [], [], '203.0.113.9');
        $fails = $this->h->module->readModel()->recentFailedAttempts();
        $this->assertCount(1, $fails);
        $this->assertSame('203.0.113.9', $fails[0]['ip']);
        // releases and binaries
        $this->h->publishBinary('1.0.0', 'amd64', 4096, true, 'pilot', 20);
        $this->h->publishBinary('1.0.0', 'arm64', 4096, true);
        $bins = $this->h->module->readModel()->binaries();
        $this->assertSame(['arm64', 'amd64'], array_column($bins, 'arch'));
        $this->assertTrue($bins[0]['is_current']);
        $this->assertArrayNotHasKey('storage_name', $bins[0], 'the storage name is internal');
        $rel = $this->h->module->readModel()->releases();
        $this->assertCount(1, $rel);
        $this->assertSame(['1.0.0', 'pilot', 20, true], [$rel[0]['version'], $rel[0]['ring'], $rel[0]['rollout_pct'], $rel[0]['hosted']]);
        $cur = $this->h->module->readModel()->currentBinaries();
        $this->assertSame('1.0.0', $cur['amd64']['version'] ?? null);
        $this->assertSame('1.0.0', $cur['arm64']['version'] ?? null);
        $this->assertGreaterThan(0, $this->h->module->readModel()->uploadLimit());
    }

    public function testSettingsSummaryShowsSwitchesLimitsAndNoSecrets(): void
    {
        $this->h->enable();
        $s = $this->h->module->readModel()->settingsSummary();
        $this->assertSame(1, (int) $s['enabled']);
        $this->assertTrue($s['signing_key_set']);
        $this->assertFalse($s['mesh_login_key_set']);
        $this->assertSame(['monitoring', 'metrics', 'jobs', 'updates'], array_keys(array_filter($s['features'])));
        $this->assertSame(0, $s['limits']['max_checkins_per_min']);
        $this->assertCount(3, $s['checks']);
        $this->assertArrayNotHasKey('signing_private_key_enc', $s);
        $this->assertArrayNotHasKey('mesh_login_key_enc', $s);
        $this->assertNull($s['service_base']);
    }
}
