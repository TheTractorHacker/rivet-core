<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresCheckHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresMigrator;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Rmm\Store\Postgres\PostgresStoreMaintenance;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** The TimescaleDB mode: hypertables, compression, chunk retention with the rollup guard. Skips unless the extension is installed in the scratch database. */
final class PostgresTimescaleTest extends TestCase
{
    private const DAY = 86400;

    private PostgresConnectionInterface $db;
    private MutableClock $clock;
    private PostgresStoreMaintenance $maint;
    private PostgresMetricStore $metrics;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        if (!(new PostgresMigrator($this->db))->timescaleInstalled()) {
            if (getenv('RIVETCORE_TEST_PG_MODE') === 'timescale') {
                $this->fail('RIVETCORE_TEST_PG_MODE=timescale but the extension is not installed in the scratch database.');
            }
            $this->markTestSkipped('TimescaleDB is not installed in the scratch PostgreSQL (CI jobs "postgres 18 + TimescaleDB" and "postgres 16 + TimescaleDB").');
        }
        $this->assertSame(PostgresSchema::MODE_TIMESCALE, PostgresTestDatabase::reset($this->db, PostgresSchema::MODE_TIMESCALE)['mode']);
        $this->clock = new MutableClock();
        $this->metrics = new PostgresMetricStore($this->db, $this->clock);
        $this->maint = new PostgresStoreMaintenance($this->db, $this->clock, $this->metrics);
    }

    /** @return array<string,int> */
    private function limits(int $partitioning = 1): array
    {
        return ['partitioning' => $partitioning, 'partition_span_days' => 1, 'partition_ahead' => 3, 'check_history_days' => 7, 'metric_hourly_days' => 14, 'metric_daily_days' => 400];
    }

    private function sample(int $asset, int $ts): array
    {
        return ['asset_id' => $asset, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 10.0, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => null];
    }

    public function testTheModeIsRecordedAndTheTimeSeriesAreHypertablesWithOneDayChunks(): void
    {
        $this->assertSame(PostgresSchema::MODE_TIMESCALE, $this->maint->mode());
        foreach (array_keys(PostgresSchema::TIME_SERIES) as $t) {
            $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM timescaledb_information.hypertables WHERE hypertable_name = ?', [$t]));
        }
        $today = intdiv(time(), self::DAY) * self::DAY;
        $this->metrics->ingest([$this->sample(1, $today - 3 * self::DAY + 600), $this->sample(1, $today - self::DAY + 600)], 1);
        $this->assertSame(2, (int) $this->db->value("SELECT COUNT(*) FROM timescaledb_information.chunks WHERE hypertable_name = 'rmm_metric_hourly'"), 'one chunk per day of data');
    }

    public function testWithPartitioningOffNothingPhysicalHappensAndRowsAreCountedAsNotPartitioned(): void
    {
        $run = $this->maint->run($this->limits(0), [], 14, 400, 5);
        $this->assertSame([], $run['partitioned']);
        $this->assertSame('not_partitioned', $run['partitions']['rmm_metric_hourly']['status']);
        $this->assertFalse((bool) $this->db->value("SELECT compression_enabled FROM timescaledb_information.hypertables WHERE hypertable_name = 'rmm_metric_hourly'"));
    }

    public function testMaintenanceEnablesCompressionAndDropsExpiredChunksButNotBeforeTheRollup(): void
    {
        $today = intdiv(time(), self::DAY) * self::DAY;
        $this->metrics->ingest([$this->sample(1, $today - 20 * self::DAY + 600), $this->sample(1, $today - 3 * self::DAY + 600)], 1);
        $h = new PostgresCheckHistoryStore($this->db);
        $h->append(1, [['key' => 'k', 'status' => 'ok', 'detail' => 'old', 'at' => gmdate('Y-m-d H:i:s', $today - 10 * self::DAY)], ['key' => 'k', 'status' => 'ok', 'detail' => 'new', 'at' => gmdate('Y-m-d H:i:s', $today - self::DAY)]]);
        // the guard: the rollup has not covered the old day yet, so its chunk stays
        $guarded = $this->maint->maintain(['rmm_metric_hourly' => 14, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 7], 1, 3, true, ['rmm_metric_hourly' => gmdate('Y-m-d H:i:s', $today - 25 * self::DAY)]);
        $this->assertSame(0, $guarded['tables']['rmm_metric_hourly']['dropped']);
        $this->assertSame(1, $guarded['tables']['endpoint_agent_check_history']['dropped'], 'the 10 day old check history chunk is past the 7 day retention');
        // the real run: the rollup goes first (it is part of run()), then the old chunk goes
        $run = $this->maint->run($this->limits(1), [], 14, 400, 5);
        $this->assertSame('ok', $run['partitions']['rmm_metric_hourly']['status'], $run['partitions']['rmm_metric_hourly']['message']);
        $this->assertContains('rmm_metric_hourly', $run['partitioned']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'), 'only the 3 day old hour is left');
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM rmm_metric_daily WHERE day_start < now() - interval '15 days'"), 'the old day reached the daily tier first');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_check_history'));
        foreach (array_keys(PostgresSchema::TIME_SERIES) as $t) {
            $this->assertTrue((bool) $this->db->value('SELECT compression_enabled FROM timescaledb_information.hypertables WHERE hypertable_name = ?', [$t]), "$t: compression is on");
            $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM timescaledb_information.jobs WHERE proc_name = 'policy_compression' AND hypertable_name = ?", [$t]), "$t: one compression policy");
        }
        $again = $this->maint->run($this->limits(1), [], 14, 400, 5);
        $this->assertSame(0, $again['partitions']['rmm_metric_hourly']['dropped']);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM timescaledb_information.jobs WHERE proc_name = 'policy_compression' AND hypertable_name = 'rmm_metric_hourly'"), 'the policy is not added twice');
    }

    public function testCompressedChunksStillAnswerReadsAndAcceptUpserts(): void
    {
        $today = intdiv(time(), self::DAY) * self::DAY;
        $old = $today - 9 * self::DAY + 600;
        $this->metrics->ingest([$this->sample(7, $old)], 1);
        $this->maint->run($this->limits(1), [], 14, 400, 5);
        $this->db->value("SELECT compress_chunk(c, if_not_compressed => true) FROM show_chunks('rmm_metric_hourly', older_than => now() - interval '7 days') c");
        $this->assertGreaterThan(0, (int) $this->db->value("SELECT COUNT(*) FROM timescaledb_information.chunks WHERE hypertable_name = 'rmm_metric_hourly' AND is_compressed"));
        $peak = $this->metrics->peak(7, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($old - 3600)));
        $this->assertSame(1, $peak['samples'], 'a read through a compressed chunk');
        $this->metrics->ingest([$this->sample(7, $old + 60)], 1);   // a late delivery into the compressed hour
        $this->assertSame(2, (int) $this->db->value('SELECT samples FROM rmm_metric_hourly WHERE asset_id = 7'), 'the upsert reached the compressed row');
        // (the day was rolled up before the late hour arrived: like on MariaDB, a late hour stays in the hourly tier only and the daily figure keeps its one sample)
        $this->assertSame(1, $this->metrics->peak(7, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($old - 3600)))['samples']);
    }

    public function testTiersReportTheChunks(): void
    {
        $this->metrics->ingest([$this->sample(1, time() - 600)], 1);
        $t = $this->maint->tiers($this->limits(1), []);
        $this->assertTrue($t['tables']['rmm_metric_hourly']['partitioned']);
        $this->assertGreaterThanOrEqual(1, $t['tables']['rmm_metric_hourly']['partitions']);
        $this->assertFalse($this->maint->tiers($this->limits(0), [])['tables']['rmm_metric_hourly']['partitioned']);
    }
}
