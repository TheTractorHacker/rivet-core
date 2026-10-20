<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\RemoteTestCase;

/**
 * The device header through the real read model and RemoteActions: a seeded Proxmox-like Linux device whose inventory carries eight ZFS datasets (with and without
 * the additive `source`), the Mesh states from the real settings and report, and four principals.
 */
final class DeviceHeaderTest extends RemoteTestCase
{
    private const GB = 1073741824;
    private const NODE = 'node//AbCdEfGhIjKlMnOpQrStUvWxYz0123456789@$AbCdEfGhIjKlMnOpQrStUvWxYz01';

    /** @return array<string,mixed> */
    private function inventory(bool $withSource, ?bool $desktop = null): array
    {
        $sets = ['/' => 'rpool/ROOT/pve-1', '/rpool' => 'rpool', '/var/lib/vz' => 'rpool/var-lib-vz', '/rpool/ROOT' => 'rpool/ROOT', '/rpool/data' => 'rpool/data',
            '/rpool/data/basevol-100-disk-0' => 'rpool/data/basevol-100-disk-0', '/rpool/data/subvol-102-disk-0' => 'rpool/data/subvol-102-disk-0', '/rpool/data/subvol-103-disk-0' => 'rpool/data/subvol-103-disk-0'];
        $disks = [];
        foreach ($sets as $mount => $src) {
            $disks[] = ['mount' => $mount, 'total_bytes' => (100 + 10) * self::GB, 'free_bytes' => 100 * self::GB, 'fs' => 'zfs'] + ($withSource ? ['source' => $src, 'label' => ''] : []);
        }
        $disks[] = ['mount' => '/boot/efi', 'total_bytes' => self::GB, 'free_bytes' => self::GB, 'fs' => 'vfat'];

        return ['hostname' => 'pve1', 'os' => 'linux', 'os_version' => 'Debian GNU/Linux 12', 'disks' => $disks, 'uptime_s' => 86400] + ($desktop === null ? [] : ['desktop_session' => $desktop]);
    }

    /** @return array{0:int,1:string} */
    private function proxmox(bool $withSource = true, ?bool $desktop = null): array
    {
        [$dev, $token] = $this->linked('pve1', null, 'linux');
        $disk = [['mount' => '/', 'used_pct' => 9.1, 'free_bytes' => 100 * self::GB, 'total_bytes' => 110 * self::GB]];
        [$c] = $this->h->checkin($token, ['inventory' => $this->inventory($withSource, $desktop), 'metrics' => ['cpu_pct' => 3.0, 'mem_pct' => 88.0, 'disk' => $disk, 'net_rx_bps' => 1.0, 'net_tx_bps' => 1.0]]);
        $this->assertSame(200, $c);

        return [$dev, $token];
    }

    /** @return array<string,mixed> */
    private function header(int $dev): array
    {
        $rm = $this->h->module->readModel();
        $row = $this->h->module->devices()->find($dev);
        $this->assertNotNull($row);

        return $rm->header($rm->detail($row, false));
    }

    private function meshOn(bool $key = true): void
    {
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test', 'mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com']);
        if ($key) {
            $this->assertTrue($this->h->module->admin()->saveMesh($this->admin, ['mesh_enabled' => 1, 'mesh_url' => 'https://mesh.example.com', 'mesh_login_key' => str_repeat('ab', 32)])->ok);
        }
        $this->assertSame([], $this->h->module->meshAgentSettings()->update(['mesh_group_id' => str_repeat('ab', 48)], 1));
    }

    public function testProxmoxHostGetsOneDiskChipAndOnePool(): void
    {
        [$dev] = $this->proxmox();
        $h = $this->header($dev);
        $this->assertSame(['cpu', 'ram', 'disk', 'uptime'], array_column($h['chips'], 'key'));
        $disk = $h['chips'][2];
        $this->assertSame('rpool', $disk['label']);
        $this->assertSame(0, $disk['more']);
        $this->assertSame(['family' => 'linux', 'label' => 'Debian GNU/Linux 12'], $h['os']);
        $this->assertSame('warn', $h['chips'][1]['level'], '88 % memory');
        $this->assertSame('online', $h['status']);
        $pool = array_values(array_filter($h['volumes'], static fn (array $v): bool => isset($v['pool'])));
        $this->assertCount(1, $pool);
        $this->assertSame(8, $pool[0]['pool']['count']);
        $this->assertSame('pve1', $h['name']);
        $this->assertNull($h['has_desktop']);
        json_encode($h, JSON_THROW_ON_ERROR);
    }

