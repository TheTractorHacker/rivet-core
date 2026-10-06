<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Tests\Support\FakeDatabase;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Webhooks\WebhookDispatcher;
use RivetCore\Webhooks\WebhookSubscription;
use RivetCore\Webhooks\WebhookSubscriptionLookupInterface;
use RivetCore\Webhooks\WebhookSubscriptionsInterface;

final class WebhookFormatsDispatcherTest extends TestCase
{
    /** @var list<array{url:string,body:string,headers:list<string>,method:?string}> */
    private array $seen = [];

    private function dispatcher(string $url = 'https://h.example/a', array $subOptions = [], ?FakeDatabase $db = null): WebhookDispatcher
    {
        $subs = new class($url, $subOptions) implements WebhookSubscriptionsInterface, WebhookSubscriptionLookupInterface {
            public function __construct(private string $url, private array $options)
            {
            }

            public function forEvent(string $eventType): array
            {
                return [new WebhookSubscription(7, $this->url, 'sekret', $this->options)];
            }

            public function find(int $webhookId): ?WebhookSubscription
            {
                return new WebhookSubscription(7, $this->url, 'sekret', $this->options);
            }
        };

        return new WebhookDispatcher($db ?? new FakeDatabase(), $subs, new FixedClock(), ['X-Test'], function ($url, $body, $headers, $t, $target, $method = 'POST') {
            $this->seen[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'method' => $method];

            return ['status' => 200, 'body' => '', 'error' => null];
        });
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

    public function testDefaultBodyIsUnchangedGolden(): void
    {
        $this->dispatcher()->deliverTo(7, 'ticket.created', ['id' => 1, 'subject' => 'héllo/wörld'], 1, '2026-01-02T03:04:05Z', 1000);
        $s = $this->seen[0];
        self::assertSame('{"event":"ticket.created","timestamp":"2026-01-02T03:04:05Z","data":{"id":1,"subject":"héllo/wörld"}}', $s['body']);
        self::assertSame('application/json', self::header($s['headers'], 'Content-Type'));
        self::assertSame('POST', $s['method']);
        self::assertSame('sha256=' . hash_hmac('sha256', $s['body'], 'sekret'), self::header($s['headers'], 'X-Test-Signature'));
        self::assertSame(['Content-Type: application/json', 'X-Rivet-Timestamp: 1000'], array_slice($s['headers'], 0, 2));
        self::assertSame('t=1000,v1=' . hash_hmac('sha256', '1000.' . $s['body'], 'sekret'), self::header($s['headers'], 'X-Rivet-Signature-V2'));
        self::assertCount(5, $s['headers']);
    }

    public function testExplicitJsonFormatEqualsDefaultBody(): void
    {
        $d = $this->dispatcher();
        $d->deliverTo(7, 'ticket.created', ['id' => 1], 1, '2026-01-02T03:04:05Z', 1000);
        $d->deliverTo(7, 'ticket.created', ['id' => 1], 1, '2026-01-02T03:04:05Z', 1000, ['format' => 'json']);
        self::assertSame($this->seen[0]['body'], $this->seen[1]['body']);
        self::assertSame($this->seen[0]['headers'], $this->seen[1]['headers']);
    }

    public function testFormatBodyContentTypeAndSignatureOverSentBytes(): void
    {
        $this->dispatcher()->deliverTo(7, 'ticket.created', ['ticket_number' => 'T1', 'ticket_subject' => 'Hi'], 1, '2026-01-02T03:04:05Z', 1000, ['format' => 'slack']);
        $s = $this->seen[0];
        self::assertSame('application/json; charset=utf-8', self::header($s['headers'], 'Content-Type'));
        $j = json_decode($s['body'], true);
        self::assertSame('New ticket', $j['blocks'][0]['text']['text']);
        self::assertSame('sha256=' . hash_hmac('sha256', $s['body'], 'sekret'), self::header($s['headers'], 'X-Test-Signature'));
        self::assertSame('t=1000,v1=' . hash_hmac('sha256', '1000.' . $s['body'], 'sekret'), self::header($s['headers'], 'X-Rivet-Signature-V2'));
    }

    public function testFormatterHeadersAndExtraHeadersAreSent(): void
    {
        $this->dispatcher()->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z', 1000, [
            'format' => 'ntfy', 'extraHeaders' => ['Authorization' => 'Bearer tk_1', 'priority' => '1', 'X-Custom' => 'yes'],
        ]);
        $h = $this->seen[0]['headers'];
        self::assertSame('text/plain; charset=utf-8', self::header($h, 'Content-Type'));
        self::assertSame('Bearer tk_1', self::header($h, 'Authorization'));
        self::assertSame('yes', self::header($h, 'X-Custom'));
        self::assertSame('New ticket', self::header($h, 'Title'));
        self::assertSame('1', self::header($h, 'priority'), 'configured header wins over the formatter one, case-insensitively');
        self::assertNull(self::header($h, 'Priority'));
    }

    public function testSubscriptionOptionsAreUsedByDeliverAndDeliverTo(): void
    {
        $d = $this->dispatcher('https://h.example/a', ['format' => 'gotify']);
        $d->deliver('ticket.created', ['ticket_subject' => 'x']);
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z');
        foreach ($this->seen as $s) {
            self::assertArrayHasKey('title', json_decode($s['body'], true));
        }
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z', null, ['format' => 'json']);
        self::assertArrayHasKey('event', json_decode($this->seen[2]['body'], true), 'call options override subscription options');
    }

    public function testTemplateFormatThroughDispatcher(): void
    {
        $this->dispatcher()->deliverTo(7, 'ticket.created', ['ticket_id' => 9], 1, '2026-01-02T03:04:05Z', 1000, ['format' => 'template', 'template' => '{"id":{{data.ticket_id|json}}}']);
        self::assertSame('{"id":9}', $this->seen[0]['body']);
    }

    public function testPutMethodAndTxnPlaceholder(): void
    {
        $d = $this->dispatcher('https://m.example/_matrix/client/v3/rooms/%21r:e/send/m.room.message/{txn}');
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z', 1000, ['format' => 'matrix', 'method' => 'put']);
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 2, '2026-01-02T03:04:05Z', 2000, ['format' => 'matrix', 'method' => 'PUT']);
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'y'], 1, '2026-01-02T03:04:05Z', 2000, ['format' => 'matrix', 'method' => 'PUT']);
        self::assertSame('PUT', $this->seen[0]['method']);
        self::assertStringNotContainsString('{txn}', $this->seen[0]['url']);
        self::assertSame($this->seen[0]['url'], $this->seen[1]['url'], 'a retry of the same message keeps its transaction id');
        self::assertNotSame($this->seen[0]['url'], $this->seen[2]['url']);
        self::assertMatchesRegularExpression('#/send/m\.room\.message/[0-9a-f]{32}$#', $this->seen[0]['url']);
    }

    public function testCurlOptionsUsePutOnlyWhenAsked(): void
    {
        $o = WebhookDispatcher::curlOptions('b', ['A: b'], 5, null, 'PUT');
        self::assertSame('PUT', $o[CURLOPT_CUSTOMREQUEST]);
        self::assertTrue($o[CURLOPT_POST]);
        self::assertFalse($o[CURLOPT_FOLLOWLOCATION]);
        self::assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, WebhookDispatcher::curlOptions('b', [], 5));
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function rejectedOptions(): iterable
    {
        yield 'method GET' => [['format' => 'json', 'method' => 'GET']];
        yield 'method DELETE' => [['method' => 'DELETE']];
        yield 'method injection' => [['method' => "POST\r\nX: y"]];
        yield 'unknown format' => [['format' => 'carrier-pigeon']];
        yield 'telegram without chat' => [['format' => 'telegram']];
        yield 'bad template' => [['format' => 'template', 'template' => '{{ system("id") }}']];
        yield 'crlf in value' => [['extraHeaders' => ['X-A' => "v\r\nX-Evil: 1"]]];
        yield 'crlf in name' => [['extraHeaders' => ["X-A\r\nX-Evil" => 'v']]];
        yield 'nul in value' => [['extraHeaders' => ['X-A' => "v\0"]]];
        yield 'space in name' => [['extraHeaders' => ['X A' => 'v']]];
        yield 'host' => [['extraHeaders' => ['Host' => 'evil.example']]];
        yield 'content-length' => [['extraHeaders' => ['content-length' => '0']]];
        yield 'transfer-encoding' => [['extraHeaders' => ['Transfer-Encoding' => 'chunked']]];
        yield 'connection' => [['extraHeaders' => ['Connection' => 'close']]];
        yield 'content-type' => [['extraHeaders' => ['Content-Type' => 'text/html']]];
        yield 'timestamp' => [['extraHeaders' => ['X-Rivet-Timestamp' => '1']]];
        yield 'v2 signature' => [['extraHeaders' => ['X-Rivet-Signature-V2' => 't=1,v1=x']]];
        yield 'prefix signature' => [['extraHeaders' => ['X-Test-Signature' => 'sha256=x']]];
        yield 'prefix event' => [['extraHeaders' => ['x-test-event' => 'x']]];
        yield 'not an array' => [['extraHeaders' => 'X-A: b']];
        yield 'non scalar value' => [['extraHeaders' => ['X-A' => ['b']]]];
    }

    /** @param array<string,mixed> $options */
    #[\PHPUnit\Framework\Attributes\DataProvider('rejectedOptions')]
    public function testBadOptionsFailTheAttemptWithoutContactingTheEndpoint(array $options): void
    {
        $db = new FakeDatabase();
        $r = $this->dispatcher('https://h.example/a', [], $db)->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z', 1000, $options);
        self::assertSame([], $this->seen);
        self::assertFalse($r['ok']);
        self::assertNull($r['http_status']);
        self::assertNotNull($r['error']);
        self::assertStringContainsString('INSERT INTO webhook_deliveries', $db->calls[0]['sql']);
    }

    public function testBadSubscriptionOptionsAlsoFailDeliver(): void
    {
        $r = $this->dispatcher('https://h.example/a', ['extraHeaders' => ['Host' => 'evil.example']])->deliver('ticket.created', []);
        self::assertSame([], $this->seen);
        self::assertFalse($r[0]['ok']);
    }

    public function testRetriesOfAFormattedEventKeepBodyAndLegacySignature(): void
    {
        $d = $this->dispatcher();
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 1, '2026-01-02T03:04:05Z', 1000, ['format' => 'discord']);
        $d->deliverTo(7, 'ticket.created', ['ticket_subject' => 'x'], 2, '2026-01-02T03:04:05Z', 2000, ['format' => 'discord']);
        self::assertSame($this->seen[0]['body'], $this->seen[1]['body']);
        self::assertSame(self::header($this->seen[0]['headers'], 'X-Test-Signature'), self::header($this->seen[1]['headers'], 'X-Test-Signature'));
        self::assertNotSame(self::header($this->seen[0]['headers'], 'X-Rivet-Signature-V2'), self::header($this->seen[1]['headers'], 'X-Rivet-Signature-V2'));
    }
}
