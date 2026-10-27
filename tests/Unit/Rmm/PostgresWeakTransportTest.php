<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;

/** COMM-01: the additive transport helpers of PostgresConfig; the constructor default and fromArray stay as they were. */
final class PostgresWeakTransportTest extends TestCase
{
    /** @return iterable<string,array{string,string,bool}> */
    public static function cases(): iterable
    {
        foreach (['disable', 'allow', 'prefer'] as $m) {
            yield "remote $m" => ['db.example.test', $m, true];
            yield "ip $m" => ['10.0.0.5', $m, true];
            yield "socket $m" => ['/var/run/postgresql', $m, false];
            yield "loopback $m" => ['127.0.0.1', $m, false];
            yield "localhost $m" => ['localhost', $m, false];
        }
        foreach (['require', 'verify-ca', 'verify-full'] as $m) {
            yield "remote $m" => ['db.example.test', $m, false];
        }
    }

    #[DataProvider('cases')]
    public function testWeakTransport(string $host, string $mode, bool $weak): void
    {
        $this->assertSame($weak, (new PostgresConfig('d', 'u', '', $host, 5432, $mode))->usesWeakTransport());
    }

    public function testExistingBehaviourIsUnchanged(): void
    {
        $this->assertSame('prefer', (new PostgresConfig('d', 'u'))->sslMode, 'the constructor default is a public contract');
        $c = PostgresConfig::fromArray(['database' => 'd', 'user' => 'u', 'host' => 'db.example.test']);
        $this->assertSame('prefer', $c->sslMode, 'reading a stored connection never fails or changes it');
        $this->assertTrue($c->usesWeakTransport());
        $this->assertSame('verify-full', PostgresConfig::DEFAULT_REMOTE_SSL_MODE);
    }
}
