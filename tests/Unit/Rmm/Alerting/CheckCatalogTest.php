<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Alerting;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Checks\CheckCatalog;
use RivetCore\Rmm\Crypto\Signer;
use RivetCore\Rmm\Settings\ChecksValidator as C;

final class CheckCatalogTest extends TestCase
{
    public function testTheCatalogAndTheValidatorAgree(): void
    {
        $this->assertSame(C::TYPES, CheckCatalog::all());
        $this->assertCount(16, CheckCatalog::all());
        foreach (CheckCatalog::LEGACY as $t) {
            $this->assertTrue(CheckCatalog::isLegacy($t));
            $this->assertFalse(CheckCatalog::reportsValue($t));
            $this->assertTrue(CheckCatalog::offeredTo($t, null), 'a legacy type goes to a device that announced nothing');
        }
        $this->assertFalse(CheckCatalog::offeredTo('ping', null));
        $this->assertFalse(CheckCatalog::offeredTo('ping', ['check:disk']));
        $this->assertTrue(CheckCatalog::offeredTo('ping', ['check:disk', 'check:ping']));
        $this->assertTrue(CheckCatalog::reportsValue('ping'));
        $this->assertFalse(CheckCatalog::reportsValue('smart'), 'smart has no number');
    }

    /** @param array<string,mixed> $params */
    private function ok(string $type, array $params): array
    {
        [$out, $err] = C::validate((string) json_encode([['key' => 'k', 'type' => $type, 'params' => $params, 'interval_s' => 60]]));
        $this->assertNull($err, $type . ' ' . json_encode($params));
        $this->assertNotNull($out);

        return (array) $out[0]['params'];
    }

    private function bad(string $type, array $params, string $needle): void
    {
        [$out, $err] = C::validate((string) json_encode([['key' => 'k', 'type' => $type, 'params' => $params, 'interval_s' => 60]]));
        $this->assertNull($out, $type . ' ' . json_encode($params));
        $this->assertStringContainsString($needle, (string) $err);
    }

    public function testEveryTypeAcceptsItsMinimalParams(): void
    {
        $this->ok('cpu', []);
        $this->ok('memory', ['metric' => 'swap_used_pct', 'warn_pct' => 50]);
        $this->ok('process', ['name' => 'nginx']);
        $this->ok('port', ['host' => 'db.corp.test', 'port' => 5432]);
        $this->ok('ping', ['host' => '10.0.0.1']);
        $this->ok('http', ['url' => 'https://example.test/health?x=1']);
        $this->ok('cert', ['paths' => ['/etc/ssl/certs/site.pem']]);
        $this->ok('cert', ['store' => 'LocalMachine\\My']);
        $this->ok('cert', ['host' => 'mail.corp.test', 'port' => 993]);
        $this->ok('ntp', []);
        $this->ok('smart', []);
        $this->ok('av', []);
        $this->ok('systemd_failed', ['ignore' => ['apt-daily.service']]);
        $this->ok('inodes', ['mount' => '/var']);
    }

