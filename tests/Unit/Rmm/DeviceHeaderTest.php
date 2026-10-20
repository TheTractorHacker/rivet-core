<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Presentation\DeviceHeader;
use RivetCore\Rmm\Presentation\RemoteHeader;

final class DeviceHeaderTest extends TestCase
{
    private const GB = 1073741824;

    /** @param list<array<string,mixed>> $disks @param array<string,mixed> $extra @return array<string,mixed> */
    private static function dev(string $os, array $disks, array $extra = [], array $metricDisks = []): array
    {
        return $extra + ['hostname' => 'host1', 'status' => 'online', 'os_version' => $os === 'linux' ? 'Debian GNU/Linux 12' : 'Windows 11 Pro 23H2', 'arch' => 'amd64', 'agent_version' => '1.0.0', 'ring' => 'stable',
            'last_checkin_at' => '2026-10-20T10:00:00Z', 'uptime_s' => 3600, 'inventory' => ['os' => $os, 'disks' => $disks], 'metrics' => ['cpu_pct' => 12.34, 'mem_pct' => 50, 'disk' => $metricDisks]];
    }

    private static function d(string $mount, int $totalGb, int $freeGb, string $fs = 'ext4', string $source = '', string $label = ''): array
    {
        return ['mount' => $mount, 'total_bytes' => $totalGb * self::GB, 'free_bytes' => $freeGb * self::GB, 'fs' => $fs] + ($source !== '' ? ['source' => $source] : []) + ($label !== '' ? ['label' => $label] : []);
    }

    /** @return array<string,mixed> */
    private static function chip(array $h, string $key): array
    {
        foreach ($h['chips'] as $c) {
            if ($c['key'] === $key) {
                return $c;
            }
        }
        self::fail("no $key chip");
    }

    public function testProxmoxEightDatasetsBecomeOneChipAndOnePool(): void
    {
        // The statfs of a dataset: total = its own use + the pool's shared free space, free = the shared figure.
        $free = 880;
        $sets = ['/' => ['rpool/ROOT/pve-1', 4], '/rpool' => ['rpool', 0], '/var/lib/vz' => ['rpool/var-lib-vz', 20], '/rpool/ROOT' => ['rpool/ROOT', 0], '/rpool/data' => ['rpool/data', 0],
            '/rpool/data/basevol-100-disk-0' => ['rpool/data/basevol-100-disk-0', 2], '/rpool/data/subvol-102-disk-0' => ['rpool/data/subvol-102-disk-0', 30], '/rpool/data/subvol-103-disk-0' => ['rpool/data/subvol-103-disk-0', 44]];
        $disks = [];
        foreach ($sets as $mount => [$src, $used]) {
            $disks[] = self::d($mount, $used + $free, $free, 'zfs', $src);
        }
        $disks[] = self::d('/boot/efi', 1, 1, 'vfat', '/dev/nvme0n1p2');
        $disks[] = ['mount' => '/run', 'total_bytes' => 4 * self::GB, 'free_bytes' => 4 * self::GB, 'fs' => 'tmpfs'];
        $h = DeviceHeader::build(self::dev('linux', $disks));

        $this->assertSame(['cpu', 'ram', 'disk', 'uptime'], array_column($h['chips'], 'key'));
        $disk = self::chip($h, 'disk');
        $this->assertSame('rpool', $disk['label']);
        $this->assertTrue($disk['pool']);
        $this->assertSame(0, $disk['more'], '/boot/efi and /run do not count as other volumes');
        $this->assertSame($free * self::GB, $disk['free_bytes']);
        $this->assertSame((4 + 20 + 2 + 30 + 44 + $free) * self::GB, $disk['total_bytes']);
        $this->assertEqualsWithDelta(100 * 100 / 980, $disk['used_pct'], 0.1);
        $this->assertSame('ok', $disk['level']);
        $pools = array_values(array_filter($h['volumes'], static fn (array $v): bool => isset($v['pool'])));
        $this->assertCount(1, $pools);
        $this->assertSame(8, $pools[0]['pool']['count']);
        $this->assertCount(8, $pools[0]['pool']['children']);
        $this->assertCount(3, $h['volumes'], 'pool + /boot/efi + /run');
        $run = array_values(array_filter($h['volumes'], static fn (array $v): bool => $v['mount'] === '/run'));
        $this->assertTrue($run[0]['pseudo']);
    }

