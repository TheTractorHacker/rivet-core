<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Metrics\VictoriaMetricsStore;
use RivetCore\Rmm\Store\Postgres\PostgresConfig;
use RivetCore\Support\SecretString;
use RivetCore\Webhooks\UrlPolicy;

/** CORE-07: credentials held by Core objects must not appear in dumps, array casts or stack traces. */
final class SecretsInDumpsTest extends TestCase
{
    private const PW = 'fixture-secret-value-71';

    public function testPostgresPasswordIsHiddenFromEveryDumpIncludingArrayCasts(): void
    {
        $c = new PostgresConfig('db', 'u', self::PW, 'db.example.test');
        $this->assertSame(self::PW, $c->password(), 'the connection still gets the password');
        foreach ([print_r($c, true), print_r((array) $c, true), var_export((array) $c, true), (string) json_encode($c)] as $dump) {
            $this->assertStringNotContainsString(self::PW, $dump);
        }
        ob_start();
        var_dump($c, (array) $c);
        $this->assertStringNotContainsString(self::PW, (string) ob_get_clean());
    }

    public function testSecretStringHidesAndRefusesSerialisation(): void
    {
        $s = new SecretString(self::PW);
        $this->assertSame(self::PW, $s->reveal());
        $this->assertStringNotContainsString(self::PW, print_r($s, true) . (string) $s . var_export((array) $s === [], true));
        $this->expectException(\LogicException::class);
        serialize($s);
    }

    public function testVictoriaMetricsCredentialsAreNotInDumpsOrTraces(): void
    {
        $store = new VictoriaMetricsStore('https://vm.example.test', new UrlPolicy(), null, null, self::PW);
        $this->assertStringNotContainsString(self::PW, print_r($store, true));
        ob_start();
        var_dump($store);
        $this->assertStringNotContainsString(self::PW, (string) ob_get_clean());
        try {
            new VictoriaMetricsStore('https://vm.example.test', new UrlPolicy(), null, null, "bad\ntoken-" . self::PW);
            $this->fail('a token with a newline must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString(self::PW, $e->getTraceAsString());
        }
    }
}
