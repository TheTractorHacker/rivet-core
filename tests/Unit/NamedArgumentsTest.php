<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RivetCore\Audit\AuditService;
use RivetCore\Mcp\ToolPipeline;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisConnectionConfig;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\TestRedis;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** The long constructors are frozen with "named arguments are the supported calling style" (docs/api-freeze-review.md). */
final class NamedArgumentsTest extends TestCase
{
    public function testRedisConnectionConfigNamedArguments(): void
    {
        $c = new RedisConnectionConfig(host: 'redis.internal', db: 3, tls: true, verifyPeer: false, username: 'app', password: 'pw');
        self::assertSame('redis.internal', $c->host);
        self::assertSame(6379, $c->port);
        self::assertSame(3, $c->db);
        self::assertTrue($c->tls);
        self::assertFalse($c->verifyPeer);
        self::assertSame('app', $c->username);
        self::assertNull($c->caFile);
        self::assertSame($c->host, (new RedisConnectionConfig('redis.internal', 6379, 3, 'pw', 'app', true, false))->host, 'positional order still works');
    }

    public function testWebhookDispatcherNamedArguments(): void
    {
        $subs = new class implements WebhookSubscriptionsInterface {
            public function forEvent(string $eventType): array
            {
                return [];
            }
        };
        $d = new WebhookDispatcher(
            database: new FakeDatabase(),
            subscriptions: $subs,
            clock: new FixedClock(),
            headerPrefixes: ['X-Test'],
            timeoutSeconds: 3,
            urlPolicy: new UrlPolicy(),
            requireUrlPolicy: true,
        );
        self::assertSame([], $d->deliver('ticket.created', []));
    }

    public function testToolPipelineNamedArgumentsAcceptPsrLogger(): void
    {
        $p = new ToolPipeline(
            rateLimiter: new RateLimiter(new TestRedis(null, true), 'p:'),
            audit: new AuditService(new FakeDatabase(), new NullRequestContext()),
            request: new NullRequestContext(),
            rateLimit: 5,
            source: 'mcp',
            logError: new NullLogger(),
        );
        $r = $p->run(7, 'tool', [], fn () => true, fn () => ['ok' => true]);
        self::assertTrue($r['success']);
    }
}