    public function testRefusals(): void
    {
        $this->bad('cpu', ['window_s' => 0], 'cpu.window_s');
        $this->bad('cpu', ['window_s' => 31], 'cpu.window_s');
        $this->bad('cpu', ['warn_pct' => 90, 'fail_pct' => 50], 'fail_pct must not be below');
        $this->bad('cpu', ['bogus' => 1], 'has no param "bogus"');
        $this->bad('cpu', ['warn_pct' => '90'], 'cpu.warn_pct');
        $this->bad('memory', ['metric' => 'disk'], 'memory.metric');
        $this->bad('process', [], 'needs param "name"');
        $this->bad('process', ['name' => '../etc'], 'process.name');
        $this->bad('process', ['name' => 'x', 'min_count' => -1], 'min_count');
        $this->bad('port', ['host' => 'a', 'port' => 0], 'port.port');
        $this->bad('port', ['host' => 'a'], 'needs param "port"');
        $this->bad('port', ['host' => '-oProxyCommand=x', 'port' => 22], 'port.host');
        $this->bad('port', ['host' => 'a b', 'port' => 22], 'port.host');
        $this->bad('port', ['host' => str_repeat('a', 254), 'port' => 22], 'port.host');
        $this->bad('ping', ['host' => 'x', 'count' => 11], 'ping.count');
        $this->bad('ping', ['host' => 'x', 'warn_ms' => 100, 'fail_ms' => 50], 'fail_ms must not be below');
        $this->bad('http', ['url' => 'ftp://x/'], 'http.url');
        $this->bad('http', ['url' => 'https://user:pw@host/'], 'http.url');
        $this->bad('http', ['url' => 'https://h/', 'method' => 'POST'], 'http.method');
        $this->bad('http', ['url' => 'https://h/', 'expect_min' => 300, 'expect_max' => 200], 'expect_max');
        $this->bad('http', ['url' => 'https://h/', 'cert_warn_days' => 3, 'cert_fail_days' => 10], 'cert_fail_days must not be above');
        $this->bad('http', ['url' => 'https://h/', 'verify_tls' => 'no'], 'verify_tls');
        $this->bad('http', ['url' => 'https://h/' . str_repeat('a', 2000)], 'http.url');
        $this->bad('cert', [], 'exactly one of');
        $this->bad('cert', ['paths' => ['/a'], 'host' => 'h'], 'exactly one of');
        $this->bad('cert', ['paths' => ['relative.pem']], 'cert.paths');
        $this->bad('cert', ['paths' => array_fill(0, 11, '/a')], 'cert.paths');
        $this->bad('cert', ['store' => 'Bogus\\My'], 'cert.store');
        $this->bad('cert', ['paths' => ['/a'], 'warn_days' => 3, 'fail_days' => 10], 'fail_days must not be above');
        $this->bad('ntp', ['server' => ''], 'ntp.server');
        $this->bad('ntp', ['warn_ms' => 500, 'fail_ms' => 100], 'fail_ms must not be below');
        $this->bad('smart', ['devices' => ['sda']], 'smart.devices');
        $this->bad('smart', ['devices' => ['/dev/sda; rm']], 'smart.devices');
        $this->bad('av', ['max_signature_age_days' => 0], 'max_signature_age_days');
        $this->bad('systemd_failed', ['ignore' => []], 'systemd_failed.ignore');
        $this->bad('inodes', ['mount' => 'var'], 'inodes.mount');
    }

    public function testThresholdsAndFlapAreValidatedEverywhereTheyApply(): void
    {
        $p = $this->ok('ping', ['host' => 'x', 'thresholds' => ['warn' => ['op' => 'gt', 'value' => 100]], 'flap' => []]);
        $this->assertSame(['warn' => ['op' => 'gt', 'value' => 100], 'hysteresis' => 0, 'for_samples' => 1, 'for_minutes' => 0], $p['thresholds']);
        $this->assertSame(['window' => 20, 'high_pct' => 50, 'low_pct' => 25], $p['flap']);
        $this->bad('smart', ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 1]]], 'does not report a value');
        $this->bad('ping', ['host' => 'x', 'thresholds' => ['warn' => ['op' => 'gt', 'value' => 1.5]]], 'whole numbers');
        $this->bad('ping', ['host' => 'x', 'flap' => ['window' => 2]], 'flap.window');
        $this->bad('ping', ['host' => 'x', 'ignore_parent' => 'yes'], 'ignore_parent');
        $this->assertTrue($this->ok('ping', ['host' => 'x', 'ignore_parent' => true])['ignore_parent']);
        // a legacy type can flap but has no number to compare
        $p = $this->ok('disk', ['mount' => 'C:', 'flap' => ['window' => 10]]);
        $this->assertSame(10, $p['flap']['window']);
        $this->bad('disk', ['thresholds' => ['warn' => ['op' => 'gt', 'value' => 1]]], 'do not report a value');
        $this->bad('service', ['name' => 'x', 'flap' => ['window' => 99]], 'flap.window');
    }

    public function testASignedCheckWithAllTheNewFieldsHasAStableCanonicalForm(): void
    {
        [$out] = C::validate('[{"key":"p1","type":"ping","params":{"host":"10.0.0.1","thresholds":{"warn":{"op":"gt","value":100},"crit":{"op":"gt","value":300},"for_samples":3},"count":3},"interval_s":60}]');
        $this->assertNotNull($out);
        $msg = Signer::checkMessage($out[0]);
        $this->assertSame('{"interval_s":60,"key":"p1","params":{"count":3,"host":"10.0.0.1","thresholds":{"crit":{"op":"gt","value":300},"for_minutes":0,"for_samples":3,"hysteresis":0,"warn":{"op":"gt","value":100}}},"type":"ping"}', $msg);
    }
}
