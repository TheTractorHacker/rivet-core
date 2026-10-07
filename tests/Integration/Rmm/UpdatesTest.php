<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Tests\Support\RmmTestCase;

/** The update manifest (rings, rollout, min_version, no downgrade, failed versions) and the hosted download read side. */
final class UpdatesTest extends RmmTestCase
{
    private int $dev = 0;
    private string $T = '';
    private string $pub = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tok = $this->h->token(null, 24, 20);
        $this->h->module->settings()->set(['service_url' => 'https://rmm.example.test']);
        [, , $j] = $this->h->enroll($tok, $this->h::device(['serial' => 'UP-1']));
        $this->dev = (int) $j['device_id'];
        $this->T = $j['device_token'];
        $this->pub = $j['signing_public_key'];
    }

    /** @return array<string,mixed>|null */
    private function manifest(): ?array
    {
        return $this->h->module->updates()->manifestFor($this->h->module->devices()->find($this->dev) ?? []);
    }

    public function testNothingIsOfferedWithoutARelease(): void
    {
        [, , $r] = $this->h->checkin($this->T);
        $this->assertNull($r['update']);
        $this->assertNull($this->manifest());
    }

    public function testASignedManifestForTheNewestEligibleRelease(): void
    {
        $b = $this->h->publishBinary('1.1.0', 'amd64', 8192, true, 'stable');
        $this->h->publishBinary('1.0.0', 'amd64', 4096, false, 'stable');   // not newer than the device's 1.0.0
        [, , $r] = $this->h->checkin($this->T);
        $u = $r['update'];
        $this->assertSame(['version', 'url', 'sha256', 'signature', 'min_version'], array_keys($u));
        $this->assertSame(['1.1.0', $b['sha256'], '0.0.0'], [$u['version'], $u['sha256'], $u['min_version']]);
        $this->assertSame('https://rmm.example.test/api/v1/agent_update?arch=amd64&version=1.1.0', $u['url']);
        $this->assertTrue(Signer::verifyManifest($u['sha256'], $u['signature'], $this->pub), 'signed over the lowercase hex SHA-256 text');
        $this->assertFalse(Signer::verifyManifest(strtoupper($u['sha256']), $u['signature'], $this->pub));
    }

    public function testRingsRolloutMinVersionAndArchitecture(): void
    {
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'pilot');
        $this->assertNull($this->manifest(), 'a stable device is not offered a pilot release');
        $this->assertTrue($this->h->module->deviceService()->setRing($this->dev, 'pilot'));
        $this->assertSame('2.0.0', $this->manifest()['version'] ?? null);
        $this->assertFalse($this->h->module->deviceService()->setRing($this->dev, 'beta'));
        $this->assertFalse($this->h->module->deviceService()->setRing(999999, 'pilot'), 'an unknown device is not a success');
        // rollout percentage: deterministic per (device, version), 0 offers nobody, 100 everybody
        $this->h->q("UPDATE endpoint_agent_releases SET rollout_pct=0 WHERE version='2.0.0'");
        $this->assertNull($this->manifest());
        $this->h->q("UPDATE endpoint_agent_releases SET rollout_pct=100 WHERE version='2.0.0'");
        $this->assertNotNull($this->manifest());
        $in = \RivetCore\Rmm\Update\UpdateService::inRollout(...);
        $bucket = crc32($this->dev . '|2.0.0') % 100;
        $this->assertTrue($in($this->dev, ['rollout_pct' => $bucket + 1, 'version' => '2.0.0']));
        $this->assertFalse($in($this->dev, ['rollout_pct' => $bucket, 'version' => '2.0.0']));
        // raising the percentage only ever adds devices
        $set = [];
        foreach ([10, 20, 50, 80] as $pct) {
            $set[$pct] = array_filter(range(1, 300), static fn (int $d): bool => (crc32($d . '|2.0.0') % 100) < $pct);
        }
        $this->assertSame([], array_diff($set[10], $set[20]));
        $this->assertSame([], array_diff($set[50], $set[80]));
        // min_version: too old to jump straight there
        $this->h->q("UPDATE endpoint_agent_releases SET min_version='1.5.0' WHERE version='2.0.0'");
        $this->assertNull($this->manifest());
        $this->h->q("UPDATE endpoint_agent_releases SET min_version='1.0.0' WHERE version='2.0.0'");
        $this->assertNotNull($this->manifest());
        // a per-architecture release is only for that architecture
        $this->h->q("UPDATE endpoint_agent_releases SET arch='arm64' WHERE version='2.0.0'");
        $this->assertNull($this->manifest());
        $this->h->q("UPDATE endpoint_agent_releases SET arch='amd64', active=0 WHERE version='2.0.0'");
        $this->assertNull($this->manifest(), 'an inactive release is never offered');
    }

    public function testNeverADowngradeAndAFailedVersionIsSkipped(): void
    {
        $this->h->q("UPDATE endpoint_agent_devices SET agent_version='3.0.0' WHERE device_id={$this->dev}");
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable');
        $this->assertNull($this->manifest());
        $this->h->q("UPDATE endpoint_agent_devices SET agent_version='1.0.0' WHERE device_id={$this->dev}");
        $this->assertNotNull($this->manifest());
        $this->h->checkin($this->T, ['update_result' => ['version' => '2.0.0', 'state' => 'rolled_back', 'detail' => "it\nbroke"]]);
        $st = json_decode((string) $this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->dev}"), true);
        $this->assertSame(['2.0.0'], $st['failed_versions']);
        $this->assertSame(['2.0.0', 'rolled_back', 'it broke'], [$st['last']['version'], $st['last']['state'], $st['last']['detail']]);
        $this->assertNull($this->manifest(), 'the version that just failed is not offered again');
        $this->assertContains('Agent Update Failed', array_column($this->h->audit->records(), 'action'));
        $this->h->publishBinary('2.0.1', 'amd64', 8192, false, 'stable');
        $this->assertSame('2.0.1', $this->manifest()['version'] ?? null, 'a newer release is offered');
        $this->h->module->updates()->clearFailures($this->dev);
        $this->assertSame('2.0.1', $this->manifest()['version'] ?? null);
        // an "ok" result, and garbage, record nothing harmful
        $this->h->checkin($this->T, ['update_result' => ['version' => '2.0.1', 'state' => 'ok']]);
        $this->h->checkin($this->T, ['update_result' => ['version' => 'x', 'state' => 'failed']]);
        $this->h->checkin($this->T, ['update_result' => 'nope']);
        $st = json_decode((string) $this->h->one("SELECT update_state_json FROM endpoint_agent_devices WHERE device_id={$this->dev}"), true);
        $this->assertSame([], $st['failed_versions']);
    }

    public function testNoHttpsServiceUrlMeansNoHostedOffer(): void
    {
        $this->h->publishBinary('2.0.0', 'amd64', 8192, false, 'stable');
        $this->assertNotNull($this->manifest());
        $this->h->module->settings()->set(['service_url' => '']);
        $this->assertNull($this->manifest());
        $this->h->module->settings()->set(['service_url' => 'http://rmm.example.test']);
        $this->assertNull($this->manifest(), 'plain http is not safe to offer');
        $this->h->module->settings()->set(['service_url' => 'http://127.0.0.1:8080/']);
        $this->assertSame('http://127.0.0.1:8080/api/v1/agent_update?arch=amd64&version=2.0.0', $this->manifest()['url'] ?? null, 'loopback http is tolerated when the edition allows it');
        $this->h->module->settings()->set(['service_url' => 'https://user:pw@rmm.example.test']);
        $this->assertNull($this->manifest());
    }

    public function testALegacyManualReleaseIsValidatedAndServedFromTheServiceHost(): void
    {
        $u = $this->h->module->updates();
        $sha = str_repeat('c', 64);
        $this->assertSame('Versions must look like 1.2.3.', $u->addRelease('1.2', 'https://rmm.example.test/a.exe', $sha, '0.0.0', 'stable', 100, '', 1));
        $this->assertSame('sha256 must be 64 hex characters.', $u->addRelease('1.2.3', 'https://rmm.example.test/a.exe', 'zz', '0.0.0', 'stable', 100, '', 1));
        $this->assertSame('The package URL must be an https address.', $u->addRelease('1.2.3', 'http://rmm.example.test/a.exe', $sha, '0.0.0', 'stable', 100, '', 1));
        $this->assertStringContainsString('must be served from this host', (string) $u->addRelease('1.2.3', 'https://evil.example/a.exe', $sha, '0.0.0', 'stable', 100, '', 1));
        $this->assertSame('Unknown ring.', $u->addRelease('1.2.3', 'https://rmm.example.test/a.exe', $sha, '0.0.0', 'beta', 100, '', 1));
        $this->assertNull($u->addRelease('1.2.3', 'https://rmm.example.test/a.exe', strtoupper($sha), '0.0.0', 'stable', 500, 'notes', 1));
        $this->assertSame([$sha, 100], [$this->h->one("SELECT sha256 FROM endpoint_agent_releases WHERE version='1.2.3'"), (int) $this->h->one("SELECT rollout_pct FROM endpoint_agent_releases WHERE version='1.2.3'")]);
        $m = $this->manifest();
        $this->assertSame('https://rmm.example.test/a.exe', $m['url'] ?? null, 'a manual release keeps its own URL');
        $this->assertContains('Agent Release Published', array_column($this->h->audit->records(), 'action'));
    }

    public function testTheHostedDownloadStreamsOnlyWhatTheManifestOffers(): void
    {
        $b = $this->h->publishBinary('1.1.0', 'amd64', 12288, true, 'stable');
        $this->h->publishBinary('1.0.0', 'amd64', 8192, false, null);
        $get = fn (array $q, ?string $t = null) => $this->h->call('GET', 'agent_update', null, $t ?? $this->T, $q);
        [$c] = $this->h->call('GET', 'agent_update', null, null, ['arch' => 'amd64', 'version' => '1.1.0']);
        $this->assertSame(401, $c);
        [$c, $hd] = $this->h->call('POST', 'agent_update', null, $this->T);
        $this->assertSame([405, 'GET'], [$c, $hd['Allow']]);
        foreach ([[], ['arch' => 'sparc', 'version' => '1.1.0'], ['arch' => 'amd64', 'version' => 'latest'], ['arch' => 'amd64']] as $q) {
            [$c, , $r] = $get($q);
            $this->assertSame([422, 'invalid'], [$c, $r['code']]);
        }
        foreach ([['arch' => 'amd64', 'version' => '1.0.0'], ['arch' => 'amd64', 'version' => '9.9.9'], ['arch' => 'arm64', 'version' => '1.1.0']] as $q) {
            [$c, , $r] = $get($q);
            $this->assertSame([404, 'not_found', 'Not found.'], [$c, $r['code'], $r['error']], json_encode($q));
        }
        [$c, $hd, , $resp] = $get(['arch' => 'amd64', 'version' => '1.1.0']);
        $this->assertSame(200, $c);
        $this->assertNotNull($resp->file);
        $this->assertSame([12288, $b['path']], [$resp->file->length, $resp->file->path]);
        $this->assertSame(['application/octet-stream', 'attachment; filename="rivetit-agent-amd64.exe"', 'nosniff', 'no-store', 'no-cache', 'no'],
            [$hd['Content-Type'], $hd['Content-Disposition'], $hd['X-Content-Type-Options'], $hd['Cache-Control'], $hd['Pragma'], $hd['X-Accel-Buffering']]);
        // emit it for real
        $out = '';
        $status = 0;
        $headers = [];
        (new SapiEmitter(function (int $s) use (&$status): void {
            $status = $s;
        }, function (string $h) use (&$headers): void {
            $headers[] = $h;
        }, function (string $b) use (&$out): void {
            $out .= $b;
        }))->emit($resp);
        $this->assertSame([200, $b['bytes']], [$status, $out]);
        $this->assertContains('Content-Length: 12288', $headers);
        $this->assertContains('Agent Update Downloaded', array_column($this->h->audit->records(), 'action'));
    }

    public function testADamagedOrSwappedStoredFileIsNeverServed(): void
    {
        $b = $this->h->publishBinary('1.1.0', 'amd64', 8192, true, 'stable');
        $q = ['arch' => 'amd64', 'version' => '1.1.0'];
        file_put_contents($b['path'], str_repeat('Z', 8192));   // same size, different bytes
        [$c, , $r] = $this->h->call('GET', 'agent_update', null, $this->T, $q);
        $this->assertSame([503, 'unavailable', 'The stored agent binary failed its integrity check.'], [$c, $r['code'], $r['error']]);
        file_put_contents($b['path'], 'short');
        [$c, , $r] = $this->h->call('GET', 'agent_update', null, $this->T, $q);
        $this->assertSame([503, 'The stored agent binary is missing or damaged.'], [$c, $r['error']]);
        unlink($b['path']);
        [$c, , $r] = $this->h->call('GET', 'agent_update', null, $this->T, $q);
        $this->assertSame(503, $c);
        // a release row that disagrees with its binary row is a 404, never a download
        $this->h->q("UPDATE endpoint_agent_releases SET sha256='" . str_repeat('f', 64) . "'");
        [$c] = $this->h->call('GET', 'agent_update', null, $this->T, $q);
        $this->assertSame(404, $c);
        // a storage name that is not ours never becomes a path
        $this->h->q("UPDATE endpoint_agent_releases SET sha256='{$b['sha256']}'");
        $this->h->q("UPDATE endpoint_agent_binaries SET storage_name='../../etc/passwd'");
        [$c] = $this->h->call('GET', 'agent_update', null, $this->T, $q);
        $this->assertSame(503, $c);
    }

    public function testADeactivatedBinaryIsNotServed(): void
    {
        $this->h->publishBinary('1.1.0', 'amd64', 8192, true, 'stable');
        $this->h->q('UPDATE endpoint_agent_binaries SET active=0');
        [$c] = $this->h->call('GET', 'agent_update', null, $this->T, ['arch' => 'amd64', 'version' => '1.1.0']);
        $this->assertSame(404, $c);
    }
}
