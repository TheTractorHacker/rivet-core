<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Contracts\ClockInterface;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** The daily rollup of the PostgreSQL metric store with a clock the test controls: the grace period, slices that resume, idempotence, a late hour. */
final class PostgresRollupTest extends TestCase
{
    private const DAY = 86400;
    /** A fixed UTC midnight well in the past, so "yesterday" is a complete day whatever the real time is. */
    private const D0 = 1_790_000_000 - 1_790_000_000 % 86400;

    private PostgresConnectionInterface $db;
    private int $now = 0;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->db);
        $this->now = self::D0 + self::DAY + 3600;   // 01:00 UTC on the day after D0
        $this->clock = new class($this->now, $this) implements ClockInterface {
            public function __construct(public int $t, private readonly PostgresRollupTest $test)
            {
            }

            public function now(): \DateTimeImmutable
            {
                return (new \DateTimeImmutable('@' . $this->test->now()))->setTimezone(new \DateTimeZone('UTC'));
            }
        };
    }

    public function now(): int
    {
        return $this->now;
    }

    private function sample(int $asset, int $ts, float $v): array
    {
        return ['asset_id' => $asset, 'key' => 'cpu.utilization', 'instance' => null, 'value' => $v, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => null];
    }

    public function testADayIsNotRolledUpUntilTheGracePeriodAfterItEnded(): void
    {
        $store = new PostgresMetricStore($this->db, $this->clock);
        $store->ingest([$this->sample(1, self::D0 + 3600 * 10 + 5, 10.0), $this->sample(1, self::D0 + 3600 * 11 + 5, 30.0)], 1);
        $this->assertSame(0, $store->rollUp(5)['days'], '01:00 on the next day: a device may still deliver late hours of D0 (grace 2 hours)');
        $this->assertNull($store->rollupWatermark());
        $this->now = self::D0 + self::DAY + 2 * 3600 + 1;   // 02:00:01
        $r = $store->rollUp(5);
        $this->assertSame(1, $r['days']);
        $this->assertSame(gmdate('Y-m-d H:i:s', self::D0 + self::DAY), $store->rollupWatermark());
        $daily = $this->db->queryOne("SELECT samples, sum_value, min_value, max_value FROM rmm_metric_daily WHERE asset_id = 1 AND day_start = to_timestamp(" . self::D0 . ')');
        $this->assertSame([2, 40.0, 10.0, 30.0], [(int) $daily['samples'], (float) $daily['sum_value'], (float) $daily['min_value'], (float) $daily['max_value']]);
    }

    public function testSlicesResumeWhereTheLastRunStoppedAndARepeatChangesNothing(): void
    {
        $store = new PostgresMetricStore($this->db, $this->clock, 14, 604800, 400, 2);   // two assets per slice
        $this->now = self::D0 + self::DAY + 5 * 3600;
        $samples = [];
        foreach ([1, 2, 3, 4, 5, 6, 7] as $asset) {
            $samples[] = $this->sample($asset, self::D0 + 3600 * 3 + 7, (float) $asset);
        }
        $store->ingest($samples, 1);
        $first = $store->rollUp(30, 2);
        $this->assertSame([0, 2], [$first['days'], $first['slices']], 'two slices fit the cap, the day is not finished');
        $this->assertSame(gmdate('Y-m-d H:i:s', self::D0), $first['watermark'], 'the watermark stays at the day being rolled');
        $mark = (new \RivetCore\Rmm\Store\Postgres\PostgresRollupState($this->db))->get();
        $this->assertSame(['day' => gmdate('Y-m-d H:i:s', self::D0), 'asset_done' => 4], $mark['detail'], 'and the note says how far it got: the slices (0,2] and (2,4] are done');
        $second = $store->rollUp(30);
        $this->assertSame(1, $second['days']);
        $this->assertSame(7, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_daily'));
        $snapshot = $this->db->query('SELECT asset_id, samples, sum_value FROM rmm_metric_daily ORDER BY asset_id');
        $this->assertSame(0, $store->rollUp(30)['days'], 'nothing left');
        $this->assertSame($snapshot, $this->db->query('SELECT asset_id, samples, sum_value FROM rmm_metric_daily ORDER BY asset_id'), 'and a repeat changes nothing');
        // running the first slice again by hand (a crash between the slice and the note) rewrites the same rows, it does not add to them
        $this->db->execute("UPDATE rmm_metric_rollup_state SET watermark = to_timestamp(" . self::D0 . "), detail = NULL");
        $this->assertSame(1, $store->rollUp(30)['days']);
        $this->assertSame($snapshot, $this->db->query('SELECT asset_id, samples, sum_value FROM rmm_metric_daily ORDER BY asset_id'), 'the rollup is a replace, not an add');
    }

    public function testAnHourThatArrivesAfterItsDayWasRolledUpStaysInTheHourlyTierOnly(): void
    {
        $store = new PostgresMetricStore($this->db, $this->clock);
        $this->now = self::D0 + self::DAY + 4 * 3600;
        $store->ingest([$this->sample(1, self::D0 + 3600 * 5 + 1, 20.0)], 1);
        $store->rollUp(5);
        $store->ingest([$this->sample(1, self::D0 + 3600 * 6 + 1, 60.0)], 1);   // the device was offline and delivers late
        $this->assertSame(0, $store->rollUp(5)['days']);
        $this->assertSame(1, (int) $this->db->value('SELECT samples FROM rmm_metric_daily WHERE asset_id = 1'));
        $this->assertSame(2, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly WHERE asset_id = 1'), 'the late hour is kept in the hourly tier');
    }
}
