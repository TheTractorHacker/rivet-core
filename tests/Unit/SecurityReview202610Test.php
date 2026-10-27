<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Automation\AutomationRuleStore;
use RivetCore\Cron\JobRunner;
use RivetCore\Mcp\ToolPipeline;
use RivetCore\Redis\RateLimiter;
use RivetCore\Redis\RedisConnectionConfig;
use RivetCore\Support\ErrorLogLogger;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\TestRedis;
use RivetCore\Webhooks\Authentication;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\EventSummary;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/**
 * Regression tests for the second security review (docs/security/review-2026-10.md). One test per finding that was fixed.
 */
final class SecurityReview202610Test extends TestCase
{
    /** @var resource|null */
    private $server = null;
    private string $routerFile = '';

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            $status = proc_get_status($this->server);
            if ($status['running'] && $status['pid'] > 0) {
                // php -S is started through "exec", so this is the server itself
                @posix_kill($status['pid'], 9);
            }
            proc_close($this->server);
            $this->server = null;
        }
        if ($this->routerFile !== '') {
            @unlink($this->routerFile);
            $this->routerFile = '';
        }
    }

    // ---- SR-01: header names ending in a newline slipped through "$" -------------------------------------------

    public function testHeaderNameWithTrailingNewlineIsRejected(): void
    {
        self::assertTrue(Authentication::isValidHeaderName('X-Api-Key'));
        self::assertFalse(Authentication::isValidHeaderName("X-Api-Key\n"));
        self::assertFalse(Authentication::isValidHeaderName("X-Api-Key\r\n"));
        // the forbidden-name list cannot be dodged with a trailing newline either
        self::assertNotSame([], Authentication::validate(['mode' => 'header', 'header_name' => "X-Rivet-Signature\n", 'header_value' => 'v']));
        self::assertNotSame([], Authentication::validate(['mode' => 'header', 'header_name' => "Host\n", 'header_value' => 'v']));
    }

    // ---- SR-02: same "$" pattern in other validators ------------------------------------------------------------

    public function testTrailingNewlineIsRejectedByUrlAndNameValidators(): void
    {
        self::assertFalse(EventSummary::isSafeUrl("https://example.com/x\n"));
        self::assertTrue(EventSummary::isSafeUrl('https://example.com/x'));

        $generic = Destinations::get('generic-json');
        self::assertNotNull($generic);
        self::assertTrue($generic->urlMatches('https://example.com/hook'));
        self::assertFalse($generic->urlMatches("https://example.com/hook\n"));
        $slack = Destinations::get('slack');
        self::assertNotNull($slack);
        self::assertFalse($slack->urlMatches("https://hooks.slack.com/services/T0/B0/xyz\n"));

        $bad = new RedisConnectionConfig('redis.example', 6379, 0, 'pw', "user\n");
        self::assertNotNull($bad->validate());
        self::assertNotNull((new RedisConnectionConfig("redis.example\n"))->validate());
        self::assertNull((new RedisConnectionConfig('redis.example'))->validate());
    }

    public function testCronArgumentsAndPhpBinaryMustMatchExactly(): void
    {
        $root = sys_get_temp_dir() . '/rc-sec-' . bin2hex(random_bytes(4));
        mkdir($root . '/cron', 0700, true);
        file_put_contents($root . '/cron/job.php', '<?php echo 1;');
        try {
            $runner = new JobRunner($root, $root . '/state');
            self::assertSame(['ok' => false, 'message' => 'Unexpected argument.'], $runner->start('k', $root . '/cron/job.php', ["--a=b\n"]));
            self::assertSame(['ok' => false, 'message' => 'Unexpected PHP binary.'], $runner->start('k', $root . '/cron/job.php', [], "/usr/bin/php\n"));
        } finally {
            @unlink($root . '/cron/job.php');
            @rmdir($root . '/cron');
            @rmdir($root . '/state');
            @rmdir($root);
        }
    }

    public function testAutomationWebhookUrlMustNotCarryCredentialsOrWhitespace(): void
    {
        $store = new AutomationRuleStore(new FakeDatabase());
        foreach (['http://user:pw@example.com/h', "https://example.com/\x01h", 'https://example.com/\\@evil', 'https://exa mple.com/'] as $url) {
            try {
                $store->save(null, 'r', 'ticket.created', [], 'send_webhook', ['url' => $url], true);
                self::fail("accepted $url");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(1, $store->save(null, 'r', 'ticket.created', [], 'send_webhook', ['url' => 'https://example.com/h'], true));
    }

    // ---- SR-03: event header CRLF; SR-04: response body is capped ----------------------------------------------

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

    public function testEventNameCannotInjectHeaders(): void
    {
        $seen = [];
        $d = new WebhookDispatcher(new FakeDatabase(), self::subs('https://h.example/a'), new FixedClock(), ['X-Test'], function ($url, $body, $headers) use (&$seen) {
            $seen = $headers;

            return ['status' => 200, 'body' => '', 'error' => null];
        });
        $d->deliverTo(7, "ticket.created\r\nX-Injected: 1", ['id' => 1]);
        self::assertContains('X-Test-Event: ticket.createdX-Injected: 1', $seen);
        foreach ($seen as $line) {
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $line);
        }
    }

    public function testHostileReceiverCannotExhaustMemoryOrHoldTheRequest(): void
    {
        if (!function_exists('posix_kill') || !function_exists('curl_init')) {
            self::markTestSkipped('needs posix and curl');
        }
        $probe = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($probe === false) {
            self::markTestSkipped('cannot open a local port');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $this->routerFile = sys_get_temp_dir() . '/rc-router-' . bin2hex(random_bytes(4)) . '.php';
        // 160 MB of response, 64 KB at a time; far beyond what the dispatcher may keep
        file_put_contents($this->routerFile, '<?php header("Content-Type: text/plain"); for ($i = 0; $i < 2560; $i++) { echo str_repeat("A", 65536); flush(); }');
        $this->server = proc_open(
            ['/bin/sh', '-c', 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg($this->routerFile)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($this->server);
        $up = false;
        for ($i = 0; $i < 50 && !$up; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $e, $s, 0.2);
            if ($c !== false) {
                fclose($c);
                $up = true;
            } else {
                usleep(100000);
            }
        }
        if (!$up) {
            self::markTestSkipped('could not start the local test server');
        }

        $db = new FakeDatabase();
        $d = new WebhookDispatcher($db, self::subs('http://127.0.0.1:' . $port . '/'), new FixedClock(), ['X-Test'], null, 20);
        $before = memory_get_peak_usage(true);
        $start = microtime(true);
        $r = $d->deliverTo(7, 'ticket.created', ['id' => 1]);
        $elapsed = microtime(true) - $start;

        self::assertTrue($r['ok'], (string) $r['error']);
        self::assertSame(200, $r['http_status']);
        self::assertLessThan(16 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'the response must not be buffered');
        self::assertLessThan(15.0, $elapsed);
        $logged = $db->calls[0]['params'][6];
        self::assertSame(1000, strlen((string) $logged));
    }

    // ---- SR-05..07: audit ---------------------------------------------------------------------------------------

    public function testAuditFanOutNeverSeesSecretsAndDeepSecretsAreNotStored(): void
    {
        $db = new FakeDatabase();
        $seen = null;
        $svc = new AuditService($db, new NullRequestContext(), function (...$args) use (&$seen): void {
            $seen = $args[6];
        });
        $deep = ['password' => 'hunter2'];
        for ($i = 0; $i < 12; $i++) {
            $deep = ['n' => $deep];
        }
        $svc->log('x.y', 1, null, null, 'a', null, ['token' => 'abc', 'ok' => 1, 'deep' => $deep]);

        self::assertSame('[redacted]', $seen['token']);
        self::assertSame(1, $seen['ok']);
        $stored = (string) $db->calls[0]['params'][6];
        self::assertStringNotContainsString('hunter2', $stored);
        self::assertStringNotContainsString('abc', $stored);
        self::assertStringNotContainsString('hunter2', json_encode($seen, JSON_THROW_ON_ERROR));
    }

    public function testCompoundSecretKeysAreRedactedButHarmlessOnesAreNot(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, new NullRequestContext()))->log('x', 1, null, null, 'a', null, [
            'db_password' => 'p1', 'X-Api-Key' => 'k1', 'Client Secret' => 's1', 'csrf_token' => 't1', 'Authorization' => 'Bearer z',
            'password_changed' => true, 'token_count' => 3, 'passed' => 2, 'rows' => 4,
        ]);
        $m = json_decode((string) $db->calls[0]['params'][6], true, 512, JSON_THROW_ON_ERROR);
        foreach (['db_password', 'X-Api-Key', 'Client Secret', 'csrf_token', 'Authorization'] as $k) {
            self::assertSame('[redacted]', $m[$k], $k);
        }
        self::assertSame(['password_changed' => true, 'token_count' => 3, 'passed' => 2, 'rows' => 4], array_intersect_key($m, array_flip(['password_changed', 'token_count', 'passed', 'rows'])));
    }

    public function testOversizedMetadataStillRecordsTheEvent(): void
    {
        $db = new FakeDatabase();
        (new AuditService($db, new NullRequestContext()))->log('mcp.tool_call', 5, 'mcp_tool', 'search', 'read', 'MCP search: ok', ['args' => ['q' => str_repeat('x', 200000)], 'tool' => 'search']);
        $json = (string) $db->calls[0]['params'][6];
        self::assertLessThan(65535, strlen($json));
        $m = json_decode($json, true);
        self::assertTrue($m['_truncated']);
        self::assertSame('search', $m['tool']);
        self::assertStringStartsWith('[truncated:', $m['args']);
        self::assertSame('mcp.tool_call', $db->calls[0]['params'][0]);
    }

    public function testAuditedToolCallWithHugeArgumentsIsStillRecorded(): void
    {
        $db = new FakeDatabase();
        $pipeline = new ToolPipeline(new RateLimiter(new TestRedis(null, true), 'p:'), new AuditService($db, new NullRequestContext()), new NullRequestContext());
        $r = $pipeline->run(9, 'list_tickets', ['filter' => ['x' => str_repeat('y', 500000)]], static fn (int $u): bool => true, static fn (int $u): array => [1, 2]);
        self::assertTrue($r['success']);
        self::assertCount(1, $db->calls);
        self::assertStringContainsString('"outcome":"ok"', (string) $db->calls[0]['params'][6]);
    }

    // ---- SR-08: a permission check that throws is a denial ------------------------------------------------------

    public function testPermissionCheckThatThrowsFailsClosed(): void
    {
        $db = new FakeDatabase();
        $ran = false;
        $logged = [];
        $pipeline = new ToolPipeline(
            new RateLimiter(new TestRedis(null, true), 'p:'),
            new AuditService($db, new NullRequestContext()),
            new NullRequestContext(),
            60,
            60,
            'mcp',
            function (string $m) use (&$logged): void {
                $logged[] = $m;
            }
        );
        $r = $pipeline->run(9, 'list_tickets', [], static function (int $u): bool {
            throw new \RuntimeException("db down\nforged: line");
        }, function (int $u) use (&$ran): array {
            $ran = true;

            return [];
        });
        self::assertFalse($r['success']);
        self::assertFalse($ran, 'the tool body must not run');
        self::assertSame('PERMISSION_DENIED', $r['errors'][0]['code']);
        self::assertStringContainsString('"outcome":"denied"', (string) $db->calls[0]['params'][6]);
        self::assertNotSame([], $logged);
        self::assertStringNotContainsString("\n", $logged[0], 'log lines must not be forgeable');
    }

    // ---- SR-09: log injection -----------------------------------------------------------------------------------

    public function testLogMessagesAreSingleLine(): void
    {
        self::assertSame('a\nb\r\x01c', ErrorLogLogger::oneLine("a\nb\r\x01c"));
        self::assertSame("tab\tkept", ErrorLogLogger::oneLine("tab\tkept"));
        $got = '';
        $logger = ErrorLogLogger::resolve(function (string $m) use (&$got): void {
            $got = $m;
        });
        $logger->error("first\nSECOND");
        self::assertSame('first\nSECOND', $got);
    }
}
