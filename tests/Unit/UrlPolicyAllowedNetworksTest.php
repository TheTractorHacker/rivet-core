<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Support\LocalNetworks;
use RivetCore\Webhooks\NetworkList;
use RivetCore\Webhooks\UrlPolicy;

final class UrlPolicyAllowedNetworksTest extends TestCase
{
    /** @param list<string> $nets */
    private static function policy(array $nets, bool $allowPrivate = false): UrlPolicy
    {
        $map = [
            'lan.example.com' => ['192.168.1.10'],
            'lan2.example.com' => ['192.168.2.10'],
            'ten.example.com' => ['10.1.2.3'],
            'mixed.example.com' => ['192.168.1.10', '10.1.2.3'],
            'mixedpub.example.com' => ['93.184.216.34', '192.168.1.10'],
            'meta.example.com' => ['169.254.169.254'],
            'loop.example.com' => ['127.0.0.1'],
            'ula.example.com' => ['fd12:3456:789a::5'],
            'ula2.example.com' => ['fd99::5'],
            'mapped.example.com' => ['::ffff:192.168.1.10'],
            'mappedloop.example.com' => ['::ffff:127.0.0.1'],
            'mappedmeta.example.com' => ['::ffff:169.254.169.254'],
        ];

        return new UrlPolicy($allowPrivate, static fn (string $h): array => $map[$h] ?? [], allowedNetworks: $nets);
    }

    /** @return iterable<string,array{list<string>,string,bool}> */
    public static function cases(): iterable
    {
        yield 'allowed LAN host' => [['192.168.1.0/24'], 'http://lan.example.com/x', true];
        yield 'LAN host outside list' => [['192.168.1.0/24'], 'http://lan2.example.com/x', false];
        yield '10.x when only 192.168 listed' => [['192.168.0.0/16'], 'http://ten.example.com/', false];
        yield '10.x listed' => [['10.0.0.0/8'], 'http://ten.example.com/', true];
        yield 'bare address /32' => [['192.168.1.10'], 'http://lan.example.com/', true];
        yield 'bare address other host' => [['192.168.1.11'], 'http://lan.example.com/', false];
        yield 'ip literal in list' => [['192.168.1.0/24'], 'http://192.168.1.77:8080/', true];
        yield 'one allowed one disallowed' => [['192.168.1.0/24'], 'http://mixed.example.com/', false];
        yield 'both allowed' => [['192.168.1.0/24', '10.0.0.0/8'], 'http://mixed.example.com/', true];
        yield 'public plus listed LAN' => [['192.168.1.0/24'], 'http://mixedpub.example.com/', true];
        yield 'public plus unlisted LAN' => [[], 'http://mixedpub.example.com/', false];
        yield 'metadata never, even listed' => [['169.254.0.0/16'], 'http://meta.example.com/', false];
        yield 'metadata literal never' => [['169.254.169.254'], 'http://169.254.169.254/', false];
        yield 'loopback never, even listed' => [['127.0.0.0/8'], 'http://loop.example.com/', false];
        yield 'loopback literal listed' => [['127.0.0.1/32'], 'http://127.0.0.1/', false];
        yield 'link-local v6 never' => [['fe80::/10'], 'http://[fe80::1]/', false];
        yield 'v6 loopback never' => [['::1/128', '::/0'], 'http://[::1]/', false];
        yield 'unspecified never' => [['0.0.0.0/0'], 'http://0.0.0.0/', false];
        yield 'multicast never' => [['224.0.0.0/4'], 'http://224.0.0.1/', false];
        yield 'broadcast never' => [['255.255.255.255/32'], 'http://255.255.255.255/', false];
        yield 'ULA allowed' => [['fd12:3456:789a::/48'], 'http://ula.example.com/', true];
        yield 'ULA denied' => [['fd12:3456:789a::/48'], 'http://ula2.example.com/', false];
        yield 'ULA literal allowed' => [['fd00::/8'], 'http://[fd12:3456:789a::5]/', true];
        yield 'mapped allowed' => [['192.168.1.0/24'], 'http://mapped.example.com/', true];
        yield 'mapped not listed' => [['10.0.0.0/8'], 'http://mapped.example.com/', false];
        yield 'mapped loopback never' => [['127.0.0.0/8'], 'http://mappedloop.example.com/', false];
        yield 'mapped metadata never' => [['169.254.0.0/16'], 'http://mappedmeta.example.com/', false];
        yield 'mapped literal allowed' => [['192.168.1.0/24'], 'http://[::ffff:192.168.1.10]/', true];
        yield 'empty list denies LAN' => [[], 'http://lan.example.com/', false];
        yield 'bad scheme still rejected' => [['192.168.1.0/24'], 'ftp://lan.example.com/', false];
    }

