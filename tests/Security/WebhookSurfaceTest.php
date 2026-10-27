<?php

declare(strict_types=1);

namespace RivetCore\Tests\Security;

use RivetCore\Tests\Support\FixedClock;
use RivetCore\Webhooks\Destinations;
use RivetCore\Webhooks\UrlPolicy;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

/** Webhook surface: RC-SR2-04, -05, -06, -19 plus guard tests for controls verified in the review (no finding). */
final class WebhookSurfaceTest extends SecurityTestCase
{
    private function subs(string $url): WebhookSubscriptionsInterface
    {
        return new class ($url) implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
            public function __construct(private string $url)
            {
            }

            public function forEvent(string $eventType): array
            {
                return [$this->find(1)];
            }

            public function find(int $webhookId): ?WebhookSubscription
            {
                return new WebhookSubscription(1, $this->url, 'whsec_test_secret');
            }
        };
    }

    /**
     * Documents RC-SR2-04: the curl transport reads the whole response into memory (CURLOPT_RETURNTRANSFER) and sets no
     * size cap (no CURLOPT_MAXFILESIZE / write callback), although only 1000 bytes are ever logged. A receiver that
     * answers with a huge body is bounded only by the 10 s timeout and memory_limit (a fatal error breaks "never
     * throws"). Flip: assert the options contain a size cap and peak memory stays near the baseline.
     */
    public function testRc04ResponseBodyIsBufferedWithoutACap(): void
    {
        $options = WebhookDispatcher::curlOptions('{}', [], 10);
        $this->assertArrayNotHasKey(CURLOPT_MAXFILESIZE, $options);
        $this->assertArrayNotHasKey(CURLOPT_WRITEFUNCTION, $options);
        $this->assertTrue($options[CURLOPT_RETURNTRANSFER]);

        $router = $this->tmpFile('big-router.php');
        file_put_contents($router, '<?php header("Content-Type: text/plain"); echo str_repeat("A", 24 * 1024 * 1024);');
        [$port, $proc] = $this->startServer($router);
        try {
            $db = new class implements \RivetCore\Database\DatabaseInterface {
                /** @var list<array<mixed>> */
                public array $inserts = [];

                public function fetchOne(string $sql, array $params = []): ?array
                {
                    return null;
                }

                public function fetchAll(string $sql, array $params = []): array
                {
                    return [];
                }

                public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
                {
                    $this->inserts[] = $params;

                    return new \RivetCore\Database\ExecutionResult(1, 1);
                }

                public function transaction(callable $callback): mixed
                {
                    return $callback();
                }
            };
            $d = new WebhookDispatcher($db, $this->subs("http://127.0.0.1:$port/hook"), new FixedClock(), ['X-T']);
            memory_reset_peak_usage();
            $before = memory_get_peak_usage();
            $d->deliver('ticket.created', ['id' => 1]);
            $growth = memory_get_peak_usage() - $before;
        } finally {
            $this->stopServer($proc);
            @unlink($router);
        }
        $this->assertGreaterThan(20 * 1024 * 1024, $growth, 'the whole 24 MB body was held in memory');
        $this->assertSame(1000, strlen((string) $db->inserts[0][6]), 'only 1000 bytes are logged');
    }

    /**
     * Documents RC-SR2-05: response_body_snippet is cut with byte-based substr() and inserted into a utf8mb4 column;
     * a body that is not valid UTF-8 (or is cut inside a multi-byte character) makes the INSERT fail under strict SQL
     * mode, and logAttempt() swallows the error, so the delivery attempt leaves NO row. A receiver can therefore erase
     * its own delivery record. Flip: assert one row per attempt (mb_scrub + mb_strcut before storing).
     */
    public function testRc05InvalidUtf8ResponseDropsTheDeliveryLogRow(): void
    {
        $db = $this->db('webhook_deliveries');
        foreach (["\xff\xfe binary", str_repeat('a', 999) . "\xc3\xa9", 'plain ok body'] as $body) {
            $d = new WebhookDispatcher($db, $this->subs('http://example.test/h'), new FixedClock(), ['X-T'], fn () => ['status' => 200, 'body' => $body, 'error' => null]);
            $r = $d->deliver('ticket.created', ['id' => 1]);
            $this->assertTrue($r[0]['ok'], 'the delivery itself succeeded');
        }
        $this->assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) AS c FROM webhook_deliveries')['c'], 'two of the three attempts were never logged');
    }

    /**
     * Documents RC-SR2-06: Destination::urlMatches() ends its patterns with `$`, which in PCRE also matches before a
     * trailing newline, so "https://api.telegram.org/bot1:abc/sendMessage\n" passes the platform check. UrlPolicy::vet()
     * rejects control characters at send time (guarded below), so this is defence in depth only. Flip: assert false
     * (use \z or the D modifier).
     */
    public function testRc06UrlPatternAcceptsTrailingNewline(): void
    {
        $telegram = Destinations::get('telegram');
        $this->assertNotNull($telegram);
        $this->assertTrue($telegram->urlMatches("https://api.telegram.org/bot1:abc/sendMessage\n"));
        $this->assertTrue($telegram->urlMatches('https://api.telegram.org/bot1:abc/sendMessage'));
        $this->assertNull((new UrlPolicy())->vet("https://api.telegram.org/bot1:abc/sendMessage\n"), 'the send-time policy still refuses it');
    }

    /**
     * Documents RC-SR2-19 (INFO): UrlPolicy has no port allow-list (any port on a public host, or on a host inside an
     * admin-listed network, is reachable) and its special-range tables lack a few IANA entries (e.g. 3fff::/20, the
     * documentation range from RFC 9637). Not exploitable beyond what the admin who configures webhooks can already do.
     */
    public function testRc19UrlPolicyHasNoPortRestriction(): void
    {
        $this->assertNotNull((new UrlPolicy())->vet('http://1.1.1.1:6379/'));
        $this->assertNotNull((new UrlPolicy(false, null, ['10.0.0.0/8']))->vet('http://10.1.2.3:6379/'));
        $this->assertNotNull((new UrlPolicy())->vet('http://[3fff::1]/'));
        $this->assertNull((new UrlPolicy(false, null, ['10.0.0.0/8']))->vet('http://127.0.0.1:6379/'), 'loopback stays unreachable');
        $this->assertNull((new UrlPolicy(false, null, ['10.0.0.0/8']))->vet('http://169.254.169.254/'), 'metadata stays unreachable');
    }

    /** Guard (no finding): numeric host spellings that resolve to loopback are rejected, and so is userinfo trickery. */
    public function testGuardNumericLoopbackSpellingsAreRejected(): void
    {
        $p = new UrlPolicy();
        foreach (['http://0x7f.1/', 'http://2130706433/', 'http://017700000001/', 'http://127.1/', 'http://[::ffff:7f00:1]/', 'http://localhost/', 'http://example.com@127.0.0.1/'] as $url) {
            $this->assertNull($p->vet($url), $url);
        }
    }

    /**
     * Guard (no finding): redirects are never followed. A local server answers 302 to a second path on itself that
     * would record a hit; the hit file must not appear and the attempt is reported as failed HTTP 302.
     */
    public function testGuardRedirectIsNotFollowed(): void
    {
        $hit = $this->tmpFile('redirect-hit.txt');
        @unlink($hit);
        $router = $this->tmpFile('redirect-router.php');
        file_put_contents($router, '<?php if ($_SERVER["REQUEST_URI"] === "/internal") { file_put_contents(' . var_export($hit, true) . ', "hit"); echo "internal"; return; } header("Location: http://127.0.0.1:" . $_SERVER["SERVER_PORT"] . "/internal", true, 302);');
        [$port, $proc] = $this->startServer($router);
        try {
            $db = $this->db('webhook_deliveries');
            $d = new WebhookDispatcher($db, $this->subs("http://127.0.0.1:$port/hook"), new FixedClock(), ['X-T']);
            $r = $d->deliver('ticket.created', ['id' => 1]);
        } finally {
            $this->stopServer($proc);
            @unlink($router);
        }
        $this->assertSame(302, $r[0]['http_status']);
        $this->assertFalse($r[0]['ok']);
        $this->assertFileDoesNotExist($hit);
        $o = WebhookDispatcher::curlOptions('{}', [], 10);
        $this->assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(0, $o[CURLOPT_MAXREDIRS]);
        $this->assertSame(CURLPROTO_HTTP | CURLPROTO_HTTPS, $o[CURLOPT_PROTOCOLS]);
    }

    /**
     * Guard (no finding): a URL carrying a query token and a signing secret never reach webhook_deliveries; a failed
     * connection logs curl's generic error text only.
     */
    public function testGuardSecretsAreNotWrittenToTheDeliveryLog(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strlen('127.0.0.1:'));
        fclose($sock); // nothing listens: connection refused
        $db = $this->db('webhook_deliveries');
        $d = new WebhookDispatcher($db, $this->subs("http://127.0.0.1:$port/hook/PATHSECRET?token=QUERYSECRET"), new FixedClock(), ['X-T']);
        $r = $d->deliver('ticket.created', ['id' => 1]);
        $this->assertFalse($r[0]['ok']);
        $row = $db->fetchOne('SELECT * FROM webhook_deliveries');
        $this->assertNotNull($row);
        $dump = json_encode($row);
        foreach (['QUERYSECRET', 'PATHSECRET', 'whsec_test_secret'] as $secret) {
            $this->assertStringNotContainsString($secret, (string) $dump);
            $this->assertStringNotContainsString($secret, (string) $r[0]['error']);
        }
    }

    /** Guard (no finding): DNS rebinding. The policy resolves once; the transport is pinned to exactly those addresses. */
    public function testGuardPinnedTargetIsTheVettedAnswer(): void
    {
        $answers = [['93.184.216.34'], ['127.0.0.1']];
        $policy = new UrlPolicy(false, function (string $host) use (&$answers): array {
            return array_shift($answers) ?? [];
        });
        $seen = null;
        $d = new WebhookDispatcher(
            new class implements \RivetCore\Database\DatabaseInterface {
                public function fetchOne(string $sql, array $params = []): ?array
                {
                    return null;
                }

                public function fetchAll(string $sql, array $params = []): array
                {
                    return [];
                }

                public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
                {
                    return new \RivetCore\Database\ExecutionResult(1, 1);
                }

                public function transaction(callable $callback): mixed
                {
                    return $callback();
                }
            },
            $this->subs('https://hooks.example.test/in'),
            new FixedClock(),
            ['X-T'],
            function (string $url, string $body, array $headers, int $timeout, ?array $target = null) use (&$seen): array {
                $seen = $target;

                return ['status' => 200, 'body' => 'ok', 'error' => null];
            },
            10,
            $policy
        );
        $d->deliver('ticket.created', ['id' => 1]);
        $this->assertSame(['93.184.216.34'], $seen['ips'] ?? null, 'connect target is the vetted (public) address, never the second (loopback) answer');
        $o = WebhookDispatcher::curlOptions('{}', [], 10, $seen);
        $this->assertSame(['hooks.example.test:443:93.184.216.34'], $o[CURLOPT_RESOLVE]);
        $this->assertSame('', $o[CURLOPT_PROXY]);
    }
}
