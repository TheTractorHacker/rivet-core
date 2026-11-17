<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Monitoring\Inbound;

use PHPUnit\Framework\TestCase;
use RivetCore\Monitoring\Inbound\EngineConfig;
use RivetCore\Monitoring\Inbound\IncidentEngine;
use RivetCore\Monitoring\Inbound\InboundAuthenticator;
use RivetCore\Monitoring\Inbound\InboundHandler;
use RivetCore\Monitoring\Inbound\InboundResult;
use RivetCore\Monitoring\Inbound\MonitorTarget;
use RivetCore\Monitoring\Inbound\RateLimiterInterface;
use RivetCore\Monitoring\Inbound\UptimeKumaPayload;
use RivetCore\Testing\InMemoryIncidentStore;
use RivetCore\Testing\InMemoryMonitorClientMap;
use RivetCore\Testing\InMemoryTicketGateway;

final class InboundHandlerTest extends TestCase
{
    private const SECRET = 's3cret-value-for-tests-0123456789';

    private InMemoryTicketGateway $gw;
    private InMemoryIncidentStore $store;

    private function handler(?InboundAuthenticator $auth = null, ?RateLimiterInterface $lim = null): InboundHandler
    {
        $this->gw = new InMemoryTicketGateway();
        $this->store = new InMemoryIncidentStore();
        $map = (new InMemoryMonitorClientMap())->mapMonitor('7', MonitorTarget::client(3));
        $engine = new IncidentEngine($this->store, $this->gw, $map, new EngineConfig(), static fn (): int => 1_800_100_000);

        return new InboundHandler($auth ?? new InboundAuthenticator([self::SECRET]), new UptimeKumaPayload(), $engine, $lim);
    }

    /** @param array<string,string> $h */
    private static function good(array $h = []): array
    {
        return $h + ['X-Webhook-Secret' => self::SECRET, 'Content-Type' => 'application/json'];
    }

    public function testHappyPathDownThenUp(): void
    {
        $h = $this->handler();
        $r = $h->handle(self::good(), UptimeKumaPayloadTest::body(0), '203.0.113.5');
        self::assertSame(200, $r->status);
        self::assertSame(['ok' => true, 'action' => 'opened', 'ticket_id' => 500], $r->body);
        self::assertSame('{"ok":true,"action":"opened","ticket_id":500}', $r->json());
        $r = $h->handle(self::good(), UptimeKumaPayloadTest::body(1, ['heartbeat' => ['time' => '2026-11-13 10:05:00.000']]), '203.0.113.5');
        self::assertSame('resolved', $r->body['action']);
    }

    public function testBearerAndHeaderCaseInsensitive(): void
    {
        $h = $this->handler();
        self::assertSame(200, $h->handle(['authorization' => 'Bearer ' . self::SECRET], UptimeKumaPayloadTest::body(2), '1.1.1.1')->status);
        self::assertSame(200, $h->handle(['x-webhook-secret' => self::SECRET], UptimeKumaPayloadTest::body(2), '1.1.1.1')->status);
        self::assertSame(200, $h->handle(['X-WEBHOOK-SECRET' => [self::SECRET]], UptimeKumaPayloadTest::body(2), '1.1.1.1')->status);
    }

    public function testMissingWrongAndMalformedSecretsAre401AndTouchNothing(): void
    {
        $h = $this->handler();
        $cases = [
            [],
            ['X-Webhook-Secret' => ''],
            ['X-Webhook-Secret' => 'wrong'],
            ['X-Webhook-Secret' => self::SECRET . 'x'],
            ['X-Webhook-Secret' => substr(self::SECRET, 0, -1)],
            ['Authorization' => self::SECRET],
            ['Authorization' => 'Basic ' . self::SECRET],
            ['Authorization' => 'Bearer'],
            ['Authorization' => 'Bearer wrong'],
            ['X-Comet-Secret' => self::SECRET],
        ];
        foreach ($cases as $hdr) {
            $r = $h->handle($hdr, UptimeKumaPayloadTest::body(0), '1.1.1.1');
            self::assertSame(401, $r->status, json_encode($hdr));
            self::assertSame(['ok' => false, 'error' => 'unauthorized'], $r->body);
        }
        self::assertSame([], $this->gw->tickets);
        self::assertSame(0, $this->store->puts);
    }

    public function testAnUnconfiguredOrEmptyOrShortSecretRejectsEveryRequest(): void
    {
        foreach ([[], [''], [null], ['short'], [str_repeat('a', InboundAuthenticator::MIN_SECRET_LENGTH - 1)]] as $secrets) {
            $h = $this->handler(new InboundAuthenticator($secrets));
            foreach ([[], ['X-Webhook-Secret' => ''], ['X-Webhook-Secret' => 'short'], ['Authorization' => 'Bearer '], ['Authorization' => 'Bearer short']] as $hdr) {
                self::assertSame(401, $h->handle($hdr, UptimeKumaPayloadTest::body(0), '1.1.1.1')->status);
            }
            self::assertSame([], $this->gw->tickets);
        }
        self::assertFalse((new InboundAuthenticator(['']))->isConfigured());
        self::assertTrue((new InboundAuthenticator([self::SECRET]))->isConfigured());
    }

