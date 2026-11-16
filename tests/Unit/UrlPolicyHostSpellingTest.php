<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;

/**
 * CORE-01: curl percent-decodes the host of a URL, the resolver pin (CURLOPT_RESOLVE) is keyed on the literal text. A host such as
 * "a%62c.example" therefore passed vet() against the address of the literal name while curl connected to "abc.example". Only plain DNS
 * label characters are accepted now.
 */
final class UrlPolicyHostSpellingTest extends TestCase
{
    /** @return iterable<string,array{0:string}> */
    public static function hostile(): iterable
    {
        yield 'percent encoded letter' => ['http://a%62c.example.test/hook'];
        yield 'percent encoded dot' => ['http://abc%2eexample.test/hook'];
        yield 'percent encoded nul' => ['http://abc%00.example.test/hook'];
        yield 'percent sign' => ['https://a%25b.example.test/'];
        yield 'non ascii host' => ["https://b\u{00fc}cher.example.test/"];
        yield 'underscore only separators' => ['https://a b.example.test/'];
        yield 'star' => ['https://*.example.test/'];
        yield 'comma list' => ['https://a.example.test,b.example.test/'];
    }

    #[DataProvider('hostile')]
    public function testAHostThatCurlWouldSpellDifferentlyIsRefusedBeforeResolving(string $url): void
    {
        $resolved = [];
        $policy = new UrlPolicy(false, static function (string $h) use (&$resolved): array {
            $resolved[] = $h;
            return ['93.184.216.34'];
        });
        $this->assertNull($policy->vet($url));
        $this->assertSame([], $resolved, 'the resolver must not even be asked about a host with a doubtful spelling');
    }

    public function testOrdinaryHostsStillPassAndKeepThePin(): void
    {
        $policy = new UrlPolicy(false, static fn (string $h): array => ['93.184.216.34']);
        foreach (['https://hooks.example.test/x', 'https://my-host_1.sub.example.test:8443/x', 'https://xn--bcher-kva.example.test/'] as $u) {
            $t = $policy->vet($u);
            $this->assertNotNull($t, $u);
            $this->assertSame(['93.184.216.34'], $t['ips']);
        }
        $t = $policy->vet('https://hooks.example.test./x');
        $this->assertSame('hooks.example.test', $t['host']);
        $this->assertSame('https://hooks.example.test/x', WebhookDispatcher::pinnedUrl('https://hooks.example.test./x', $t));
    }

    /** @return iterable<string,array{0:string}> */
    public static function numeric(): iterable
    {
        yield 'decimal' => ['http://2130706433:8919/x'];
        yield 'short dotted' => ['http://127.1/x'];
        yield 'hex' => ['http://0x7f.1/x'];
        yield 'hex whole' => ['http://0x7f000001/x'];
        yield 'octal' => ['http://0177.0.0.1/x'];
        yield 'three parts' => ['http://10.1.258/x'];
        yield 'name with a numeric last label' => ['http://metadata.123/x'];
    }

    /** CORE-DS-75 (CORE-27): a numeric spelling of an IPv4 address is not a DNS name, even if the resolver claims a public answer for it. */
    #[DataProvider('numeric')]
    public function testNumericIpv4SpellingsAreRefusedBeforeResolving(string $url): void
    {
        $resolved = [];
        $policy = new UrlPolicy(false, static function (string $h) use (&$resolved): array {
            $resolved[] = $h;
            return ['93.184.216.34'];
        });
        $this->assertNull($policy->vet($url));
        $this->assertSame([], $resolved);
    }

    /** CORE-DS-73: metadata and service addresses inside a listed internal range stay unreachable. */
    public function testCloudMetadataAddressesAreNeverAllowedEvenWhenTheirRangeIsListed(): void
    {
        $ip = ['meta.test' => '100.100.100.200', 'ts.test' => '100.100.100.100', 'imds6.test' => 'fd00:ec2::254', 'lan.test' => '100.64.1.5', 'ula.test' => 'fd12:3456::7'];
        $policy = new UrlPolicy(false, static fn (string $h): array => [$ip[$h]], ['100.64.0.0/10', 'fc00::/7']);
        $this->assertNull($policy->vet('http://meta.test/latest/meta-data'));
        $this->assertNull($policy->vet('http://ts.test/'));
        $this->assertNull($policy->vet('http://imds6.test/latest/meta-data'));
        $this->assertNotNull($policy->vet('http://lan.test/'), 'the rest of the listed range still works');
        $this->assertNotNull($policy->vet('http://ula.test/'));
    }
}
