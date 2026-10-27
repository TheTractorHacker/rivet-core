<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Http\ApiError;
use RivetCore\Rmm\Http\RmmFileBody;
use RivetCore\Rmm\Http\RmmRequest;
use RivetCore\Rmm\Http\RmmResponse;
use RivetCore\Rmm\Http\SapiEmitter;
use RivetCore\Rmm\Support\NullRmmMetricSink;

final class HttpObjectsTest extends TestCase
{
    /** @var list<string> */
    private array $sent = [];

    private function emitter(): SapiEmitter
    {
        $this->sent = [];

        return new SapiEmitter(
            function (int $s): void {
                $this->sent[] = "status $s";
            },
            function (string $h): void {
                $this->sent[] = $h;
            },
            function (string $b): void {
                $this->sent[] = 'body:' . $b;
            }
        );
    }

    public function testErrorResponseHasTheFrozenJsonShape(): void
    {
        $r = RmmResponse::error(new ApiError(429, 'rate_limited', 'Too many requests.', ['Retry-After' => '60']));
        $this->assertSame(429, $r->status);
        $this->assertSame('{"error":"Too many requests.","code":"rate_limited"}', $r->body);
        $this->assertSame('no-store', $r->headers['Cache-Control']);
        $this->assertSame('application/json', $r->headers['Content-Type']);
        $this->assertSame('60', $r->headers['Retry-After']);
    }

    public function testJsonUsesTheFrozenFlags(): void
    {
        $r = RmmResponse::json(200, ['url' => 'https://x/y', 'name' => "Zoë", 'f' => 1.0]);
        $this->assertSame('{"url":"https://x/y","name":"Zoë","f":1.0}', $r->body);
    }

    public function testRequestHeaderLookupIsCaseInsensitiveOnTheName(): void
    {
        $req = new RmmRequest('POST', 'agent_checkin', [], [], ['authorization' => 'Bearer x'], '203.0.113.1', null, true, null, null);
        $this->assertSame('Bearer x', $req->header('Authorization'));
        $this->assertNull($req->header('x-none'));
    }

    public function testEmitterSendsStatusHeadersAndBody(): void
    {
        $this->emitter()->emit(RmmResponse::json(201, ['ok' => true]));
        $this->assertSame(['status 201', 'Content-Type: application/json', 'Cache-Control: no-store', 'body:{"ok":true}'], $this->sent);
    }

    public function testEmitterStreamsAFileWithTrailerAfterVerifyingIt(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rmmf');
        $data = random_bytes(200_000);
        file_put_contents($path, $data);
        try {
            $e = $this->emitter();
            $e->emit(new RmmResponse(200, ['Content-Type' => 'application/octet-stream'], null, new RmmFileBody($path, 200_000, 'TRAILER', hash('sha256', $data))));
            $this->assertContains('Content-Length: 200007', $this->sent);
            $body = '';
            foreach ($this->sent as $line) {
                if (str_starts_with($line, 'body:')) {
                    $body .= substr($line, 5);
                }
            }
            $this->assertSame($data . 'TRAILER', $body);
            $this->assertGreaterThan(3, count(array_filter($this->sent, static fn (string $l): bool => str_starts_with($l, 'body:'))), 'streamed in chunks');
        } finally {
            unlink($path);
        }
    }

    public function testEmitterRefusesAFileWhoseSizeOrDigestDoesNotMatch(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'rmmf');
        file_put_contents($path, 'abcdef');
        $prev = ini_set('error_log', '/dev/null');
        try {
            foreach ([new RmmFileBody($path, 5), new RmmFileBody($path, 6, null, str_repeat('0', 64))] as $bad) {
                $this->emitter()->emit(new RmmResponse(200, [], null, $bad));
                $this->assertSame('status 500', $this->sent[0]);
                $this->assertContains('body:{"error":"Internal error.","code":"internal"}', $this->sent);
                $this->assertNotContains('body:abcdef', $this->sent);
            }
        } finally {
            ini_set('error_log', (string) $prev);
            unlink($path);
        }
    }

    public function testNullSinkAcceptsAnythingAndKeepsNothing(): void
    {
        (new NullRmmMetricSink())->ingest([['asset_id' => 1, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 5, 'at' => new \DateTimeImmutable(), 'label' => null]], 1);
        $this->addToAssertionCount(1);
    }
}
