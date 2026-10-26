<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm\Metrics;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Metrics\CurlHttpTransport;
use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Tests\Support\FakeVictoriaMetricsServer;
use RivetCore\Webhooks\UrlPolicy;

/** The default transport and the store over a real socket (the fake server on loopback; allowPrivate only for that). */
final class CurlHttpTransportTest extends TestCase
{
    private static ?FakeVictoriaMetricsServer $server = null;

    public static function setUpBeforeClass(): void
    {
        self::$server = new FakeVictoriaMetricsServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::$server?->stop();
    }

    protected function setUp(): void
    {
        self::$server?->reset();
    }

    private function server(): FakeVictoriaMetricsServer
    {
        $this->assertNotNull(self::$server);

        return self::$server;
    }

    /** @return array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string} */
    private static function p(): array
    {
        return ['asset_id' => 7, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 5, 'at' => new \DateTimeImmutable('@1700000000'), 'label' => null];
    }

    private function store(?string $token = null, int $timeout = 5): VictoriaMetricsStore
    {
        return new VictoriaMetricsStore($this->server()->url(), new UrlPolicy(true), null, null, $token, null, $timeout, true);
    }

    public function testTheDefaultPolicyRefusesLoopbackWithoutConnecting(): void
    {
        $this->expectException(ExternalStoreException::class);
        $this->expectExceptionMessage('not allowed');
        (new CurlHttpTransport(new UrlPolicy()))->request('GET', $this->server()->url() . '/health', [], '', 5);
    }

    public function testOnlyGetAndPostAreSpoken(): void
    {
        $this->expectException(ExternalStoreException::class);
        (new CurlHttpTransport(new UrlPolicy(true)))->request('DELETE', $this->server()->url() . '/health', [], '', 5);
    }

    public function testALoneLineFeedInAHeaderIsRefusedAndATabIsNot(): void
    {
        $t = new CurlHttpTransport(new UrlPolicy(true));
        try {
            $t->request('GET', $this->server()->url() . '/health', ["X: a\nEvil: 1"], '', 5);
            $this->fail('a bare LF must be refused');
        } catch (ExternalStoreException $e) {
            $this->assertSame('invalid request header', $e->getMessage());
        }
        $this->assertNotNull($t->request('GET', $this->server()->url() . '/health', ["X: a\tb"], '', 5));
    }

    public function testHeaderInjectionIsRefused(): void
    {
        $this->expectException(ExternalStoreException::class);
        (new CurlHttpTransport(new UrlPolicy(true)))->request('GET', $this->server()->url() . '/health', ["X: a\r\nEvil: 1"], '', 5);
    }

    public function testGetAndPostRoundTrip(): void
    {
        $t = new CurlHttpTransport(new UrlPolicy(true));
        $this->assertSame(['status' => 200, 'body' => 'OK'], $t->request('GET', $this->server()->url() . '/health', [], '', 5));
        $this->assertSame(204, $t->request('POST', $this->server()->url() . '/write?precision=s', ['Content-Type: text/plain'], "rmm,asset=1 value=1 1700000000\n", 5)['status']);
        $this->assertSame(1, $this->server()->storedSamples());
    }

    public function testWriteReadRoundTripAndTheAuthorizationHeaderIsSent(): void
    {
        $store = $this->store('tok-123');
        $this->server()->setMode(['require_auth' => 'Bearer tok-123']);
        $store->write([self::p()]);
        $this->assertSame(1, $this->server()->storedSamples());
        $auths = array_unique(array_column($this->server()->requests(), 'auth'));
        $this->assertSame(['Bearer tok-123'], $auths);
        $this->assertSame('/write', $this->server()->requests()[0]['path']);
        $this->assertSame('precision=s', $this->server()->requests()[0]['query']);
        // a wrong token is an authentication problem: transient, and the token is not in the message
        $this->server()->setMode(['require_auth' => 'Bearer other']);
        try {
            $store->write([self::p()]);
            $this->fail('expected a failure');
        } catch (ExternalStoreException $e) {
            $this->assertFalse($e->permanent);
            $this->assertStringNotContainsString('tok-123', $e->getMessage());
        }
    }

    public function testErrorStatusesFromTheServer(): void
    {
        $store = $this->store();
        foreach ([400 => true, 429 => false, 500 => false] as $status => $permanent) {
            $this->server()->setMode(['status' => $status]);
            try {
                $store->write([self::p()]);
                $this->fail('expected a failure');
            } catch (ExternalStoreException $e) {
                $this->assertSame($permanent, $e->permanent, (string) $status);
            }
        }
        $this->server()->setMode([]);
        $store->write([self::p()]);
    }

    public function testARedirectIsNotFollowed(): void
    {
        $this->server()->setMode(['redirect' => true]);
        try {
            $this->store()->write([self::p()]);
            $this->fail('expected a failure');
        } catch (ExternalStoreException $e) {
            $this->assertFalse($e->permanent);
            $this->assertStringContainsString('302', $e->getMessage());
        }
        $this->assertCount(1, $this->server()->requests(), 'only the first request was made');
    }

    public function testASlowServerTimesOutAsATransientFailure(): void
    {
        $this->server()->setMode(['sleep' => 3]);
        $start = microtime(true);
        try {
            $this->store(null, 1)->write([self::p()]);
            $this->fail('expected a timeout');
        } catch (ExternalStoreException $e) {
            $this->assertFalse($e->permanent);
        }
        $this->assertLessThan(2.9, microtime(true) - $start, 'the timeout is honoured');
    }

    public function testAResponseOverOneMebibyteIsAFailure(): void
    {
        $this->server()->setMode(['status' => 200, 'body' => 1048576 + 10]);
        $this->expectException(ExternalStoreException::class);
        $this->expectExceptionMessage('too large');
        (new CurlHttpTransport(new UrlPolicy(true)))->request('GET', $this->server()->url() . '/health', [], '', 5);
    }

    public function testAnUnreachablePortIsATransientFailure(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $this->assertNotFalse($sock);
        $port = (int) explode(':', (string) stream_socket_get_name($sock, false))[1];
        fclose($sock);
        $store = new VictoriaMetricsStore('http://127.0.0.1:' . $port, new UrlPolicy(true), null, null, null, null, 2, true);
        try {
            $store->write([self::p()]);
            $this->fail('expected a failure');
        } catch (ExternalStoreException $e) {
            $this->assertFalse($e->permanent);
        }
        $this->assertFalse($store->ping());
    }

    public function testPingAndAPrefixedBase(): void
    {
        $store = new VictoriaMetricsStore($this->server()->url() . '/insert/0/influx/', new UrlPolicy(true), null, $this->server()->url() . '/select/0/prometheus', null, null, 5, true);
        $this->assertTrue($store->ping());
        $store->write([self::p()]);
        $this->assertSame('/insert/0/influx/write', $this->server()->requests()[1]['path']);
        $s = $store->series(7, 'cpu.utilization', null, new \DateTimeImmutable('@1700000000'), new \DateTimeImmutable('@1700000000'), 3600);
        $this->assertCount(1, $s);
        $this->assertSame('/select/0/prometheus/api/v1/query_range', $this->server()->requests()[2]['path']);
    }
}
