<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Rmm\Binaries\BinaryInspector;
use RivetCore\Rmm\Migration\Migration0037AgentOs;
use RivetCore\Rmm\Support\AgentOs;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/** Agent binaries and releases are per operating system (migration 0037, rc.30): a Linux device is never offered or served a Windows executable, and the other way round. */
final class AgentOsTest extends RmmTestCase
{
    private int $win = 0;
    private int $lin = 0;
    private string $winTok = '';
    private string $linTok = '';

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(null, null, ['allow_linux' => true]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        AgentOs::forget();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        $tok = $this->h->token(null, 24, 20);
        [, , $w] = $this->h->enroll($tok, $this->h::device(['serial' => 'W-1']));
        [, , $l] = $this->h->enroll($tok, $this->h::device(['serial' => 'L-1', 'os' => 'linux', 'os_version' => 'Debian 12', 'hostname' => 'pve1']));
        $this->win = (int) $w['device_id'];
        $this->winTok = $w['device_token'];
        $this->lin = (int) $l['device_id'];
        $this->linTok = $l['device_token'];
    }

    private function dev(int $id): array
    {
        return $this->h->module->devices()->find($id) ?? [];
    }

    /** @return array{0:int,1:array<string,string>,2:mixed,3:\RivetCore\Rmm\Http\RmmResponse} */
    private function get(string $tok, array $q)
    {
        return $this->h->call('GET', 'agent_update', null, $tok, $q);
    }