    /** @param list<string> $nets */
    #[DataProvider('cases')]
    public function testAllowedNetworks(array $nets, string $url, bool $ok): void
    {
        self::assertSame($ok, self::policy($nets)->isSafe($url), $url);
    }

    public function testVetTargetUnchanged(): void
    {
        $t = self::policy(['192.168.1.0/24'])->vet('https://lan.example.com:8443/h');
        self::assertSame(['host' => 'lan.example.com', 'port' => 8443, 'ips' => ['192.168.1.10']], $t);
    }

    public function testAllowPrivateStillPermits(): void
    {
        $p = self::policy([], true);
        self::assertTrue($p->isSafe('http://lan.example.com/'));
        self::assertTrue($p->isSafe('http://loop.example.com/'));
        self::assertNotNull((new UrlPolicy(true, static fn (): array => ['10.0.0.1']))->vet('http://x.test/'));
    }

    public function testBackwardCompatibleConstructor(): void
    {
        $p = new UrlPolicy(false, static fn (): array => ['192.168.1.10']);
        self::assertFalse($p->isSafe('http://x.test/'));
    }

    /** @return iterable<string,array{string|list<string>,list<string>,int}> */
    public static function parseCases(): iterable
    {
        yield 'normalises and masks' => ['192.168.1.77/24', ['192.168.1.0/24'], 0];
        yield 'mixed separators' => ["10.0.0.0/8, 192.168.1.0/24\n172.16.5.0/24;fd00::/48", ['10.0.0.0/8', '192.168.1.0/24', '172.16.5.0/24', 'fd00::/48'], 0];
        yield 'bare v4' => ['192.168.1.5', ['192.168.1.5/32'], 0];
        yield 'bare v6 canonical' => ['FD00:0:0:0::1', ['fd00::1/128'], 0];
        yield 'v6 masked' => ['FD12:3456:789A:1::5/48', ['fd12:3456:789a::/48'], 0];
        yield 'array input' => [['192.168.1.0/24', '10.1.0.0/16'], ['192.168.1.0/24', '10.1.0.0/16'], 0];
        yield 'cgnat' => ['100.64.0.0/10', ['100.64.0.0/10'], 0];
        yield 'too wide v4' => ['10.0.0.0/7', [], 1];
        yield 'too wide v6' => ['fd00::/47', [], 1];
        yield 'too wide inside space' => ['192.168.0.0/15', [], 1];
        yield 'public' => ['8.8.8.0/24', [], 1];
        yield 'public v6' => ['2001:db8::/48', [], 1];
        yield 'loopback' => ['127.0.0.0/8', [], 1];
        yield 'link-local' => ['169.254.0.0/16', [], 1];
        yield 'metadata' => ['169.254.169.254', [], 1];
        yield 'link-local v6' => ['fe80::/64', [], 1];
        yield 'default route' => ['0.0.0.0/0', [], 1];
        yield 'mapped v6 rejected' => ['::ffff:192.168.1.0/120', [], 1];
        yield 'garbage' => ['not-a-net', [], 1];
        yield 'bad prefix' => ['192.168.1.0/abc 192.168.1.0/33 192.168.1.0/24/1', [], 3];
        yield 'duplicates' => ['192.168.1.0/24 192.168.1.5/24 192.168.1.0/24', ['192.168.1.0/24'], 0];
        yield 'good plus bad' => ['192.168.1.0/24 8.8.8.8', ['192.168.1.0/24'], 1];
        yield 'empty' => ['', [], 0];
    }

