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
        $map = (new InMemoryMonitorClientMap())->mapMonitor('7', MonitorTarget::client(3))->mapTag('client:acme', MonitorTarget::client(3))->mapTag('client:globex', MonitorTarget::client(4));
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
        self::assertSame(['ok' => true, 'action' => 'opened'], $r->body, 'never a ticket id (red team 2026-11-21 Chain A)');
        self::assertSame('{"ok":true,"action":"opened"}', $r->json());
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
        self::assertContains($r->status, [200, 400, 401, 403, 413, 429, 500]);
        self::assertStringNotContainsString('ticket_id', $j);
    }

    // ------------------------------------------------------------------ red team 2026-11-21 Chain A / NEW-1

    private const ACME = 'acme-kuma-secret-0123456789abcdef';
    private const GLOBEX = 'globex-kuma-secret-0123456789abcd';

    private function senders(): InboundAuthenticator
    {
        return new InboundAuthenticator([self::SECRET], ['acme' => ['secrets' => [self::ACME], 'clients' => [3]], 'globex' => ['secrets' => [self::GLOBEX], 'clients' => [4]]]);
    }

    public function testNoOutcomeEverAnswersATicketId(): void
    {
        $h = $this->handler();
        $answers = [
            $h->handle(self::good(), UptimeKumaPayloadTest::body(0), '1.1.1.1'),                                                                 // opened
            $h->handle(self::good(), UptimeKumaPayloadTest::body(0), '1.1.1.1'),                                                                 // duplicate
            $h->handle(self::good(), UptimeKumaPayloadTest::body(1, ['heartbeat' => ['time' => '2001-01-01 00:00:00']]), '1.1.1.1'),             // stale
            $h->handle(self::good(), UptimeKumaPayloadTest::body(1, ['heartbeat' => ['time' => '2026-11-13 10:05:00']]), '1.1.1.1'),             // resolved
        ];
        self::assertSame(['opened', 'duplicate', 'stale', 'resolved'], array_map(static fn (InboundResult $r): string => (string) $r->body['action'], $answers));
        foreach ($answers as $r) {
            self::assertSame(['ok', 'action'], array_keys($r->body));
        }
    }

    public function testEachSenderSecretIdentifiesItsSenderAndTheSharedOneStillWorks(): void
    {
        $auth = $this->senders();
        self::assertSame('acme', $auth->identify(['X-Webhook-Secret' => self::ACME])?->id);
        self::assertSame([3], $auth->identify(['Authorization' => 'Bearer ' . self::ACME])?->clientIds);
        self::assertSame('globex', $auth->identify(['X-Webhook-Secret' => self::GLOBEX])?->id);
        self::assertTrue($auth->identify(['X-Webhook-Secret' => self::SECRET])?->isShared());
        self::assertNull($auth->identify(['X-Webhook-Secret' => 'acme-kuma-secret-0123456789abcdeX']));
        self::assertNull($auth->identify(['X-Webhook-Secret' => self::ACME, 'Authorization' => 'Bearer ' . self::GLOBEX]), 'two senders at once: nobody');
        self::assertSame(['configured' => true, 'senders' => ['acme', 'globex']], $auth->__debugInfo());
        self::assertStringNotContainsString(self::ACME, print_r($auth, true));
    }

    public function testASecretConfiguredForTwoSendersAuthenticatesNobody(): void
    {
        $auth = new InboundAuthenticator([self::ACME], ['acme' => ['secrets' => [self::ACME]], 'other' => ['secrets' => [self::GLOBEX, self::GLOBEX]]]);
        self::assertNull($auth->identify(['X-Webhook-Secret' => self::ACME]), 'shared and per-sender at once is ambiguous');
        self::assertSame('other', $auth->identify(['X-Webhook-Secret' => self::GLOBEX])?->id, 'the same secret twice for one sender is fine');
        $this->expectException(\InvalidArgumentException::class);
        new InboundAuthenticator([], ['Bad Id' => ['secrets' => [self::ACME]]]);
    }

    public function testASenderOfOneClientIsRefusedAMonitorOfAnotherClientAnd403TouchesNothing(): void
    {
        $h = $this->handler($this->senders());
        // monitor 7 belongs to client 3 (Acme): Globex's own Kuma may not open a ticket for it, by id or by tag
        $r = $h->handle(['X-Webhook-Secret' => self::GLOBEX], UptimeKumaPayloadTest::body(0, ['monitor' => ['tags' => [['name' => 'env', 'value' => 'prod']]]]), '1.1.1.1');
        self::assertSame(403, $r->status);
        self::assertSame(['ok' => false, 'error' => 'out_of_scope'], $r->body);
        $r = $h->handle(['X-Webhook-Secret' => self::GLOBEX], UptimeKumaPayloadTest::body(0, ['heartbeat' => ['monitorID' => 9001], 'monitor' => ['id' => 9001, 'tags' => [['name' => 'client', 'value' => 'acme']]]]), '1.1.1.1');
        self::assertSame(403, $r->status, 'planting with the tag client:acme is refused');
        self::assertSame([], $this->gw->tickets);
        $r = $h->handle(['X-Webhook-Secret' => self::GLOBEX], UptimeKumaPayloadTest::body(0, ['heartbeat' => ['monitorID' => 9002], 'monitor' => ['id' => 9002, 'tags' => [['name' => 'env', 'value' => 'prod']]]]), '1.1.1.1');
        self::assertSame(200, $r->status, 'an unmapped monitor of a one-client sender goes to that client');
        self::assertSame(4, $this->gw->clientOf(500));
        self::assertCount(1, $this->gw->tickets);
        // Acme's own Kuma opens its monitor 7 for client 3
        self::assertSame('opened', $h->handle(['X-Webhook-Secret' => self::ACME], UptimeKumaPayloadTest::body(0), '1.1.1.1')->body['action']);
        self::assertSame(3, $this->gw->clientOf(501));
    }

    public function testAnotherSenderCannotResolveOrSilenceAMonitorItDoesNotOwn(): void
    {
        $h = $this->handler($this->senders());
        self::assertSame('opened', $h->handle(['X-Webhook-Secret' => self::ACME], UptimeKumaPayloadTest::body(0), '1.1.1.1')->body['action']);
        // Globex sends UP for "monitor 7", in the past and in the future: that is ITS monitor 7, which has no incident; Acme's ticket stays open
        foreach (['2001-01-01 00:00:00', gmdate('Y-m-d H:i:s', 1_800_100_000 + 290)] as $t) {
            $r = $h->handle(['X-Webhook-Secret' => self::GLOBEX], UptimeKumaPayloadTest::body(1, ['heartbeat' => ['time' => $t]]), '1.1.1.1');
            self::assertSame(['ok' => true, 'action' => 'unchanged'], $r->body);
        }
        self::assertTrue($this->gw->isStillOpen(500));
        self::assertNotNull($this->store->get('uptime-kuma', 'acme/7'));
        self::assertNotNull($this->store->get('uptime-kuma', 'globex/7'));
        self::assertNull($this->store->get('uptime-kuma', '7'), 'named senders never use the shared key');
        self::assertSame('uptime-kuma:acme/7', $this->gw->tickets[500]['request']->incidentKey);
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
