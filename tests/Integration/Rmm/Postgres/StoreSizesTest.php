<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Sizes\PostgresStoreSizes;
use RivetCore\Tests\Support\PostgresTestDatabase;

final class StoreSizesTest extends TestCase
{
    public function testSizesOfARealStoreAndAnUnreachableOne(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($db);
        $db->execute("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) SELECT 1, 'k', 'ok', repeat('x', 100), TIMESTAMPTZ '2026-01-01 00:00:00+00' + g * interval '1 minute' FROM generate_series(1, 2000) g");
        $db->execute('ANALYZE');
        $r = (new PostgresStoreSizes($db))->report();
        $this->assertTrue($r['available']);
        $this->assertSame('postgres', $r['kind']);
        $by = array_column($r['tables'], null, 'table');
        $this->assertGreaterThan(0, $by['endpoint_agent_check_history']['bytes'], 'the partitions are summed');
        $this->assertSame(2000, $by['endpoint_agent_check_history']['rows']);
        $this->assertSame('2026-01-01 00:01:00', $r['oldest_at']);
        $this->assertSame($r['total_bytes'], array_sum(array_column($r['tables'], 'bytes')));
        $this->assertSame('endpoint_agent_check_history', $r['tables'][0]['table'], 'largest first');

        $down = new PostgresStoreSizes(static function (): never {
            throw new PostgresConnectionException('server is down');
        });
        $d = $down->report();
        $this->assertFalse($d['available']);
        $this->assertStringContainsString('server is down', (string) $d['error']);
        $this->assertSame([], $d['tables']);
    }
}
