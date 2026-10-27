<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Software\SoftwareHash;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Tags, groups, software reads, fleet filters, outdated-software queries and the technician REST routes added in Phase 1. */
final class InventoryTest extends RmmTestCase
{
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;
    private RmmPrincipal $viewer;
    private RmmPrincipal $scoped;
    /** @var array<string,array{0:int,1:string}> name => [device id, device token] */
    private array $d = [];

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(null, null, ['allow_linux' => true], false, null,
            new AllowUsersPolicy([1 => true, 10 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED], 12 => [RmmAbility::DEVICE_VIEW], 16 => [RmmAbility::DEVICE_VIEW, RmmAbility::DEVICE_MANAGE]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        [$this->admin, $this->tech, $this->viewer, $this->scoped] = [new RmmPrincipal(1, 'Admin'), new RmmPrincipal(10, 'Tech'), new RmmPrincipal(12, 'Viewer'), new RmmPrincipal(16, 'Scoped')];
        $this->h->tenancy->restrictUser(16, [$this->h->clientB]);
        $this->h->enable();
        $this->h->module->settings()->update(['features_json' => ['monitoring' => true, 'metrics' => true, 'jobs' => true, 'updates' => true, 'inventory_software' => true]]);
        $this->d['alpha'] = $this->device('alpha', $this->h->clientA);
        $this->d['bravo'] = $this->device('bravo', $this->h->clientA);
        $this->d['charlie'] = $this->device('charlie', $this->h->clientB);
    }

    /** @return array{0:int,1:string} */
    private function device(string $name, int $client): array
    {
        $this->h->asset(['name' => $name, 'serial' => "SER-$name", 'client_id' => $client]);
        [, , $j] = $this->h->enroll($this->h->token($client, 24, 5), $this->h::device(['serial' => "SER-$name", 'hostname' => $name, 'os' => 'linux']));
        $this->assertSame('linked', $j['status']);

        return [(int) $j['device_id'], $j['device_token']];
    }

    /** @param list<array{0:string,1:string,2:string}> $list source, name, version */
    private function report(string $device, array $list): void
    {
        $items = array_map(static fn (array $i): array => ['name' => $i[1], 'version' => $i[2], 'publisher' => '', 'source' => $i[0]], $list);
        $hash = SoftwareHash::of(array_map(static fn (array $i): array => ['source' => $i[0], 'name' => $i[1], 'version' => $i[2], 'publisher' => ''], $list));
        [$c] = $this->h->checkin($this->d[$device][1], ['capabilities' => ['software_inventory'], 'software' => ['mode' => 'full', 'hash' => $hash, 'count' => count($list), 'truncated' => false, 'items' => $items]]);
        $this->assertSame(200, $c);
    }

    private function id(string $n): int
    {
        return $this->d[$n][0];
    }

    public function testTagsLifecycleAndValidation(): void
    {
        $tags = $this->h->module->tags();
        $t = $tags->create('  Patch   Ring 1 ', '#AABBCC', 'first ring', 1);
        $this->assertSame(['Patch Ring 1', '#aabbcc'], [$t['name'], $t['color']]);
        $this->assertSame($t['tag_id'], $tags->create('patch ring 1')['tag_id'], 'names are unique without regard to case');
        foreach (['', '   ', str_repeat('x', 61), "bad\x00null", '<script>', '.leading-dot-ok-but-not-alnum', '😀'] as $bad) {
            try {
                $tags->create($bad);
                $this->fail("accepted: $bad");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $tags->create('ok', 'red');
    }

    public function testTaggingAFleetAndFilteringByTag(): void
    {
        $api = fn (string $m, array $seg, ?RmmPrincipal $who, mixed $body = null, array $q = []) => $this->h->tech($m, $seg, $who, $body, $q);
        [$c, $j] = $api('POST', ['tags'], $this->admin, ['name' => 'kiosk', 'color' => '#112233']);
        $this->assertSame(201, $c);
        $kiosk = $j['tag']['tag_id'];
        [$c] = $api('POST', [(string) $this->id('alpha'), 'tags'], $this->admin, ['tag' => 'kiosk']);
        $this->assertSame(200, $c);
        [$c, $j] = $api('POST', [(string) $this->id('bravo'), 'tags'], $this->admin, ['tag' => $kiosk]);
        $this->assertSame([200, 'kiosk'], [$c, $j['tag']['name']]);
        [$c] = $api('POST', [(string) $this->id('bravo'), 'tags'], $this->admin, ['tag' => 'vip']);
        $this->assertSame(200, $c, 'a new name creates the tag');
        [$c, $j] = $api('GET', [(string) $this->id('bravo'), 'tags'], $this->viewer);
        $this->assertSame([200, ['kiosk', 'vip']], [$c, array_column($j['data'], 'name')]);

        [$c, $j] = $api('GET', [], $this->admin, null, ['tag' => 'kiosk']);
        $this->assertSame([200, 2], [$c, $j['total']]);
        $this->assertSame(['alpha', 'bravo'], array_column($j['data'], 'hostname'));
        [, $j] = $api('GET', [], $this->admin, null, ['tag' => (string) $kiosk]);
        $this->assertSame(2, $j['total'], 'a tag id filters too');
        [, $j] = $api('GET', [], $this->admin, null, ['tag' => 'nope']);
        $this->assertSame(0, $j['total']);
        // the fleet list the edition renders carries each device's tags; the REST list keeps its frozen shape
        $ext = $this->h->module->readModel()->listDevices(['tag' => 'vip']);
        $this->assertSame(['kiosk', 'vip'], array_column($ext['items'][0]['tags'], 'name'));
        $this->assertArrayNotHasKey('tags', $this->h->module->readModel()->listDevices([], null, 50, 0, false)['items'][0]);

        [$c, $j] = $api('GET', ['tags'], $this->viewer);
        $this->assertSame([200, ['kiosk' => 2, 'vip' => 1]], [$c, array_column($j['data'], 'device_count', 'name')]);
        [$c] = $api('DELETE', [(string) $this->id('bravo'), 'tags', (string) $kiosk], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $api('DELETE', [(string) $this->id('bravo'), 'tags', (string) $kiosk], $this->admin);
        $this->assertSame(404, $c);
        [$c] = $api('PATCH', ['tags', (string) $kiosk], $this->admin, ['name' => 'Kiosks']);
        $this->assertSame(200, $c);
        [$c, $j] = $api('PATCH', ['tags', (string) $kiosk], $this->admin, ['name' => 'vip']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $api('DELETE', ['tags', (string) $kiosk], $this->admin);
        $this->assertSame(200, $c);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_device_tags WHERE tag_id = ' . $kiosk), 'deleting a tag takes it off every device');
        $this->assertTrue($this->h->audit->records() !== []);
    }

    public function testAuthorizationReadsNeedViewAndTagsNeedManage(): void
    {
        $api = fn (string $m, array $seg, ?RmmPrincipal $who, mixed $body = null) => $this->h->tech($m, $seg, $who, $body);
        $a = (string) $this->id('alpha');
        $c = (string) $this->id('charlie');
        [$st] = $api('POST', [$a, 'tags'], $this->viewer, ['tag' => 'x']);
        $this->assertSame(403, $st, 'view-only cannot tag');
        [$st] = $api('POST', [$a, 'tags'], $this->tech, ['tag' => 'x']);
        $this->assertSame(403, $st, 'running jobs is not managing');
        [$st] = $api('POST', ['tags'], $this->tech, ['name' => 'x']);
        $this->assertSame(403, $st);
        [$st] = $api('POST', ['groups'], $this->viewer, ['name' => 'g']);
        $this->assertSame(403, $st);
        [$st] = $api('GET', [$a, 'tags'], null);
        $this->assertSame(401, $st);
        [$st] = $api('POST', [$a, 'tags'], $this->scoped, ['tag' => 'x']);
        $this->assertSame(404, $st, 'a device outside the caller\'s clients is the same 404 as a missing one');
        [$st] = $api('POST', [$c, 'tags'], $this->scoped, ['tag' => 'x']);
        $this->assertSame(200, $st, 'inside its client, with the manage grant');
        [$st] = $api('GET', [$a, 'software'], $this->scoped);
        $this->assertSame(404, $st);
        [$st] = $api('GET', ['9999', 'software'], $this->admin);
        $this->assertSame(404, $st);
        [$st] = $api('GET', [$a, 'software'], $this->viewer);
        $this->assertSame(200, $st);
        [$st] = $api('POST', [$a, 'software', 'refresh'], $this->viewer);
        $this->assertSame(403, $st, 'a refresh needs the grant to run saved jobs');
        [$st] = $api('POST', [$a, 'software', 'refresh'], $this->tech);
        $this->assertSame(202, $st);
        [$st, $j] = $api('POST', [$a, 'tags'], $this->admin, ['tag' => '']);
        $this->assertSame([422, 'invalid'], [$st, $j['code']]);
        $this->h->module->settings()->disable();
        [$st] = $api('GET', [$a, 'tags'], $this->admin);
        $this->assertSame(404, $st, 'module off');
    }

    public function testGroupsHoldStaticMembersAndFollowTags(): void
    {
        $api = fn (string $m, array $seg, ?RmmPrincipal $who, mixed $body = null, array $q = []) => $this->h->tech($m, $seg, $who, $body, $q);
        [$c, $j] = $api('POST', ['groups'], $this->admin, ['name' => 'Front desk', 'description' => 'reception PCs']);
        $this->assertSame(201, $c);
        $g = $j['group']['group_id'];
        [$c, $j] = $api('POST', ['groups'], $this->admin, ['name' => 'front desk']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c, $j] = $api('POST', ['groups', (string) $g, 'devices'], $this->admin, ['device_ids' => [$this->id('alpha'), $this->id('alpha')]]);
        $this->assertSame([200, 1], [$c, $j['added']]);
        [$c, $j] = $api('POST', ['groups', (string) $g, 'devices'], $this->admin, ['device_ids' => ['x']]);
        $this->assertSame(422, $c);
        [$c] = $api('POST', ['groups', (string) $g, 'devices'], $this->admin, ['device_ids' => [999999]]);
        $this->assertSame(404, $c, 'one missing device refuses the whole request');
        [, $t] = $api('POST', ['tags'], $this->admin, ['name' => 'lobby']);
        $api('POST', [(string) $this->id('bravo'), 'tags'], $this->admin, ['tag' => 'lobby']);
        [$c] = $api('PUT', ['groups', (string) $g, 'tags'], $this->admin, ['tag_ids' => [$t['tag']['tag_id']]]);
        $this->assertSame(200, $c);
        [$c, $j] = $api('GET', ['groups', (string) $g], $this->viewer);
        $this->assertSame([200, 2, ['alpha', 'bravo']], [$c, $j['total'], array_column($j['devices'], 'hostname')]);
        $this->assertSame(2, $j['group']['device_count']);
        $api('POST', [(string) $this->id('alpha'), 'tags'], $this->admin, ['tag' => 'lobby']);
        [, $j] = $api('GET', ['groups', (string) $g], $this->viewer);
        $this->assertSame(2, $j['total'], 'a device that is both a static member and tagged counts once');
        [, $j] = $api('GET', [], $this->admin, null, ['group' => (string) $g]);
        $this->assertSame(2, $j['total']);
        [, $j] = $api('GET', [(string) $this->id('bravo'), 'tags'], $this->viewer);
        $this->assertSame(['Front desk'], array_column($j['groups'], 'name'));
        // a scoped viewer sees only its client's devices of the group
        [, $j] = $api('GET', ['groups', (string) $g], $this->scoped);
        $this->assertSame(0, $j['total']);
        [$c] = $api('DELETE', ['groups', (string) $g, 'devices', (string) $this->id('alpha')], $this->admin);
        $this->assertSame(200, $c);
        [$c] = $api('DELETE', ['groups', (string) $g, 'devices', (string) $this->id('alpha')], $this->admin);
        $this->assertSame(404, $c);
        [$c, $j] = $api('PUT', ['groups', (string) $g, 'tags'], $this->admin, ['tag_ids' => [987654]]);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $api('PATCH', ['groups', (string) $g], $this->admin, ['name' => 'Reception']);
        $this->assertSame(200, $c);
        [$c, $j] = $api('GET', ['groups'], $this->viewer);
        $this->assertSame(['Reception'], array_column($j['data'], 'name'));
        [$c] = $api('DELETE', ['groups', (string) $g], $this->admin);
        $this->assertSame(200, $c);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_group_tags') + (int) $this->h->one('SELECT COUNT(*) FROM rmm_group_devices'));
        [$c] = $api('GET', ['groups', (string) $g], $this->viewer);
        $this->assertSame(404, $c);
    }

    public function testSoftwareReadsFiltersCatalogAndOutdatedQueries(): void
    {
        $this->report('alpha', [['dpkg', 'openssl', '3.0.2'], ['dpkg', 'curl', '8.5.0'], ['snap', 'firefox', '131.0']]);
        $this->report('bravo', [['dpkg', 'openssl', '3.0.13'], ['dpkg', 'libcurl4', '8.5.0']]);
        $this->report('charlie', [['dpkg', 'openssl', '1.1.1'], ['registry', 'Google Chrome', '129.0.6668']]);
        $api = fn (string $m, array $seg, ?RmmPrincipal $who, array $q = []) => $this->h->tech($m, $seg, $who, null, $q);

        [$c, $j] = $api('GET', [(string) $this->id('alpha'), 'software'], $this->viewer);
        $this->assertSame([200, 3, ['curl', 'firefox', 'openssl']], [$c, $j['total'], array_column($j['data'], 'name')]);
        $this->assertTrue($j['state']['reported']);
        $this->assertSame(3, $j['state']['count']);
        $this->assertTrue($j['state']['capable']);
        $this->assertSame(['name', 'version', 'publisher', 'source', 'installed_on', 'first_seen_at', 'last_seen_at', 'removed_at'], array_keys($j['data'][0]));
        [, $j] = $api('GET', [(string) $this->id('alpha'), 'software'], $this->viewer, ['q' => 'ssl', 'limit' => '1']);
        $this->assertSame([1, 1], [$j['total'], count($j['data'])]);

        [, $j] = $api('GET', [], $this->admin, ['software' => 'openssl']);
        $this->assertSame([3, ['alpha', 'bravo', 'charlie']], [$j['total'], array_column($j['data'], 'hostname')]);
        [, $j] = $api('GET', [], $this->admin, ['software' => 'chrome']);
        $this->assertSame(['charlie'], array_column($j['data'], 'hostname'));
        [, $j] = $api('GET', [], $this->admin, ['software' => '100%']);
        $this->assertSame(0, $j['total'], 'LIKE wildcards are literal');

        [$c, $j] = $api('GET', ['software'], $this->viewer, ['q' => 'openssl']);
        $this->assertSame([200, 1, 3, 3], [$c, $j['total'], $j['data'][0]['devices'], $j['data'][0]['versions']]);
        [, $j] = $api('GET', ['software'], $this->scoped, ['q' => 'openssl']);
        $this->assertSame(1, $j['data'][0]['devices'], 'the catalog counts only visible devices');

        [$c, $j] = $api('GET', ['software', 'outdated'], $this->viewer, ['name' => 'openssl', 'min_version' => '3.0.10']);
        $this->assertSame([200, ['alpha' => '3.0.2', 'charlie' => '1.1.1']], [$c, array_column($j['data'], 'version', 'hostname')]);
        [, $j] = $api('GET', ['software', 'outdated'], $this->scoped, ['name' => 'openssl', 'min_version' => '3.0.10']);
        $this->assertSame(['charlie'], array_column($j['data'], 'hostname'));
        [$c, $j] = $api('GET', ['software', 'outdated'], $this->viewer, ['name' => 'openssl']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
        [$c] = $api('GET', ['software', '5'], $this->viewer);
        $this->assertSame(404, $c);
    }

    public function testSoftwareHistoryEndpointPagesNewestFirst(): void
    {
        $this->report('alpha', [['dpkg', 'a', '1']]);
        $v1 = [['dpkg', 'a', '2'], ['dpkg', 'b', '1']];
        $hash = static fn (array $l): string => SoftwareHash::of(array_map(static fn (array $i): array => ['source' => $i[0], 'name' => $i[1], 'version' => $i[2], 'publisher' => ''], $l));
        $this->h->checkin($this->d['alpha'][1], ['capabilities' => ['software_inventory'], 'software' => ['mode' => 'delta', 'base_hash' => $hash([['dpkg', 'a', '1']]), 'hash' => $hash($v1), 'count' => 2, 'truncated' => false,
            'items' => [['name' => 'a', 'version' => '2', 'publisher' => '', 'source' => 'dpkg'], ['name' => 'b', 'version' => '1', 'publisher' => '', 'source' => 'dpkg']], 'removed' => []]]);
        [$c, $j] = $this->h->tech('GET', [(string) $this->id('alpha'), 'software', 'history'], $this->viewer);
        $this->assertSame([200, 2], [$c, $j['total']]);
        $this->assertSame(['change', 'name', 'source'], array_values(array_intersect(['change', 'name', 'source'], array_keys($j['data'][0]))));
        $this->assertEqualsCanonicalizing(['upgraded:a', 'installed:b'], array_map(static fn (array $r): string => $r['change'] . ':' . $r['name'], $j['data']));
        [, $j] = $this->h->tech('GET', [(string) $this->id('alpha'), 'software', 'history'], $this->viewer, null, ['name' => 'b', 'limit' => '1']);
        $this->assertSame([1, 'installed'], [$j['total'], $j['data'][0]['change']]);
        [$c] = $this->h->tech('GET', [(string) $this->id('alpha'), 'software', 'nope'], $this->viewer);
        $this->assertSame(404, $c);
    }

    public function testCheckHistoryKeepsChangesAndOneSamplePerGapAndComputesAvailability(): void
    {
        $tok = $this->d['alpha'][1];
        $clock = $this->h->clock;
        $post = function (string $status, int $advance = 0) use ($tok, $clock): void {
            if ($advance > 0) {
                $clock->advance($advance);
            }
            [$c] = $this->h->checkin($tok, ['collected_at' => gmdate('Y-m-d\TH:i:s\Z', $clock->now()->getTimestamp()), 'checks' => [['key' => 'disk_c', 'status' => $status, 'detail' => $status === 'ok' ? '' : '91% used']]]);
            $this->assertSame(200, $c);
        };
        $this->h->module->settings()->update(['limits_json' => ['check_history_gap_s' => 900]]);
        // the harness clock is real time: align to the start of a 900 s bucket so the series is deterministic
        $now = $clock->now()->getTimestamp();
        $clock->advance(900 - $now % 900);
        $post('ok');
        $post('ok', 60);          // same bucket, same status: not stored
        $post('ok', 60);          // not stored
        $post('fail', 60);        // a change: stored
        $post('fail', 900);       // next bucket: stored
        $post('ok', 120);         // a change: stored
        $rows = $this->h->rows("SELECT status FROM endpoint_agent_check_history WHERE device_id = {$this->id('alpha')} AND check_key = 'disk_c' ORDER BY hist_id");
        $this->assertSame(['ok', 'fail', 'fail', 'ok'], array_column($rows, 'status'));
        [$c, $j] = $this->h->tech('GET', [(string) $this->id('alpha'), 'checks', 'disk_c', 'history'], $this->viewer, null, ['hours' => '2']);
        $this->assertSame(200, $c);
        $this->assertSame(['ok', 'fail', 'fail', 'ok'], array_column($j['points'], 'status'));
        $this->assertSame(['key', 'since', 'hours', 'points', 'seconds', 'availability_pct', 'changes', 'retention_days'], array_keys($j));
        $this->assertSame(2, $j['changes']);
        $this->assertSame(7, $j['retention_days']);
        $this->assertGreaterThan(0, $j['seconds']['fail']);
        $this->assertLessThan(100.0, $j['availability_pct']);
        $this->assertSame('91% used', $j['points'][1]['detail']);
        [$c] = $this->h->tech('GET', [(string) $this->id('charlie'), 'checks', 'disk_c', 'history'], $this->scoped);
        $this->assertSame(200, $c);
        [$c] = $this->h->tech('GET', [(string) $this->id('alpha'), 'checks', 'disk_c', 'history'], $this->scoped);
        $this->assertSame(404, $c);
        // history can be switched off
        $this->h->q('DELETE FROM endpoint_agent_check_history');
        $this->h->module->settings()->update(['limits_json' => ['check_history_days' => 0]]);
        $post('fail', 3600);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_check_history'));
    }

    public function testNetworkEndpointAndSoftwareStateForADeviceThatNeverReported(): void
    {
        [$c, $j] = $this->h->tech('GET', [(string) $this->id('alpha'), 'network'], $this->viewer);
        $this->assertSame([200, 24, ['current' => null, 'peak' => null, 'avg' => null, 'peak_at' => null]], [$c, $j['window_hours'], $j['rx']]);
        [, $j] = $this->h->tech('GET', [(string) $this->id('alpha'), 'software'], $this->viewer);
        $this->assertSame([[], 0, false], [$j['data'], $j['total'], $j['state']['reported']]);
        [$c] = $this->h->tech('GET', [(string) $this->id('alpha'), 'network', 'x'], $this->viewer);
        $this->assertSame(404, $c);
        [$c] = $this->h->tech('POST', [(string) $this->id('alpha'), 'network'], $this->viewer);
        $this->assertSame(404, $c);
    }

    public function testFleetFilterByLocationAndTheFrozenListShapeIsUnchanged(): void
    {
        $this->h->q('UPDATE endpoint_agent_devices SET location_id = 7 WHERE device_id = ' . $this->id('bravo'));
        [, $j] = $this->h->tech('GET', [], $this->admin, null, ['location_id' => '7']);
        $this->assertSame(['bravo'], array_column($j['data'], 'hostname'));
        $this->assertSame(['device_id', 'hostname', 'asset_id', 'client_id', 'link_state', 'status', 'last_checkin_at', 'offline_since', 'agent_version', 'ring', 'os_version', 'revoked', 'retired'], array_keys($j['data'][0]));
    }

    public function testTheLiveDocumentHasTheDocumentedShapeAnEtagAndShedsPolling(): void
    {
        $tok = $this->d['alpha'][1];
        $dev = $this->id('alpha');
        [$c, $empty] = $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer);
        $this->assertSame(200, $c);
        $this->assertSame('never', $empty['state'], 'before the first check-in');
        $this->assertNull($empty['gauges']['cpu_pct']);
        $this->assertSame([], $empty['gauges']['disks']);
        $this->h->checkin($tok, ['metrics' => ['cpu_pct' => 14.2, 'mem_pct' => 56.1, 'disk' => [['mount' => 'C:', 'used_pct' => 54.3]], 'net_rx_bps' => 6200000, 'net_tx_bps' => null],
            'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '97%']], 'inventory' => ['hostname' => 'alpha', 'disks' => [['mount' => 'C:', 'total_bytes' => 128000000000, 'free_bytes' => 59000000000, 'fs' => 'NTFS']]]]);
        [$c, $j, $r] = $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer);
        $this->assertSame(200, $c);
        $this->assertSame(['v', 'state', 'last_checkin_at', 'age_s', 'next_check_in_s', 'agent_version', 'uptime_s', 'pending_reboot', 'gauges', 'checks', 'alerts', 'jobs', 'poll_s', 'shed', 'seq'], array_keys($j));
        $this->assertSame(['online', 30, 0], [$j['state'], $j['poll_s'], $j['shed']]);
        $this->assertSame([14.2, 56.1, 6200000, null], [$j['gauges']['cpu_pct'], $j['gauges']['mem_pct'], $j['gauges']['net_rx_bps'], $j['gauges']['net_tx_bps']], 'a missing reading is null, never 0');
        $this->assertSame([['mount' => 'C:', 'used_pct' => 54.3, 'free_bytes' => 59000000000, 'total_bytes' => 128000000000]], $j['gauges']['disks']);
        $this->assertSame(['disk_c', 'fail', null], [$j['checks'][0]['key'], $j['checks'][0]['status'], $j['checks'][0]['alert_id']]);
        $this->assertSame(['open' => 0, 'worst' => null], $j['alerts']);
        $this->assertLessThan(4096, strlen((string) $r->body), 'under 4 KB');
        $etag = $r->headers['ETag'];
        $this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{32}"$/', $etag);
        // unchanged: 304 without a body
        $req = new \RivetCore\Rmm\Http\RmmRequest('GET', 'endpoint_devices', [(string) $dev, 'live'], [], ['if-none-match' => $etag], '127.0.0.1', 'ua', true, null, null);
        $r304 = $this->h->module->technicianApi()->handle($req, $this->viewer);
        $this->assertSame([304, null, $etag], [$r304->status, $r304->body, $r304->headers['ETag']]);
        // a new check-in, an alert and a queued job each change it
        $this->h->checkin($tok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '97%']]]);
        $this->h->checkin($tok, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => '97%']]]);
        [, $j2, $r2] = $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer);
        $this->assertNotSame($etag, $r2->headers['ETag']);
        $this->assertSame(['open' => 1, 'worst' => 'error'], $j2['alerts']);
        $this->assertGreaterThan(0, $j2['checks'][0]['alert_id']);
        $before = $r2->headers['ETag'];
        $this->h->module->technician()->submitJob($this->admin, $dev, ['type' => 'collect']);
        [, $j3, $r3] = $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer);
        $this->assertSame(1, $j3['jobs']['queued']);
        $this->assertNotSame($before, $r3->headers['ETag']);
        // load shedding slows then pauses the poll
        $this->h->module->settings()->set(['shed_level' => 1]);
        $this->assertSame(60, $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer)[1]['poll_s']);
        $this->h->module->settings()->set(['shed_level' => 2]);
        $this->assertSame(0, $this->h->tech('GET', [(string) $dev, 'live'], $this->viewer)[1]['poll_s']);
        // authorization: same 404 outside scope, 401 without a user
        $this->assertSame(404, $this->h->tech('GET', [(string) $dev, 'live'], $this->scoped)[0]);
        $this->assertSame(401, $this->h->tech('GET', [(string) $dev, 'live'], null)[0]);
        $this->assertSame(404, $this->h->tech('POST', [(string) $dev, 'live'], $this->admin)[0]);
    }

    public function testCountsInTheTagAndGroupListsFollowTheCallersClients(): void
    {
        $api = fn (string $m, array $seg, ?RmmPrincipal $who, mixed $body = null) => $this->h->tech($m, $seg, $who, $body);
        $api('POST', [(string) $this->id('alpha'), 'tags'], $this->admin, ['tag' => 'shared']);
        $api('POST', [(string) $this->id('charlie'), 'tags'], $this->admin, ['tag' => 'shared']);
        [, $g] = $api('POST', ['groups'], $this->admin, ['name' => 'Everyone']);
        $gid = (string) $g['group']['group_id'];
        $api('POST', ['groups', $gid, 'devices'], $this->admin, ['device_ids' => [$this->id('alpha'), $this->id('bravo'), $this->id('charlie')]]);
        [, $j] = $api('GET', ['tags'], $this->admin);
        $this->assertSame(2, array_column($j['data'], 'device_count', 'name')['shared']);
        [, $j] = $api('GET', ['tags'], $this->scoped);
        $this->assertSame(1, array_column($j['data'], 'device_count', 'name')['shared'], 'the scoped caller counts only its own client');
        [, $j] = $api('GET', ['groups'], $this->admin);
        $this->assertSame(3, $j['data'][0]['device_count']);
        [, $j] = $api('GET', ['groups'], $this->scoped);
        $this->assertSame(1, $j['data'][0]['device_count']);
        [, $j] = $api('GET', ['groups', $gid], $this->scoped);
        $this->assertSame([1, ['charlie']], [$j['group']['device_count'], array_column($j['devices'], 'hostname')]);
        // a retired device is not counted
        $this->h->q('UPDATE endpoint_agent_devices SET retired_at = UTC_TIMESTAMP() WHERE device_id = ' . $this->id('bravo'));
        [, $j] = $api('GET', ['groups'], $this->admin);
        $this->assertSame(2, $j['data'][0]['device_count']);
    }
}
