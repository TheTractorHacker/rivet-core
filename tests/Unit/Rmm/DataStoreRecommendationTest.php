<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Rmm;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Capacity\DataStoreRecommendation as R;

final class DataStoreRecommendationTest extends TestCase
{
    private static function sizes(int $bytes = 0, bool $ok = true, ?string $oldest = null): array
    {
        return ['kind' => 'mariadb', 'available' => $ok, 'total_bytes' => $bytes, 'oldest_at' => $oldest];
    }

    public function testTheFleetThresholdIsEightyPercent(): void
    {
        $this->assertFalse(R::evaluate('mariadb', 3999, 5000, 51200, self::sizes(), 0)['recommend']);
        $r = R::evaluate('mariadb', 4000, 5000, 51200, self::sizes(), 0);
        $this->assertTrue($r['recommend']);
        $this->assertSame('MariaDB is comfortable up to 5000 devices. You have 4000. Consider PostgreSQL.', $r['message']);
        $this->assertSame(['devices'], $r['reasons']);
        $this->assertTrue(R::evaluate('mariadb', 9000, 5000, 51200, self::sizes(), 0)['recommend']);
    }

    public function testTheSizeThresholdAndItsOff(): void
    {
        $over = self::sizes(101 * 1048576);
        $this->assertSame(['size'], R::evaluate('mariadb', 1, 5000, 100, $over, 0)['reasons']);
        $this->assertFalse(R::evaluate('mariadb', 1, 5000, 101, self::sizes(101 * 1048576), 0)['recommend'], 'equal is not over');
        $this->assertFalse(R::evaluate('mariadb', 1, 5000, 0, $over, 0)['recommend'], '0 turns the size trigger off');
        $this->assertFalse(R::evaluate('mariadb', 1, 5000, 100, self::sizes(999999999, false), 0)['recommend'], 'unknown size never triggers');
        $this->assertNull(R::evaluate('mariadb', 1, 5000, 100, self::sizes(999999999, false), 0)['hot_bytes']);
    }

    public function testNothingOnPostgresAndGrowth(): void
    {
        $r = R::evaluate('postgres', 99999, 5000, 1, self::sizes(10 * 1048576), 0);
        $this->assertFalse($r['recommend']);
        $this->assertNull($r['message']);
        $day = 1_800_000_000;
        $g = R::evaluate('mariadb', 1, 5000, 0, self::sizes(100 * 1048576, true, gmdate('Y-m-d H:i:s', $day - 10 * 86400)), $day);
        $this->assertSame(10.0, $g['growth_mb_per_day']);
        $this->assertNull(R::evaluate('mariadb', 1, 5000, 0, self::sizes(1), 0)['growth_mb_per_day']);
    }
}