    public function testTheSameVersionAndArchGivesEachOsItsOwnBinary(): void
    {
        $w = $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable');
        $l = $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable', 100, '0.0.0', 'linux');
        $mw = $this->h->module->updates()->manifestFor($this->dev($this->win));
        $ml = $this->h->module->updates()->manifestFor($this->dev($this->lin));
        $this->assertSame([$w['sha256'], 'https://rmm.example.test/api/v1/agent_update?arch=amd64&version=2.0.0'], [$mw['sha256'], $mw['url']], 'the Windows manifest is what it always was');
        $this->assertSame([$l['sha256'], 'https://rmm.example.test/api/v1/agent_update?arch=amd64&version=2.0.0&os=linux'], [$ml['sha256'], $ml['url']]);
        $this->assertNotSame($mw['sha256'], $ml['sha256']);

        // each device downloads its own file; an old agent sends no os at all
        [$c, , , $r] = $this->get($this->winTok, ['arch' => 'amd64', 'version' => '2.0.0']);
        $this->assertSame([200, $w['path']], [$c, $r->file?->path]);
        [$c, $hd, , $r] = $this->get($this->linTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'linux']);
        $this->assertSame([200, $l['path']], [$c, $r->file?->path]);
        $this->assertSame('attachment; filename="rivetit-agent-amd64"', $hd['Content-Disposition']);
        [$c, , , $r] = $this->get($this->linTok, ['arch' => 'amd64', 'version' => '2.0.0']);
        $this->assertSame([200, $l['path']], [$c, $r->file?->path], 'no os parameter: the device\'s stored os decides');
        // an agent that contradicts its enrolled OS, or names an OS we do not know
        [$c] = $this->get($this->linTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'windows']);
        $this->assertSame(404, $c);
        [$c] = $this->get($this->winTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'linux']);
        $this->assertSame(404, $c);
        [$c, , $j] = $this->get($this->winTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'freebsd']);
        $this->assertSame([422, 'invalid'], [$c, $j['code']]);
    }

    public function testANewWindowsOnlyReleaseIsNeverOfferedToALinuxDevice(): void
    {
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable');
        $this->assertNotNull($this->h->module->updates()->manifestFor($this->dev($this->win)));
        $this->assertNull($this->h->module->updates()->manifestFor($this->dev($this->lin)), 'the bug: a Linux device was offered the Windows build');
        [$c] = $this->get($this->linTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'linux']);
        $this->assertSame(404, $c);
        // and a Linux-only release is never offered to Windows
        $this->h->publishBinary('2.1.0', 'amd64', 8192, false, 'stable', 100, '0.0.0', 'linux');
        $this->assertSame('2.0.0', $this->h->module->updates()->manifestFor($this->dev($this->win))['version']);
        $this->assertSame('2.1.0', $this->h->module->updates()->manifestFor($this->dev($this->lin))['version']);
        // a legacy external release row (no binary) is a Windows release too
        $this->assertNull($this->h->module->updates()->addRelease('3.0.0', 'https://rmm.example.test/a.exe', str_repeat('c', 64), '0.0.0', 'stable', 100, '', 1));
        $this->assertSame('3.0.0', $this->h->module->updates()->manifestFor($this->dev($this->win))['version']);
        $this->assertSame('2.1.0', $this->h->module->updates()->manifestFor($this->dev($this->lin))['version']);
    }

    public function testInstallerBinariesAreWindowsOnly(): void
    {
        $this->h->publishBinary('2.0.0', 'amd64', 8192, true, null, 100, '0.0.0', 'linux');
        $this->assertNull($this->h->module->updates()->currentBinary('amd64'));
        $w = $this->h->publishBinary('1.9.0', 'amd64', 8192, true);
        $this->assertSame($w['binary_id'], (int) $this->h->module->updates()->currentBinary('amd64')['binary_id']);
        $this->assertSame('2.0.0', $this->h->module->updates()->currentBinary('amd64', 'linux')['version']);
    }

    public function testWrongMagicIsRefusedAtServeTimeNeverStreamedAndTheRowIsDisabled(): void
    {
        $l = $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable', 100, '0.0.0', 'linux');
        $pe = RmmHarness::fakePe(0x8664, 8192, 'oops');   // the Windows build ended up under the Linux row, sha and size consistent
        file_put_contents($l['path'], $pe);
        $this->h->q("UPDATE endpoint_agent_binaries SET sha256='" . hash('sha256', $pe) . "' WHERE binary_id={$l['binary_id']}");
        $this->h->q("UPDATE endpoint_agent_releases SET sha256='" . hash('sha256', $pe) . "' WHERE binary_id={$l['binary_id']}");
        $row = $this->h->module->readModel()->binaries()[0];
        $this->assertFalse($row['platform_ok'], 'the admin sees it before anything is served');
        $this->assertSame('linux', $row['os']);

        [$c, , $j, $r] = $this->get($this->linTok, ['arch' => 'amd64', 'version' => '2.0.0', 'os' => 'linux']);
        $this->assertSame([503, 'unavailable'], [$c, $j['code']]);
        $this->assertNull($r->file, 'no bytes are streamed');
        $this->assertStringContainsString('wrong platform', (string) $j['error']);
        $this->assertSame(0, (int) $this->h->one("SELECT active FROM endpoint_agent_binaries WHERE binary_id={$l['binary_id']}"));
        $this->assertSame(0, (int) $this->h->one("SELECT active FROM endpoint_agent_releases WHERE binary_id={$l['binary_id']}"));
        $this->assertContains('Agent Binary Invalid', array_column($this->h->audit->records(), 'action'));
        $this->assertNull($this->h->module->updates()->manifestFor($this->dev($this->lin)), 'and it is no longer offered');
        $this->assertFalse($this->h->module->binaryStore()->activate($l['binary_id']), 'it cannot be switched back on');
        $this->assertFalse($this->h->module->readModel()->binaries()[0]['active']);
    }

    public function testPublishChecksTheFileAgainstTheDeclaredPlatform(): void
    {
        $dir = sys_get_temp_dir() . '/osp_' . bin2hex(random_bytes(4));
        mkdir($dir);
        $f = static function (string $name, string $bytes) use ($dir): string {
            file_put_contents("$dir/$name", $bytes);

            return "$dir/$name";
        };
        $store = $this->h->module->binaryStore();
        $elf = $f('a.elf', RmmHarness::fakeElf(62, 4096, 'v 3.0.0'));
        $pe = $f('a.exe', RmmHarness::fakePe(0x8664, 4096, 'x'));
        $r = $store->publish($elf, '3.0.0', 'amd64', 1, ['os' => 'linux', 'release_ring' => 'stable', 'rollout_pct' => 50]);
        $this->assertTrue($r['ok'], json_encode($r));
        $this->assertSame('linux', $r['binary']['os']);
        $this->assertSame(['linux', 50], [$this->h->one('SELECT os FROM endpoint_agent_releases WHERE binary_id=' . $r['binary']['binary_id']), (int) $this->h->one('SELECT rollout_pct FROM endpoint_agent_releases WHERE binary_id=' . $r['binary']['binary_id'])]);
        $this->assertStringContainsString('&os=linux', (string) $this->h->one('SELECT url FROM endpoint_agent_releases WHERE binary_id=' . $r['binary']['binary_id']));
        $again = $store->publish($elf, '3.0.0', 'amd64', 1, ['os' => 'linux']);
        $this->assertTrue($again['ok'] && !$again['created'], 'the identical file again is a no-op');
        // the same version for the other OS is a different binary
        $this->assertTrue($store->publish($pe, '3.0.0', 'amd64', 1)['ok']);
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE version='3.0.0'"));
        // wrong magic for the declared platform, both ways, with a clear message
        $e = $store->publish($pe, '3.1.0', 'amd64', 1, ['os' => 'linux']);
        $this->assertFalse($e['ok']);
        $this->assertStringContainsString('Windows (PE) executable, but it was published as a Linux agent', $e['error']);
        $e = $store->publish($elf, '3.1.0', 'amd64', 1);
        $this->assertStringContainsString('Linux (ELF) executable, but it was published as a Windows agent', $e['error']);
        $this->assertStringContainsString('platform must be', $store->publish($elf, '3.1.0', 'amd64', 1, ['os' => 'darwin'])['error']);
        // ELF specifics: machine, type, size, version string, byte order
        $this->assertStringContainsString('built for amd64, not arm64', $store->publish($elf, '3.0.0', 'arm64', 1, ['os' => 'linux'])['error']);
        $this->assertStringContainsString('does not contain the version string', $store->publish($f('b.elf', RmmHarness::fakeElf(62, 4096, 'other')), '3.2.0', 'amd64', 1, ['os' => 'linux'])['error']);
        $this->assertStringContainsString('not an executable', $store->publish($f('c.elf', RmmHarness::fakeElf(62, 4096, '3.3.0', 1)), '3.3.0', 'amd64', 1, ['os' => 'linux'])['error']);
        $this->assertTrue($store->publish($f('d.elf', RmmHarness::fakeElf(183, 4096, '3.4.0', 3)), '3.4.0', 'arm64', 1, ['os' => 'linux'])['ok'], 'a PIE executable (ET_DYN) and aarch64 are accepted');
        $be = RmmHarness::fakeElf(62, 4096, '3.5.0');
        $be[5] = chr(2);
        $this->assertStringContainsString('64-bit little-endian', $store->publish($f('e.elf', $be), '3.5.0', 'amd64', 1, ['os' => 'linux'])['error']);
        $this->assertStringContainsString('too small', $store->publish($f('f.elf', "\x7fELF"), '3.6.0', 'amd64', 1, ['os' => 'linux'])['error']);
        $this->assertStringContainsString('not a Linux executable', $store->publish($f('g.elf', str_repeat('A', 4096)), '3.6.0', 'amd64', 1, ['os' => 'linux'])['error']);
        // a different file under the same (version, arch, os) is refused
        $this->assertStringContainsString('different contents', $store->publish($f('h.elf', RmmHarness::fakeElf(62, 5000, '3.0.0')), '3.0.0', 'amd64', 1, ['os' => 'linux'])['error']);
        // the admin layer passes the platform through, and the stored file is outside any web root with a generated name
        $res = $this->h->module->admin()->uploadBinary($this->h->principal(), $f('i.elf', RmmHarness::fakeElf(62, 4096, '3.7.0')), '3.7.0', 'amd64', ['os' => 'linux']);
        $this->assertTrue($res->ok, json_encode($res));
        $this->assertMatchesRegularExpression('/^bin_[0-9a-f]{32}\.bin$/', (string) $this->h->one("SELECT storage_name FROM endpoint_agent_binaries WHERE version='3.7.0'"));
        $b = (int) $this->h->one("SELECT binary_id FROM endpoint_agent_binaries WHERE version='3.7.0'");
        $this->assertStringContainsString('cannot be offered as a windows', (string) $store->publishRelease($b, 'stable', 10, '', 1, 'windows'));
        $this->assertNull($store->publishRelease($b, 'stable', 10, '', 1, 'linux'));
        $this->assertTrue($this->h->module->admin()->binaryAction($this->h->principal(), $b, 'offer_update', 'pilot', 5)->ok);
        $rel = array_values(array_filter($this->h->module->readModel()->releases(), static fn (array $x): bool => $x['version'] === '3.7.0'));
        $this->assertSame('linux', $rel[0]['os']);
        foreach (glob("$dir/*") ?: [] as $x) {
            unlink($x);
        }
        rmdir($dir);
    }

    public function testInspectorStandalone(): void
    {
        $dir = sys_get_temp_dir() . '/osi_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/x", RmmHarness::fakeElf(62, 2048, '1.2.3'));
        $this->assertIsArray(BinaryInspector::inspect("$dir/x", 'amd64', 1 << 20, 'linux', '1.2.3'));
        $this->assertIsString(BinaryInspector::inspect("$dir/x", 'amd64', 1 << 20, 'windows'));
        $this->assertIsString(BinaryInspector::inspect("$dir/x", 'amd64', 1000, 'linux'), 'size cap');
        $this->assertTrue(BinaryInspector::containsString("$dir/x", '1.2.3'));
        $this->assertFalse(BinaryInspector::containsString("$dir/x", '1.2.4'));
        unlink("$dir/x");
        rmdir($dir);
    }

    public function testAWrongPlatformFailureDoesNotSkipListTheVersionButARealOneDoes(): void
    {
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable', 100, '0.0.0', 'linux');
        $u = $this->h->module->updates();
        $this->h->checkin($this->linTok, ['update_result' => ['version' => '2.0.0', 'state' => 'failed', 'detail' => 'downloaded file is not a valid ELF executable']]);
        $st = json_decode((string) $this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->lin}"), true);
        $this->assertSame([], $st['failed_versions']);
        $this->assertSame('failed', $st['last']['state']);
        $this->assertSame('2.0.0', $u->manifestFor($this->dev($this->lin))['version'], 'still offered');
        $this->h->checkin($this->linTok, ['update_result' => ['version' => '2.0.0', 'state' => 'rolled_back', 'detail' => 'health check failed']]);
        $this->assertNull($u->manifestFor($this->dev($this->lin)), 'a real failure still skip-lists');
        // clearUpdateFailures: one version of one device
        $this->assertSame(1, $u->clearUpdateFailures($this->lin, '2.0.0'));
        $this->assertSame(0, $u->clearUpdateFailures($this->lin, '2.0.0'));
        $this->assertSame('2.0.0', $u->manifestFor($this->dev($this->lin))['version']);
        // publishing the release again (a fixed binary) clears the skip-list of that version on devices of that OS only
        $this->h->checkin($this->linTok, ['update_result' => ['version' => '2.0.0', 'state' => 'failed', 'detail' => 'crash']]);
        $this->h->checkin($this->winTok, ['update_result' => ['version' => '2.0.0', 'state' => 'failed', 'detail' => 'crash']]);
        $b = (int) $this->h->one("SELECT binary_id FROM endpoint_agent_binaries WHERE os='linux'");
        $this->assertNull($this->h->module->binaryStore()->publishRelease($b, 'stable', 100, '', 1));
        $this->assertSame('2.0.0', $u->manifestFor($this->dev($this->lin))['version']);
        $win = json_decode((string) $this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->win}"), true);
        $this->assertSame(['2.0.0'], $win['failed_versions']);
        // everything, for all devices
        $this->assertSame(2, $u->clearUpdateFailures(), "every device that has an update state");
        $this->assertNull($this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->win}"));
        // admin wrapper validates input
        $this->assertFalse($this->h->module->admin()->clearUpdateFailures($this->h->principal(), null, 'bad')->ok);
        $this->assertFalse($this->h->module->admin()->clearUpdateFailures($this->h->principal(), null, '1.0.0', 'beos')->ok);
        $this->assertTrue($this->h->module->admin()->clearUpdateFailures($this->h->principal(), null, '1.0.0', 'linux')->ok);
        $this->assertTrue(\RivetCore\Rmm\Update\UpdateService::isWrongPlatform('not a valid ELF executable'));
        $this->assertFalse(\RivetCore\Rmm\Update\UpdateService::isWrongPlatform('health check failed'));
    }

    public function testMigration0037IsIdempotentAndCodeToleratesItNotHavingRun(): void
    {
        $db = new MysqliDatabase($this->h->mysqli);
        $m = new Migration0037AgentOs();
        $m->up($db);
        $m->up($db);
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='os' AND TABLE_NAME IN ('endpoint_agent_binaries','endpoint_agent_releases')"));
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('endpoint_agent_binaries','endpoint_agent_releases') AND INDEX_NAME IN ('uniq_version_arch','uniq_version_ring_arch')"));
        $this->assertSame('windows', trim((string) $this->h->one("SELECT COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='endpoint_agent_binaries' AND COLUMN_NAME='os'"), "'"));
        // an installation that has not run it yet: the old columns and keys
        $this->h->q('DELETE FROM endpoint_agent_binaries');
        $this->h->q('DELETE FROM endpoint_agent_releases');
        $this->h->q('ALTER TABLE endpoint_agent_binaries DROP INDEX uniq_version_arch_os, DROP COLUMN os, ADD UNIQUE KEY uniq_version_arch (version, arch)');
        $this->h->q('ALTER TABLE endpoint_agent_releases DROP INDEX uniq_version_ring_arch_os, DROP COLUMN os, ADD UNIQUE KEY uniq_version_ring_arch (version, ring, arch)');
        AgentOs::forget();
        $dir = sys_get_temp_dir() . '/osm_' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/w.exe", RmmHarness::fakePe(0x8664, 4096, 'x'));
        file_put_contents("$dir/l.elf", RmmHarness::fakeElf(62, 4096, '2.0.0'));
        try {
            $store = $this->h->module->binaryStore();
            $this->assertFalse(AgentOs::supported($this->sqlOf()));
            $this->assertTrue($store->publish("$dir/w.exe", '2.0.0', 'amd64', 1, ['activate' => true, 'release_ring' => 'stable', 'rollout_pct' => 100])['ok'], 'Windows publishing works without the column');
            $this->assertStringContainsString('migration 0037', $store->publish("$dir/l.elf", '2.0.0', 'amd64', 1, ['os' => 'linux'])['error']);
            $this->assertNotNull($this->h->module->updates()->currentBinary('amd64'));
            $this->assertNull($this->h->module->updates()->currentBinary('amd64', 'linux'));
            $this->assertSame('2.0.0', $this->h->module->updates()->manifestFor(array_merge($this->dev($this->win), ['agent_version' => '1.0.0']))['version']);
            $this->assertNull($this->h->module->updates()->manifestFor($this->dev($this->lin)), 'a Linux device is not offered the Windows build even before the migration');
            $this->assertSame('windows', $this->h->module->readModel()->releases()[0]['os']);
            [$c] = $this->get($this->winTok, ['arch' => 'amd64', 'version' => '2.0.0']);
            $this->assertSame(200, $c);
            $this->assertStringContainsString('migration 0037', (string) $this->h->module->updates()->addRelease('9.0.0', 'https://rmm.example.test/a', str_repeat('a', 64), '0.0.0', 'stable', 1, '', 1, 'linux'));
        } finally {
            $m->up($db);
            AgentOs::forget();
            foreach (glob("$dir/*") ?: [] as $x) {
                unlink($x);
            }
            rmdir($dir);
        }
        $this->assertTrue(AgentOs::supported($this->sqlOf()));
    }

    private function sqlOf(): \RivetCore\Rmm\Support\Sql
    {
        $r = new \ReflectionMethod($this->h->module, 'sql');

        return $r->invoke($this->h->module);
    }
}
