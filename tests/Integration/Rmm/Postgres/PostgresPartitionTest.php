<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresCheckHistoryStore;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresSchema;
use RivetCore\Rmm\Store\Postgres\PostgresStoreMaintenance;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** Native day partitions of the three time series: creation, row moves, pruning by drop, the rollup guard, idempotence (the TimescaleDB twin is PostgresTimescaleTest). */
final class PostgresPartitionTest extends TestCase
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
        $r = PostgresTestDatabase::reset($this->db, PostgresSchema::MODE_NATIVE);
        $this->assertSame(PostgresSchema::MODE_NATIVE, $r['mode']);
        $this->clock = new MutableClock();
        $this->metrics = new PostgresMetricStore($this->db, $this->clock);
        $this->maint = new PostgresStoreMaintenance($this->db, $this->clock, $this->metrics);
    }

    /** @return array<string,int> */
    private function limits(int $partitioning = 1, int $span = 1, int $ahead = 3): array
    {
        return ['partitioning' => $partitioning, 'partition_span_days' => $span, 'partition_ahead' => $ahead, 'check_history_days' => 7, 'metric_hourly_days' => 14, 'metric_daily_days' => 400];
    }

    private function today(): int
    {
        return intdiv(time(), self::DAY) * self::DAY;
    }

    private function sample(int $asset, int $ts, float $v = 10.0): array
    {
        return ['asset_id' => $asset, 'key' => 'cpu.utilization', 'instance' => null, 'value' => $v, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => null];
    }

    public function testNothingPhysicalHappensWhileThePartitioningLimitIsOffAndNoPartitionsExist(): void
    {
        $run = $this->maint->run($this->limits(0), [], 14, 400, 5);
        $this->assertSame([], $run['partitioned']);
        foreach ($run['partitions'] as $t) {
            $this->assertSame('not_partitioned', $t['status']);
        }
        $this->assertSame([], $this->maint->partitions('rmm_metric_hourly'));
    }

    public function testTurningPartitioningOnCreatesTheNextSpansAndMovesExistingRowsOutOfTheDefaultPartition(): void
    {
        $today = $this->today();
        $this->metrics->ingest([$this->sample(1, $today - 5 * self::DAY + 3600), $this->sample(1, $today - 2 * self::DAY + 7200), $this->sample(2, $today + 100)], 1);
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'), 'before: everything sits in the DEFAULT partition');
        $run = $this->maint->run($this->limits(1, 1, 3), [], 14, 400, 5);
        $this->assertContains('rmm_metric_hourly', $run['partitioned']);
        $this->assertSame('ok', $run['partitions']['rmm_metric_hourly']['status']);
        $parts = $this->maint->partitions('rmm_metric_hourly');
        $this->assertGreaterThanOrEqual(9, count($parts), 'from the oldest row (5 days ago) to 3 spans ahead');
        $this->assertSame($today - 5 * self::DAY, $parts[0]['from']);
        $this->assertSame($today + 4 * self::DAY, $parts[count($parts) - 1]['to']);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'), 'the rows moved into their partitions');
        $this->assertSame(3, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'), 'and none was lost');
        $name = 'rmm_metric_hourly_p' . gmdate('Ymd', $today - 2 * self::DAY);
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM $name"), 'the row of that day is in that day\'s partition');
        // a new row lands in its partition, not in the default one
        $this->metrics->ingest([$this->sample(3, $today + self::DAY + 10)], 1);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'));
        // reads go through the parent as before
        $this->assertNotNull($this->metrics->peak(1, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 6 * self::DAY))));
        // the upsert (ON CONFLICT) works across partitions
        $this->metrics->ingest([$this->sample(1, $today - 2 * self::DAY + 7200, 30.0)], 1);
        $peak = $this->metrics->peak(1, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 3 * self::DAY)));
        $this->assertSame(2, $peak['samples']);
    }

    public function testTheMaintenanceIsIdempotent(): void
    {
        $this->maint->run($this->limits(1, 1, 3), [], 14, 400, 5);
        $n = count($this->maint->partitions('rmm_metric_hourly'));
        $again = $this->maint->run($this->limits(1, 1, 3), [], 14, 400, 5);
        $this->assertSame($n, count($this->maint->partitions('rmm_metric_hourly')));
        $this->assertSame(0, $again['partitions']['rmm_metric_hourly']['created']);
        $this->assertSame(0, $again['partitions']['rmm_metric_hourly']['dropped']);
        $this->assertSame('ok', $again['partitions']['endpoint_agent_check_history']['status']);
    }

    public function testExpiredPartitionsAreDroppedWholeButNeverBeforeTheRollupCoveredThem(): void
    {
        $today = $this->today();
        // data 20 days old (past the 14 day retention) and 3 days old (kept)
        $this->metrics->ingest([$this->sample(1, $today - 20 * self::DAY + 600), $this->sample(1, $today - 3 * self::DAY + 600)], 1);
        $this->maint->run($this->limits(1, 1, 2), [], 14, 400, 5);
        // the rollup has run in that call (it rolls complete days up); drop what is expired
        $names = array_column($this->maint->partitions('rmm_metric_hourly'), 'name');
        $old = 'rmm_metric_hourly_p' . gmdate('Ymd', $today - 20 * self::DAY);
        $this->assertNotContains($old, $names, 'the 20 day old partition is gone: a whole partition dropped, not rows deleted');
        $this->assertContains('rmm_metric_hourly_p' . gmdate('Ymd', $today - 3 * self::DAY), $names);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'));
        // its day reached the daily tier first (the guard): the daily tier holds the old day
        $this->assertSame(1, (int) $this->db->value("SELECT COUNT(*) FROM rmm_metric_daily WHERE day_start < now() - interval '15 days'"));
    }

    public function testAPartitionTheRollupHasNotCoveredYetIsKept(): void
    {
        $today = $this->today();
        $this->metrics->ingest([$this->sample(1, $today - 20 * self::DAY + 600)], 1);
        $this->maint->maintain(['rmm_metric_hourly' => 400, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 400], 1, 1, true);   // create the partitions, expire nothing
        $guarded = $this->maint->maintain(['rmm_metric_hourly' => 14, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 7], 1, 1, true, ['rmm_metric_hourly' => gmdate('Y-m-d H:i:s', $today - 25 * self::DAY)]);
        $this->assertSame(0, $guarded['tables']['rmm_metric_hourly']['dropped']);
        $this->assertStringContainsString('rollup has not covered', $guarded['tables']['rmm_metric_hourly']['message']);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'));
    }

    public function testCheckHistoryPartitionsFollowTheRetentionAndRowsKeepTheirOrder(): void
    {
        $today = $this->today();
        $h = new PostgresCheckHistoryStore($this->db);
        $at = static fn (int $ts): string => gmdate('Y-m-d H:i:s', $ts);
        $h->append(1, [['key' => 'k', 'status' => 'ok', 'detail' => 'old', 'at' => $at($today - 10 * self::DAY)], ['key' => 'k', 'status' => 'warn', 'detail' => 'new1', 'at' => $at($today - self::DAY)],
            ['key' => 'k', 'status' => 'fail', 'detail' => 'new2', 'at' => $at($today - self::DAY)]]);
        $run = $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $this->assertGreaterThanOrEqual(1, $run['partitions']['endpoint_agent_check_history']['dropped'], '10 days old is past the 7 day check history retention');
        $rows = $h->since(1, 'k', $at($today - 30 * self::DAY), 10);
        $this->assertSame(['new1', 'new2'], array_column($rows, 'detail'), 'insertion order inside one instant');
        $this->assertNotNull($h->lastBefore(1, 'k', $at($today)));
    }

    public function testALateOldRowInTheDefaultPartitionIsKeptUntilTheRollupCoveredItAndThenExpired(): void
    {
        $today = $this->today();
        $retention = ['rmm_metric_hourly' => 14, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 7];
        $this->maint->maintain(['rmm_metric_hourly' => 400, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 400], 1, 2, true);   // partitions from today on
        $this->metrics->ingest([$this->sample(1, $today - 20 * self::DAY + 600)], 1);   // an offline device delivers an old hour: it lands in DEFAULT
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'));
        $this->maint->maintain($retention, 1, 2, true, ['rmm_metric_hourly' => gmdate('Y-m-d H:i:s', $today - 25 * self::DAY)]);   // the rollup has not reached that day
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'), 'not yet rolled up: kept, like a partition would be');
        $this->maint->maintain($retention, 1, 2, true, ['rmm_metric_hourly' => gmdate('Y-m-d H:i:s', $today - 10 * self::DAY)]);   // the rollup passed it
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'), 'covered and past retention: expired');
    }

    public function testRowsInsideAGapThatNeedsACatchUpPartitionAreMovedIntoIt(): void
    {
        $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $row = time() + 100 * self::DAY;
        $this->metrics->ingest([$this->sample(1, $row)], 1);   // far ahead of the partitions: DEFAULT
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'));
        $this->clock->advance(400 * self::DAY);   // the maintenance did not run for more than a year; the row is now in the past, inside the gap
        $this->maint->maintain(['rmm_metric_hourly' => 1000, 'rmm_metric_daily' => 1000, 'endpoint_agent_check_history' => 1000], 1, 1, true);
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'), 'the catch-up partition took the row out of DEFAULT');
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'));
    }

    public function testRowsPastTheLastPartitionWaitInTheDefaultPartitionAndAreNotLost(): void
    {
        $today = $this->today();
        $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $this->metrics->ingest([$this->sample(1, $today + 40 * self::DAY)], 1);   // far ahead of the created spans
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'));
        $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $this->assertSame(1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'));
    }

    public function testALongGapIsCoveredByOneCatchUpPartitionThenRegularOnes(): void
    {
        $today = $this->today();
        $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $this->clock->advance(400 * self::DAY);   // the maintenance did not run for more than a year
        $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5);
        $parts = $this->maint->partitions('rmm_metric_hourly');
        // the wide catch-up partition over the gap was created and, being wholly expired, dropped again; what is left is the regular tail up to the new now
        $this->assertLessThanOrEqual(PostgresStoreMaintenance::MAX_NEW + 2, count($parts), 'a single run never creates more than MAX_NEW + 1 partitions');
        $this->assertGreaterThanOrEqual($today + 400 * self::DAY, $parts[count($parts) - 1]['to']);
        $this->assertSame('ok', $this->maint->run($this->limits(1, 1, 1), [], 14, 400, 5)['partitions']['rmm_metric_hourly']['status']);
    }

    public function testTiersReportThePartitionsAndTheRollupWatermark(): void
    {
        $limits = $this->limits(1, 1, 2);
        $this->maint->run($limits, [], 14, 400, 5);
        $t = $this->maint->tiers($limits, ['metric_hourly_days' => 30]);
        $this->assertTrue($t['partitioning_enabled']);
        $this->assertTrue($t['tables']['rmm_metric_hourly']['partitioned']);
        $this->assertGreaterThanOrEqual(3, $t['tables']['rmm_metric_hourly']['partitions']);
        $this->assertSame(30, $t['tables']['rmm_metric_hourly']['retention_days']);
        $this->assertSame(400, $t['tables']['rmm_metric_daily']['retention_days']);
    }

    public function testRowsWrittenWhileThePartitionsAreBuiltAreNeitherLostNorDoubled(): void
    {
        $today = $this->today();
        $at = max($today + 60, time() - 3000);
        // today's rows are still arriving in the DEFAULT partition when partitioning is switched on: a writer runs through three maintenance runs
        $p = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/Support/pg_worker.php', 'spread', (string) json_encode(['count' => 250, 'base' => 5000, 'at' => $at])], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($p);
        usleep(150000);
        for ($i = 0; $i < 3; ++$i) {
            $run = $this->maint->run($this->limits(1, 1, 3), [], 14, 400, 5);
            $this->assertSame('ok', $run['partitions']['rmm_metric_hourly']['status'], $run['partitions']['rmm_metric_hourly']['message']);
            usleep(100000);
        }
        $out = json_decode(trim((string) stream_get_contents($pipes[1])), true);
        proc_close($p);
        $this->assertSame([], $out['errors'], 'the writer was never refused');
        $this->assertSame(250, $out['inserted']);
        $this->assertSame(250 + 1, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly'), 'one row per distinct series, none lost, none copied twice');
        $this->assertSame(250, (int) $this->db->value("SELECT samples FROM rmm_metric_hourly WHERE asset_id = 5000 AND metric_key = 'memory.utilization'"), 'and the series everyone updated has every update');
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly_default'));
    }

    public function testTwoMaintenanceRunsAtOnceDoNotCollide(): void
    {
        $other = PostgresTestDatabase::connect();
        $this->assertTrue((bool) $other->value('SELECT pg_try_advisory_lock(?)', [PostgresStoreMaintenance::LOCK_KEY]));
        $busy = $this->maint->maintain(['rmm_metric_hourly' => 14], 1, 1, true);
        $this->assertSame('busy', $busy['tables']['*']['status']);
        $this->assertSame([], $busy['partitioned']);
        $other->value('SELECT pg_advisory_unlock(?)', [PostgresStoreMaintenance::LOCK_KEY]);
        $this->assertContains('rmm_metric_hourly', $this->maint->maintain(['rmm_metric_hourly' => 14], 1, 1, true)['partitioned']);
    }
}
