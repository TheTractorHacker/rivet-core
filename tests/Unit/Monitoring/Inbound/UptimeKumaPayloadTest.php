<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Monitoring\Inbound;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Monitoring\Inbound\InboundEvent;
use RivetCore\Monitoring\Inbound\MonitorStatus;
use RivetCore\Monitoring\Inbound\PayloadRejection;
use RivetCore\Monitoring\Inbound\UptimeKumaPayload;

final class UptimeKumaPayloadTest extends TestCase
{
    /** @param array<string,mixed> $over */
    public static function body(int $status = 0, array $over = []): string
    {
        $d = [
            'heartbeat' => ['monitorID' => 7, 'status' => $status, 'time' => '2026-11-13 10:00:00.000', 'msg' => 'timeout of 48000ms exceeded', 'ping' => null, 'important' => true, 'duration' => 0],
            'monitor' => ['id' => 7, 'name' => 'Web', 'url' => 'https://example.com', 'hostname' => null, 'port' => null, 'type' => 'http', 'tags' => [['name' => 'client', 'value' => 'acme', 'color' => '#fff']]],
            'msg' => '[Web] [DOWN] timeout',
        ];

        return json_encode(array_replace_recursive($d, $over), JSON_THROW_ON_ERROR);
    }

    private function ok(string $raw): InboundEvent
    {
        $e = (new UptimeKumaPayload())->parse($raw);
        self::assertInstanceOf(InboundEvent::class, $e);

        return $e;
    }

    private function rejected(string $raw): PayloadRejection
    {
        $e = (new UptimeKumaPayload())->parse($raw);
        self::assertInstanceOf(PayloadRejection::class, $e);

        return $e;
    }

    /** @return array<string,array{int,MonitorStatus}> */
    public static function statuses(): array
    {
        return ['down' => [0, MonitorStatus::Down], 'up' => [1, MonitorStatus::Up], 'pending' => [2, MonitorStatus::Pending], 'maintenance' => [3, MonitorStatus::Maintenance]];
    }

    #[DataProvider('statuses')]
    public function testEachStatus(int $n, MonitorStatus $want): void
    {
        $e = $this->ok(self::body($n));
        self::assertSame($want, $e->status);
        self::assertFalse($e->isTest);
        self::assertSame('7', $e->monitorKey);
        self::assertSame('Web', $e->monitorName);
        self::assertSame(gmdate('U', 0) + (int) (new \DateTimeImmutable('2026-11-13 10:00:00', new \DateTimeZone('UTC')))->format('U'), $e->time);
        self::assertSame(['client:acme'], $e->tags);
        self::assertSame('uptime-kuma', $e->source);
    }

    public function testNullHeartbeatIsATestEvent(): void
    {
        foreach (['{"heartbeat":null,"monitor":null,"msg":"Testing"}', '{"msg":"Testing"}', '{"heartbeat":null,"monitor":{"id":1,"name":"x"}}'] as $raw) {
            $e = $this->ok($raw);
            self::assertTrue($e->isTest);
            self::assertNull($e->status);
        }
    }

    public function testNullMonitorFallsBackToHeartbeatId(): void
    {
        $e = $this->ok('{"heartbeat":{"monitorID":12,"status":0,"time":"2026-11-13T10:00:00Z"},"monitor":null,"msg":"down"}');
        self::assertSame('12', $e->monitorKey);
        self::assertSame('Monitor 12', $e->monitorName);
        self::assertSame('down', $e->message);
    }

    public function testMissingTimeIsAllowedAndLeftNull(): void
    {
        self::assertNull($this->ok('{"heartbeat":{"monitorID":3,"status":1}}')->time);
    }

    /** @return array<string,array{string,string}> */
    public static function rejections(): array
    {
        $ok = static fn (array $hb, ?array $mon = ['id' => 7]): string => json_encode(['heartbeat' => $hb, 'monitor' => $mon], JSON_THROW_ON_ERROR);

        return [
            'empty' => ['', PayloadRejection::INVALID_JSON],
            'garbage' => ['{nope', PayloadRejection::INVALID_JSON],
            'scalar' => ['"x"', PayloadRejection::NOT_AN_OBJECT],
            'list' => ['[1,2]', PayloadRejection::NOT_AN_OBJECT],
            'deep' => [str_repeat('[', 50) . str_repeat(']', 50), PayloadRejection::INVALID_JSON],
            'heartbeat is a string' => ['{"heartbeat":"x"}', PayloadRejection::BAD_FIELD],
            'monitor is a list' => ['{"heartbeat":{"status":0,"monitorID":1},"monitor":[1]}', PayloadRejection::BAD_FIELD],
            'status missing' => [$ok(['monitorID' => 7]), PayloadRejection::BAD_STATUS],
            'status string' => [$ok(['monitorID' => 7, 'status' => '0']), PayloadRejection::BAD_STATUS],
            'status 9' => [$ok(['monitorID' => 7, 'status' => 9]), PayloadRejection::BAD_STATUS],
            'status bool' => [$ok(['monitorID' => 7, 'status' => false]), PayloadRejection::BAD_STATUS],
            'no monitor id' => ['{"heartbeat":{"status":0},"monitor":{"name":"x"}}', PayloadRejection::NO_MONITOR],
            'id zero' => [$ok(['monitorID' => 0, 'status' => 0], null), PayloadRejection::NO_MONITOR],
            'id negative' => [$ok(['monitorID' => -4, 'status' => 0], null), PayloadRejection::NO_MONITOR],
            'id array' => [$ok(['monitorID' => [1], 'status' => 0], null), PayloadRejection::NO_MONITOR],
            'ids disagree' => [$ok(['monitorID' => 8, 'status' => 0]), PayloadRejection::NO_MONITOR],
            'bad time' => [$ok(['monitorID' => 7, 'status' => 0, 'time' => 'yesterday']), PayloadRejection::BAD_TIME],
            'time number' => [$ok(['monitorID' => 7, 'status' => 0, 'time' => 12345]), PayloadRejection::BAD_TIME],
            'time 1970' => [$ok(['monitorID' => 7, 'status' => 0, 'time' => '1970-01-01 00:00:01']), PayloadRejection::BAD_TIME],
            'name is an object' => [$ok(['monitorID' => 7, 'status' => 0], ['id' => 7, 'name' => ['x']]), PayloadRejection::BAD_FIELD],
            'tags not a list' => [$ok(['monitorID' => 7, 'status' => 0], ['id' => 7, 'tags' => ['a' => 'b']]), PayloadRejection::BAD_FIELD],
            'tag not an object' => [$ok(['monitorID' => 7, 'status' => 0], ['id' => 7, 'tags' => ['x']]), PayloadRejection::BAD_FIELD],
            'oversize' => [str_repeat(' ', UptimeKumaPayload::MAX_BODY + 1), PayloadRejection::TOO_LARGE],
        ];
    }