    public function testOldAgentWithoutSourceStillGroupsZfsBySharedFreeSpace(): void
    {
        $disks = [self::d('/', 904, 880, 'zfs'), self::d('/rpool', 880, 880, 'zfs'), self::d('/rpool/data', 880, 880, 'zfs'), self::d('/rpool/data/subvol-1', 910, 880, 'zfs'), self::d('/mnt/backup', 1000, 100, 'ext4')];
        $h = DeviceHeader::build(self::dev('linux', $disks));
        $this->assertSame('rpool', self::chip($h, 'disk')['label']);
        $this->assertSame(1, self::chip($h, 'disk')['more'], '/mnt/backup is the other volume');
        $this->assertSame(2, count($h['volumes']));
    }

    public function testWindowsPrefersCAndHidesRecoveryAndGuidVolumes(): void
    {
        $disks = [self::d('D:', 1000, 100, 'NTFS'), self::d('C:', 500, 100, 'NTFS'), self::d('E:', 1, 0, 'NTFS', '', 'Recovery'), ['mount' => '\\\\?\\Volume{abc}\\', 'total_bytes' => 100, 'free_bytes' => 10, 'fs' => 'NTFS']];
        $h = DeviceHeader::build(self::dev('windows', $disks));
        $disk = self::chip($h, 'disk');
        $this->assertSame('C:', $disk['label'], 'the system volume wins over the fuller D:');
        $this->assertSame(80.0, $disk['used_pct']);
        $this->assertSame('warn', $disk['level'], '80 % is the warn limit');
        $this->assertSame(1, $disk['more']);
        $this->assertSame([false, false, true, true], array_column($h['volumes'], 'pseudo'), 'D:, C:, then the Recovery and GUID-only volumes');
    }

    public function testWindowsWithoutCPicksTheFullest(): void
    {
        $h = DeviceHeader::build(self::dev('windows', [self::d('D:', 100, 50, 'NTFS'), self::d('E:', 100, 5, 'NTFS')]));
        $this->assertSame('E:', self::chip($h, 'disk')['label']);
        $this->assertSame('crit', self::chip($h, 'disk')['level']);
    }

    public function testLinuxRootBootEfiSnapLoopsAndTmpfs(): void
    {
        $disks = [self::d('/boot/efi', 1, 1, 'vfat'), self::d('/snap/core/1', 1, 0, 'squashfs'), ['mount' => '/dev/shm', 'total_bytes' => self::GB, 'free_bytes' => self::GB, 'fs' => 'tmpfs'],
            self::d('/var/lib/docker/overlay2/x', 100, 10, 'overlay'), self::d('/', 100, 40, 'ext4', '/dev/sda2'), self::d('/data', 100, 5, 'xfs', '/dev/sdb1')];
        $h = DeviceHeader::build(self::dev('linux', $disks));
        $disk = self::chip($h, 'disk');
        $this->assertSame('/', $disk['label']);
        $this->assertSame(60.0, $disk['used_pct']);
        $this->assertSame(1, $disk['more'], 'only /data is another volume');
        $this->assertCount(6, $h['volumes']);
    }

    public function testBootEfiIsChosenWhenItIsTheOnlyVolume(): void
    {
        $h = DeviceHeader::build(self::dev('linux', [self::d('/boot/efi', 1, 1, 'vfat'), ['mount' => '/run', 'total_bytes' => 5, 'free_bytes' => 5, 'fs' => 'tmpfs']]));
        $this->assertSame('/boot/efi', self::chip($h, 'disk')['label']);
        $this->assertSame(0, self::chip($h, 'disk')['more']);
    }