    /** @param string|list<string> $in @param list<string> $nets */
    #[DataProvider('parseCases')]
    public function testParse(string|array $in, array $nets, int $errors): void
    {
        $r = NetworkList::parse($in);
        self::assertSame($nets, $r['networks']);
        self::assertCount($errors, $r['errors']);
    }

    public function testMaxEntries(): void
    {
        $in = [];
        for ($i = 0; $i < 17; $i++) {
            $in[] = "10.$i.0.0/16";
        }
        $r = NetworkList::parse($in);
        self::assertCount(16, $r['networks']);
        self::assertCount(1, $r['errors']);
        self::assertCount(16, NetworkList::parse(array_slice($in, 0, 16))['networks']);
        self::assertSame([], NetworkList::parse(array_slice($in, 0, 16))['errors']);
    }

    public function testContains(): void
    {
        $n = ['192.168.1.0/24', 'fd00::/48'];
        self::assertTrue(NetworkList::contains($n, '192.168.1.200'));
        self::assertFalse(NetworkList::contains($n, '192.168.2.1'));
        self::assertTrue(NetworkList::contains($n, '::ffff:192.168.1.5'));
        self::assertFalse(NetworkList::contains($n, '::ffff:192.168.2.5'));
        self::assertTrue(NetworkList::contains($n, 'fd00::abcd'));
        self::assertFalse(NetworkList::contains($n, 'fd01::1'));
        self::assertFalse(NetworkList::contains($n, 'garbage'));
        self::assertFalse(NetworkList::contains(['junk', '1.2.3.4/99'], '1.2.3.4'));
        self::assertTrue(NetworkList::contains(['10.0.0.0/13'], '10.7.255.255'));
        self::assertFalse(NetworkList::contains(['10.0.0.0/13'], '10.8.0.0'));
    }

    public function testLocalNetworksDetect(): void
    {
        $unicast = static fn (string $a, string $m): array => ['family' => 2, 'address' => $a, 'netmask' => $m];
        $fake = static fn (): array => [
            'lo' => ['up' => true, 'unicast' => [$unicast('127.0.0.1', '255.0.0.0')]],
            'docker0' => ['up' => true, 'unicast' => [$unicast('172.17.0.1', '255.255.0.0')]],
            'br-abc123' => ['up' => true, 'unicast' => [$unicast('172.18.0.1', '255.255.0.0')]],
            'veth1' => ['up' => true, 'unicast' => [$unicast('10.9.9.9', '255.255.255.0')]],
            'virbr0' => ['up' => true, 'unicast' => [$unicast('192.168.122.1', '255.255.255.0')]],
            'eth9' => ['up' => false, 'unicast' => [$unicast('192.168.50.2', '255.255.255.0')]],
            'wan0' => ['up' => true, 'unicast' => [$unicast('203.0.113.9', '255.255.255.0')]],
            'eth0' => ['up' => true, 'unicast' => [
                $unicast('192.168.1.77', '255.255.255.0'),
                ['family' => 10, 'address' => 'fe80::1', 'netmask' => 'ffff:ffff:ffff:ffff::'],
            ]],
            'eth1' => ['up' => true, 'unicast' => [$unicast('10.20.30.40', '255.255.0.0'), $unicast('10.0.0.1', '255.0.255.0')]],
        ];
        self::assertSame([
            ['interface' => 'eth0', 'cidr' => '192.168.1.0/24', 'address' => '192.168.1.77'],
            ['interface' => 'eth1', 'cidr' => '10.20.0.0/16', 'address' => '10.20.30.40'],
        ], LocalNetworks::detect($fake));
        self::assertSame([], LocalNetworks::detect(static fn (): array => []));
        self::assertSame([], LocalNetworks::detect(static fn (): bool => false));
        self::assertIsArray(LocalNetworks::detect());
    }
}
