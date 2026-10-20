<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmAbility;
use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Hypervisor\HypervisorProtocol;
use RivetCore\Rmm\Hypervisor\HypervisorService;
use RivetCore\Rmm\RmmEvent;
use RivetCore\Testing\InMemoryRmmEvents;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RemoteTestCase;
use RivetCore\Tests\Support\RmmHarness;

/**
 * Hypervisor visibility (Proxmox VE, Hyper-V): off by default, a SIGNED configuration offered only to an agent that asks, field-by-field validation of the reported block, caps,
 * missing and purged VMs, the alert pipeline (debounce, auto-resolve, per-VM exclusion), events, the REST read model and an old agent that is not affected at all.
 */
final class HypervisorTest extends RemoteTestCase
{
    private InMemoryRmmEvents $bus;
    private RmmPrincipal $manager;
    private int $seq = 0;

    protected function makeHarness(): RmmHarness
    {
        $this->bus = new InMemoryRmmEvents();
        $this->transferDir = sys_get_temp_dir() . '/rmm_xfer_' . bin2hex(random_bytes(4));
        $view = [RmmAbility::DEVICE_VIEW, RmmAbility::HYPERVISOR_VIEW];

        return new RmmHarness(null, null, ['allow_linux' => true, 'transfer_dir' => $this->transferDir], false, null,
            new AllowUsersPolicy([1 => true, 10 => $view, 11 => [RmmAbility::DEVICE_VIEW, RmmAbility::JOB_RUN_SAVED], 12 => [RmmAbility::DEVICE_VIEW], 13 => [...$view, RmmAbility::HYPERVISOR_MANAGE], 16 => true]), null, $this->bus);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = new RmmPrincipal(13, 'Manager');
        [$this->dev, $this->devToken] = $this->linked('PVE1', null, 'linux', self::caps());
    }

    /** @return list<string> */
    private static function caps(string $os = 'linux'): array
    {
        $c = [...self::allCaps($os), 'hypervisor'];
        sort($c);

        return $c;
    }

    private function on(): void
    {
        $this->switchOn(['hypervisor']);
    }

    /** @param array<string,mixed> $over */
    private static function vm(string $id, array $over = []): array
    {
        $type = str_contains($id, '/') ? explode('/', $id)[0] : 'hyperv';

        return $over + ['id' => $id, 'name' => 'vm-' . str_replace('/', '-', $id), 'type' => $type, 'state' => 'running', 'node' => 'pve1', 'cpus' => 2, 'cpu_pct' => 3.5, 'mem_bytes' => 1 << 30, 'mem_max_bytes' => 2 << 30,
            'disk_bytes' => 32 << 30, 'uptime_s' => 3600];
    }

    /**
     * @param list<array<string,mixed>> $vms
     * @param array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function full(array $vms, array $over = []): array
    {
        return $over + ['mode' => 'full', 'platform' => 'proxmox', 'version' => '8.2.4', 'collected_at' => self::iso(-5), 'vms' => $vms, 'storage' => []];
    }

    /** @param array<string,mixed> $block @return array{0:int,1:mixed} */
    private function send(array $block, ?string $token = null): array
    {
        [$c, , $j] = $this->h->checkin($token ?? $this->devToken, ['hypervisor' => $block]);

        return [$c, $j];
    }

    private function vmRow(string $key, ?int $dev = null): ?array
    {
        $r = $this->h->rows('SELECT * FROM rmm_hypervisor_vms WHERE device_id = ' . ($dev ?? $this->dev) . " AND vm_key = '" . $key . "'");

        return $r[0] ?? null;
    }

    /** @return list<string> the check keys that have an open alert */
    private function openAlerts(): array
    {
        return array_column($this->h->rows("SELECT check_key FROM endpoint_agent_checks WHERE device_id = $this->dev AND check_key LIKE 'hv.%' AND status IN ('warn', 'fail') AND alert_id IS NOT NULL ORDER BY check_key"), 'check_key');
    }

    /** The same report several times: the threshold classes wait for `failure_debounce` (3) reports. @param array<string,mixed> $block */
    private function repeat(array $block, int $n = 3): void
    {
        for ($i = 0; $i < $n; ++$i) {
            $this->send($block);
        }
    }

    private function checkStatus(string $key): ?string
    {
        $v = $this->h->one("SELECT status FROM endpoint_agent_checks WHERE device_id = $this->dev AND check_key = '" . $key . "'");

        return $v === null ? null : (string) $v;
    }

    // ------------------------------------------------------------------ off by default, offered signed, old agents untouched