    public function testSingleDiskAndNoDisksAndMetricsOverride(): void
    {
        $h = DeviceHeader::build(self::dev('linux', [self::d('/', 100, 50)], [], [['mount' => '/', 'used_pct' => 95.0, 'free_bytes' => 5 * self::GB, 'total_bytes' => 100 * self::GB]]));
        $this->assertSame('crit', self::chip($h, 'disk')['level'], 'fresh metrics win over the inventory figures');
        $this->assertSame(0, self::chip($h, 'disk')['more']);

        $none = DeviceHeader::build(self::dev('linux', []));
        $this->assertSame(['cpu', 'ram', 'uptime'], array_column($none['chips'], 'key'));
        $this->assertSame([], $none['volumes']);

        $bare = DeviceHeader::build(['hostname' => 'x']);
        $this->assertSame([], $bare['chips']);
        $this->assertSame('other', $bare['os']['family']);
    }

    public function testMetricOnlyDiskWithoutInventoryStillShows(): void
    {
        $h = DeviceHeader::build(self::dev('windows', [], [], [['mount' => 'C:', 'used_pct' => 40.5]]));
        $this->assertSame(40.5, self::chip($h, 'disk')['used_pct']);
        $this->assertNull(self::chip($h, 'disk')['free_bytes']);
    }

    public function testLevelsAndOverrides(): void
    {
        $dev = self::dev('linux', []);
        foreach ([[84.9, 'ok'], [85, 'warn'], [94.9, 'warn'], [95, 'crit']] as [$v, $lvl]) {
            $dev['metrics']['cpu_pct'] = $v;
            $this->assertSame($lvl, self::chip(DeviceHeader::build($dev), 'cpu')['level'], (string) $v);
        }
        $dev['metrics']['cpu_pct'] = 50;
        $h = DeviceHeader::build($dev, ['cpu' => ['warn' => ['op' => 'gte', 'value' => 40]]]);
        $this->assertSame('warn', self::chip($h, 'cpu')['level']);
        $dev['metrics']['cpu_pct'] = 250;
        $this->assertSame(100.0, self::chip(DeviceHeader::build($dev), 'cpu')['pct'], 'clamped');
    }

