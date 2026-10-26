<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Redis\RedisConnectionConfig;

/** CORE-04 (a Redis that accepts and never answers) and CORE-07 (the password in dumps). */
final class RedisConnectionSecurityTest extends TestCase
{
    private const PW = 'fixture-pw-not-real-9';

    public function testReadWriteTimeoutFollowsTheConnectTimeout(): void
    {
        $p = (new RedisConnectionConfig('r.example'))->toPredisParameters(0.4);
        $this->assertSame(0.4, $p['timeout']);
        $this->assertSame(0.4, $p['read_write_timeout']);
    }

    public function testAHungRedisIsGivenUpOnWithinTheTimeout(): void
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped('cannot listen on loopback');
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        $client = new \Predis\Client((new RedisConnectionConfig('127.0.0.1', $port))->toPredisParameters(0.3));
        $t = microtime(true);
        try {
            $client->ping();
            $this->fail('a silent server must not answer');
        } catch (\Throwable) {
            // expected
        }
        $this->assertLessThan(3.0, microtime(true) - $t, 'the call must return within a few timeouts, not default_socket_timeout');
        fclose($server);
    }

    public function testPasswordDoesNotLeaveThroughJsonOrSerialize(): void
    {
        $c = new RedisConnectionConfig('r.example', 6379, 0, self::PW, 'svc', true);
        $this->assertStringNotContainsString(self::PW, (string) json_encode($c));
        $this->assertStringContainsString('"host":"r.example"', (string) json_encode($c));
        $this->assertStringNotContainsString(self::PW, print_r($c, true));
        $this->expectException(\LogicException::class);
        serialize($c);
    }
}