    public function testOffByDefaultAndAnOldAgentIsAnsweredExactlyAsBefore(): void
    {
        $this->assertFalse($this->h->module->settings()->featureOn('hypervisor'));
        $this->assertContains('hypervisor', \RivetCore\Rmm\Settings\RmmSettings::FEATURES);
        $this->assertNotContains('hypervisor', \RivetCore\Rmm\Settings\RmmSettings::LEGACY_FEATURES);
        $r = $this->announce($this->devToken, self::caps(), 'linux');
        $this->assertArrayNotHasKey('features', $r);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($r['config']));
        [$c] = $this->send(self::full([self::vm('qemu/100')]));
        $this->assertSame(200, $c, 'a block while the switch is off is ignored, not an error');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_hosts'));
        // switched on: an agent that never heard of it sees no difference
        $this->on();
        $old = $this->announce($this->devToken, self::allCaps('linux'), 'linux');
        $this->assertArrayNotHasKey('features', $old);
        $this->assertSame(['checks', 'collect_interval_s'], array_keys($old['config']));
        [$c2] = $this->h->checkin($this->devToken, ['capabilities' => self::allCaps('linux'), 'hypervisor' => self::full([self::vm('qemu/100')])]);
        $this->assertSame(200, $c2);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'), 'a device that did not announce hypervisor cannot feed it');
    }

    public function testTheSignedConfigIsOfferedOnlyToAnAgentThatAskedWhileTheSwitchIsOn(): void
    {
        $this->on();
        $r = $this->announce($this->devToken, self::caps(), 'linux');
        $this->assertSame(['hypervisor'], $r['features']);
        $cfg = $r['config']['hypervisor'];
        $this->assertSame(['v', 'kind', 'platform', 'full_interval_s', 'summary_interval_s', 'max_vms', 'snapshot_lookups', 'seq', 'version', 'signature'], array_keys($cfg));
        $this->assertSame([1, 'hypervisor_config', 'linux', 600, 120, 1000], [$cfg['v'], $cfg['kind'], $cfg['platform'], $cfg['full_interval_s'], $cfg['summary_interval_s'], $cfg['max_vms']]);
        $this->assertLessThan(HypervisorProtocol::CONFIG_MAX_BYTES, strlen((string) json_encode($cfg)));
        [, $pub] = $this->h->module->settings()->signingKey();
        $this->assertTrue(Signer::verify(Signer::hypervisorConfigMessage($cfg), $cfg['signature'], $pub));
        $this->assertStringStartsWith("rivetit-hypervisor-config-v1\n{", Signer::hypervisorConfigMessage($cfg));
        foreach (['platform' => 'windows', 'full_interval_s' => 301, 'max_vms' => 999] as $k => $v) {
            $t = $cfg;
            $t[$k] = $v;
            $this->assertFalse(Signer::verify(Signer::hypervisorConfigMessage($t), $cfg['signature'], $pub), "$k is signed");
        }
        $plain = $cfg;
        unset($plain['signature']);
        foreach ([Signer::jobMessage($plain), Signer::checkMessage($plain), Signer::changeConfigMessage($plain)] as $m) {
            $this->assertFalse(Signer::verify($m, $cfg['signature'], $pub), 'domain separation');
        }
        $this->assertSame($cfg['version'], $this->announce($this->devToken, self::caps(), 'linux')['config']['hypervisor']['version']);
        // the interval is part of the signed config, and a settings change raises seq and changes the version
        $this->h->clock->advance(10);
        $this->assertSame(200, $this->api('PUT', ['hypervisor', 'settings'], $this->manager, ['limits' => ['hypervisor_full_interval_s' => 900, 'hypervisor_summary_interval_s' => 60]])[0]);
        $n = $this->announce($this->devToken, self::caps(), 'linux')['config']['hypervisor'];
        $this->assertSame([900, 60], [$n['full_interval_s'], $n['summary_interval_s']]);
        $this->assertNotSame($cfg['version'], $n['version']);
        $this->assertGreaterThanOrEqual($cfg['seq'], $n['seq']);
        // Windows devices get their own platform
        [, $wt] = $this->linked('HV1', null, 'windows', self::caps('windows'));
        $this->assertSame('windows', $this->announce($wt, self::caps('windows'), 'windows')['config']['hypervisor']['platform']);
        // load shedding level 2 withdraws the offer (and 1 drops the block)
        $this->h->module->settings()->set(['shed_level' => 2]);
        $this->assertArrayNotHasKey('features', $this->announce($this->devToken, self::caps(), 'linux'));
        $this->h->module->settings()->set(['shed_level' => 1]);
        $this->send(self::full([self::vm('qemu/100')]));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
        $this->h->module->settings()->set(['shed_level' => 0]);
        $this->send(self::full([self::vm('qemu/100')]));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
    }

    // ------------------------------------------------------------------ ingest

    public function testAFullReportIsStoredIdempotentlyAndKeepsAStateHistoryRing(): void
    {
        $this->on();
        $vms = [self::vm('qemu/100', ['tags' => ['prod', 'windows'], 'snapshots' => ['count' => 2, 'oldest_at' => self::iso(-3 * 86400)], 'last_backup' => ['at' => self::iso(-3600), 'result' => 'ok'], 'backup_covered' => true, 'guest_agent' => true]),
            self::vm('qemu/101', ['state' => 'stopped', 'cpu_pct' => null, 'mem_bytes' => null, 'uptime_s' => null]), self::vm('qemu/9000', ['template' => true, 'state' => 'stopped'])];
        $storage = [['name' => 'local', 'type' => 'dir', 'total_bytes' => 1000, 'used_bytes' => 500, 'health' => 'ok'], ['name' => 'rpool', 'type' => 'zfs_pool', 'total_bytes' => 2000, 'used_bytes' => 100, 'health' => 'warn']];
        $block = self::full($vms, ['storage' => $storage, 'cluster' => ['name' => 'lab', 'quorate' => true, 'nodes' => [['name' => 'pve1', 'online' => true], ['name' => 'pve2', 'online' => false]]]]);
        $this->assertSame(200, $this->send($block)[0]);
        $this->assertSame(200, $this->send($block)[0]);
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_storage'));
        $host = $this->h->rows('SELECT * FROM rmm_hypervisor_hosts')[0];
        $this->assertSame(['proxmox', '8.2.4', 'lab', '1', 2], [$host['platform'], $host['version'], $host['cluster_name'], (string) $host['quorate'], $host['vm_count'] + 0]);
        $a = $this->vmRow('qemu/100');
        $this->assertSame(['vm-qemu-100', 'qemu', 'running', 'prod,windows', 2, 'ok', '1', '1'], [$a['name'], $a['type'], $a['state'], $a['tags'], $a['snapshot_count'] + 0, $a['last_backup_result'], (string) $a['backup_covered'], (string) $a['guest_agent']]);
        $this->assertNull($this->vmRow('qemu/101')['cpu_pct'], 'a stopped VM has no CPU reading: null, never 0');
        // a lookup that was skipped this time keeps the last known snapshot and backup values
        $block2 = self::full([self::vm('qemu/100'), self::vm('qemu/101'), self::vm('qemu/9000', ['template' => true])]);
        $this->send($block2);
        $a = $this->vmRow('qemu/100');
        $this->assertSame([2, 'ok'], [$a['snapshot_count'] + 0, $a['last_backup_result']]);
        // state changes go to the ring, which is bounded
        $this->h->module->settings()->update(['limits_json' => ['hypervisor_history_per_vm' => 5]]);
        for ($i = 0; $i < 9; ++$i) {
            $this->send(self::full([self::vm('qemu/100', ['state' => $i % 2 === 0 ? 'stopped' : 'running'])]));
        }
        $this->assertSame(5, (int) $this->h->one("SELECT COUNT(*) FROM rmm_hypervisor_vm_history WHERE vm_id = {$a['vm_id']}"));
        $this->assertSame('stopped', $this->vmRow('qemu/100')['state']);
        $this->assertNotNull($this->vmRow('qemu/100')['state_changed_at']);
    }

    public function testEachFieldIsValidatedOnItsOwnAndHostileTextIsCleaned(): void
    {
        $this->on();
        $evil = self::vm('qemu/200', ['name' => "x\u{0}\n<script>alert(1)</script>\u{202e}" . str_repeat('A', 300), 'cpu_pct' => 500, 'mem_bytes' => -5, 'mem_max_bytes' => '9', 'cpus' => 1e9, 'uptime_s' => 1.5,
            'state' => 'on fire', 'tags' => array_merge(['<b>t</b>', str_repeat('t', 99)], array_fill(0, 30, 'z')), 'snapshots' => ['count' => 'many', 'oldest_at' => 'yesterday'],
            'last_backup' => ['at' => self::iso(86400 * 400), 'result' => 'great'], 'guest_agent' => 'yes', 'replication' => 'sure']);
        $bad = [self::vm('lxc/../etc'), self::vm('qemu/0'), self::vm('qemu/100', ['type' => 'lxc']), self::vm('qemu/101', ['type' => 'hyperv']), self::vm('0f1a2b3c-0000-4000-8000-000000000001', ['type' => 'qemu']),
            ['id' => ['x']], 'text', null, self::vm('qemu/102', ['type' => 'vmware'])];
        $this->send(self::full([$evil, self::vm('qemu/201'), ...$bad], ['storage' => [['name' => '', 'type' => 'x'], ['name' => 'ok', 'total_bytes' => 10, 'used_bytes' => 99, 'health' => 'great'], 'junk'],
            'cluster' => ['name' => 'c', 'quorate' => 'yes', 'nodes' => 'many'], 'version' => str_repeat('v', 500), 'collected_at' => 'not a time']));
        $keys = array_column($this->h->rows('SELECT vm_key FROM rmm_hypervisor_vms ORDER BY vm_key'), 'vm_key');
        $this->assertSame(['qemu/200', 'qemu/201'], $keys, 'the others disagree between id, type and platform and are dropped, one by one');
        $v = $this->vmRow('qemu/200');
        $this->assertSame(100, mb_strlen($v['name']));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1f\x{202e}]/u', $v['name']);
        $this->assertNull($v['cpu_pct']);
        $this->assertNull($v['mem_bytes']);
        $this->assertNull($v['mem_max_bytes'], 'a string is not a number');
        $this->assertSame('0', (string) $v['cpus']);
        $this->assertNull($v['uptime_s']);
        $this->assertSame('unknown', $v['state']);
        $this->assertLessThanOrEqual(HypervisorProtocol::MAX_TAGS, count(explode(',', $v['tags'])));
        $this->assertNull($v['snapshot_count'], 'a bad snapshot object leaves the value unknown');
        $this->assertNull($v['last_backup_at'], 'a backup dated in the far future is refused');
        $this->assertNull($v['guest_agent']);
        $this->assertSame('', $v['replication']);
        $st = $this->h->rows('SELECT * FROM rmm_hypervisor_storage');
        $this->assertCount(1, $st);
        $this->assertSame(['10', '10', 'unknown'], [(string) $st[0]['total_bytes'], (string) $st[0]['used_bytes'], $st[0]['health']], 'used is clamped to total');
        $h = $this->h->rows('SELECT * FROM rmm_hypervisor_hosts')[0];
        $this->assertLessThanOrEqual(60, mb_strlen($h['version']));
        $this->assertNull($h['quorate']);
        // a block that is not an object at all, a wrong mode or platform: ignored, the check-in still succeeds
        foreach ([[], 'x', ['mode' => 'weird', 'platform' => 'proxmox'], ['mode' => 'full', 'platform' => 'vmware'], ['mode' => 'full']] as $junk) {
            $this->assertSame(200, $this->send(is_array($junk) ? $junk : ['x'])[0]);
        }
        $this->assertNull(HypervisorService::cleanReport('x', time()));
    }

    public function testMissingVmsArePurgedAfterRetentionAndAnErrorOrACutNeverMarksThem(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101')], ['storage' => [['name' => 's', 'type' => 'dir', 'total_bytes' => 10, 'used_bytes' => 1, 'health' => 'ok']]]));
        // an error report with a partial list: nothing is marked missing
        $this->send(self::full([self::vm('qemu/100')], ['error' => 'storage: pvesh exited with status 2']));
        $this->assertNull($this->vmRow('qemu/101')['missing_since']);
        $this->assertSame('storage: pvesh exited with status 2', $this->h->rows('SELECT error FROM rmm_hypervisor_hosts')[0]['error']);
        // a report the agent cut at its caps: same
        $this->send(self::full([self::vm('qemu/100')], ['truncated' => true]));
        $this->assertNull($this->vmRow('qemu/101')['missing_since']);
        // an error-only report changes nothing else
        $this->send(['mode' => 'full', 'platform' => 'proxmox', 'collected_at' => self::iso(-1), 'error' => 'guests: pvesh exited with status 255']);
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE missing_since IS NULL'));
        // a complete report without it: missing, and the storage too
        $this->send(self::full([self::vm('qemu/100')]));
        $this->assertNotNull($this->vmRow('qemu/101')['missing_since']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_storage WHERE missing_since IS NOT NULL'));
        $this->assertSame(1, (int) $this->h->rows('SELECT vm_count FROM rmm_hypervisor_hosts')[0]['vm_count']);
        // back again: no longer missing
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101')]));
        $this->assertNull($this->vmRow('qemu/101')['missing_since']);
        // gone for good: purged after the retention days by the housekeeping
        $this->send(self::full([self::vm('qemu/100')]));
        $this->h->clock->advance(29 * 86400);
        $this->h->module->housekeeping()->run();
        $this->assertNotNull($this->vmRow('qemu/101'), 'still inside the 30 days');
        $this->h->clock->advance(2 * 86400);
        $out = $this->h->module->housekeeping()->run();
        $this->assertNull($this->vmRow('qemu/101'));
        $this->assertArrayHasKey('pruned_hypervisor_vms', $out);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_storage'));
    }

    public function testThePerDeviceCapStopsAHostThatInventsGuests(): void
    {
        $this->on();
        $this->h->module->settings()->update(['limits_json' => ['hypervisor_max_vms' => 5]]);
        $vms = [];
        for ($i = 1; $i <= 8; ++$i) {
            $vms[] = self::vm('qemu/' . (100 + $i));
        }
        $this->send(self::full($vms));
        $this->assertSame(5, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE missing_since IS NOT NULL'), 'a report that the cap cut never marks anything missing');
        $this->send(self::full(array_slice($vms, 0, 2)));
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE missing_since IS NOT NULL'), 'a complete report may');
    }

    public function testASummaryOnlyChangesTheStateOfVmsWeAlreadyKnow(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101')]));
        $this->send(['mode' => 'summary', 'platform' => 'proxmox', 'collected_at' => self::iso(-1), 'changes' => [['id' => 'qemu/101', 'state' => 'stopped'], ['id' => 'qemu/777', 'state' => 'stopped'], ['id' => 'bad', 'state' => 'running'], ['id' => 'qemu/100', 'state' => 'running']], 'vm_count' => 2]);
        $this->assertSame('stopped', $this->vmRow('qemu/101')['state']);
        $this->assertNull($this->vmRow('qemu/777'), 'an unknown VM waits for the next full report');
        $this->assertSame('running', $this->vmRow('qemu/100')['state']);
        $this->assertNull($this->vmRow('qemu/101')['uptime_s']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vm_history'));
        $this->assertSame(2, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms WHERE missing_since IS NULL'), 'a summary never marks VMs missing');
    }

    public function testAHostThatIsFailingToStoreNeverFailsTheCheckin(): void
    {
        $this->on();
        $this->h->mysqli->query('RENAME TABLE rmm_hypervisor_hosts TO rmm_hypervisor_hosts_off');
        try {
            [$c] = $this->send(self::full([self::vm('qemu/100')]));
            $this->assertSame(200, $c, 'e.g. migration 0036 not applied yet');
        } finally {
            $this->h->mysqli->query('RENAME TABLE rmm_hypervisor_hosts_off TO rmm_hypervisor_hosts');
        }
    }

    // ------------------------------------------------------------------ alerts and events

    public function testAVmThatStopsRaisesAnAlertAndAnEventAndResolvesWhenItRunsAgain(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101', ['state' => 'stopped', 'cpu_pct' => null]), self::vm('qemu/9000', ['template' => true, 'state' => 'stopped'])]));
        $this->assertSame([], $this->openAlerts(), 'a VM first seen stopped was never seen running; templates never alert');
        $key = HypervisorProtocol::vmCheckKey('vmdown', 'qemu/100');
        $this->assertStringStartsWith('hv.vmdown.', $key);
        $this->assertMatchesRegularExpression(\RivetCore\Rmm\RmmProtocol::CHECK_KEY_RE, $key);
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped', 'cpu_pct' => null]), self::vm('qemu/101', ['state' => 'stopped'])]));
        $this->assertCount(1, $this->openAlerts());
        $this->assertSame('warn', $this->checkStatus($key));
        $ev = $this->bus->of(RmmEvent::HYPERVISOR_VM_DOWN);
        $this->assertCount(1, $ev);
        $this->assertSame(['qemu/100', 'running', 'stopped', $key], [$ev[0]['vm_key'], $ev[0]['from_state'], $ev[0]['to_state'], $ev[0]['alert_check_key']]);
        $this->assertStringContainsString('vm-qemu-100', (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key='$key'"));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped']), self::vm('qemu/101', ['state' => 'stopped'])]));
        $this->assertCount(1, $this->bus->of(RmmEvent::HYPERVISOR_VM_DOWN), 'one event per transition, not per report');
        // it runs again: the alert resolves by itself
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101', ['state' => 'stopped'])]));
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101', ['state' => 'stopped'])]));
        $this->assertSame('ok', $this->checkStatus($key));
        $this->assertSame([], $this->openAlerts());
    }

    public function testAVmThatStaysStoppedLongIsTakenAsSwitchedOffAndAnAlertToggleSilencesIt(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100')]));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped'])]));
        $key = HypervisorProtocol::vmCheckKey('vmdown', 'qemu/100');
        $this->assertSame('warn', $this->checkStatus($key));
        $this->h->clock->advance(25 * 3600);
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped'])]));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped'])]));
        $this->assertSame('ok', $this->checkStatus($key), 'stopped for more than hypervisor_vm_down_resolve_h hours');
        // alert class off: the condition is recorded as an event, no check
        $this->send(self::full([self::vm('qemu/101')]));
        $this->h->module->settings()->update(['limits_json' => ['hypervisor_alert_vm_down' => 0]]);
        $this->send(self::full([self::vm('qemu/101', ['state' => 'stopped'])]));
        $this->assertNull($this->checkStatus(HypervisorProtocol::vmCheckKey('vmdown', 'qemu/101')));
        $this->assertCount(2, $this->bus->of(RmmEvent::HYPERVISOR_VM_DOWN));
    }

    public function testBackupAndSnapshotThresholdsAreConfigurable(): void
    {
        $this->on();
        $old = ['count' => 1, 'oldest_at' => self::iso(-10 * 86400)];
        $this->send(self::full([
            self::vm('qemu/100', ['last_backup' => ['at' => self::iso(-3600), 'result' => 'ok'], 'snapshots' => ['count' => 0]]),
            self::vm('qemu/101', ['last_backup' => ['at' => self::iso(-3 * 86400), 'result' => 'ok'], 'snapshots' => $old]),
            self::vm('qemu/102', ['last_backup' => ['at' => self::iso(-60), 'result' => 'failed']]),
            self::vm('lxc/200', ['last_backup' => ['at' => self::iso(-60), 'result' => 'warning']]),
            self::vm('qemu/103', ['backup_covered' => true]),
            self::vm('qemu/104'),
        ]));
        $b = fn (string $id): ?string => $this->checkStatus(HypervisorProtocol::vmCheckKey('backup', $id));
        $s = fn (string $id): ?string => $this->checkStatus(HypervisorProtocol::vmCheckKey('snapshot', $id));
        $this->assertNull($b('qemu/100'), 'a current backup: nothing to say');
        $this->assertSame('warn', $b('qemu/101'), 'older than the 2 day default');
        $this->assertSame('fail', $b('qemu/102'));
        $this->assertSame('warn', $b('lxc/200'));
        $this->assertNull($b('qemu/103'), 'covered by a job but new: given the days first');
        $this->assertNull($b('qemu/104'), 'no backup information at all: not judged');
        $this->assertSame('warn', $s('qemu/101'));
        $this->assertNull($s('qemu/100'));
        $reasons = array_column($this->bus->of(RmmEvent::HYPERVISOR_BACKUP_FAILED), 'reason');
        sort($reasons);
        $this->assertSame(['failed', 'stale', 'warning'], $reasons);
        $this->assertSame(10, $this->bus->of(RmmEvent::HYPERVISOR_SNAPSHOT_OLD)[0]['age_days']);
        // raise the thresholds: the next full report resolves both
        $this->assertSame(200, $this->api('PUT', ['hypervisor', 'settings'], $this->manager, ['limits' => ['hypervisor_backup_days' => 7, 'hypervisor_snapshot_days' => 30]])[0]);
        $same = [self::vm('qemu/101', ['last_backup' => ['at' => self::iso(-3 * 86400), 'result' => 'ok'], 'snapshots' => $old])];
        $this->send(self::full($same));
        $this->send(self::full($same));
        $this->assertSame('ok', $b('qemu/101'));
        $this->assertSame('ok', $s('qemu/101'));
    }

    public function testStorageThresholdsPoolHealthAndQuorum(): void
    {
        $this->on();
        $st = static fn (int $used, string $h = 'ok'): array => [['name' => 'local-zfs', 'type' => 'zfspool', 'total_bytes' => 1000, 'used_bytes' => $used, 'health' => $h], ['name' => 'tank', 'type' => 'zfs_pool', 'total_bytes' => 1000, 'used_bytes' => 10, 'health' => $h], ['name' => 'ceph', 'type' => 'ceph', 'health' => $h]];
        $nodes = [['name' => 'a', 'online' => true], ['name' => 'b', 'online' => false], ['name' => 'c', 'online' => false]];
        $this->repeat(self::full([], ['storage' => $st(900, 'warn'), 'cluster' => ['name' => 'lab', 'quorate' => false, 'nodes' => $nodes]]));
        $this->assertSame('warn', $this->checkStatus('hv.storage.' . HypervisorProtocol::keyPart('local-zfs')));
        $this->assertSame('warn', $this->checkStatus('hv.pool.' . HypervisorProtocol::keyPart('tank')));
        $this->assertSame('warn', $this->checkStatus('hv.pool.ceph'));
        $this->assertSame('fail', $this->checkStatus('hv.quorum'));
        $this->assertNull($this->checkStatus('hv.storage.ceph'), 'a storage without a size has no percentage');
        $this->assertSame(1, count($this->bus->of(RmmEvent::HYPERVISOR_CLUSTER_QUORUM_LOST)));
        $this->assertSame([1, 3], [$this->bus->of(RmmEvent::HYPERVISOR_CLUSTER_QUORUM_LOST)[0]['nodes_online'], $this->bus->of(RmmEvent::HYPERVISOR_CLUSTER_QUORUM_LOST)[0]['nodes_total']]);
        $this->assertSame(90, $this->bus->of(RmmEvent::HYPERVISOR_STORAGE_LOW)[0]['used_pct']);
        $this->assertSame('warn', $this->bus->of(RmmEvent::HYPERVISOR_STORAGE_LOW)[0]['level']);
        $this->repeat(self::full([], ['storage' => $st(960, 'error'), 'cluster' => ['name' => 'lab', 'quorate' => false, 'nodes' => $nodes]]));
        $this->assertSame('fail', $this->checkStatus('hv.storage.' . HypervisorProtocol::keyPart('local-zfs')), '96% is past the 95% critical');
        $this->assertSame('fail', $this->checkStatus('hv.pool.' . HypervisorProtocol::keyPart('tank')));
        // everything healthy again: all of it resolves on its own
        $this->send(self::full([], ['storage' => $st(100), 'cluster' => ['name' => 'lab', 'quorate' => true, 'nodes' => $nodes]]));
        $this->send(self::full([], ['storage' => $st(100), 'cluster' => ['name' => 'lab', 'quorate' => true, 'nodes' => $nodes]]));
        $this->assertSame([], $this->openAlerts());
        // the percentages are settings with a sane order
        $this->assertSame(422, $this->api('PUT', ['hypervisor', 'settings'], $this->manager, ['limits' => ['hypervisor_storage_warn_pct' => 99]])[0]);
        $this->assertSame(200, $this->api('PUT', ['hypervisor', 'settings'], $this->manager, ['limits' => ['hypervisor_storage_warn_pct' => 50, 'hypervisor_storage_crit_pct' => 60]])[0]);
        $this->repeat(self::full([], ['storage' => $st(550)]));
        $this->assertSame('warn', $this->checkStatus('hv.storage.' . HypervisorProtocol::keyPart('local-zfs')));
    }

    public function testIgnoringAVmResolvesItsAlertAndKeepsItQuiet(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100', ['last_backup' => ['at' => self::iso(-60), 'result' => 'failed']])]));
        $this->assertCount(1, $this->openAlerts());
        $id = (int) $this->vmRow('qemu/100')['vm_id'];
        $this->assertSame(403, $this->api('PUT', ['hypervisor', 'vms', (string) $id], $this->viewerWithView(), ['ignored' => true])[0]);
        $this->assertSame(422, $this->api('PUT', ['hypervisor', 'vms', (string) $id], $this->manager, ['ignored' => 'yes'])[0]);
        [$c, $j] = $this->api('PUT', ['hypervisor', 'vms', (string) $id], $this->manager, ['ignored' => true, 'reason' => 'dev guest <b>x</b>']);
        $this->assertSame(200, $c);
        $this->assertTrue($j['ignored']);
        $this->assertSame('dev guest <b>x</b>', $j['ignored_reason'], 'stored as text; the UI escapes it');
        $this->assertSame('ok', $this->checkStatus(HypervisorProtocol::vmCheckKey('backup', 'qemu/100')));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped', 'last_backup' => ['at' => self::iso(-60), 'result' => 'failed']])]));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'running', 'last_backup' => ['at' => self::iso(-60), 'result' => 'failed']])]));
        $this->assertSame([], $this->openAlerts());
        $this->assertNull($this->checkStatus(HypervisorProtocol::vmCheckKey('vmdown', 'qemu/100')));
        $this->assertSame(1, (int) $this->vmRow('qemu/100')['ignored'], 'a new report does not undo the exclusion');
        $this->assertStringContainsString('Hypervisor VM Ignored', (json_encode($this->h->audit->records()) ?: ''));
        // un-ignore: alerts come back
        $this->api('PUT', ['hypervisor', 'vms', (string) $id], $this->manager, ['ignored' => false]);
        $this->send(self::full([self::vm('qemu/100', ['last_backup' => ['at' => self::iso(-60), 'result' => 'failed']])]));
        $this->assertCount(1, $this->openAlerts());
        // the whole host
        [$c] = $this->api('PUT', [(string) $this->dev, 'hypervisor'], $this->manager, ['ignored' => true]);
        $this->assertSame(200, $c);
        $this->assertSame([], $this->openAlerts());
        $this->send(self::full([self::vm('qemu/555')]));
        $this->assertNull($this->vmRow('qemu/555'), 'an ignored host stores nothing');
    }

    private function viewerWithView(): RmmPrincipal
    {
        return new RmmPrincipal(10, 'Viewer');
    }

    public function testAlertTextCannotCarryMarkup(): void
    {
        $this->on();
        $name = '<img src=x onerror=alert(1)> `rm -rf` "q" \'s\' \\';
        $this->send(self::full([self::vm('qemu/100', ['name' => $name])]));
        $this->send(self::full([self::vm('qemu/100', ['name' => $name, 'state' => 'stopped'])]));
        $detail = (string) $this->h->one("SELECT detail FROM endpoint_agent_checks WHERE device_id=$this->dev AND check_key LIKE 'hv.vmdown.%'");
        $this->assertNotSame('', $detail);
        $this->assertDoesNotMatchRegularExpression('/[<>`"\'\\\\]/', $detail);
        $ev = $this->bus->of(RmmEvent::HYPERVISOR_VM_DOWN)[0];
        $this->assertDoesNotMatchRegularExpression('/[<>`"\'\\\\]/', $ev['vm_name']);
    }

    // ------------------------------------------------------------------ REST read model

    public function testTheReadModelHostsVmsDetailAndSuggestions(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100', ['tags' => ['prod']]), self::vm('qemu/101', ['state' => 'stopped']), self::vm('lxc/200'), self::vm('qemu/9000', ['template' => true])],
            ['storage' => [['name' => 'rpool', 'type' => 'zfs_pool', 'total_bytes' => 100, 'used_bytes' => 50, 'health' => 'warn']], 'cluster' => ['name' => 'lab', 'quorate' => true, 'nodes' => [['name' => 'pve1', 'online' => true]]]]));
        $this->send(self::full([self::vm('qemu/100', ['state' => 'stopped'])], ['storage' => [['name' => 'rpool', 'type' => 'zfs_pool', 'total_bytes' => 100, 'used_bytes' => 50, 'health' => 'warn']], 'cluster' => ['name' => 'lab', 'quorate' => true, 'nodes' => [['name' => 'pve1', 'online' => true]]]]));
        [$c, $j] = $this->api('GET', ['hypervisor'], $this->viewerWithView());
        $this->assertSame(200, $c);
        $this->assertSame(1, $j['total']);
        $h = $j['data'][0];
        $this->assertSame([$this->dev, 'PVE1', 'proxmox', '8.2.4', 'lab'], [$h['device_id'], $h['hostname'], $h['platform'], $h['version'], $h['cluster']['name']]);
        $this->assertSame([1, 0, 1, 3], [$h['vm_count'], $h['vms_running'], $h['storages_unhealthy'], $h['vms_missing']], 'only qemu/100 is left (stopped): the rest are missing (3, one of them a template)');
        $this->assertSame(['hosts' => 1, 'vms' => 1, 'running' => 0, 'ignored' => 0, 'missing' => 3], $j['summary']);
        $this->assertSame(200, $this->api('GET', ['hypervisor'], $this->viewerWithView(), null, ['problems' => '1'])[0]);
        $this->assertSame(1, $this->api('GET', ['hypervisor'], $this->viewerWithView(), null, ['problems' => '1'])[1]['total'], 'an unhealthy storage makes a problem host');
        $this->assertSame(0, $this->api('GET', ['hypervisor'], $this->viewerWithView(), null, ['q' => 'nomatch'])[1]['total']);
        // host detail
        [$c, $d] = $this->api('GET', [(string) $this->dev, 'hypervisor'], $this->viewerWithView());
        $this->assertSame(200, $c);
        $this->assertSame(['rpool'], array_column($d['storage'], 'name'));
        $this->assertSame(50, $d['storage'][0]['used_pct']);
        $this->assertSame(['stopped' => 1], $d['vm_states']);
        $this->assertSame(['qemu/100'], array_column($d['vms'], 'key'));
        // VM search
        $this->send(self::full([self::vm('qemu/100', ['tags' => ['prod']]), self::vm('qemu/101', ['state' => 'stopped']), self::vm('lxc/200'), self::vm('qemu/9000', ['template' => true])]));
        $names = fn (array $q) => array_column($this->api('GET', ['hypervisor', 'vms'], $this->viewerWithView(), null, $q)[1]['data'], 'key');
        $this->assertSame(['lxc/200', 'qemu/100', 'qemu/101', 'qemu/9000'], $names([]), 'ordered by name; templates are listed too');
        $this->assertSame(['qemu/100'], $names(['q' => 'prod']));
        $this->assertSame(['qemu/101'], $names(['state' => 'stopped', 'template' => '0']));
        $this->assertSame(['lxc/200'], $names(['type' => 'lxc']));
        $this->assertSame([], $names(['q' => "100'; DROP TABLE x;--"]));
        $this->assertCount(4, $names(['state' => 'bogus']), 'an unknown state value is ignored, not an error');
        // one VM with its ring
        $id = (int) $this->vmRow('qemu/100')['vm_id'];
        [$c, $v] = $this->api('GET', ['hypervisor', 'vms', (string) $id], $this->viewerWithView());
        $this->assertSame(200, $c);
        $this->assertSame('qemu/100', $v['key']);
        $this->assertSame(['running', 'stopped'], [$v['history'][0]['to'], $v['history'][1]['to']], 'newest first: it started again, after it stopped');
        $this->assertGreaterThanOrEqual(2, count($v['history']));
        // suggestions: by name, never linked
        $this->h->asset(['name' => 'vm-qemu-100', 'serial' => 'SER-X', 'client_id' => $this->h->clientA]);
        [$c, $s] = $this->api('GET', ['hypervisor', 'vms', (string) $id, 'asset-suggestions'], $this->viewerWithView());
        $this->assertSame(200, $c);
        $this->assertSame(['hostname', 'medium', true], [$s['data'][0]['matched_by'], $s['data'][0]['confidence'], $s['data'][0]['same_client']]);
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_devices WHERE asset_id = {$s['data'][0]['asset_id']} AND device_id <> $this->dev"), 'nothing is linked or created');
        // unknown, malformed, wrong verb
        $this->assertSame(404, $this->api('GET', ['hypervisor', 'vms', '999999'], $this->viewerWithView())[0]);
        $this->assertSame(404, $this->api('GET', ['hypervisor', 'vms', 'abc'], $this->viewerWithView())[0]);
        $this->assertSame(405, $this->api('POST', ['hypervisor'], $this->viewerWithView(), [])[0]);
        $this->assertSame(404, $this->api('GET', ['hypervisor', 'nope'], $this->viewerWithView())[0]);
        // grants
        $this->assertSame(403, $this->api('GET', ['hypervisor'], new RmmPrincipal(12, 'NoGrant'))[0], 'device.view alone is not enough');
        $this->assertSame(403, $this->api('PUT', ['hypervisor', 'settings'], $this->viewerWithView(), ['limits' => ['hypervisor_backup_days' => 3]])[0]);
        $this->assertSame(403, $this->api('PUT', ['hypervisor', 'settings'], $this->manager, ['features' => ['hypervisor' => false]])[0], 'the switch is rmm.admin');
        $this->assertSame(200, $this->api('PUT', ['hypervisor', 'settings'], $this->admin, ['features' => ['hypervisor' => false]])[0]);
        $this->assertFalse($this->h->module->settings()->featureOn('hypervisor'));
        foreach ([['bogus' => 1], ['features' => ['monitoring' => false]], ['limits' => ['max_checkins_per_min' => 5]], ['limits' => ['hypervisor_backup_days' => 0]], ['limits' => ['hypervisor_backup_days' => '3']]] as $bad) {
            $this->assertSame(422, $this->api('PUT', ['hypervisor', 'settings'], $this->admin, $bad)[0], json_encode($bad));
        }
        [, $set] = $this->api('GET', ['hypervisor', 'settings'], $this->viewerWithView());
        $this->assertSame([7, 2, 85, 95], [$set['defaults']['hypervisor_snapshot_days'], $set['defaults']['hypervisor_backup_days'], $set['defaults']['hypervisor_storage_warn_pct'], $set['defaults']['hypervisor_storage_crit_pct']]);
        $audit = (json_encode($this->h->audit->records()) ?: '');
        $this->assertStringContainsString('Hypervisor Settings Changed', $audit);
        $this->assertStringContainsString('Hypervisor Settings Denied', $audit);
    }

    public function testAClientScopedTechnicianNeverSeesAnotherClientsHypervisors(): void
    {
        $this->on();
        $this->send(self::full([self::vm('qemu/100')]));
        [$b, $bt] = $this->linked('PVEB', $this->h->clientB, 'linux', self::caps());
        $this->send(self::full([self::vm('qemu/100'), self::vm('qemu/101')]), $bt);
        $scoped = $this->scoped;
        $keys = array_column($this->api('GET', ['hypervisor', 'vms'], $scoped)[1]['data'], 'device_id');
        $this->assertSame([$b, $b], $keys, 'only client B');
        $this->assertSame(1, $this->api('GET', ['hypervisor'], $scoped)[1]['total']);
        $this->assertSame(404, $this->api('GET', [(string) $this->dev, 'hypervisor'], $scoped)[0]);
        $idA = (int) $this->vmRow('qemu/100')['vm_id'];
        $this->assertSame(404, $this->api('GET', ['hypervisor', 'vms', (string) $idA], $scoped)[0]);
        $this->assertSame(404, $this->api('PUT', ['hypervisor', 'vms', (string) $idA], $scoped, ['ignored' => true])[0]);
        $this->assertSame(0, (int) $this->vmRow('qemu/100')['ignored']);
        $this->assertSame(404, $this->api('GET', ['hypervisor'], $scoped, null, ['client_id' => (string) $this->h->clientA])[0]);
    }

    public function testWhatAThousandVmReportCostsInStatementsAndBytes(): void
    {
        $this->on();
        $vms = [];
        for ($i = 1; $i <= 1000; ++$i) {
            $vms[] = self::vm('qemu/' . (100 + $i), ['name' => 'server-' . $i . '.prod', 'tags' => ['prod', 'web'], 'snapshots' => ['count' => 1, 'oldest_at' => self::iso(-86400)], 'last_backup' => ['at' => self::iso(-3600), 'result' => 'ok'], 'backup_covered' => true, 'guest_agent' => true]);
        }
        $storage = [['name' => 'local', 'type' => 'dir', 'total_bytes' => 1000, 'used_bytes' => 100, 'health' => 'ok']];
        $count = function (array $block): int {
            $before = $this->h->counting->statements;
            $this->send($block);

            return $this->h->counting->statements - $before;
        };
        $first = $count(self::full($vms, ['storage' => $storage]));
        $steady = $count(self::full($vms, ['storage' => $storage]));
        $vms[0]['state'] = 'stopped';
        $withChange = $count(self::full($vms, ['storage' => $storage]));
        $summary = $count(['mode' => 'summary', 'platform' => 'proxmox', 'collected_at' => self::iso(-1), 'changes' => [['id' => 'qemu/101', 'state' => 'running']], 'vm_count' => 1000]);
        $off = $count(['mode' => 'summary', 'platform' => 'proxmox', 'collected_at' => self::iso(-1), 'changes' => []]);
        fwrite(STDERR, "\nhypervisor statements per check-in (1000 VMs): first full $first, steady full $steady, full with one change $withChange, summary with one change $summary, empty summary $off\n");
        $this->assertLessThanOrEqual(80, $first);
        $this->assertLessThanOrEqual(60, $steady);
        $this->assertLessThanOrEqual(75, $withChange);
        $this->assertLessThanOrEqual(30, $summary);
        $this->assertLessThanOrEqual(20, $off);
        $this->assertSame(1000, (int) $this->h->one('SELECT COUNT(*) FROM rmm_hypervisor_vms'));
        $bytes = (int) $this->h->one("SELECT data_length + index_length FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'rmm_hypervisor_vms'");
        fwrite(STDERR, 'rmm_hypervisor_vms: ' . $bytes . ' bytes for 1000 rows (' . intdiv($bytes, 1000) . " per VM; InnoDB pages, so a small table is rounded up)\n");
    }

    public function testQueuedIngestModeProcessesTheReportToo(): void
    {
        $this->on();
        $this->h->module->settings()->set(['ingest_mode' => 'queued']);
        $this->send(self::full([self::vm('qemu/100')]));
        $this->h->module->ingestQueue()->drain();
        $this->assertNotNull($this->vmRow('qemu/100'));
    }
}