    public function testRotationAcceptsOldAndNewSecret(): void
    {
        $h = $this->handler(new InboundAuthenticator([self::SECRET, 'the-new-secret-abcdefghijkl']));
        self::assertSame(200, $h->handle(['X-Webhook-Secret' => self::SECRET], UptimeKumaPayloadTest::body(2), '1.1.1.1')->status);
        self::assertSame(200, $h->handle(['X-Webhook-Secret' => 'the-new-secret-abcdefghijkl'], UptimeKumaPayloadTest::body(2), '1.1.1.1')->status);
    }

    public function testComparisonIsHashEqualsOverFixedLengthDigests(): void
    {
        $src = (string) file_get_contents((string) (new \ReflectionClass(InboundAuthenticator::class))->getFileName());
        self::assertStringContainsString('hash_equals(', $src);
        self::assertStringContainsString("hash('sha256'", $src);
        self::assertDoesNotMatchRegularExpression('/\$\w+\s*(===|==|!==|!=)\s*\$this->/', $src, 'a plain comparison of the secret');
    }

    public function testRateLimitComesFirstAndSeesTheIp(): void
    {
        $seen = [];
        $lim = new class ($seen) implements RateLimiterInterface {
            /** @param list<string> $seen */
            public function __construct(public array &$seen)
            {
            }

            public function allow(string $key): bool
            {
                $this->seen[] = $key;

                return false;
            }
        };
        $h = $this->handler(null, $lim);
        $r = $h->handle(self::good(), UptimeKumaPayloadTest::body(0), '198.51.100.9');
        self::assertSame(429, $r->status);
        self::assertSame('60', $r->headers['Retry-After']);
        self::assertSame(['inbound-monitoring:198.51.100.9'], $seen);
        self::assertSame([], $this->gw->tickets);
    }

    public function testOversizeBodyIs413OnlyForAnAuthenticatedSender(): void
    {
        $h = $this->handler();
        $big = str_repeat('a', UptimeKumaPayload::MAX_BODY + 1);
        self::assertSame(413, $h->handle(self::good(), $big, '1.1.1.1')->status);
        self::assertSame(401, $h->handle([], $big, '1.1.1.1')->status);
    }

    public function testBadBodiesAre400WithAFixedToken(): void
    {
        $h = $this->handler();
        foreach (['', 'nope', '[1]', '{"heartbeat":{"status":7,"monitorID":1}}'] as $b) {
            $r = $h->handle(self::good(), $b, '1.1.1.1');
            self::assertSame(400, $r->status);
            self::assertFalse($r->body['ok']);
        }
    }

    public function testTestNotificationIsAcceptedAndChangesNothing(): void
    {
        $h = $this->handler();
        $r = $h->handle(self::good(), '{"heartbeat":null,"monitor":null,"msg":"Testing"}', '1.1.1.1');
        self::assertSame(200, $r->status);
        self::assertSame('test', $r->body['action']);
        self::assertSame(0, $this->store->puts);
    }

    public function testEngineFailureIs500WithoutLeakingTheMessageOrTheBody(): void
    {
        $h = $this->handler();
        $this->gw->beforeCall = static function (): void {
            throw new \RuntimeException('SQLSTATE password=' . self::SECRET);
        };
        $r = $h->handle(self::good(), UptimeKumaPayloadTest::body(0), '1.1.1.1');
        self::assertSame(500, $r->status);
        self::assertSame(['ok' => false, 'error' => 'internal'], $r->body);
        self::assertStringNotContainsString(self::SECRET, $r->json());
    }

    public function testNoResponseEverContainsTheSecretOrEchoesTheBody(): void
    {
        $h = $this->handler();
        $marker = 'ECHO-MARKER-9f3a';
        $bodies = [UptimeKumaPayloadTest::body(0, ['monitor' => ['name' => $marker], 'heartbeat' => ['msg' => $marker]]), 'bad ' . $marker, '{"heartbeat":{"status":"' . $marker . '"}}'];
        $headers = [self::good(), ['X-Webhook-Secret' => 'wrong-' . self::SECRET], []];
        foreach ($bodies as $b) {
            foreach ($headers as $hdr) {
                $r = $h->handle($hdr, $b, '1.1.1.1');
                $this->assertSafe($r, $marker);
            }
        }
        // a debug dump of the authenticator never shows a secret either
        self::assertStringNotContainsString(self::SECRET, print_r(new InboundAuthenticator([self::SECRET]), true));
        self::assertStringNotContainsString(self::SECRET, var_export((new InboundAuthenticator([self::SECRET]))->__debugInfo(), true));
    }

    private function assertSafe(InboundResult $r, string $marker): void
    {
        $j = $r->json();
        self::assertStringNotContainsString(self::SECRET, $j);
        self::assertStringNotContainsString($marker, $j);
        self::assertJson($j);
        self::assertContains($r->status, [200, 400, 401, 413, 429, 500]);
    }

    public function testFlappingAndIdempotencyThroughTheHandler(): void
    {
        $h = $this->handler();
        $b = UptimeKumaPayloadTest::body(0);
        self::assertSame('opened', $h->handle(self::good(), $b, '1.1.1.1')->body['action']);
        self::assertSame('duplicate', $h->handle(self::good(), $b, '1.1.1.1')->body['action']);
        self::assertCount(1, $this->gw->tickets);
    }
}
