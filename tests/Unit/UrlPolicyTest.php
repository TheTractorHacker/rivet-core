<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\UrlPolicy;

final class UrlPolicyTest extends TestCase
{
    private static function policy(bool $allowPrivate = false): UrlPolicy
    {
        $map = [
            'hooks.example.com' => ['93.184.216.34'],
            'dual.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            'mixed.example.com' => ['93.184.216.34', '10.0.0.5'],
            'internal.example.com' => ['192.168.1.10'],
            'rebind.example.com' => ['127.0.0.1'],
            'meta.example.com' => ['169.254.169.254'],
            'v6loop.example.com' => ['::1'],
            'ula.example.com' => ['fd00::1'],
        ];

        return new UrlPolicy($allowPrivate, static fn (string $h): array => $map[$h] ?? []);
    }

    /** @return iterable<string,array{string,bool}> */
    public static function urls(): iterable
    {
        yield 'https public' => ['https://hooks.example.com/a?x=1', true];
        yield 'http public port' => ['http://hooks.example.com:8080/a', true];
        yield 'uppercase host' => ['HTTPS://Hooks.Example.com./a', true];
        yield 'dual stack public' => ['https://dual.example.com/', true];
        yield 'public ip literal' => ['https://93.184.216.34/', true];
        yield 'public v6 literal' => ['https://[2606:2800:220:1:248:1893:25c8:1946]/', true];
        yield 'one private among public' => ['https://mixed.example.com/', false];
        yield 'rebinding to loopback' => ['https://rebind.example.com/', false];
        yield 'private by name' => ['https://internal.example.com/', false];
        yield 'metadata name' => ['http://meta.example.com/latest', false];
        yield 'metadata ip' => ['http://169.254.169.254/latest/meta-data', false];
        yield 'loopback ip' => ['http://127.0.0.1/', false];
        yield 'loopback 127.1.2.3' => ['http://127.1.2.3/', false];
        yield '10/8' => ['http://10.1.2.3/', false];
        yield '172.16/12' => ['http://172.16.0.1/', false];
        yield '192.168' => ['http://192.168.0.1/', false];
        yield 'cgnat' => ['http://100.64.0.1/', false];
        yield 'unspecified' => ['http://0.0.0.0/', false];
        yield 'reserved 240/4' => ['http://240.0.0.1/', false];
        yield 'v6 loopback' => ['http://[::1]/', false];
        yield 'v6 loopback by name' => ['http://v6loop.example.com/', false];
        yield 'v6 ula' => ['http://[fd12:3456::1]/', false];
        yield 'v6 ula by name' => ['http://ula.example.com/', false];
        yield 'v6 link local' => ['http://[fe80::1]/', false];
        yield 'v4-mapped loopback' => ['http://[::ffff:127.0.0.1]/', false];
        yield 'v4-mapped metadata' => ['http://[::ffff:169.254.169.254]/', false];
        yield 'nat64 private' => ['http://[64:ff9b::a00:1]/', false];
        yield 'localhost' => ['http://localhost/', false];
        yield 'unresolvable' => ['https://nope.example.com/', false];
        yield 'ftp' => ['ftp://hooks.example.com/', false];
        yield 'file' => ['file:///etc/passwd', false];
        yield 'gopher' => ['gopher://hooks.example.com/', false];
        yield 'no scheme' => ['hooks.example.com/a', false];
        yield 'userinfo' => ['https://user:pw@hooks.example.com/', false];
        yield 'user only' => ['https://user@hooks.example.com/', false];
        yield 'at trick' => ['https://hooks.example.com@127.0.0.1/', false];
        yield 'backslash' => ['https://hooks.example.com\\@127.0.0.1/', false];
        yield 'space' => ['https://hooks.example.com/a b', false];
        yield 'newline' => ["https://hooks.example.com/a\nHost: x", false];
        yield 'tab' => ["https://hooks.example.com/\ta", false];
        yield 'nul' => ["https://hooks.example.com/\0", false];
        yield 'empty' => ['', false];
        yield 'bad port' => ['https://hooks.example.com:99999/', false];
        yield 'no host' => ['https:///path', false];
    }

    #[DataProvider('urls')]
    public function testPolicyTable(string $url, bool $safe): void
    {
        self::assertSame($safe, self::policy()->isSafe($url), $url);
    }

    public function testReturnsPinnableTarget(): void
    {
        self::assertSame(
            ['host' => 'dual.example.com', 'port' => 443, 'ips' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']],
            self::policy()->vet('https://dual.example.com/x')
        );
        self::assertSame(['host' => 'hooks.example.com', 'port' => 8080, 'ips' => ['93.184.216.34']], self::policy()->vet('http://hooks.example.com:8080/'));
        self::assertSame(80, self::policy()->vet('http://hooks.example.com/')['port']);
    }

    public function testAllowPrivateSkipsOnlyTheAddressTest(): void
    {
        $p = self::policy(true);
        self::assertTrue($p->isSafe('http://internal.example.com/'));
        self::assertTrue($p->isSafe('http://127.0.0.1:8080/'));
        self::assertSame(['host' => 'internal.example.com', 'port' => 80, 'ips' => ['192.168.1.10']], $p->vet('http://internal.example.com/'));
        self::assertFalse($p->isSafe('ftp://internal.example.com/'));
        self::assertFalse($p->isSafe('http://u:p@internal.example.com/'));
        self::assertFalse($p->isSafe('http://nope.example.com/'));
    }

    public function testResolverFailureAndGarbageAreRejected(): void
    {
        self::assertFalse((new UrlPolicy(false, static function (): array { throw new \RuntimeException('dns down'); }))->isSafe('https://x.example.com/'));
        self::assertFalse((new UrlPolicy(false, static fn (): array => ['not-an-ip']))->isSafe('https://x.example.com/'));
    }
}
