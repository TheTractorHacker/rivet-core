<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Metrics;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Tests\Support\RecordingHttpTransport;
use RivetCore\Webhooks\UrlPolicy;

final class VictoriaMetricsStoreTest extends TestCase
{
    /** @return array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string} */
    private static function p(int $asset = 7, string $key = 'cpu.utilization', ?string $inst = null, int|float $v = 12.5, int $ts = 1_700_000_000, ?string $label = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $inst, 'value' => $v, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => $label];
    }

    private static function publicPolicy(): UrlPolicy
    {
        return new UrlPolicy(false, static fn (string $h): array => ['93.184.216.34']);
    }

    // ------------------------------------------------------------------ line protocol

    public function testLineProtocolShape(): void
    {
        $this->assertSame('rmm,asset=7,metric=cpu.utilization value=12.5 1700000000', VictoriaMetricsStore::lineProtocol(self::p()));
        $this->assertSame('rmm,asset=7,metric=disk.utilization,instance=C:,label=System value=40 1700000000', VictoriaMetricsStore::lineProtocol(self::p(7, 'disk.utilization', 'C:', 40, 1_700_000_000, 'System')));
        $this->assertSame('rmm,asset=7,metric=cpu.utilization value=1.0e+25 1700000000', VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', null, 1e25)));
    }

    public function testEmptyInstanceAndEmptyLabelAreOmitted(): void
    {
        $this->assertSame(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', null)), VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', '', 12.5, 1_700_000_000, '')));
    }

    /** @return array<string,array{string,string}> */
    public static function escapes(): array
    {
        return [
            'comma' => ['a,b', 'a\\,b'],
            'space' => ['a b', 'a\\ b'],
            'equals' => ['a=b', 'a\\=b'],
            'backslash' => ['C:\\', 'C:\\\\'],
            'all' => ['x y,z=1\\', 'x\\ y\\,z\\=1\\\\'],
            'quote is literal' => ['a"b', 'a"b'],
            'unicode' => ['ünï', 'ünï'],
        ];
    }

    #[DataProvider('escapes')]
    public function testTagValuesAreEscaped(string $raw, string $escaped): void
    {
        $this->assertSame($escaped, VictoriaMetricsStore::escapeTag($raw));
        $this->assertSame('rmm,asset=7,metric=disk.utilization,instance=' . $escaped . ' value=1 1700000000', VictoriaMetricsStore::lineProtocol(self::p(7, 'disk.utilization', $raw, 1)));
    }

    public function testAHostileValueCannotAddATagFieldOrLine(): void
    {
        $line = (string) VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', 'x,asset=9 value=99 1'));
        $this->assertSame('rmm,asset=7,metric=cpu.utilization,instance=x\\,asset\\=9\\ value\\=99\\ 1 value=12.5 1700000000', $line);
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', "x\nrmm,asset=9 value=99")));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', "x\r")));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', "\xff\xfe")));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', str_repeat('a', 257))));
        $this->assertStringNotContainsString('label', (string) VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', null, 1, 1_700_000_000, "bad\nlabel")), 'an unusable label is left out, the point is kept');
    }

    public function testUnrepresentablePointsAreSkipped(): void
    {
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(0)));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'Bad Key')));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', null, NAN)));
        $this->assertNull(VictoriaMetricsStore::lineProtocol(self::p(7, 'cpu.utilization', null, INF)));
    }

    public function testBatchesAreSplitAndTheWriteUrlIsPlain(): void
    {
        $t = new RecordingHttpTransport();
        $store = new VictoriaMetricsStore('https://vm.example/insert/0/influx/', self::publicPolicy(), $t, null, 'tok', null, 5, false, 3);
        $points = [];
        for ($i = 0; $i < 7; ++$i) {
            $points[] = self::p(7, 'cpu.utilization', null, $i, 1_700_000_000 + $i);
        }
        $points[] = self::p(7, 'cpu.utilization', null, 1, 1, "x\ny");   // label unusable but point kept
        $points[] = self::p(0);                                           // skipped
        $store->write($points);
        $this->assertCount(3, $t->requests, '8 representable points at 3 per request');
        foreach ($t->requests as $r) {
            $this->assertSame('POST', $r['method']);
            $this->assertSame('https://vm.example/insert/0/influx/write?precision=s', $r['url']);
        }
        $this->assertSame([3, 3, 2], array_map(static fn (array $r): int => substr_count($r['body'], "\n"), $t->requests));
        $this->assertContains('Authorization: Bearer tok', $t->requests[0]['headers']);
    }

    public function testNothingToWriteMakesNoRequest(): void
    {
        $t = new RecordingHttpTransport();
        $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t);
        $store->write([]);
        $store->write([self::p(0)]);
        $this->assertSame([], $t->requests);
    }

    // ------------------------------------------------------------------ PromQL

    public function testSelectorShape(): void
    {
        $this->assertSame('rmm_value{asset="7",metric="cpu.utilization",instance=""}', VictoriaMetricsStore::selector(7, 'cpu.utilization', null));
        $this->assertSame(VictoriaMetricsStore::selector(7, 'cpu.utilization', null), VictoriaMetricsStore::selector(7, 'cpu.utilization', ''));
        $this->assertSame('rmm_value{asset="7",metric="disk.utilization",instance="C:\\\\"}', VictoriaMetricsStore::selector(7, 'disk.utilization', 'C:\\'));
    }

    /** @return array<string,array{string}> */
    public static function hostile(): array
    {
        return [
            'close and union' => ['"} or vector(1) #'],
            'close selector' => ['x"}[1h]) or vector(1) #'],
            'trailing backslash' => ['\\'],
            'escaped quote' => ['\\"} or rmm_value{asset=~".*'],
            'regex matcher' => ['a",instance=~".*'],
            'braces' => ['{}'],
        ];
    }

    #[DataProvider('hostile')]
    public function testAHostileInstanceStaysInsideItsQuotes(string $instance): void
    {
        $sel = (string) VictoriaMetricsStore::selector(7, 'cpu.utilization', $instance);
        $prefix = 'rmm_value{asset="7",metric="cpu.utilization",instance="';
        $this->assertStringStartsWith($prefix, $sel);
        $this->assertStringEndsWith('"}', $sel);
        $inner = substr($sel, strlen($prefix), -2);
        // read the quoted string back the way PromQL does: it must be exactly the instance and must end exactly at the closing quote
        $this->assertSame(1, preg_match('/^((?:[^"\\\\]|\\\\.)*)$/s', $inner), 'no unescaped quote inside');
        $this->assertSame($instance, (string) preg_replace('/\\\\(["\\\\])/', '$1', $inner));
    }

    public function testControlCharactersAndBadKeysHaveNoSelector(): void
    {
        foreach (["a\nb", "a\rb", "a\0b", "a\x1fb", "a\x7fb", "\xff", str_repeat('a', 257)] as $inst) {
            $this->assertNull(VictoriaMetricsStore::selector(7, 'cpu.utilization', $inst));
        }
        foreach (['', 'CPU', 'a"b', 'a b', 'a}', str_repeat('a', 65), "a\n"] as $key) {
            $this->assertNull(VictoriaMetricsStore::selector(7, $key, null), $key);
        }
        $this->assertNull(VictoriaMetricsStore::selector(0, 'cpu.utilization', null));
        $this->assertNull(VictoriaMetricsStore::selector(-5, 'cpu.utilization', null));
    }

    public function testReadsForUnrepresentableSeriesMakeNoRequest(): void
    {
        $t = new RecordingHttpTransport();
        $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t);
        $now = new \DateTimeImmutable('@' . time());
        $this->assertSame([], $store->series(7, 'cpu.utilization', "a\nb", $now, $now, 3600));
        $this->assertNull($store->peak(7, 'cpu.utilization', "a\nb", $now));
        $this->assertSame([], $t->requests);
    }

    // ------------------------------------------------------------------ policy and secrets

    /** @return array<string,array{string,string}> */
    public static function refused(): array
    {
        return [
            'loopback' => ['https://127.0.0.1/', 'not allowed'],
            'private 10/8' => ['https://10.1.2.3/', 'not allowed'],
            'private 192.168' => ['https://192.168.1.10:8428', 'not allowed'],
            'metadata' => ['https://169.254.169.254/latest', 'not allowed'],
            'ipv6 loopback' => ['https://[::1]/', 'not allowed'],
            'plain http to a public host' => ['http://93.184.216.34/', 'plain http'],
        ];
    }

    #[DataProvider('refused')]
    public function testTheUrlPolicyRefusesBeforeAnyRequest(string $url, string $why): void
    {
        $t = new RecordingHttpTransport();
        $store = new VictoriaMetricsStore($url, new UrlPolicy(), $t, null, 'S3CRET-TOKEN', null, 5, true);
        foreach ([
            static fn () => $store->write([self::p()]),
            static fn () => $store->series(7, 'cpu.utilization', null, new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700003600'), 3600),
            static fn () => $store->peak(7, 'cpu.utilization', null, new \DateTimeImmutable('@' . (time() - 3600))),
        ] as $call) {
            try {
                $call();
                $this->fail('expected a refusal');
            } catch (ExternalStoreException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
                $this->assertFalse($e->permanent, 'a configuration problem keeps the buffered data');
                $this->assertStringNotContainsString('S3CRET-TOKEN', $e->getMessage());
            }
        }
        $this->assertFalse($store->ping());
        $this->assertSame([], $t->requests, 'a refused URL is never contacted');
    }

    public function testPlainHttpNeedsTheExplicitFlagAndAPrivateAllowedHost(): void
    {
        $private = new UrlPolicy(false, null, ['10.0.0.0/8']);
        $t = new RecordingHttpTransport();
        $without = new VictoriaMetricsStore('http://10.1.2.3:8428', $private, $t);
        try {
            $without->write([self::p()]);
            $this->fail('http must be refused without the flag');
        } catch (ExternalStoreException $e) {
            $this->assertStringContainsString('plain http', $e->getMessage());
        }
        $this->assertSame([], $t->requests);
        $with = new VictoriaMetricsStore('http://10.1.2.3:8428', $private, $t, null, null, null, 5, true);
        $with->write([self::p()]);
        $this->assertCount(1, $t->requests);
        // the allowed-network list does not admit loopback or link-local, flag or not
        $this->expectException(ExternalStoreException::class);
        (new VictoriaMetricsStore('http://127.0.0.1:8428', new UrlPolicy(false, null, ['127.0.0.0/8', '10.0.0.0/8']), $t, null, null, null, 5, true))->write([self::p()]);
    }

    public function testBadConfigurationIsRefusedAtConstruction(): void
    {
        foreach (['ftp://x/', 'https://user:pw@vm.example/', 'https://vm.example/?a=1', 'https://vm.example/#x', 'vm.example', ''] as $url) {
            try {
                new VictoriaMetricsStore($url, new UrlPolicy());
                $this->fail("accepted $url");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringNotContainsString('pw', $e->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        new VictoriaMetricsStore('https://vm.example', new UrlPolicy(), null, null, 'a', ['u', 'p']);
    }

    public function testSecretsNeverAppearInExceptions(): void
    {
        $secrets = ['S3CRET-TOKEN', 'p4ssw0rd'];
        $transports = [
            new RecordingHttpTransport(['status' => 401, 'body' => 'S3CRET-TOKEN rejected']),
            new RecordingHttpTransport(['status' => 400, 'body' => 'bad Authorization: Bearer S3CRET-TOKEN']),
            new RecordingHttpTransport(['status' => 500, 'body' => 'p4ssw0rd']),
        ];
        $transports[0]->queue = [new \RuntimeException('curl said: Authorization: Bearer S3CRET-TOKEN')];
        foreach ($transports as $i => $t) {
            $store = $i === 2
                ? new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t, null, null, ['user', 'p4ssw0rd'])
                : new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t, null, 'S3CRET-TOKEN');
            for ($n = 0; $n < 2; ++$n) {
                try {
                    $store->write([self::p()]);
                } catch (ExternalStoreException $e) {
                    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
                        foreach ($secrets as $s) {
                            $this->assertStringNotContainsString($s, $x->getMessage() . $x->getTraceAsString());
                        }
                    }
                }
            }
        }
        $this->assertContains('Authorization: Basic ' . base64_encode('user:p4ssw0rd'), $transports[2]->requests[0]['headers']);
    }

    /** @return array<string,array{int,bool}> */
    public static function statuses(): array
    {
        return ['400' => [400, true], '404' => [404, true], '413' => [413, true], '422' => [422, true], '429' => [429, false], '500' => [500, false], '503' => [503, false],
            '401' => [401, false], '403' => [403, false], '302' => [302, false]];
    }

    #[DataProvider('statuses')]
    public function testStatusesAreClassifiedAsPermanentOrTransient(int $status, bool $permanent): void
    {
        $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), new RecordingHttpTransport(['status' => $status, 'body' => '']));
        try {
            $store->write([self::p()]);
            $this->fail('expected a failure');
        } catch (ExternalStoreException $e) {
            $this->assertSame($permanent, $e->permanent);
            $this->assertStringContainsString((string) $status, $e->getMessage());
        }
        foreach ([200, 204] as $ok) {
            (new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), new RecordingHttpTransport(['status' => $ok, 'body' => ''])))->write([self::p()]);
        }
    }

    public function testATransportFailureIsATransientExternalStoreException(): void
    {
        $t = new RecordingHttpTransport();
        $t->queue = [new \RuntimeException('boom'), RecordingHttpTransport::down()];
        $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t);
        foreach ([1, 2] as $_) {
            try {
                $store->write([self::p()]);
                $this->fail('expected a failure');
            } catch (ExternalStoreException $e) {
                $this->assertFalse($e->permanent);
            }
        }
    }

    public function testReadsParseAndMergeSeveralLabelSeries(): void
    {
        $answers = [];
        foreach (['min' => [1, 5], 'max' => [9, 7], 'avg' => [5, 6], 'count' => [2, 1]] as $perSeries) {
            $result = [];
            foreach ($perSeries as $i => $val) {
                $result[] = ['metric' => ['asset' => '7', 'label' => 'l' . $i], 'values' => [[1_700_002_799, (string) $val]]];
            }
            $answers[] = ['status' => 200, 'body' => (string) json_encode(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]])];
        }
        $t = new RecordingHttpTransport();
        $t->queue = $answers;
        $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), $t);
        $s = $store->series(7, 'cpu.utilization', null, new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700000100'), 3600);
        $this->assertCount(1, $s);
        $this->assertSame(1_699_999_200, $s[0]['at']->getTimestamp());
        $this->assertSame([1.0, 9.0, 3], [$s[0]['min'], $s[0]['max'], $s[0]['samples']]);
        $this->assertEqualsWithDelta((5 * 2 + 6 * 1) / 3, $s[0]['avg'], 1e-9);
        $this->assertCount(4, $t->requests);
        $this->assertStringContainsString('query=min_over_time%28rmm_value%7Basset%3D%227%22%2Cmetric%3D%22cpu.utilization%22%2Cinstance%3D%22%22%7D%5B3600s%5D%29', $t->requests[0]['body']);
        $this->assertSame('https://vm.example/api/v1/query_range', $t->requests[0]['url']);
    }

    public function testAnUnreadableAnswerIsAnExternalStoreException(): void
    {
        foreach (['not json', '{"status":"error"}', '{"status":"success","data":{"resultType":"scalar","result":[]}}'] as $body) {
            $store = new VictoriaMetricsStore('https://vm.example', self::publicPolicy(), new RecordingHttpTransport(['status' => 200, 'body' => $body]));
            $this->expectException(ExternalStoreException::class);
            $store->series(7, 'cpu.utilization', null, new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700000100'), 3600);
        }
    }
}