    #[DataProvider('rejections')]
    public function testRejections(string $raw, string $code): void
    {
        $r = $this->rejected($raw);
        self::assertSame($code, $r->code);
        self::assertSame($code === PayloadRejection::TOO_LARGE ? 413 : 400, $r->httpStatus());
        self::assertStringNotContainsString('nope', $r->message);
    }

    public function testBodyExactlyAtTheCapIsAccepted(): void
    {
        $base = self::body(0, ['monitor' => ['name' => '']]);
        $pad = str_repeat(' ', UptimeKumaPayload::MAX_BODY - strlen($base));
        self::assertInstanceOf(InboundEvent::class, (new UptimeKumaPayload())->parse($base . $pad));
    }

    public function testHostileStringsAreCleanedAndCut(): void
    {
        $evil = "Web\x00\x1B[31m\u{202E}evil\u{200B}\r\n<script>alert('x')</script>" . str_repeat('A', 5000);
        $e = $this->ok(self::body(0, ['monitor' => ['name' => $evil, 'url' => $evil, 'hostname' => $evil, 'type' => $evil], 'heartbeat' => ['msg' => $evil]]));
        foreach ([$e->monitorName, $e->url, $e->hostname, $e->type, $e->message] as $s) {
            self::assertSame(0, preg_match('/[\x00-\x1F\x7F\x{202E}\x{200B}]/u', $s), 'control characters remain');
        }
        self::assertLessThanOrEqual(UptimeKumaPayload::MAX_NAME, mb_strlen($e->monitorName));
        self::assertLessThanOrEqual(UptimeKumaPayload::MAX_URL, mb_strlen($e->url));
        self::assertLessThanOrEqual(UptimeKumaPayload::MAX_HOSTNAME, mb_strlen($e->hostname));
        self::assertLessThanOrEqual(UptimeKumaPayload::MAX_TYPE, mb_strlen($e->type));
        self::assertLessThanOrEqual(UptimeKumaPayload::MAX_MESSAGE, mb_strlen($e->message));
        self::assertStringStartsWith('Web[31mevil<script>', $e->monitorName);   // HTML is not rewritten: the edition escapes
    }

    public function testMultibyteCutNeverSplitsACharacter(): void
    {
        $e = $this->ok(self::body(0, ['monitor' => ['name' => str_repeat("\u{1F600}", 500)]]));
        self::assertTrue(mb_check_encoding($e->monitorName, 'UTF-8'));
        self::assertSame(UptimeKumaPayload::MAX_NAME, mb_strlen($e->monitorName));
    }

    public function testTagsAreCapped(): void
    {
        $tags = [];
        for ($i = 0; $i < 60; $i++) {
            $tags[] = ['name' => 't' . $i, 'value' => ''];
        }
        $e = $this->ok(json_encode(['heartbeat' => ['monitorID' => 1, 'status' => 0], 'monitor' => ['id' => 1, 'tags' => $tags]], JSON_THROW_ON_ERROR));
        self::assertCount(UptimeKumaPayload::MAX_TAGS, $e->tags);
        self::assertSame('t0', $e->tags[0]);
    }

    public function testNumericStringIdAndUnknownMembersAreFine(): void
    {
        $e = $this->ok('{"heartbeat":{"monitorID":"15","status":1,"extra":{"a":1}},"monitor":{"id":"15"},"unknown":[1]}');
        self::assertSame('15', $e->monitorKey);
    }

    public function testMessageFallsBackToTopLevelMsg(): void
    {
        $e = $this->ok('{"heartbeat":{"monitorID":1,"status":0},"monitor":{"id":1},"msg":"[x] [DOWN]"}');
        self::assertSame('[x] [DOWN]', $e->message);
    }
}