    public function testAnOldAgentWithoutSourceStillGroups(): void
    {
        [$dev] = $this->proxmox(false);
        $h = $this->header($dev);
        $this->assertSame('rpool', $h['chips'][2]['label']);
        $this->assertSame(0, $h['chips'][2]['more']);
    }

    public function testDesktopSessionIsKeptAndReported(): void
    {
        [$dev] = $this->proxmox(true, true);
        $this->assertTrue($this->header($dev)['has_desktop']);
        $r = $this->h->module->remoteActions()->headerRemote($this->admin, $dev);
        $this->assertTrue($r->ok);
    }

    public function testRemoteStatesThroughTheRealSettingsAndReport(): void
    {
        [$dev, $token] = $this->proxmox();
        $this->switchOn(['remote_tools']);
        $ra = $this->h->module->remoteActions();
        $data = static fn ($r): array => $r->data;

        $this->assertSame('disabled', $data($ra->headerRemote($this->admin, $dev))['state']);
        $this->meshOn(false);
        $this->assertSame('not_configured', $data($ra->headerRemote($this->admin, $dev))['state']);
        $this->meshOn();
        $this->assertSame('unmapped', $data($ra->headerRemote($this->admin, $dev))['state']);

        [$c] = $this->h->checkin($token, ['mesh_agent' => ['installed' => true, 'version' => '1.1.10']]);
        $this->assertSame(200, $c);
        $r = $data($ra->headerRemote($this->admin, $dev));
        $this->assertSame('unmapped_agent_installed', $r['state']);
        $this->assertStringContainsString('restart the RivetIT agent', $r['hint']);
        $this->assertTrue($r['can_map_manually']);
        $this->assertNull($r['primary']);

        $this->assertTrue($this->h->module->devices()->setMeshNode($dev, self::NODE, 1));
        $r = $data($ra->headerRemote($this->admin, $dev));
        $this->assertSame(['ready', 'terminal'], [$r['state'], $r['primary']]);
        $this->assertSame([true, true, true], array_column($r['views'], 'available'));
        $this->assertFalse($r['can_map_manually']);
        // meshInfo() is untouched
        $info = $ra->meshInfo($this->admin, $dev);
        $this->assertSame(['desktop', 'terminal', 'files'], $info->data['views']);
    }

    public function testPrincipalsSeeOnlyWhatTheyMayOpen(): void
    {
        [$dev] = $this->proxmox();
        $this->switchOn(['remote_tools']);
        $this->meshOn();
        $this->assertTrue($this->h->module->devices()->setMeshNode($dev, self::NODE, 1));
        $ra = $this->h->module->remoteActions();

        $tech = $ra->headerRemote($this->tech, $dev)->data;
        $this->assertSame('terminal', $tech['primary']);

        $runner = $ra->headerRemote($this->runner, $dev)->data;   // remote.launch without remote.manage
        $this->assertSame([true, false, false], array_column($runner['views'], 'available'));
        $this->assertNull($runner['primary'], 'a Linux server whose terminal is not allowed leads with nothing');

        $viewer = $ra->headerRemote($this->viewer, $dev)->data;
        $this->assertSame([false, false, false], array_column($viewer['views'], 'available'));
        $this->assertSame('ready', $viewer['state']);

        $scoped = $ra->headerRemote($this->scoped, $dev);
        $this->assertFalse($scoped->ok);
        $this->assertSame(404, $scoped->http);
    }

    public function testTerminalNeedsTheRemoteToolsSwitch(): void
    {
        [$dev] = $this->proxmox();
        $this->meshOn();
        $this->assertTrue($this->h->module->devices()->setMeshNode($dev, self::NODE, 1));
        $r = $this->h->module->remoteActions()->headerRemote($this->admin, $dev)->data;
        $this->assertSame([true, false, false], array_column($r['views'], 'available'));
        $this->assertNull($r['primary']);
    }
}
