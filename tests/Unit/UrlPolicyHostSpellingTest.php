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
}
