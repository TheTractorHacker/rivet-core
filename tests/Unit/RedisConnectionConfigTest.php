<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\RedisAdmin;
use RivetCore\Redis\RedisConnectionConfig;

final class RedisConnectionConfigTest extends TestCase
{
    public function testPlainConfigMapsToTcpParametersLikeTheEditionsDo(): void
    {
        $c = RedisConnectionConfig::fromArray(['host' => '127.0.0.1', 'port' => 6380, 'db' => 2, 'password' => 'pw']);
        $this->assertSame(
            ['scheme' => 'tcp', 'host' => '127.0.0.1', 'port' => 6380, 'database' => 2, 'timeout' => 1.0, 'password' => 'pw'],
            $c->toPredisParameters()
        );
        $this->assertSame(0.5, $c->toPredisParameters(0.5)['timeout']);
    }

    public function testEmptyPasswordIsNotSent(): void
    {
        $p = RedisConnectionConfig::fromArray(['host' => 'h', 'port' => 1, 'db' => 0, 'password' => ''])->toPredisParameters();
        $this->assertArrayNotHasKey('password', $p);
        $this->assertArrayNotHasKey('username', $p);
        $this->assertArrayNotHasKey('ssl', $p);
    }

    public function testUsernameIsSentWithPasswordOnly(): void
    {
        $p = RedisConnectionConfig::fromArray(['host' => 'h', 'port' => 1, 'username' => 'app', 'password' => 'pw'])->toPredisParameters();
        $this->assertSame('app', $p['username']);
        $this->assertSame('pw', $p['password']);
    }

    public function testTlsMapsToSchemeAndSslContext(): void
    {
        $p = RedisConnectionConfig::fromArray([
            'host' => 'redis.example.com', 'port' => 6379, 'tls' => true, 'tls_ca_file' => '/etc/ca.pem',
            'tls_cert_file' => '/etc/c.pem', 'tls_key_file' => '/etc/c.key',
        ])->toPredisParameters();
        $this->assertSame('tls', $p['scheme']);
        $this->assertSame(
            ['verify_peer' => true, 'verify_peer_name' => true, 'cafile' => '/etc/ca.pem', 'local_cert' => '/etc/c.pem', 'local_pk' => '/etc/c.key'],
            $p['ssl']
        );
    }

    public function testVerifyCanBeTurnedOff(): void
    {
        $p = RedisConnectionConfig::fromArray(['host' => 'h', 'port' => 1, 'tls' => '1', 'tls_verify' => '0'])->toPredisParameters();
        $this->assertSame(['verify_peer' => false, 'verify_peer_name' => false], $p['ssl']);
    }

    public function testIpv6BracketsAreStrippedForPredis(): void
    {
        $this->assertSame('::1', (new RedisConnectionConfig('[::1]'))->toPredisParameters()['host']);
    }

    /** @return iterable<string, array{RedisConnectionConfig}> */
    public static function invalid(): iterable
    {
        yield 'empty host' => [new RedisConnectionConfig('')];
        yield 'host chars' => [new RedisConnectionConfig('red is;rm')];
        yield 'host newline' => [new RedisConnectionConfig("a\nb")];
        yield 'port 0' => [new RedisConnectionConfig('h', 0)];
        yield 'port high' => [new RedisConnectionConfig('h', 70000)];
        yield 'db 16' => [new RedisConnectionConfig('h', 6379, 16)];
        yield 'long password' => [new RedisConnectionConfig('h', 6379, 0, str_repeat('x', 501))];
        yield 'password newline' => [new RedisConnectionConfig('h', 6379, 0, "a\r\nFLUSHALL")];
        yield 'bad username' => [new RedisConnectionConfig('h', 6379, 0, 'pw', 'a b')];
        yield 'username without password' => [new RedisConnectionConfig('h', 6379, 0, null, 'app')];
        yield 'ca without tls' => [new RedisConnectionConfig('h', 6379, 0, null, null, false, true, '/ca.pem')];
        yield 'key without cert' => [new RedisConnectionConfig('h', 6379, 0, null, null, true, true, null, null, '/k')];
        yield 'url as ca path' => [new RedisConnectionConfig('h', 6379, 0, null, null, true, true, 'http://evil/ca')];
    }

    /** @dataProvider invalid */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalid')]
    public function testInvalidValuesAreRejected(RedisConnectionConfig $c): void
    {
        $this->assertNotNull($c->validate());
    }

    public function testValidValuesPass(): void
    {
        $this->assertNull((new RedisConnectionConfig('redis-1.internal', 6379, 3, 'pw', 'app', true, true, '/ca.pem', '/c.pem', '/c.key'))->validate());
        $this->assertNull((new RedisConnectionConfig('::1'))->validate());
        $this->assertNull((new RedisConnectionConfig('[::1]', 1, 15))->validate());
    }

    public function testMissingCertificateFilesAreCaughtWhenCheckingFiles(): void
    {
        $c = new RedisConnectionConfig('h', 6379, 0, null, null, true, true, '/nonexistent/ca.pem');
        $this->assertNull($c->validate());
        $this->assertStringContainsString('CA file', (string) $c->validate(true));
    }

    public function testLegacyStaticValidateStillWorks(): void
    {
        $this->assertNull(RedisAdmin::validate('127.0.0.1', 6380, 0, ''));
        $this->assertSame('The port must be between 1 and 65535.', RedisAdmin::validate('h', 0, 0, ''));
        $this->assertSame('The database number must be between 0 and 15.', RedisAdmin::validate('h', 1, 99, ''));
        $this->assertSame('Enter a host name or IP address.', RedisAdmin::validate('', 1, 0, ''));
    }

    public function testPasswordNeverAppearsInDebugOutputOrRedactedText(): void
    {
        $c = new RedisConnectionConfig('h', 6379, 0, 'S3cretValue!', 'app');
        $this->assertStringNotContainsString('S3cretValue!', print_r($c, true));
        ob_start();
        var_dump($c);
        $this->assertStringNotContainsString('S3cretValue!', (string) ob_get_clean());
        $this->assertSame('AUTH *** failed', $c->redact('AUTH S3cretValue! failed'));
        $this->assertSame('unchanged', (new RedisConnectionConfig('h'))->redact('unchanged'));
    }

    public function testTestRejectsInvalidInputWithoutConnecting(): void
    {
        $r = (new RedisAdmin([]))->test(['host' => 'bad host!', 'port' => 6379, 'db' => 0]);
        $this->assertFalse($r['ok']);
        $this->assertSame('invalid', $r['reason']);
    }

    public function testUnreachableIsReportedWithoutLeakingThePassword(): void
    {
        // port 1 on localhost: connection refused
        $r = (new RedisAdmin([]))->test(['host' => '127.0.0.1', 'port' => 1, 'db' => 0, 'password' => 'Sup3rSecret']);
        $this->assertFalse($r['ok']);
        $this->assertSame('unreachable', $r['reason']);
        $this->assertStringNotContainsString('Sup3rSecret', $r['message']);
    }
}
