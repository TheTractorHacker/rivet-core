<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Change\ChangeSettings;
use RivetCore\Rmm\Change\ChangeWatchPolicy;

/** CORE-DS-34: a registry watch key is never turned into a backtracking pattern, and a wildcard is a whole segment. */
final class ChangeRegistryWatchMatchTest extends TestCase
{
    public function testManyWildcardsInOneSegmentCostNothing(): void
    {
        $watch = ['kind' => 'registry', 'paths' => array_fill(0, 10, ['key' => 'HKLM\\SOFTWARE\\*a*a*a*b'])];
        $target = 'HKLM\\SOFTWARE\\xb' . str_repeat('a', 280);
        $t = microtime(true);
        for ($i = 0; $i < 100; $i++) {
            $this->assertFalse(ChangeSettings::targetInWatch($watch, $target));
        }
        $this->assertLessThan(0.5, microtime(true) - $t, '100 records x 10 paths');
    }

    public function testThePolicyRefusesAWildcardInsideAName(): void
    {
        $p = new ChangeWatchPolicy();
        $this->assertFalse($p->allows('registry', 'HKLM\\SOFTWARE\\*a*a*a*b', 0));
        $this->assertFalse($p->allows('registry', 'HKLM\\SOFTWARE\\Acme*\\Run', 0));
        $this->assertTrue($p->allows('registry', 'HKU\\*\\Software\\Microsoft\\Windows\\CurrentVersion\\Run', 0));
    }

    public function testWholeSegmentWildcardAndPrefixMatchingStillWork(): void
    {
        $w = ['kind' => 'registry', 'paths' => [['key' => 'HKU\\*\\Software\\Microsoft\\Windows\\CurrentVersion\\Run']]];
        $this->assertTrue(ChangeSettings::targetInWatch($w, 'HKU\\S-1-5-21-1-2-3-1001\\Software\\Microsoft\\Windows\\CurrentVersion\\Run'));
        $this->assertTrue(ChangeSettings::targetInWatch($w, 'hku\\S-1-5-21-1-2-3-1001\\SOFTWARE\\Microsoft\\Windows\\CurrentVersion\\Run\\Sub'));
        $this->assertFalse(ChangeSettings::targetInWatch($w, 'HKU\\\\Software\\Microsoft\\Windows\\CurrentVersion\\Run'), 'the wildcard needs a segment');
        $this->assertFalse(ChangeSettings::targetInWatch($w, 'HKU\\S-1-5-21-1\\Software\\Microsoft\\Windows\\CurrentVersion\\RunOnce'));
        $this->assertFalse(ChangeSettings::targetInWatch($w, 'HKU\\S-1-5-21-1\\Software'));
        $k = ['kind' => 'registry', 'paths' => [['key' => 'HKEY_LOCAL_MACHINE\\SOFTWARE\\Acme']]];
        $this->assertTrue(ChangeSettings::targetInWatch($k, 'HKLM\\SOFTWARE\\ACME\\Sub'));
        $this->assertFalse(ChangeSettings::targetInWatch($k, 'HKLM\\SOFTWARE\\AcmeEvil'));
    }

    /** CORE-DS-65: Proxmox / PBS / Ceph secret files cannot be watched (the agent's deny list needs the same entries; see the changelog). */
    public function testHypervisorSecretFilesCannotBeWatched(): void
    {
        $p = new ChangeWatchPolicy();
        foreach (['/etc/pve/priv/token.cfg', '/etc/pve/priv/storage/pbs1.pw', '/etc/pve/priv/tfa.cfg', '/etc/pve/priv/acme/default', '/etc/ceph/ceph.client.admin.keyring', '/etc/pve/priv/ceph.client.admin.keyring',
            '/etc/zabbix/zabbix_agentd.psk', '/etc/proxmox-backup/token.shadow', '/etc/proxmox-backup/user.cfg', '/etc/foo/vpn.cred', '/etc/restic/repo.enc'] as $path) {
            $this->assertFalse($p->allows('file', $path, 0), $path);
        }
        foreach (['/etc/pve/storage.cfg', '/etc/ceph/ceph.conf', '/etc/hosts', '/etc/ssh/sshd_config'] as $path) {
            $this->assertTrue($p->allows('file', $path, 0), $path);
        }
    }
}
