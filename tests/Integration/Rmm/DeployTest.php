<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Authz\RmmPrincipal;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Rmm\Installer\InstallerStamp;
use RivetCore\Rmm\RmmProtocol;
use RivetCore\Tests\Support\AllowUsersPolicy;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Port of the non-HTTP parts of RivetIT tests/endpoint_agent_deploy_unit.php and endpoint_agent_deploy_http.php: PE validation and
 * the upload rules of BinaryStore (through RmmAdmin::uploadBinary), release rows, current-binary switching, the per-client installer
 * (stamped bytes, payload, token row, audit, hostile names, CA, refusals), the deployment commands and the streaming checks.
 * The HTTP layer of the original (forged sessions, CSRF, multipart upload, php -S) is the edition's.
 */
final class DeployTest extends RmmTestCase
{
    private RmmPrincipal $admin;
    private RmmPrincipal $tech;
    private string $tmp = '';

    protected function makeHarness(): RmmHarness
    {
        return new RmmHarness(policy: new AllowUsersPolicy([1 => true, 10 => [\RivetCore\Rmm\Authz\RmmAbility::DEVICE_VIEW, \RivetCore\Rmm\Authz\RmmAbility::JOB_RUN_SAVED]]));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new RmmPrincipal(1, 'Admin');
        $this->tech = new RmmPrincipal(10, 'Tech');
        $this->tmp = sys_get_temp_dir() . '/rmm_deploy_' . bin2hex(random_bytes(4));
        mkdir($this->tmp, 0700);
        $this->h->enable();
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.com']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    private function put(string $name, string $bytes): string
    {
        file_put_contents($this->tmp . '/' . $name, $bytes);

        return $this->tmp . '/' . $name;
    }

    private function pe(int $machine, int $size = 8192, string $fill = 'a'): string
    {
        return RmmHarness::fakePe($machine, $size, $fill);
    }

    private function bins(): int
    {
        return (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_binaries');
    }

    private function upload(string $bytes, string $version = '1.0.0', string $arch = 'amd64', array $opts = [], ?RmmPrincipal $who = null): \RivetCore\Rmm\Technician\ActionResult
    {
        return $this->h->module->admin()->uploadBinary($who ?? $this->admin, $this->put('up.exe', $bytes), $version, $arch, $opts);
    }

    // ------------------------------------------------------------------ PE validation (the unit test)

    public function testPeValidation(): void
    {
        $bs = $this->h->module->binaryStore();
        $good = $bs->inspect($this->put('amd64.exe', $this->pe(0x8664, 4096)), 'amd64');
        $this->assertIsArray($good);
        $this->assertSame(4096, $good['size']);
        $this->assertSame(hash('sha256', $this->pe(0x8664, 4096)), $good['sha256']);
        $this->assertIsArray($bs->inspect($this->put('arm64.exe', $this->pe(0xAA64, 4096)), 'arm64'));
        $e = $bs->inspect($this->put('wrong.exe', $this->pe(0xAA64, 4096)), 'amd64');
        $this->assertIsString($e);
        $this->assertStringContainsString('0xAA64', $e);
        $this->assertIsString($bs->inspect($this->put('wrong2.exe', $this->pe(0x8664, 4096)), 'arm64'));
        $this->assertIsString($bs->inspect($this->put('i386.exe', $this->pe(0x014C, 4096)), 'amd64'), 'x86 refused');
        $this->assertIsString($bs->inspect($this->put('text.exe', str_repeat('hello world ', 400)), 'amd64'));
        $this->assertIsString($bs->inspect($this->put('tiny.exe', 'MZ'), 'amd64'));
        $big = $this->pe(0x8664, 5000);
        $this->assertIsString($bs->inspect($this->put('badoff.exe', substr_replace($big, pack('V', 0xFFFFFFF0), 0x3C, 4)), 'amd64'), 'huge e_lfanew refused');
        $this->assertIsString($bs->inspect($this->put('badoff2.exe', substr_replace($big, pack('V', 9000), 0x3C, 4)), 'amd64'), 'offset past the end refused');
        $this->assertIsString($bs->inspect($this->put('nopesig.exe', substr_replace($big, 'XX', 128, 2)), 'amd64'), 'missing PE signature refused');
        $dll = RmmHarness::fakePe(0x8664, 4096, 'a');
        $dll = substr_replace($dll, pack('v', 0x2022), 128 + 22, 2);
        $this->assertSame('This is a DLL, not an executable.', $bs->inspect($this->put('dll.exe', $dll), 'amd64'));
        $this->assertIsString($bs->inspect($this->put('a.exe', $this->pe(0x8664)), 'x86'), 'unknown architecture refused');
        $this->assertSame('The file could not be read.', $bs->inspect($this->tmp . '/missing.nope', 'amd64'));
        $stamped = InstallerStamp::stamp($this->pe(0x8664), '{"version":1}');
        $e = $bs->inspect($this->put('stamped.exe', $stamped), 'amd64');
        $this->assertIsString($e);
        $this->assertStringContainsString('RIVETIT-EMBED', $e, 'a pre-stamped file is refused');
        $this->assertIsArray($bs->inspect($this->put('magicbody.exe', $this->pe(0x8664) . 'xx' . InstallerStamp::MAGIC . 'yy'), 'amd64'), 'the magic string inside the file is not a footer');
        $e = $bs->inspect($this->put('big.exe', $this->pe(0x8664, 5000)), 'amd64', 4999);
        $this->assertIsString($e);
        $this->assertStringContainsString('larger', $e);
        $this->assertIsArray($bs->inspect($this->put('exact.exe', $this->pe(0x8664, 5000)), 'amd64', 5000), 'exactly at the cap is accepted');
    }

    public function testDetectIdentifiesPeAndElfByTheirHeaders(): void
    {
        $bs = $this->h->module->binaryStore();
        $pe = $bs->detect($this->put('a.exe', $this->pe(0xAA64, 4096)));
        $this->assertIsArray($pe);
        $this->assertSame(['pe', 0xAA64, 'arm64', false], [$pe['format'], $pe['machine'], $pe['arch'], $pe['dll']]);
        $elf = static fn (int $machine, int $class = 2, int $data = 1): string => "\x7fELF" . chr($class) . chr($data) . "\x01" . str_repeat("\0", 9) . pack('v', 2) . pack('v', $machine) . str_repeat("\0", 64);
        $x = $bs->detect($this->put('lin-amd64', $elf(62)));
        $this->assertIsArray($x);
        $this->assertSame(['elf', 62, 'amd64'], [$x['format'], $x['machine'], $x['arch']]);
        $x = $bs->detect($this->put('lin-arm64', $elf(183)));
        $this->assertIsArray($x);
        $this->assertSame('arm64', $x['arch']);
        $x = $bs->detect($this->put('lin-riscv', $elf(243)));
        $this->assertIsArray($x);
        $this->assertNull($x['arch'], 'a machine type the agent is not built for');
        $this->assertIsString($bs->detect($this->put('lin32', $elf(62, 1))), '32-bit ELF');
        $this->assertIsString($bs->detect($this->put('linbe', $elf(62, 2, 2))), 'big-endian ELF');
        $this->assertIsString($bs->detect($this->put('nothing', str_repeat('x', 100))));
        $e = $bs->inspect($this->put('linux-agent', $elf(62) . str_repeat("\0", 2000)), 'amd64');
        $this->assertIsString($e, 'a Linux binary is not hosted through the Windows path');
        $this->assertStringContainsString('install-linux.sh', $e);
        $this->assertSame(0, $this->bins());
    }

    // ------------------------------------------------------------------ upload and publishing

    public function testUploadRefusals(): void
    {
        $r = $this->upload($this->pe(0xAA64, 5000));
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('0xAA64', $r->message);
        $this->assertSame(0, $this->bins());
        $this->assertStringContainsString('not a Windows executable', $this->upload(str_repeat('not an exe ', 500))->message);
        $this->assertStringContainsString('RIVETIT-EMBED', $this->upload(InstallerStamp::stamp($this->pe(0x8664, 300000, 'amd'), '{"version":1}'))->message);
        foreach (['v1.0', '1.0.0/../x', '', '1.0.0 x'] as $bad) {
            $this->assertFalse($this->upload($this->pe(0x8664), $bad)->ok, json_encode($bad));
        }
        $this->assertFalse($this->upload($this->pe(0x8664), '1.0.0', 'riscv')->ok);
        $this->assertSame(0, $this->bins());
    }

    public function testOversizeUploadIsRefused(): void
    {
        $h = new RmmHarness(options: ['max_upload_bytes' => 4096], policy: new AllowUsersPolicy([1 => true]));
        $this->assertSame(4096, $h->module->binaryStore()->maxBytes());
        $r = $h->module->admin()->uploadBinary($this->admin, $this->put('big.exe', $this->pe(0x8664, 4097)), '1.0.0', 'amd64');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('larger than the 4 KiB limit', $r->message);
        $this->assertSame(0, (int) $h->one('SELECT COUNT(*) FROM endpoint_agent_binaries'));
        $this->assertTrue($h->module->admin()->uploadBinary($this->admin, $this->put('ok.exe', $this->pe(0x8664, 4096)), '1.0.0', 'amd64')->ok, 'exactly at the cap');
        $floor = new RmmHarness(options: ['max_upload_bytes' => 10], policy: new AllowUsersPolicy([1 => true]));
        $this->assertSame(1024, $floor->module->binaryStore()->maxBytes(), 'the cap never goes below 1024');
        $this->assertLessThanOrEqual($this->h->module->binaryStore()->maxBytes(), $this->h->module->binaryStore()->effectiveUploadLimit());
    }

    public function testGoodUploadStoresARowAndARandomlyNamedFile(): void
    {
        $exe = $this->pe(0x8664, 300000, 'amd');
        $r = $this->upload($exe, '1.0.0', 'amd64', ['activate' => true]);
        $this->assertTrue($r->ok, $r->message);
        $row = $this->h->rows('SELECT * FROM endpoint_agent_binaries')[0];
        $this->assertSame(['amd64', '1.0.0', hash('sha256', $exe), strlen($exe), 1, 1], [$row['arch'], $row['version'], $row['sha256'], (int) $row['size_bytes'], (int) $row['is_current'], (int) $row['active']]);
        $this->assertMatchesRegularExpression('/^bin_[0-9a-f]{32}\.bin$/', $row['storage_name']);
        $this->assertSame($exe, file_get_contents($this->h->binaryDir . '/' . $row['storage_name']));
        $this->assertFileDoesNotExist($this->h->binaryDir . '/up.exe');
        $this->assertStringContainsString('denied', (string) file_get_contents($this->h->binaryDir . '/.htaccess'));
        $this->assertFileExists($this->h->binaryDir . '/index.html');
        $this->assertStringContainsString(hash('sha256', $exe), $r->message, 'the stored SHA-256 is shown to the administrator');
        $audit = array_values(array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Binary Uploaded'));
        $this->assertCount(1, $audit);
        $this->assertStringContainsString('Admin uploaded agent binary 1.0.0', $audit[0]['description']);
        $this->assertStringContainsString('made it current', $audit[0]['description']);
    }

    public function testPublishingIsIdempotentAndAReleasedVersionNeverChanges(): void
    {
        $exe = $this->pe(0x8664, 300000, 'amd');
        $this->assertTrue($this->upload($exe, '1.0.0', 'amd64', ['activate' => true])->ok);
        $again = $this->upload($exe, '1.0.0', 'amd64', ['activate' => true]);
        $this->assertTrue($again->ok);
        $this->assertFalse($again->data['created']);
        $this->assertSame(1, $this->bins());
        $this->assertCount(1, glob($this->h->binaryDir . '/bin_*.bin') ?: []);
        $diff = $this->upload($this->pe(0x8664, 9000, 'other'), '1.0.0');
        $this->assertFalse($diff->ok);
        $this->assertStringContainsString('different contents', $diff->message);
        $this->assertSame(1, $this->bins());
        // a lost file is restored from an identical re-upload
        $name = (string) $this->h->one('SELECT storage_name FROM endpoint_agent_binaries');
        unlink($this->h->binaryDir . '/' . $name);
        $this->assertTrue($this->upload($exe, '1.0.0')->ok);
        $this->assertSame($exe, file_get_contents($this->h->binaryDir . '/' . $name));
    }

    public function testCurrentBinaryPerArchitectureAndDeactivateReactivate(): void
    {
        $this->assertTrue($this->upload($this->pe(0x8664, 300000, 'amd'), '1.0.0', 'amd64', ['activate' => true])->ok);
        $this->assertTrue($this->upload($this->pe(0xAA64, 200000, 'arm'), '1.0.0', 'arm64', ['activate' => true])->ok);
        $this->assertSame([1, 1], [(int) $this->h->one("SELECT is_current FROM endpoint_agent_binaries WHERE arch='amd64'"), (int) $this->h->one("SELECT is_current FROM endpoint_agent_binaries WHERE arch='arm64'")]);
        $this->assertTrue($this->upload($this->pe(0x8664, 250000, 'v11'), '1.1.0', 'amd64', ['activate' => true])->ok);
        $this->assertSame('1.1.0', $this->h->one("SELECT version FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1"));
        $id100 = (int) $this->h->one("SELECT binary_id FROM endpoint_agent_binaries WHERE arch='amd64' AND version='1.0.0'");
        $a = $this->h->module->admin();
        $this->assertTrue($a->binaryAction($this->admin, $id100, 'make_current')->ok);
        $this->assertSame('1.0.0', $this->h->one("SELECT version FROM endpoint_agent_binaries WHERE arch='amd64' AND is_current=1"));
        $this->assertTrue($a->binaryAction($this->admin, $id100, 'deactivate')->ok);
        $row = $this->h->rows("SELECT * FROM endpoint_agent_binaries WHERE binary_id=$id100")[0];
        $this->assertSame([0, 0], [(int) $row['active'], (int) $row['is_current']]);
        $this->assertFileExists($this->h->binaryDir . '/' . $row['storage_name'], 'deleting only deactivates: the file stays');
        $this->assertFalse($a->binaryAction($this->admin, $id100, 'make_current')->ok, 'an inactive binary cannot become current');
        $this->assertTrue($a->binaryAction($this->admin, $id100, 'activate')->ok);
        $this->assertSame(1, (int) $this->h->one("SELECT active FROM endpoint_agent_binaries WHERE binary_id=$id100"));
        $this->assertSame(404, $a->binaryAction($this->admin, 9999, 'activate')->http);
        $this->assertSame(422, $a->binaryAction($this->admin, $id100, 'format')->http);
    }

    public function testPublishingWithARingCreatesTheHostedReleaseRow(): void
    {
        $exe = $this->pe(0x8664, 7000, 'cli');
        $this->assertTrue($this->upload($exe, '2.0.0')->ok);
        $this->assertSame(0, (int) $this->h->one("SELECT is_current FROM endpoint_agent_binaries WHERE version='2.0.0'"), 'without activate the current binary is untouched');
        $r = $this->upload($exe, '2.0.0', 'amd64', ['activate' => true, 'release_ring' => 'pilot', 'rollout_pct' => 40]);
        $this->assertTrue($r->ok, $r->message);
        $rel = $this->h->rows("SELECT * FROM endpoint_agent_releases WHERE version='2.0.0'")[0];
        $this->assertSame(['pilot', 40, 'amd64', 'https://rmm.example.com/api/v1/agent_update?arch=amd64&version=2.0.0', hash('sha256', $exe)], [$rel['ring'], (int) $rel['rollout_pct'], $rel['arch'], $rel['url'], $rel['sha256']]);
        $this->assertNotNull($rel['binary_id']);
        $this->assertSame(1, (int) $this->h->one("SELECT is_current FROM endpoint_agent_binaries WHERE version='2.0.0'"));
        // offering again refreshes the same row; a bad ring or a missing service URL is refused
        $this->assertTrue($this->h->module->admin()->binaryAction($this->admin, (int) $rel['binary_id'], 'offer_update', 'pilot', 80)->ok);
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM endpoint_agent_releases WHERE version='2.0.0'"));
        $this->assertSame(80, (int) $this->h->one("SELECT rollout_pct FROM endpoint_agent_releases WHERE version='2.0.0'"));
        $this->assertFalse($this->h->module->admin()->binaryAction($this->admin, (int) $rel['binary_id'], 'offer_update', 'beta')->ok);
        $this->h->module->settings()->set(['service_url' => '']);
        $r = $this->h->module->admin()->binaryAction($this->admin, (int) $rel['binary_id'], 'offer_update', 'stable', 10);
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('https service URL', $r->message);
        // deactivating the binary also withdraws its releases
        $this->assertTrue($this->h->module->admin()->binaryAction($this->admin, (int) $rel['binary_id'], 'deactivate')->ok);
        $this->assertSame(0, (int) $this->h->one("SELECT active FROM endpoint_agent_releases WHERE version='2.0.0'"));
    }

    public function testReleaseRowEditsAndExternalReleases(): void
    {
        $a = $this->h->module->admin();
        $this->assertTrue($this->upload($this->pe(0x8664, 7000, 'r'), '2.1.0', 'amd64', ['release_ring' => 'stable', 'rollout_pct' => 10])->ok);
        $rid = (int) $this->h->one('SELECT release_id FROM endpoint_agent_releases');
        $this->assertTrue($a->updateRelease($this->admin, $rid, 250, false)->ok);
        $this->assertSame([100, 0], [(int) $this->h->one("SELECT rollout_pct FROM endpoint_agent_releases WHERE release_id=$rid"), (int) $this->h->one("SELECT active FROM endpoint_agent_releases WHERE release_id=$rid")], 'the percentage is clamped');
        $this->assertSame(404, $a->updateRelease($this->admin, 99999, 5, true)->http);
        $r = $a->addExternalRelease($this->admin, '3.0.0', 'https://rmm.example.com/pkg.exe', str_repeat('a', 64), '', 'stable', 20, 'n');
        $this->assertTrue($r->ok, $r->message);
        $this->assertFalse($a->addExternalRelease($this->admin, '3.0.1', 'https://other.example.com/pkg.exe', str_repeat('a', 64), '0.0.0', 'stable', 20, '')->ok, 'must be served from this host');
        $this->assertFalse($a->addExternalRelease($this->admin, '3.0.1', 'http://rmm.example.com/pkg.exe', str_repeat('a', 64), '0.0.0', 'stable', 20, '')->ok);
    }

    public function testAdminOperationsAreRefusedToNonAdministratorsWithoutChangingAnything(): void
    {
        $a = $this->h->module->admin();
        $r = $a->uploadBinary($this->tech, $this->put('a.exe', $this->pe(0x8664)), '1.0.0', 'amd64');
        $this->assertSame([false, 403], [$r->ok, $r->http]);
        $this->assertSame(0, $this->bins());
        $this->assertSame(403, $a->saveSettings($this->tech, ['service_url' => 'https://evil.example.com'])->http);
        $this->assertSame('https://rmm.example.com', $this->h->module->settings()->get(true)['service_url']);
        $this->assertSame(403, $a->rotateSigningKey($this->tech)->http);
        $this->assertSame(403, $a->saveMesh($this->tech, ['mesh_url' => 'https://mesh.example.com'])->http);
        $this->assertSame(403, $a->downloadInstaller($this->tech, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64')->http);
        $this->assertSame(403, $a->deploymentCommands($this->tech, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64')->http);
        $this->assertSame(403, $a->binaryAction($this->tech, 1, 'deactivate')->http);
        $this->assertSame(403, $a->updateRelease($this->tech, 1, 5, true)->http);
        $this->assertSame(403, $a->enable($this->tech)->http);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'));
        $this->assertSame(403, $this->h->module->technician()->createToken($this->tech, $this->h->clientA, 0, 'stable', 1, 1, 'x')->http);
    }

    // ------------------------------------------------------------------ the per-client installer

    private function publishCurrent(): array
    {
        $exe = $this->pe(0x8664, 300000, 'amd');
        $exeArm = $this->pe(0xAA64, 200000, 'arm');
        $this->assertTrue($this->upload($exe, '1.1.0', 'amd64', ['activate' => true])->ok);
        $this->assertTrue($this->upload($exeArm, '1.1.0', 'arm64', ['activate' => true])->ok);

        return [$exe, $exeArm];
    }

    /** @return array{0:string,1:array<string,mixed>,2:RmmResponse} body, parsed stamp, response */
    private function download(int $client, string $arch = 'amd64', string $ring = 'pilot', int $ttl = 5, int $uses = 7, string $label = 'Front <b>desk</b>'): array
    {
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $client, 0, $ring, $ttl, $uses, $label, $arch);
        $this->assertTrue($r->ok, $r->message);
        /** @var RmmResponse $resp */
        $resp = $r->data['download'];
        $body = $this->emit($resp);
        $stamp = InstallerStamp::read($body);
        $this->assertNotNull($stamp);

        return [$body, $stamp, $resp];
    }

    private function emit(RmmResponse $resp): string
    {
        $out = '';
        (new SapiEmitter(static function (int $c): void {
        }, static function (string $h): void {
        }, static function (string $b) use (&$out): void {
            $out .= $b;
        }))->emit($resp);

        return $out;
    }

    public function testInstallerIsTheCurrentBinaryPlusAVerifiedTrailer(): void
    {
        [$exe, $exeArm] = $this->publishCurrent();
        [$body, $stamp, $resp] = $this->download($this->h->clientA);
        $this->assertSame('attachment; filename="RivetIT-Agent-Setup-dept-a-x64.exe"', $resp->headers['Content-Disposition']);
        $this->assertSame('application/octet-stream', $resp->headers['Content-Type']);
        $this->assertSame('nosniff', $resp->headers['X-Content-Type-Options']);
        $this->assertStringContainsString('no-store', $resp->headers['Cache-Control']);
        $this->assertSame(strlen($body), $resp->file?->totalLength());
        $this->assertSame($exe, substr($body, 0, $stamp['exe_length']), 'the stamped file is the CURRENT binary byte for byte, plus the trailer');
        $d = $stamp['data'];
        $this->assertSame(1, $d['version']);
        $this->assertSame('Dept A', $d['department']);
        $this->assertSame('https://rmm.example.com', $d['server_url']);
        $this->assertNull($d['ca_pem']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $d['installer_id']);
        $this->assertMatchesRegularExpression('/^rvte1\.[0-9a-f]{12}\.[0-9a-f]{40}$/', $d['enrollment_token']);
        $this->assertEqualsWithDelta(time() + 5 * 3600, strtotime($d['expires_at']), 30);
        $this->assertEqualsWithDelta(time(), strtotime($d['created_at']), 30);
        $this->assertStringEndsWith('Z', $d['expires_at']);
        [, $sel, $sec] = explode('.', $d['enrollment_token']);
        $t = $this->h->rows("SELECT * FROM endpoint_agent_enrollment_tokens WHERE token_selector='$sel'")[0];
        $this->assertTrue(hash_equals($t['token_hash'], hash('sha256', $sec)));
        $this->assertSame([$this->h->clientA, 0, 'pilot', 7, 'Front <b>desk</b>', 1, 0], [(int) $t['client_id'], (int) $t['location_id'], $t['ring'], (int) $t['max_uses'], $t['label'], (int) $t['created_by'], (int) $t['use_count']]);
        $log = array_values(array_filter($this->h->audit->records(), static fn (array $a): bool => $a['action'] === 'Installer Created'));
        $this->assertCount(1, $log);
        foreach (['Admin', $d['installer_id'], 'Dept A', '#' . $t['token_id']] as $needle) {
            $this->assertStringContainsString($needle, $log[0]['description']);
        }
        $this->assertStringNotContainsString($sec, (string) json_encode($this->h->audit->records()), 'the token secret is in no audit record');
        // the minted token really enrolls a device
        [$c, , $j] = $this->h->enroll($d['enrollment_token'], $this->h::device());
        $this->assertSame(201, $c);
        $this->assertArrayHasKey('device_token', $j);
        // defaults and arm64
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 25, '', 'amd64');
        $this->assertTrue($r->ok);
        $this->assertStringStartsWith('Installer ', (string) $this->h->one('SELECT label FROM endpoint_agent_enrollment_tokens ORDER BY token_id DESC LIMIT 1'));
        [$body, $stamp, $resp] = $this->download($this->h->clientB, 'arm64');
        $this->assertSame('attachment; filename="RivetIT-Agent-Setup-dept-b-arm64.exe"', $resp->headers['Content-Disposition']);
        $this->assertSame('Dept B', $stamp['data']['department']);
        $this->assertSame($exeArm, substr($body, 0, $stamp['exe_length']));
    }

    public function testCaCertificateIsEmbeddedWhenConfigured(): void
    {
        $this->publishCurrent();
        $a = $this->h->module->admin();
        $r = $a->saveSettings($this->admin, ['ca_pem' => 'not a cert']);
        $this->assertSame([false, 422], [$r->ok, $r->http]);
        $this->assertNull($this->h->module->settings()->get(true)['ca_pem'], 'an invalid CA is refused');
        $k = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => 'Test Rivet CA'], $k);
        $cert = openssl_csr_sign($csr, null, $k, 30);
        openssl_x509_export($cert, $pem);
        $this->assertTrue($a->saveSettings($this->admin, ['ca_pem' => $pem])->ok);
        $this->assertStringContainsString('BEGIN CERTIFICATE', (string) $this->h->module->settings()->get(true)['ca_pem']);
        [, $stamp] = $this->download($this->h->clientA);
        $this->assertIsString($stamp['data']['ca_pem']);
        $this->assertNotFalse(openssl_x509_read($stamp['data']['ca_pem']));
        $this->assertTrue($a->saveSettings($this->admin, ['ca_pem' => ''])->ok);
        [, $stamp] = $this->download($this->h->clientA);
        $this->assertNull($stamp['data']['ca_pem'], 'clearing the CA setting stops embedding it');
    }

    public function testHostileClientNamesCannotInjectHeadersOrBreakTheFileName(): void
    {
        $this->publishCurrent();
        $evil = $this->h->tenancy->addClient("<img src=x onerror=alert(1)> \"q\"\r\nX-Injected: yes");
        [, $stamp, $resp] = $this->download($evil);
        $this->assertMatchesRegularExpression('/^attachment; filename="RivetIT-Agent-Setup-[a-z0-9-]+-x64\.exe"$/', $resp->headers['Content-Disposition']);
        foreach ($resp->headers as $name => $value) {
            $this->assertDoesNotMatchRegularExpression('/[\r\n]/', $name . $value);
        }
        $this->assertStringContainsString('img src=x', $stamp['data']['department']);
        $this->assertStringNotContainsString("\r", $stamp['data']['department']);
        $this->assertStringNotContainsString("\n", $stamp['data']['department'], 'control characters are flattened (JSON data, not markup)');
    }

    public function testDeploymentCommandsShowTheTokenOnceAndNeverPutItInAUrl(): void
    {
        $this->publishCurrent();
        $before = (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
        $r = $this->h->module->admin()->deploymentCommands($this->admin, $this->h->clientB, 0, 'stable', 2, 3, '', 'amd64');
        $this->assertTrue($r->ok, $r->message);
        $this->assertSame($before + 1, (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens'));
        $tok = (string) $r->data['token_plain'];
        $c = $r->data['commands'];
        $this->assertSame('RivetIT-Agent-Setup-dept-b-x64.exe', $c['filename']);
        $this->assertSame('https://rmm.example.com', $c['server']);
        $this->assertStringContainsString("\$Token  = '$tok'", $c['powershell']);
        $this->assertStringContainsString("printf '%s' '$tok'", $c['linux']);
        $this->assertSame(['RivetITAgent', '%ProgramFiles%\\RivetIT\\Agent\\rivetit-agent.exe'], [$c['detection']['service'], $c['detection']['file']]);
        $row = $r->data['token'];
        $this->assertTrue(hash_equals($row['token_hash'], hash('sha256', explode('.', $tok)[2])));
        $this->assertSame(3, (int) $row['max_uses']);
        $this->assertStringNotContainsString(explode('.', $tok)[2], (string) json_encode($this->h->audit->records()));
        // the read model never shows the secret again
        $listed = json_encode($this->h->module->readModel()->tokens());
        $this->assertStringNotContainsString(explode('.', $tok)[2], (string) $listed);
        $this->assertStringNotContainsString($row['token_hash'], (string) $listed);
    }

    public function testRefusalsCreateNoToken(): void
    {
        $a = $this->h->module->admin();
        $n = fn (): int => (int) $this->h->one('SELECT COUNT(*) FROM endpoint_agent_enrollment_tokens');
        $r = $a->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('No agent binary is published for amd64', $r->message);
        $this->assertSame(0, $n());
        $this->publishCurrent();
        $r = $a->downloadInstaller($this->admin, 9999, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertStringContainsString('Choose the client', $r->message);
        $this->assertSame(0, $n());
        $this->assertStringContainsString('Choose Windows x64', $a->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'riscv')->message);
        $this->h->module->settings()->disable();
        $r = $a->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertStringContainsString('switched off', $r->message);
        $this->assertSame(0, $n());
        $this->h->module->settings()->enable();
        $this->h->module->settings()->set(['service_url' => '']);
        $r = $a->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertStringContainsString('https', $r->message);
        $this->assertSame(0, $n());
        $this->assertNull($a->deploymentCommands($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64')->data['commands'] ?? null);
        $this->assertSame(0, $n());
    }

    public function testAnInstallerThatCannotBeServedRevokesItsToken(): void
    {
        $this->publishCurrent();
        $row = $this->h->rows("SELECT * FROM endpoint_agent_binaries WHERE arch='amd64'")[0];
        file_put_contents($this->h->binaryDir . '/' . $row['storage_name'], str_repeat('x', (int) $row['size_bytes']));   // same size, different bytes
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertFalse($r->ok);
        $this->assertStringContainsString('integrity check', $r->message);
        $t = $this->h->rows('SELECT * FROM endpoint_agent_enrollment_tokens')[0];
        $this->assertNotNull($t['revoked_at'], 'nothing was served: no live token is left behind');
        unlink($this->h->binaryDir . '/' . $row['storage_name']);
        $r = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertStringContainsString('missing or damaged', $r->message);
    }

    // ------------------------------------------------------------------ streaming

    public function testStreamVerifiesSizeAndSha256BeforeTheFirstByte(): void
    {
        $this->publishCurrent();
        $row = $this->h->module->updates()->currentBinary('amd64');
        $this->assertNotNull($row);
        $resp = $this->h->module->binaryStore()->download($row, 'x.exe');
        $this->assertInstanceOf(RmmResponse::class, $resp);
        $file = $resp->file;
        $this->assertNotNull($file);
        // a swapped file of the same size: the emitter refuses before any byte of the file is sent
        file_put_contents($file->path, str_repeat('z', $file->length));
        $sent = [];
        $status = 0;
        (new SapiEmitter(static function (int $c) use (&$status): void {
            $status = $c;
        }, static function (string $h) use (&$sent): void {
            $sent[] = $h;
        }, static function (string $b) use (&$sent): void {
            $sent[] = strlen($b) > 200 ? 'BYTES' : $b;
        }))->emit(new RmmResponse(200, ['Content-Type' => 'application/octet-stream'], null, new \RivetCore\Rmm\Http\RmmFileBody($file->path, $file->length, null, $row['sha256'])));
        $this->assertSame(500, $status);
        $this->assertNotContains('BYTES', $sent);
        $this->assertStringContainsString('internal', implode('', $sent));
        // a truncated file is refused by size
        file_put_contents($file->path, 'short');
        $this->assertIsString($this->h->module->binaryStore()->download($row, 'x.exe'));
    }

    public function testALargeDownloadIsStreamedNotBuffered(): void
    {
        $size = 24 * 1024 * 1024;
        $path = $this->tmp . '/large.bin';
        $fh = fopen($path, 'wb');
        $chunk = str_repeat('0123456789abcdef', 4096);
        for ($i = 0; $i < $size / strlen($chunk); ++$i) {
            fwrite($fh, $chunk);
        }
        fclose($fh);
        $sha = (string) hash_file('sha256', $path);
        $trailer = 'TRAILER';
        $bytes = 0;
        $before = memory_get_peak_usage(true);
        (new SapiEmitter(static function (int $c): void {
        }, static function (string $h): void {
        }, static function (string $b) use (&$bytes): void {
            $bytes += strlen($b);
        }))->emit(new RmmResponse(200, [], null, new \RivetCore\Rmm\Http\RmmFileBody($path, $size, $trailer, $sha)));
        $this->assertSame($size + strlen($trailer), $bytes);
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'memory use does not depend on the file size');
    }

    public function testTheDeviceApiAndTheAdminShareOneDownloadResponseShape(): void
    {
        $this->publishCurrent();
        $this->h->module->settings()->set(['service_url' => 'http://127.0.0.1:1']);
        $tok = $this->h->token();
        $req = new RmmRequest('POST', 'agent_installer', [], [], ['content-type' => 'application/json'], '127.0.0.1', 'x', true, null, $this->stream((string) json_encode(['token' => $tok, 'arch' => 'amd64'])));
        $device = $this->h->api->handle($req);
        $this->assertSame(200, $device->status);
        $admin = $this->h->module->admin()->downloadInstaller($this->admin, $this->h->clientA, 0, 'stable', 5, 7, '', 'amd64');
        $this->assertTrue($admin->ok);
        $this->assertSame(array_keys($device->headers), array_keys($admin->data['download']->headers));
        $this->assertSame($device->headers['Content-Type'], $admin->data['download']->headers['Content-Type']);
    }

    /** @return resource */
    private function stream(string $s)
    {
        $f = fopen('php://memory', 'w+b');
        fwrite($f, $s);
        rewind($f);

        return $f;
    }
}
