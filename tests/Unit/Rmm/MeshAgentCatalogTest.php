<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\Mesh\MeshAgentCatalog as C;

final class MeshAgentCatalogTest extends TestCase
{
    private const HEX96 = '4a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f9';

    public function testGroupIdAcceptsEvenHexOfTheRightLengthAndLowerCasesIt(): void
    {
        $this->assertSame(self::HEX96, C::normalizeGroupId(strtoupper(self::HEX96)));
        $this->assertSame(self::HEX96, C::normalizeGroupId("  " . self::HEX96 . "\n"));
        $this->assertSame(str_repeat('ab', 16), C::normalizeGroupId(str_repeat('AB', 16)), '32 is the shortest');
        $this->assertSame(str_repeat('ab', 64), C::normalizeGroupId(str_repeat('ab', 64)), '128 is the longest');
        foreach (['', ' ', str_repeat('a', 31), str_repeat('ab', 15), str_repeat('ab', 65), str_repeat('a', 33), str_repeat('zz', 20), self::HEX96 . 'g', 'mesh//short', 'node//' . str_repeat('A', 64), self::HEX96 . '&x=1', "ab\0" . self::HEX96] as $bad) {
            $this->assertNull(C::normalizeGroupId($bad), json_encode($bad));
        }
    }

    public function testGroupIdConvertsMeshCentralsOwnForm(): void
    {
        // 48 bytes 0x00..0x2f: base64 uses '+' and '/' in places; MeshCentral writes '@' and '$'.
        $raw = implode('', array_map('chr', range(0, 47)));
        $b64 = base64_encode($raw);
        $this->assertSame(64, strlen($b64));
        $mc = 'mesh//' . strtr($b64, ['+' => '@', '/' => '$']);
        $this->assertSame(bin2hex($raw), C::normalizeGroupId($mc));
        $withSpecial = base64_encode("\xfb\xff\xbf" . str_repeat("\x01", 45));
        $this->assertStringContainsString('+', $withSpecial);
        $this->assertSame(bin2hex("\xfb\xff\xbf" . str_repeat("\x01", 45)), C::normalizeGroupId('mesh//' . strtr($withSpecial, ['+' => '@', '/' => '$'])));
        $this->assertNull(C::normalizeGroupId('mesh//' . substr($mc, 6, 60)), 'wrong length');
        $this->assertNull(C::normalizeGroupId('mesh//' . str_repeat('!', 64)));
    }

    public function testPin(): void
    {
        $p384 = hash('sha384', 'x');
        $p256 = hash('sha256', 'x');
        $this->assertSame($p384, C::normalizePin(strtoupper($p384)));
        $this->assertSame($p256, C::normalizePin(implode(':', str_split(strtoupper($p256), 2))));
        $this->assertSame('', C::normalizePin('  '), 'empty is valid: the system trust store is used');
        foreach ([substr($p384, 0, 95), $p384 . '0', str_repeat('g', 96), substr($p256, 0, 63), hash('sha1', 'x')] as $bad) {
            $this->assertNull(C::normalizePin($bad), $bad);
        }
    }

    public function testAgentIdTable(): void
    {
        $this->assertSame(['linux/amd64' => 7, 'windows/arm64' => 40], C::cleanAgentIds(['windows/arm64' => 40, 'linux/amd64' => 7]));
        $this->assertSame([], C::cleanAgentIds([]));
        foreach ([[1, 2], 'x', ['macos/arm64' => 5], ['windows/amd64' => 0], ['windows/amd64' => 256], ['windows/amd64' => '4'], ['windows/amd64' => 4.0], ['windows' => 4], ['linux/x y' => 4],
            array_combine(array_map(fn ($i) => "linux/a$i" . 'x', range(10, 26)), range(1, 17))] as $bad) {
            $this->assertNull(C::cleanAgentIds($bad), json_encode($bad));
        }
        $merged = C::mergedAgentIds(['linux/amd64' => 99, 'linux/riscv64' => 77]);
        $this->assertSame(99, $merged['linux/amd64']);
        $this->assertSame(77, $merged['linux/riscv64']);
        $this->assertSame(4, $merged['windows/amd64']);
        $keys = array_keys($merged);
        $sorted = $keys;
        sort($sorted);
        $this->assertSame($sorted, $keys, 'sorted for a stable payload');
    }