    public function testHostileMountStrings(): void
    {
        $evil = "/x\x00\n<script>alert(1)</script>" . str_repeat('A', 500);
        $disks = [self::d($evil, 10, 5, 'ext4', "/dev/\x07sda"), ['mount' => ['array'], 'total_bytes' => 1], 'junk', ['mount' => '', 'total_bytes' => 1], self::d('/ok', 10, 20), ['mount' => '/neg', 'total_bytes' => -5, 'free_bytes' => 'abc', 'fs' => 123]];
        $h = DeviceHeader::build(self::dev('linux', $disks));
        foreach ($h['volumes'] as $v) {
            $this->assertLessThanOrEqual(200, mb_strlen($v['mount']));
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $v['mount']);
            $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $v['label']);
        }
        $ok = array_values(array_filter($h['volumes'], static fn (array $v): bool => $v['mount'] === '/ok'));
        $this->assertSame(0.0, $ok[0]['used_pct'] + 0.0, 'free larger than total is clamped to total');
        json_encode($h, JSON_THROW_ON_ERROR);
    }

    public function testVolumeListIsCapped(): void
    {
        $disks = [];
        for ($i = 0; $i < 400; $i++) {
            $disks[] = self::d("/m$i", 10, $i % 10, 'ext4', "/dev/d$i");
        }
        $this->assertLessThanOrEqual(200, count(DeviceHeader::build(self::dev('linux', $disks))['volumes']));
    }

    public function testOsFamilyAndMeta(): void
    {
        $h = DeviceHeader::build(self::dev('linux', []));
        $this->assertSame(['family' => 'linux', 'label' => 'Debian GNU/Linux 12'], $h['os']);
        $this->assertSame('1.0.0', $h['meta']['agent_version']);
        $this->assertSame('stable', $h['meta']['ring']);
        $this->assertNull($h['has_desktop']);
        $this->assertSame('windows', DeviceHeader::family(['os_version' => 'Windows Server 2022']));
        $this->assertSame('linux', DeviceHeader::family(['os_version' => 'Ubuntu 24.04']));
        $this->assertTrue(DeviceHeader::build(self::dev('linux', [], ['inventory' => ['os' => 'linux', 'desktop_session' => true]]))['has_desktop']);
    }

    // ------------------------------------------------------------------ remote

    /** @return array<string,mixed> */
    private static function facts(array $over = []): array
    {
        return $over + ['family' => 'linux', 'has_desktop' => null, 'server' => false, 'enabled' => true, 'configured' => true, 'node' => 'node//abc', 'agent_installed' => true, 'offline' => false, 'can_launch' => true, 'can_manage' => true, 'tools_on' => true];
    }

    /** @return array<string,bool> */
    private static function avail(array $r): array
    {
        $o = [];
        foreach ($r['views'] as $v) {
            $o[$v['view']] = $v['available'];
        }

        return $o;
    }

    public function testRemoteStatesInPrecedenceOrder(): void
    {
        $this->assertSame('disabled', RemoteHeader::decide(self::facts(['enabled' => false, 'configured' => false, 'node' => null]))['state']);
        $this->assertSame('not_configured', RemoteHeader::decide(self::facts(['configured' => false, 'node' => null]))['state']);
        $this->assertSame('unmapped_agent_installed', RemoteHeader::decide(self::facts(['node' => null]))['state']);
        $this->assertSame('unmapped', RemoteHeader::decide(self::facts(['node' => null, 'agent_installed' => false]))['state']);
        $this->assertSame('offline', RemoteHeader::decide(self::facts(['offline' => true]))['state']);
        $this->assertSame('ready', RemoteHeader::decide(self::facts())['state']);
        $r = RemoteHeader::decide(self::facts(['node' => null]));
        $this->assertStringContainsString('Mesh agent is installed but this device is not mapped yet', $r['hint']);
        $this->assertTrue($r['can_map_manually']);
        $this->assertFalse(RemoteHeader::decide(self::facts())['can_map_manually']);
        $this->assertFalse(RemoteHeader::decide(self::facts(['node' => null, 'can_manage' => false]))['can_map_manually']);
        $this->assertSame(['available' => false], array_intersect_key($r['views'][1], ['available' => 1]));
        $this->assertNull($r['primary']);
    }

    public function testPrimaryViewRules(): void
    {
        $this->assertSame('terminal', RemoteHeader::decide(self::facts())['primary'], 'Linux leads with the terminal');
        $this->assertSame('desktop', RemoteHeader::decide(self::facts(['has_desktop' => true]))['primary'], 'Linux workstation');
        $this->assertSame('terminal', RemoteHeader::decide(self::facts(['has_desktop' => true, 'server' => true]))['primary']);
        $this->assertSame('desktop', RemoteHeader::decide(self::facts(['family' => 'windows']))['primary']);
        $this->assertSame('terminal', RemoteHeader::decide(self::facts(['family' => 'windows', 'has_desktop' => false]))['primary'], 'Server Core');
        $this->assertSame('terminal', RemoteHeader::decide(self::facts(['family' => 'other']))['primary']);
    }

    public function testPermissionsAndFeatureSwitch(): void
    {
        $noManage = RemoteHeader::decide(self::facts(['can_manage' => false]));
        $this->assertSame(['desktop' => true, 'terminal' => false, 'files' => false], self::avail($noManage));
        $this->assertNull($noManage['primary'], 'a Linux server whose terminal is not allowed has no primary');
        $this->assertSame('desktop', RemoteHeader::decide(self::facts(['can_manage' => false, 'family' => 'windows']))['primary']);
        $off = RemoteHeader::decide(self::facts(['tools_on' => false, 'family' => 'windows']));
        $this->assertSame(['desktop' => true, 'terminal' => false, 'files' => false], self::avail($off));
        $this->assertSame('The remote tools are switched off.', $off['views'][1]['reason']);
        $none = RemoteHeader::decide(self::facts(['can_manage' => false, 'can_launch' => false]));
        $this->assertSame(['desktop' => false, 'terminal' => false, 'files' => false], self::avail($none));
        $this->assertNull($none['primary']);
        $this->assertSame(['desktop' => false, 'terminal' => false, 'files' => false], self::avail(RemoteHeader::decide(self::facts(['enabled' => false]))));
    }
}
