<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Port of RivetIT tests/endpoint_agent_checkin.php (auth, idempotency by seq, inventory, null stays null, buffered samples,
 * timestamps, caps, alert debounce) plus the transaction behaviour and the module-switch and load-shedding rules the Core module adds.
 */
final class CheckinTest extends RmmTestCase
{
    private int $dev = 0;
    private string $T = '';
    private int $assetId = 0;
    private int $intg = 0;

    /** Enroll one device linked to an asset. */
    private function linkedDevice(string $serial = 'CI-SER-1'): void
    {
        $tok = $this->h->token(null, 24, 100);
        $this->assetId = $this->h->asset(['name' => 'Front desk', 'serial' => $serial, 'make' => 'Dell']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => $serial, 'hostname' => 'FRONTDESK']));
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
        $this->assertSame('linked', $j['status']);
        $this->intg = $this->h->integrationId();
    }

    /** @return array<string,mixed> */
    private function inventory(): array
    {
        return ['hostname' => 'FRONTDESK', 'os' => 'windows', 'os_version' => 'Windows 11 23H2', 'manufacturer' => 'Dell', 'model' => 'Latitude 7440', 'serial' => 'CI-SER-1',
            'cpu' => ['model' => 'Intel i7-1355U', 'cores' => 10], 'memory_total_bytes' => 17179869184,
            'disks' => [['mount' => 'C:', 'total_bytes' => 512000000000, 'free_bytes' => 200000000000, 'fs' => 'NTFS']],
            'network' => [['name' => 'Ethernet', 'mac' => 'AA-BB-CC-DD-EE-01', 'ips' => ['10.0.0.5', 'not-an-ip']]], 'uptime_s' => 7200, 'logged_in_user' => '<script>alert(1)</script>', 'pending_reboot' => false];
    }

    /** @return list<array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string}> */
    private function samples(string $key): array
    {
        return array_values(array_filter($this->h->metrics->stored(), static fn (array $s): bool => $s['key'] === $key));
    }

    public function testAuthentication(): void
    {
        $this->linkedDevice();
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1]);
        $this->assertSame([401, 'invalid_token'], [$c, $r['code']]);
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1], str_repeat('a', 64));
        $this->assertSame([401, 'invalid_token'], [$c, $r['code']]);
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1], 'short');
        $this->assertSame([401, 'invalid_token'], [$c, $r['code']]);
        [$c] = $this->h->call('GET', 'agent_checkin', null, $this->T);
        $this->assertSame(405, $c);
        [$c] = $this->h->call('POST', 'agent_checkin', 'nope', $this->T);
        $this->assertSame(422, $c);
        [$c] = $this->h->call('POST', 'agent_checkin', '[1,2]', $this->T);
        $this->assertSame(422, $c);
        $this->h->q("UPDATE endpoint_agent_devices SET token_expires_at='" . gmdate('Y-m-d H:i:s', time() - 5) . "' WHERE device_id={$this->dev}");
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame([401, 'expired'], [$c, $r['code']]);
    }

    public function testFirstCheckinWithInventoryUpdatesTheDeviceTheLinkAndTheSink(): void
    {
        $this->linkedDevice();
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', ['seq' => 1, 'collected_at' => $this->h::ts(-30), 'agent_version' => '1.0.0', 'inventory' => $this->inventory(),
            'metrics' => ['cpu_pct' => 12.5, 'mem_pct' => 40, 'disk' => [['mount' => 'C:', 'used_pct' => 60.8]], 'net_rx_bps' => 1000.5, 'net_tx_bps' => 500], 'checks' => [], 'buffered' => []], $this->T);
        $this->assertSame(200, $c);
        $this->assertSame(['ok', 'status', 'matched_asset_id', 'next_check_in_s', 'jobs_pending', 'config', 'update', 'server_time', 'signing_key_id'], array_keys($r));
        $this->assertTrue($r['ok']);
        $this->assertNull($r['update']);
        $this->assertSame(0, $r['jobs_pending']);
        $this->assertSame(300, $r['next_check_in_s']);
        $this->assertSame([$this->assetId, 'linked'], [$r['matched_asset_id'], $r['status']]);
        $this->assertIsArray($r['config']['checks']);
        $this->assertSame(60, $r['config']['collect_interval_s']);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_devices WHERE device_id={$this->dev}")[0];
        $this->assertNotNull($row['last_checkin_at']);
        $this->assertSame(7200, (int) $row['uptime_s']);
        $this->assertSame(0, (int) $row['pending_reboot']);
        $this->assertSame('<script>alert(1)</script>', $row['logged_in_user']);
        $this->assertStringNotContainsString('not-an-ip', (string) $row['inventory_json']);
        $this->assertSame(['["aa:bb:cc:dd:ee:01"]'], [$row['mac_addresses']]);
        // the link health the bridge received
        $h = $this->h->bridge->healthOf($this->intg, $this->assetId);
        $this->assertSame([13, 40, 61, 'Intel i7-1355U', '16'], [$h['cpu_pct'], $h['ram_pct'], $h['disk_pct'], $h['cpu'], $h['ram_gb']]);
        $this->assertFalse($h['needs_reboot']);
        $this->assertSame(gmdate('Y-m-d H:i', time() - 7200), substr((string) $h['last_boot'], 0, 16));
        $this->assertSame('online', $this->h->bridge->link($this->intg, "rivetit:{$this->dev}")['status']);
        // the sink got cpu, memory, disk, network (bits -> bytes) and uptime
        $this->assertCount(1, $this->samples('cpu.utilization'));
        $this->assertSame(12.5, $this->samples('cpu.utilization')[0]['value']);
        $this->assertSame(1000.5 / 8, $this->samples('network.rx_bytes_per_s')[0]['value']);
        $this->assertSame(500 / 8, $this->samples('network.tx_bytes_per_s')[0]['value']);
        $this->assertSame('C:', $this->samples('disk.utilization')[0]['instance']);
        $this->assertSame(7200, $this->samples('system.uptime_seconds')[0]['value']);
        $this->assertSame(0, $this->samples('system.pending_reboot')[0]['value']);
        $this->assertSame(17179869184, $this->samples('memory.total_bytes')[0]['value']);
        $this->assertSame(200000000000, $this->samples('disk.free_bytes')[0]['value']);
        // the asset's blanks were filled from the inventory, a human's text was not overwritten
        $this->assertSame('Latitude 7440', $this->h->assets->row($this->assetId)['model']);
    }

    public function testInventoryNeverOverwritesWhatAHumanTypedOnTheAsset(): void
    {
        $tok = $this->h->token(null, 24, 100);
        $a = $this->h->asset(['name' => 'Typed', 'serial' => 'TYPED-1', 'model' => 'My own model text', 'make' => 'Hand-typed']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'TYPED-1', 'model' => 'Latitude 7440', 'manufacturer' => 'Dell']));
        $this->h->checkin($j['device_token'], ['inventory' => $this->inventory()]);
        $row = $this->h->assets->row($a);
        $this->assertSame(['My own model text', 'Hand-typed'], [$row['model'], $row['make']]);
    }

    public function testDuplicateSeqIsAcknowledgedButNotReprocessed(): void
    {
        $this->linkedDevice();
        $body = ['seq' => 50, 'collected_at' => $this->h::ts(-10), 'agent_version' => '1.0.0', 'inventory' => null, 'metrics' => ['cpu_pct' => 77.0, 'mem_pct' => null, 'disk' => [], 'net_rx_bps' => null, 'net_tx_bps' => null],
            'checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'low']]];
        $first = $this->h->call('POST', 'agent_checkin', $body, $this->T);
        $second = $this->h->call('POST', 'agent_checkin', $body, $this->T);
        $third = $this->h->call('POST', 'agent_checkin', $body, $this->T);
        $this->assertSame([200, 200, 200], [$first[0], $second[0], $third[0]]);
        $this->assertSame(1, $second[2]['jobs_pending'] + 1, 'a duplicate delivery gets the full normal body');
        $this->assertSame(array_keys($first[2]), array_keys($second[2]));
        $this->assertCount(1, $this->samples('cpu.utilization'));
        $this->assertSame(1, (int) $this->h->one("SELECT consecutive_failures FROM endpoint_agent_checks WHERE device_id={$this->dev} AND check_key='disk_c'"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id={$this->dev} AND seq=50"));
    }

    public function testANullReadingIsNeverZero(): void
    {
        $this->linkedDevice();
        $this->h->call('POST', 'agent_checkin', ['seq' => 5, 'collected_at' => $this->h::ts(-10), 'agent_version' => '1.0.0',
            'metrics' => ['cpu_pct' => 77.0, 'mem_pct' => null, 'disk' => [], 'net_rx_bps' => null, 'net_tx_bps' => null]], $this->T);
        $this->assertCount(1, $this->samples('cpu.utilization'));
        $this->assertCount(0, $this->samples('memory.utilization'));
        $this->assertCount(0, $this->samples('network.rx_bytes_per_s'));
        foreach ($this->h->metrics->stored() as $s) {
            $this->assertNotSame(0, $s['value'], 'a missing reading is never stored as 0: ' . $s['key']);
        }
        $m = json_decode((string) $this->h->one("SELECT last_metrics_json FROM endpoint_agent_devices WHERE device_id={$this->dev}"), true);
        $this->assertNull($m['mem_pct']);
        $this->assertNull($m['net_rx_bps']);
        $this->assertEquals(77.0, $m['cpu_pct']);
        $this->assertNull($this->h->bridge->healthOf($this->intg, $this->assetId)['ram_pct'], 'the link ram percent is null, not 0');
    }

    public function testBufferedBacklogKeepsItsOwnTimesAndDropsBadEntries(): void
    {
        $this->linkedDevice();
        $buf = [];
        for ($i = 1; $i <= 3; ++$i) {
            $buf[] = ['collected_at' => $this->h::ts(-3600 * $i), 'metrics' => ['cpu_pct' => 20 + $i, 'mem_pct' => 30 + $i, 'disk' => [['mount' => 'C:', 'used_pct' => 50]], 'net_rx_bps' => null, 'net_tx_bps' => null], 'checks' => []];
        }
        $buf[] = ['collected_at' => $this->h::ts(3600 * 5), 'metrics' => ['cpu_pct' => 99]];   // in the future: dropped, not fatal
        $buf[] = 'garbage';
        $buf[] = ['collected_at' => 'garbage', 'metrics' => ['cpu_pct' => 1]];
        [$c] = $this->h->checkin($this->T, ['buffered' => $buf]);
        $this->assertSame(200, $c);
        $cpu = $this->samples('cpu.utilization');
        $this->assertCount(4, $cpu);   // the current one plus three valid backlog entries
        $this->assertSame([], array_filter($cpu, static fn (array $s): bool => $s['value'] === 99.0 || $s['value'] === 1.0));
        $times = array_map(static fn (array $s): int => $s['at']->getTimestamp(), $cpu);
        $this->assertCount(4, array_unique($times), 'each sample carries its own collection time');
        [$c] = $this->h->checkin($this->T, ['buffered' => array_fill(0, 101, ['collected_at' => $this->h::ts(-60), 'metrics' => ['cpu_pct' => 5]])]);
        $this->assertSame(422, $c);
    }

    /** @return iterable<string,array{0:array<string,mixed>,1:int}> */
    public static function badBodies(): iterable
    {
        yield 'future collected_at' => [['collected_at' => '+1 hour'], 422];
        yield 'older than retention' => [['collected_at' => 'old'], 422];
        yield 'not rfc3339' => [['collected_at' => 'yesterday'], 422];
        yield 'missing collected_at' => [['collected_at' => null], 422];
        yield 'bad version' => [['agent_version' => 'v1'], 422];
        yield 'bad version two parts' => [['agent_version' => '1.0'], 422];
        yield 'checks not a list' => [['checks' => ['a' => 1]], 422];
        yield 'too many checks' => [['checks' => 'many'], 422];
        yield 'bad check key' => [['checks' => [['key' => 'bad key!', 'status' => 'ok']]], 422];
        yield 'bad check status' => [['checks' => [['key' => 'k', 'status' => 'great']]], 422];
        yield 'inventory too large' => [['inventory' => ['blob' => 'BIG']], 422];
        yield 'inventory a list' => [['inventory' => [1, 2]], 422];
        yield 'metrics a list' => [['metrics' => [1, 2]], 422];
        yield 'buffered not a list' => [['buffered' => ['a' => 1]], 422];
        yield 'seq string' => [['seq' => '7'], 422];
        yield 'seq negative' => [['seq' => -1], 422];
    }

    /** @param array<string,mixed> $over */
    #[\PHPUnit\Framework\Attributes\DataProvider('badBodies')]
    public function testValidation(array $over, int $status): void
    {
        $this->linkedDevice();
        if (($over['collected_at'] ?? null) === '+1 hour') {
            $over['collected_at'] = $this->h::ts(3600);
        }
        if (($over['collected_at'] ?? null) === 'old') {
            $over['collected_at'] = gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 400);
        }
        if (($over['checks'] ?? null) === 'many') {
            $over['checks'] = array_fill(0, 101, ['key' => 'k', 'status' => 'ok', 'detail' => '']);
        }
        if (($over['inventory']['blob'] ?? null) === 'BIG') {
            $over['inventory']['blob'] = str_repeat('x', 70000);
        }
        [$c, , $r] = $this->h->checkin($this->T, $over);
        $this->assertSame($status, $c);
        $this->assertSame('invalid', $r['code']);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id={$this->dev}"), 'a refused body leaves no trace');
    }

    public function testSmallClockSkewIsToleratedAndTheBodyCapIs413(): void
    {
        $this->linkedDevice();
        [$c] = $this->h->checkin($this->T, ['collected_at' => $this->h::ts(60)]);
        $this->assertSame(200, $c);
        [$c, , $r] = $this->h->call('POST', 'agent_checkin', str_repeat('{"a":"' . str_repeat('x', 1000) . '"},', 1500), $this->T);
        $this->assertSame([413, 'too_large'], [$c, $r['code']]);
        // exactly at the cap is read, one byte over is refused (declared or not)
        $req = $this->h->request('POST', 'agent_checkin', str_repeat('x', 1048577), $this->T, [], [], '127.0.0.1', true, 5);
        $this->assertSame(413, $this->h->api->handle($req)->status, 'a lying Content-Length does not bypass the cap');
    }

    public function testOutOfRangeReadingsAreDroppedNotClampedNotZeroed(): void
    {
        $this->linkedDevice();
        $before = count($this->h->metrics->stored());
        [$c] = $this->h->checkin($this->T, ['metrics' => ['cpu_pct' => 150.0, 'mem_pct' => -4, 'disk' => [['mount' => 'C:', 'used_pct' => 101]], 'net_rx_bps' => -1, 'net_tx_bps' => 3]]);
        $this->assertSame(200, $c);
        $this->assertCount(0, $this->samples('cpu.utilization'));
        $this->assertCount(0, $this->samples('memory.utilization'));
        $this->assertCount(0, $this->samples('disk.utilization'));
        $this->assertCount(0, $this->samples('network.rx_bytes_per_s'));
        $this->assertCount(1, $this->samples('network.tx_bytes_per_s'));
        $this->assertSame($before + 1, count($this->h->metrics->stored()));
    }

    public function testAlertDebounceEpisodesAndRecovery(): void
    {
        $this->linkedDevice();
        $fail = fn (): array => $this->h->checkin($this->T, ['checks' => [['key' => 'disk_c', 'status' => 'fail', 'detail' => 'C: is 97% full']]]);
        $good = fn (): array => $this->h->checkin($this->T, ['checks' => [['key' => 'disk_c', 'status' => 'ok', 'detail' => '']]]);
        $fail();
        $fail();
        $this->assertSame([], $this->h->bridge->alertKeys(), 'two failures (debounce 3) raise no alert yet');
        $fail();
        $key = "agent:{$this->dev}:disk_c:1";
        $this->assertSame([$key], $this->h->bridge->alertKeys());
        $alertId = (int) $this->h->one("SELECT alert_id FROM endpoint_agent_checks WHERE device_id={$this->dev}");
        $a = $this->h->bridge->alert($alertId);
        $this->assertSame([$this->assetId, $this->h->clientA, 'error', 'new'], [$a['asset_id'], $a['client_id'], $a['severity'], $a['status']]);
        $this->assertStringContainsString('disk_c', $a['message']);
        $raw = $this->h->bridge->alertRaw($alertId)['raw'];
        $this->assertSame(['source' => 'rivetit_agent', 'device_id' => $this->dev, 'check' => 'disk_c', 'status' => 'fail', 'episode' => 1], $raw);
        $fail();
        $fail();
        $fail();
        $this->assertSame(1, $this->h->bridge->alertCount($this->intg, $key), 'continued failures do not create more alerts');
        // re-delivery of the same check-in creates no duplicate alert
        $dup = ['seq' => 900001, 'collected_at' => $this->h::ts(), 'agent_version' => '1.0.0', 'checks' => [['key' => 'disk_c', 'status' => 'fail']]];
        $this->h->call('POST', 'agent_checkin', $dup, $this->T);
        $this->h->call('POST', 'agent_checkin', $dup, $this->T);
        $this->assertCount(1, $this->h->bridge->alertKeys());
        $good();
        $this->assertSame('new', $this->h->bridge->alert($alertId)['status'], 'one OK result does not resolve yet (debounce 2)');
        $good();
        $this->assertSame('resolved', $this->h->bridge->alert($alertId)['status']);
        $this->assertSame([$alertId], $this->h->bridge->autoClosedAlerts(), 'recovery runs the edition ticket auto-close through the bridge');
        $fail();
        $fail();
        $fail();
        $this->assertSame([$key, "agent:{$this->dev}:disk_c:2"], $this->h->bridge->alertKeys(), 'a new failure episode after recovery is a NEW alert');
        $this->h->checkin($this->T, ['checks' => [['key' => 'disk_c', 'status' => 'unknown', 'detail' => '']]]);
        $this->assertSame(3, (int) $this->h->one("SELECT consecutive_failures FROM endpoint_agent_checks WHERE check_key='disk_c'"), 'an unknown result changes no counter');
    }

    public function testWarnOpensAWarningAlertAndBufferedChecksRunOldestFirst(): void
    {
        $this->linkedDevice();
        $this->h->module->settings()->set(['failure_debounce' => 2]);
        $this->h->checkin($this->T, ['checks' => [['key' => 'svc', 'status' => 'warn', 'detail' => 'slow']], 'buffered' => [
            ['collected_at' => $this->h::ts(-120), 'metrics' => null, 'checks' => [['key' => 'svc', 'status' => 'warn', 'detail' => 'older']]],
        ]]);
        $this->assertSame(1, $this->h->bridge->alertCount($this->intg, "agent:{$this->dev}:svc:1"));
        $a = $this->h->bridge->alert((int) $this->h->one('SELECT alert_id FROM endpoint_agent_checks'));
        $this->assertSame('warning', $a['severity']);
        $this->assertStringContainsString("check 'svc' warning on FRONTDESK: slow", $a['message']);
        $this->assertSame('warn', $this->h->one("SELECT status FROM endpoint_agent_checks WHERE check_key='svc'"));
    }

    public function testNoLinkMeansNoSamplesAndNoLinkUpdates(): void
    {
        $tok = $this->h->token(null, 24, 100);
        [, , $j] = $this->h->enroll($tok, $this->h::device());   // pending approval: no asset
        [$c, , $r] = $this->h->checkin($j['device_token']);
        $this->assertSame([200, 'pending_approval', null], [$c, $r['status'], $r['matched_asset_id']]);
        $this->assertSame([], $this->h->metrics->stored());
        $this->assertSame(0, $this->h->bridge->linkCount($this->h->integrationId()));
    }

    public function testALinkLostBetweenCheckinsIsRecreated(): void
    {
        $this->linkedDevice();
        $this->h->bridge->removeLink($this->intg, "rivetit:{$this->dev}");
        $this->h->checkin($this->T);
        $this->assertSame('online', $this->h->bridge->link($this->intg, "rivetit:{$this->dev}")['status']);
    }

    public function testAMeshNodeFromTheDeviceNeverOverwritesAManualOne(): void
    {
        $this->linkedDevice();
        $node = 'node//' . str_repeat('A', 24);
        $inv = $this->inventory() + ['mesh_node_id' => $node];
        $this->h->checkin($this->T, ['inventory' => $inv]);
        $this->assertSame([$node, 'agent'], [$this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id={$this->dev}"), $this->h->one("SELECT source FROM endpoint_agent_mesh_nodes WHERE device_id={$this->dev}")]);
        $this->assertTrue($this->h->module->devices()->setMeshNode($this->dev, 'node//' . str_repeat('B', 24), 1));
        $this->h->checkin($this->T, ['inventory' => $inv]);
        $this->assertSame('node//' . str_repeat('B', 24), $this->h->one("SELECT mesh_node_id FROM endpoint_agent_mesh_nodes WHERE device_id={$this->dev}"));
        $this->assertFalse($this->h->module->devices()->setMeshNode($this->dev, 'not-a-node', 1));
    }

    // ------------------------------------------------------------------ transactions

    public function testAFailureInsideTheCheckinRollsEverythingBack(): void
    {
        $this->linkedDevice();
        $this->h->module->settings()->set(['failure_debounce' => 1]);
        $this->h->bridge->failMethod = 'openAlert';
        $this->h->bridge->failOn = new \RuntimeException('edition write failed');
        [$c, , $r] = $this->h->checkin($this->T, ['seq' => 77, 'checks' => [['key' => 'disk_c', 'status' => 'fail']]]);
        $this->assertSame([500, 'internal'], [$c, $r['code']]);
        $this->assertSame('Internal error.', $r['error'], 'details never reach the device');
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id={$this->dev}"), 'the idempotency row was rolled back with it');
        $this->assertNull($this->h->one("SELECT last_checkin_at FROM endpoint_agent_devices WHERE device_id={$this->dev}"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE device_id={$this->dev}"));
        // the agent retries the same seq and it now goes through (it was not swallowed as a duplicate)
        $this->h->bridge->failOn = null;
        [$c] = $this->h->checkin($this->T, ['seq' => 77, 'checks' => [['key' => 'disk_c', 'status' => 'fail']]]);
        $this->assertSame(200, $c);
        $this->assertSame(1, $this->h->bridge->alertCount($this->intg, "agent:{$this->dev}:disk_c:1"));
    }

    public function testRevokedDuringTheCallIsA401AndLeavesNoTrace(): void
    {
        $this->linkedDevice();
        $dev = $this->h->module->devices()->find($this->dev);
        $this->h->q("UPDATE endpoint_agent_devices SET revoked_at = UTC_TIMESTAMP() WHERE device_id={$this->dev}");
        try {
            $this->h->module->checkin()->handle($dev ?? [], ['seq' => 1, 'collected_at' => $this->h::ts(), 'agent_version' => '1.0.0'], '127.0.0.1');
            $this->fail('expected ApiError');
        } catch (\RivetCore\Rmm\Http\ApiError $e) {
            $this->assertSame([401, 'revoked'], [$e->http, $e->errCode]);
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checkins WHERE device_id={$this->dev}"));
    }

    public function testCheckinsOfOneDeviceSerialiseOnTheDeviceRow(): void
    {
        $this->linkedDevice();
        $other = \RivetCore\Tests\Support\ScratchDb::connect();
        $this->assertNotNull($other);
        $other->begin_transaction();
        $other->query("SELECT * FROM endpoint_agent_devices WHERE device_id={$this->dev} FOR UPDATE");
        $this->h->q('SET SESSION innodb_lock_wait_timeout = 1');
        $t = microtime(true);
        [$c, , $r] = $this->h->checkin($this->T);
        $this->assertSame([500, 'internal'], [$c, $r['code']], 'a competing holder of the device row blocks the check-in (lock wait timeout)');
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $t);
        $other->rollback();
        $this->h->q('SET SESSION innodb_lock_wait_timeout = 50');
        [$c] = $this->h->checkin($this->T);
        $this->assertSame(200, $c);
    }

    // ------------------------------------------------------------------ module switch and load shedding

    public function testSubSwitchesMetricsMonitoringUpdatesJobs(): void
    {
        $this->linkedDevice();
        $this->h->module->settings()->set(['features_json' => '{"monitoring":true,"metrics":false,"jobs":false,"updates":false,"remote":false}']);
        $this->h->q("INSERT INTO endpoint_agent_releases (version, url, sha256, min_version, ring, rollout_pct, arch) VALUES ('2.0.0', 'https://x/y', '" . str_repeat('a', 64) . "', '0.0.0', 'stable', 100, '')");
        [$c, , $r] = $this->h->checkin($this->T, ['checks' => [['key' => 'k', 'status' => 'ok']]]);
        $this->assertSame(200, $c);
        $this->assertArrayNotHasKey('update', $r, 'updates off: the check-in omits the manifest');
        $this->assertSame(0, $r['jobs_pending']);
        $this->assertSame([], $this->h->metrics->stored(), 'metrics off: nothing is ingested');
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='k'"), 'monitoring on: checks still evaluated');
        $this->h->module->settings()->set(['features_json' => '{"metrics":true,"jobs":true,"updates":true}']);
        [, , $r] = $this->h->checkin($this->T, ['checks' => [['key' => 'k2', 'status' => 'ok']]]);
        $this->assertSame('2.0.0', $r['update']['version']);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='k2'"), 'monitoring off: checks skipped');
        $this->assertNotEmpty($this->h->metrics->stored());
        // NULL features_json is the legacy default: everything on
        $this->h->module->settings()->set(['features_json' => null]);
        [, , $r] = $this->h->checkin($this->T, ['checks' => [['key' => 'k3', 'status' => 'ok']]]);
        $this->assertArrayHasKey('update', $r);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_checks WHERE check_key='k3'"));
    }

    public function testShedLevelOneStretchesTheIntervalAndSkipsStaleBacklogSamples(): void
    {
        $this->linkedDevice();
        $this->h->module->settings()->set(['shed_level' => 1]);
        [$c, , $r] = $this->h->checkin($this->T, ['buffered' => [
            ['collected_at' => $this->h::ts(-3000), 'metrics' => ['cpu_pct' => 5]],
            ['collected_at' => $this->h::ts(-60), 'metrics' => ['cpu_pct' => 6]],
        ]]);
        $this->assertSame([200, 600], [$c, $r['next_check_in_s']]);
        $cpu = array_map(static fn (array $s): float|int => $s['value'], $this->samples('cpu.utilization'));
        sort($cpu);
        $this->assertSame([6.0, 10.5], $cpu, 'the 50-minute-old backlog sample was acknowledged but not ingested');
    }

    public function testShedLevelTwoAsksARecentlySeenDeviceToComeBackLater(): void
    {
        $this->linkedDevice();
        $this->h->checkin($this->T);
        $this->h->module->settings()->set(['shed_level' => 2]);
        [$c, $hd, $r] = $this->h->checkin($this->T);
        $this->assertSame([503, 'unavailable'], [$c, $r['code']]);
        $this->assertGreaterThanOrEqual(60, (int) $hd['Retry-After']);
        $this->assertLessThanOrEqual(300, (int) $hd['Retry-After']);
        // a device not seen inside its window is still served (it is not part of the herd)
        $this->h->q("UPDATE endpoint_agent_devices SET last_checkin_at = '" . gmdate('Y-m-d H:i:s', time() - 7200) . "'");
        [$c] = $this->h->checkin($this->T);
        $this->assertSame(200, $c);
    }

    public function testResponseSignaturesVerifyWithTheEnrollmentKey(): void
    {
        $this->linkedDevice();
        [, , $r] = $this->h->checkin($this->T);
        $pub = (string) $this->h->one('SELECT signing_public_key FROM endpoint_agent_settings');
        foreach ($r['config']['checks'] as $check) {
            $this->assertTrue(Signer::verify(Signer::checkMessage($check), $check['signature'], $pub));
        }
        $this->assertSame(Signer::keyId($pub), $r['signing_key_id']);
    }
}