    public function testInstallArgs(): void
    {
        $this->assertSame(['linux' => ['-install', '--installPath=/opt/mesh'], 'windows' => ['-fullinstall']], C::cleanInstallArgs(['windows' => ['-fullinstall'], 'linux' => ['-install', '--installPath=/opt/mesh']]));
        $this->assertSame([], C::cleanInstallArgs([]));
        foreach ([['macos' => ['-x']], ['linux' => '-install'], ['linux' => ['-install; rm -rf /']], ['linux' => ['$(id)']], ['linux' => ['a b']], ['linux' => [['x']]],
            ['linux' => array_fill(0, 9, '-x')], ['linux' => [str_repeat('a', 101)]], ['linux' => ["-x\n"]], ['linux' => ['']], ['linux' => ['--']], [['-x']]] as $bad) {
            $this->assertNull(C::cleanInstallArgs($bad), json_encode($bad));
        }
        $m = C::mergedInstallArgs(['linux' => ['-install', '--installPath=/opt/mesh']]);
        $this->assertSame(['-fullinstall'], $m['windows']);
        $this->assertSame(['-install', '--installPath=/opt/mesh'], $m['linux']);
        $this->assertSame(C::DEFAULT_INSTALL_ARGS, C::mergedInstallArgs(['linux' => []]), 'an empty correction falls back to the default');
    }

    public function testDownloadUrl(): void
    {
        $this->assertSame('https://mesh.example.com/meshagents?id=6&meshid=' . self::HEX96 . '&installflags=0', C::downloadUrl('https://mesh.example.com/', 6, self::HEX96));
        $this->assertSame('https://m/x/meshagents?id=4&meshid=ab&installflags=2', C::downloadUrl('https://m/x', 4, 'ab', 2));
    }

    public function testTheBuiltInTablesEqualTheVectorFileTheGoAgentIsTestedAgainst(): void
    {
        $v = RmmVectors::load('agent_installer_mesh_vectors.json');
        $this->assertSame($v['default_agent_ids'], C::DEFAULT_AGENT_IDS);
        $this->assertSame($v['default_install_args'], C::DEFAULT_INSTALL_ARGS);
    }

    public function testMeshVectorsAreTheBytesTheServerBuilds(): void
    {
        $v = RmmVectors::load('agent_installer_mesh_vectors.json');
        $names = array_column($v['vectors'], 'name');
        $this->assertContains('mesh_pinned_sha384', $names);
        foreach ($v['vectors'] as $x) {
            $exe = (string) hex2bin($x['exe_hex']);
            $this->assertSame($x['stamped_hex'], bin2hex(InstallerStamp::stamp($exe, $x['payload'])), $x['name']);
            $r = InstallerStamp::read((string) hex2bin($x['stamped_hex']));
            $this->assertNotNull($r, $x['name']);
            $this->assertSame(1, $r['data']['version'], $x['name'] . ': the payload version stays 1');
            $this->assertLessThanOrEqual(InstallerStamp::MAX_PAYLOAD, strlen($x['payload']));
        }
    }

    public function testBuildPayloadAppendsTheMeshMembersOnlyWhenAskedFor(): void
    {
        $f = ['installer_id' => 'u', 'server_url' => 'https://h/x', 'enrollment_token' => 't', 'department' => 'd', 'ca_pem' => null, 'created_at' => 'c', 'expires_at' => 'e'];
        $plain = InstallerStamp::buildPayload($f);
        $this->assertSame($plain, InstallerStamp::buildPayload($f + ['mesh' => ['url' => 'https://m']]), 'a Mesh block without the switch is ignored');
        $this->assertSame($plain, InstallerStamp::buildPayload($f + ['install_mesh_agent' => false, 'mesh' => ['url' => 'https://m']]));
        $this->assertSame($plain, InstallerStamp::buildPayload($f + ['install_mesh_agent' => true]), 'the switch without a block is ignored');
        $with = InstallerStamp::buildPayload($f + ['install_mesh_agent' => true, 'mesh' => ['url' => 'https://m', 'meshid' => 'ab']]);
        $this->assertStringStartsWith(substr($plain, 0, -1), $with, 'the old members come first, byte for byte');
        $d = json_decode($with, true);
        $this->assertTrue($d['install_mesh_agent']);
        $this->assertSame(['url' => 'https://m', 'meshid' => 'ab'], $d['mesh']);
        $this->assertSame(1, $d['version']);
    }

    public function testThePayloadWithAMeshBlockStillFitsAndALongCaIsRefusedNotTruncated(): void
    {
        $f = ['installer_id' => 'u', 'server_url' => 'https://h/x', 'enrollment_token' => 't', 'department' => 'd', 'ca_pem' => str_repeat('A', 12000), 'created_at' => 'c', 'expires_at' => 'e',
            'install_mesh_agent' => true, 'mesh' => ['url' => 'https://mesh.example.com', 'meshid' => self::HEX96, 'pin' => hash('sha384', 'x'), 'agent_ids' => C::DEFAULT_AGENT_IDS, 'install_args' => C::DEFAULT_INSTALL_ARGS]];
        $this->assertLessThan(InstallerStamp::MAX_PAYLOAD, strlen(InstallerStamp::buildPayload($f)));
        $f['ca_pem'] = str_repeat('A', 16000);
        $this->expectException(\LengthException::class);
        InstallerStamp::buildPayload($f);
    }
}
