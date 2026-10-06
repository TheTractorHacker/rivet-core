<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

final class WebhookHardeningTest extends TestCase
{
    private static function subs(string $url): WebhookSubscriptionsInterface
    {
        return new class($url) implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
            public function __construct(private string $url)
            {
            }

            public function forEvent(string $eventType): array
            {
                return [new WebhookSubscription(7, $this->url, 'sekret')];
            }

            public function find(int $webhookId): ?WebhookSubscription
            {
                return new WebhookSubscription(7, $this->url, 'sekret');
            }
        };
    }

    /** @param list<string> $headers */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $h) {
            if (str_starts_with($h, $name . ': ')) {
                return substr($h, strlen($name) + 2);
            }
        }

        return null;
    }

    public function testSignatureV2AndLegacyHeaders(): void
    {
        $seen = [];
        $d = new WebhookDispatcher(new FakeDatabase(), self::subs('https://h.example/a'), new FixedClock(), ['X-Test'], function ($url, $body, $headers) use (&$seen) {
            $seen[] = [$body, $headers];

            return ['status' => 200, 'body' => '', 'error' => null];
        });
        $d->deliver('ticket.created', ['id' => 1]);
        [$body, $headers] = $seen[0];
        $ts = (int) self::header($headers, 'X-Rivet-Timestamp');
        self::assertSame((new FixedClock())->now()->getTimestamp(), $ts);
        self::assertSame('t=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, 'sekret'), self::header($headers, 'X-Rivet-Signature-V2'));
        self::assertSame('sha256=' . hash_hmac('sha256', $body, 'sekret'), self::header($headers, 'X-Test-Signature'));
        self::assertSame('ticket.created', self::header($headers, 'X-Test-Event'));
        self::assertSame(WebhookDispatcher::signatureV2($ts, $body, 'sekret'), self::header($headers, 'X-Rivet-Signature-V2'));
    }

    public function testRetryKeepsBodyAndLegacySignatureButFreshTimestamp(): void
    {
        $seen = [];
        $now = new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC'));
        $clock = new class($now) implements \RivetCore\Contracts\ClockInterface {
            public function __construct(public \DateTimeImmutable $t)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return $this->t;
            }
        };
        $d = new WebhookDispatcher(new FakeDatabase(), self::subs('https://h.example/a'), $clock, ['X-Test'], function ($url, $body, $headers) use (&$seen) {
            $seen[] = [$body, $headers];

            return ['status' => 200, 'body' => '', 'error' => null];
        });
        // no emittedAt: body comes from the clock, so a fresh timestamp is natural; here emittedAt is not passed and the clock moves
        $d->deliverTo(7, 'e', ['a' => 1], 1, '2026-01-02T03:04:05Z', 1000);
        $d->deliverTo(7, 'e', ['a' => 1], 2, '2026-01-02T03:04:05Z', 2000);
        self::assertSame($seen[0][0], $seen[1][0]);
        self::assertSame(self::header($seen[0][1], 'X-Test-Signature'), self::header($seen[1][1], 'X-Test-Signature'));
        self::assertSame('1000', self::header($seen[0][1], 'X-Rivet-Timestamp'));
        self::assertSame('2000', self::header($seen[1][1], 'X-Rivet-Timestamp'));
        self::assertSame('t=2000,v1=' . hash_hmac('sha256', '2000.' . $seen[1][0], 'sekret'), self::header($seen[1][1], 'X-Rivet-Signature-V2'));

        // explicit emittedAt without signedAt pins the timestamp to that instant
        $d->deliverTo(7, 'e', ['a' => 1], 3, '2026-01-02T03:04:05Z');
        self::assertSame((string) $now->getTimestamp(), self::header($seen[2][1], 'X-Rivet-Timestamp'));

        // no emittedAt: the timestamp follows the clock per attempt
        $clock->t = $now->modify('+90 seconds');
        $d->deliverTo(7, 'e', ['a' => 1], 4);
        self::assertSame((string) $clock->t->getTimestamp(), self::header($seen[3][1], 'X-Rivet-Timestamp'));
    }

    public function testPolicyRejectsWithoutCallingTransportAndLogsAttempt(): void
    {
        $called = false;
        $db = new FakeDatabase();
        $policy = new UrlPolicy(false, static fn (): array => ['127.0.0.1']);
        $d = new WebhookDispatcher($db, self::subs('https://evil.example/a'), new FixedClock(), ['X'], function () use (&$called) {
            $called = true;

            return ['status' => 200, 'body' => '', 'error' => null];
        }, 10, $policy);
        $r = $d->deliver('e', []);
        self::assertFalse($called);
        self::assertFalse($r[0]['ok']);
        self::assertSame('endpoint URL not allowed', $r[0]['error']);
        self::assertStringContainsString('INSERT INTO webhook_deliveries', $db->calls[0]['sql']);
    }

    public function testRequireUrlPolicyBlocksPrivateWithoutInjectedPolicy(): void
    {
        $called = false;
        $d = new WebhookDispatcher(new FakeDatabase(), self::subs('http://127.0.0.1/x'), new FixedClock(), ['X'], function () use (&$called) {
            $called = true;

            return ['status' => 200, 'body' => '', 'error' => null];
        }, 10, null, true);
        self::assertSame('endpoint URL not allowed', $d->deliver('e', [])[0]['error']);
        self::assertFalse($called);

        // default (no policy, not required) is unchanged
        $d2 = new WebhookDispatcher(new FakeDatabase(), self::subs('http://127.0.0.1/x'), new FixedClock(), ['X'], function () use (&$called) {
            $called = true;

            return ['status' => 200, 'body' => '', 'error' => null];
        });
        self::assertTrue($d2->deliver('e', [])[0]['ok']);
        self::assertTrue($called);
    }

    public function testVettedTargetIsPassedToTransportForPinning(): void
    {
        $targets = [];
        $policy = new UrlPolicy(false, static fn (): array => ['93.184.216.34']);
        $d = new WebhookDispatcher(new FakeDatabase(), self::subs('https://h.example:8443/a'), new FixedClock(), ['X'], function ($u, $b, $h, $t, $target = null) use (&$targets) {
            $targets[] = $target;

            return ['status' => 200, 'body' => '', 'error' => null];
        }, 10, $policy);
        $d->deliver('e', []);
        self::assertSame(['host' => 'h.example', 'port' => 8443, 'ips' => ['93.184.216.34']], $targets[0]);
    }

    public function testCurlOptionsPinResolveAndRestrictProtocols(): void
    {
        $o = WebhookDispatcher::curlOptions('{}', ['A: b'], 10, ['host' => 'h.example', 'port' => 443, 'ips' => ['93.184.216.34', '2606:2800::1']]);
        self::assertSame(['h.example:443:93.184.216.34,2606:2800::1'], $o[CURLOPT_RESOLVE]);
        self::assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertSame(0, $o[CURLOPT_MAXREDIRS]);
        self::assertTrue($o[CURLOPT_SSL_VERIFYPEER]);
        self::assertSame(10, $o[CURLOPT_TIMEOUT]);
        self::assertSame(5, $o[CURLOPT_CONNECTTIMEOUT]);
        self::assertSame('{}', $o[CURLOPT_POSTFIELDS]);

        $none = WebhookDispatcher::curlOptions('{}', [], 3);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, $none);
        self::assertSame(3, $none[CURLOPT_CONNECTTIMEOUT]);
        self::assertArrayNotHasKey(CURLOPT_RESOLVE, WebhookDispatcher::curlOptions('{}', [], 3, ['host' => '93.184.216.34', 'port' => 80, 'ips' => ['93.184.216.34']]));
    }
}
