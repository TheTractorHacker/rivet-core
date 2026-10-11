<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Migration\RmmSchema;
use RivetCore\Rmm\Storage\MetricRollup;
use RivetCore\Rmm\Storage\PartitionMaintenance;
use RivetCore\Rmm\Storage\StorageMaintenance;
use RivetCore\Rmm\Storage\StorageState;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Rmm\Support\Sql;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmTestCase;

/**
 * Scale item S5: the daily rollup tier, the readers that use it, and the time partitions with their probe, conversion, maintenance and the fallback to
 * batched DELETEs. Uses the real tables of the scratch database and puts them back as they were.
 */
final class StorageScaleTest extends RmmTestCase
{
    private Sql $sql;
    private MutableClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MutableClock();
        $this->sql = new Sql($this->h->db, $this->clock);
        $this->resetTables();
    }

    protected function tearDown(): void
    {
        $this->h->q('DROP TABLE IF EXISTS zz_fk_probe');
        $this->resetTables();
        parent::tearDown();
    }

    /** Back to the unpartitioned tables of the migrations (drops any partitioning and the check history primary key rewrite). */
    private function resetTables(): void
    {
        $this->h->q('DROP TABLE IF EXISTS zz_fk_probe');
        foreach (['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history'] as $t) {
            $this->h->q("DROP TABLE IF EXISTS `$t`");
        }
        $ddl = RmmSchema::phase1Tables();
        $this->h->q($ddl['endpoint_agent_check_history']);
        $this->h->q($ddl['rmm_metric_hourly']);
        $this->h->q(\RivetCore\Rmm\Migration\ScaleSchema::tables()['rmm_metric_daily']);
        $this->h->q('DELETE FROM rmm_storage_state');
    }

    private function hourly(int $asset, string $key, int $dayStart, int $hour, float $min, float $max, int $n = 4): void
    {
        $ts = gmdate('Y-m-d H:i:s', $dayStart + $hour * 3600);
        $this->h->q("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES ($asset, '$key', '', '$ts', $n, " . (($min + $max) / 2 * $n) . ", $min, $max)");
    }

    private function today(): int
    {
        return intdiv($this->clock->now()->getTimestamp(), 86400) * 86400;
    }

    // ------------------------------------------------------------------ rollup

    public function testACompleteDayIsRolledUpAndTheTodayIsNot(): void
    {
        $today = $this->today();
        for ($d = 3; $d >= 0; --$d) {
            for ($h = 0; $h < 24; ++$h) {
                $this->hourly(1, 'cpu.utilization', $today - $d * 86400, $h, 10 + $h, 20 + $h);
            }
        }
        $this->clock->advance(max(0, MetricRollup::GRACE_S + 1 - ($this->clock->now()->getTimestamp() - $today)));   // past the grace of today's first hours: still not a complete day
        $r = (new MetricRollup($this->sql))->run();
        $this->assertSame(3, $r['days'], 'three complete days, today excluded');
        $rows = $this->h->rows('SELECT * FROM rmm_metric_daily WHERE asset_id = 1 ORDER BY day_start');
        $this->assertCount(3, $rows);
        $this->assertSame(96, (int) $rows[0]['samples'], '24 hours of 4 samples');
        $this->assertSame([10.0, 43.0], [(float) $rows[0]['min_value'], (float) $rows[0]['max_value']]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $today), (new StorageState($this->sql))->rollupWatermark());
        // idempotent: a second run changes nothing, a re-run of a slice gives the same row
        $again = (new MetricRollup($this->sql))->run();
        $this->assertSame(0, $again['days']);
        $this->assertSame($rows, $this->h->rows('SELECT * FROM rmm_metric_daily WHERE asset_id = 1 ORDER BY day_start'));
    }

    public function testAnIncompleteDayWaitsForTheGracePeriod(): void
    {
        $secondsIntoDay = $this->clock->now()->getTimestamp() - $this->today();
        if ($secondsIntoDay >= MetricRollup::GRACE_S) {
            $this->clock->advance(86400 - $secondsIntoDay + 60);   // just after midnight: yesterday is over but inside the grace
        }
        $today = $this->today();
        $this->hourly(1, 'cpu.utilization', $today - 86400, 5, 10, 20);
        $before = (new MetricRollup($this->sql))->run();
        $this->assertSame(0, $before['days'], 'yesterday is complete but a late hour could still arrive');
        $this->clock->advance(MetricRollup::GRACE_S + 60);
        $after = (new MetricRollup($this->sql))->run();
        $this->assertGreaterThanOrEqual(1, $after['days']);
    }

    public function testARunThatStopsMidDayResumesWithoutLosingOrDoublingAnything(): void
    {
        $this->clock->advance(3 * 86400);
        $day = $this->today() - 2 * 86400;
        $values = [];
        for ($a = 1; $a <= 25; ++$a) {
            $values[] = "($a, 'cpu.utilization', '', '" . gmdate('Y-m-d H:i:s', $day + 3600) . "', 2, 30, 10, 20)";
            $values[] = "($a, 'cpu.utilization', '', '" . gmdate('Y-m-d H:i:s', $day + 7200) . "', 3, 60, 5, 30)";
        }
        $this->h->q('INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES ' . implode(',', $values));
        $roll = new MetricRollup($this->sql, null, 10);
        $first = $roll->run(20, 1);
        $this->assertSame(1, $first['slices']);
        $this->assertSame(0, $first['days'], 'stopped inside the day: the watermark did not move past it');
        $this->assertSame(gmdate('Y-m-d H:i:s', $day), $first['watermark']);
        $this->assertSame(10, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily'));
        $second = $roll->run(20, 1);
        $this->assertSame(1, $second['slices']);
        $this->assertSame(20, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily'));
        $rest = $roll->run(20);
        $this->assertGreaterThanOrEqual(1, $rest['days']);
        $this->assertSame(25, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily'), 'every asset has exactly one daily row');
        $this->assertSame(5, (int) $this->h->one('SELECT SUM(samples) FROM rmm_metric_daily WHERE asset_id = 1'));
        $this->assertSame([5.0, 30.0], array_map('floatval', [$this->h->one('SELECT min_value FROM rmm_metric_daily WHERE asset_id = 1'), $this->h->one('SELECT max_value FROM rmm_metric_daily WHERE asset_id = 1')]));
        $this->assertSame((int) $this->h->one('SELECT SUM(samples) FROM rmm_metric_hourly'), (int) $this->h->one('SELECT SUM(samples) FROM rmm_metric_daily'));
        $this->assertSame(0, $roll->run(20)['days'], 'and a repeat changes nothing');
    }

    public function testReadersUseTheDailyTierBeyondAWeekAndMatchAHourlyOnlyAnswer(): void
    {
        $this->clock->advance(86400 * 2);
        $today = $this->today();
        $sink = new DatabaseMetricSink($this->h->db, $this->clock);
        for ($d = 40; $d >= 0; --$d) {
            for ($h = 0; $h < 24; $h += 6) {
                $this->hourly(7, 'cpu.utilization', $today - $d * 86400, $h, 10 + ($d % 5), 40 + ($d % 7) + $h);
            }
        }
        $since = new \DateTimeImmutable('@' . ($today - 35 * 86400));
        $until = new \DateTimeImmutable('@' . $this->clock->now()->getTimestamp());
        $hourlyPeak = $sink->peak(7, 'cpu.utilization', null, $since);   // no rollup yet: hourly only
        $hourlySeries = $sink->series(7, 'cpu.utilization', null, $since, $until);
        $this->assertNotNull($hourlyPeak);
        (new MetricRollup($this->sql))->run(30);
        $this->assertGreaterThan(30, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily'));
        $dailyPeak = $sink->peak(7, 'cpu.utilization', null, $since);
        $this->assertNotNull($dailyPeak);
        $this->assertSame($hourlyPeak['max'], $dailyPeak['max']);
        $this->assertSame($hourlyPeak['min'], $dailyPeak['min']);
        $this->assertSame($hourlyPeak['samples'], $dailyPeak['samples']);
        $this->assertEqualsWithDelta($hourlyPeak['avg'], $dailyPeak['avg'], 1e-9);
        $dailySeries = $sink->series(7, 'cpu.utilization', null, $since, $until);
        $this->assertLessThan(count($hourlySeries), count($dailySeries), 'a 35 day graph reads days, not hours');
        $this->assertLessThanOrEqual(40, count($dailySeries));
        $this->assertSame(array_sum(array_column($hourlySeries, 'samples')), array_sum(array_column($dailySeries, 'samples')));
        // a short window never touches the daily tier
        $short = $sink->series(7, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 86400)), $until);
        $this->assertLessThanOrEqual(26, count($short));
    }

    // ------------------------------------------------------------------ probe, conversion, maintenance

    public function testTheProbeSaysWhatThisServerAndTableCanDo(): void
    {
        $p = (new PartitionMaintenance($this->sql))->probe();
        $this->assertTrue($p['supported'], implode(' ', $p['reasons']));
        $this->assertContains($p['server'], ['mariadb', 'mysql']);
        foreach (PartitionMaintenance::TABLES as $t => $_) {
            $this->assertTrue($p['tables'][$t]['convertible'], $t . ': ' . implode(' ', $p['tables'][$t]['reasons']));
            $this->assertFalse($p['tables'][$t]['partitioned']);
        }
        $this->assertSame(0, (int) $this->h->one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'rmm\\_partition\\_probe\\_%'"), 'the scratch probe table is gone');
    }

    public function testAForeignKeyMakesATableUnconvertibleAndThePruningStaysTheBatchedDelete(): void
    {
        $this->h->q('CREATE TABLE zz_fk_probe (asset_id int NOT NULL, metric_key varchar(64) NOT NULL, instance varchar(64) NOT NULL, hour_start datetime NOT NULL,
            FOREIGN KEY (asset_id, metric_key, instance, hour_start) REFERENCES rmm_metric_hourly (asset_id, metric_key, instance, hour_start)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci');
        $pm = new PartitionMaintenance($this->sql);
        $p = $pm->probe();
        $this->assertFalse($p['tables']['rmm_metric_hourly']['convertible']);
        $this->assertStringContainsString('foreign key', $p['tables']['rmm_metric_hourly']['reasons'][0]);
        $this->assertTrue($p['tables']['rmm_metric_daily']['convertible'], 'the other tables are judged on their own');
        $c = $pm->convert('rmm_metric_hourly', 1, 3);
        $this->assertFalse($c['ok']);
        $this->assertFalse($pm->isPartitioned('rmm_metric_hourly'));
        // the sink still prunes by DELETE when nothing is partitioned
        $old = gmdate('Y-m-d H:i:s', $this->today() - 30 * 86400);
        $this->h->q("INSERT INTO rmm_metric_hourly (asset_id, metric_key, instance, hour_start, samples, sum_value, min_value, max_value) VALUES (1, 'cpu.utilization', '', '$old', 1, 5, 5, 5)");
        $this->h->q('DROP TABLE zz_fk_probe');
        $sink = new DatabaseMetricSink($this->h->db, $this->clock);
        $this->assertSame(1, $sink->prune());
    }

    public function testWithoutThePrivilegesTheProbeSaysSoAndNothingIsChanged(): void
    {
        $user = 'rmm_nopart_' . bin2hex(random_bytes(3));
        $db = (string) getenv('RIVETCORE_TEST_DB_NAME');
        $this->h->q("CREATE USER '$user'@'%' IDENTIFIED BY 'pw'");
        $this->h->q("GRANT SELECT, INSERT, UPDATE, DELETE ON `$db`.* TO '$user'@'%'");
        try {
            $m = new \mysqli(getenv('RIVETCORE_TEST_DB_HOST') ?: 'localhost', $user, 'pw', $db, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
            $limited = new \RivetCore\Tests\Support\MysqliDatabase($m);
            $p = (new PartitionMaintenance(new Sql($limited, $this->clock)))->probe();
            $m->close();
            $this->assertFalse($p['supported']);
            $this->assertStringContainsString('cannot partition', $p['reasons'][0]);
            foreach ($p['tables'] as $t) {
                $this->assertFalse($t['convertible']);
            }
        } finally {
            $this->h->q("DROP USER IF EXISTS '$user'@'%'");
        }
    }

    public function testConversionKeepsEveryRowAndMaintenanceCreatesAndDropsPartitions(): void
    {
        $today = $this->today();
        foreach ([40, 20, 10, 5, 1, 0] as $d) {
            $this->hourly(3, 'cpu.utilization', $today - $d * 86400, 1, 10, 20);
        }
        $pm = new PartitionMaintenance($this->sql);
        $res = $pm->convert('rmm_metric_hourly', 1, 3);
        $this->assertTrue($res['ok'], $res['message']);
        $this->assertSame(6, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'));
        $parts = $pm->partitions('rmm_metric_hourly');
        $this->assertSame(PartitionMaintenance::OLD, $parts[0]['name']);
        $this->assertSame(PartitionMaintenance::MAX, $parts[array_key_last($parts)]['name']);
        $this->assertNull($parts[array_key_last($parts)]['bound']);
        $this->assertGreaterThan(40 + 3, count($parts) - 2, 'one partition per day from the oldest row to three days ahead');
        // maintain: retention 14 days drops the 40 and 20 day old partitions' rows, keeps the rest, keeps the future ones created
        $m = $pm->maintain(['rmm_metric_hourly' => 14, 'rmm_metric_daily' => 400, 'endpoint_agent_check_history' => 7], 1, 3);
        $this->assertSame('ok', $m['tables']['rmm_metric_hourly']['status'], $m['tables']['rmm_metric_hourly']['message']);
        $this->assertGreaterThanOrEqual(25, $m['tables']['rmm_metric_hourly']['dropped']);
        $this->assertSame(4, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'), 'only the rows older than 14 days went');
        $this->assertSame(['rmm_metric_hourly'], $m['partitioned']);
        // a day later the next partition appears and the old ones stay dropped; the run is a no-op when nothing is due
        $count = count($pm->partitions('rmm_metric_hourly'));
        $this->clock->advance(86400 * 2);
        $m2 = $pm->maintain(['rmm_metric_hourly' => 14], 1, 3);
        $this->assertGreaterThanOrEqual(2, $m2['tables']['rmm_metric_hourly']['created']);
        $this->assertSame($count + $m2['tables']['rmm_metric_hourly']['created'] - $m2['tables']['rmm_metric_hourly']['dropped'], count($pm->partitions('rmm_metric_hourly')));
        $m3 = $pm->maintain(['rmm_metric_hourly' => 14], 1, 3);
        $this->assertSame([0, 0], [$m3['tables']['rmm_metric_hourly']['created'], $m3['tables']['rmm_metric_hourly']['dropped']]);
        // writes into the partitioned table still work (upsert on the primary key)
        $sink = new DatabaseMetricSink($this->h->db, $this->clock);
        $at = new \DateTimeImmutable('@' . ($this->clock->now()->getTimestamp() - 60));
        $s = ['asset_id' => 9, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 50, 'at' => $at, 'label' => null];
        $sink->ingest([$s], 1);
        $sink->ingest([$s], 1);
        $this->assertSame('2', (string) $this->h->one('SELECT samples FROM rmm_metric_hourly WHERE asset_id = 9'));
    }

    public function testAPartitionIsNotDroppedBeforeTheDailyRollupCoveredIt(): void
    {
        $today = $this->today();
        $this->hourly(3, 'cpu.utilization', $today - 30 * 86400, 1, 10, 20);
        $pm = new PartitionMaintenance($this->sql);
        $this->assertTrue($pm->convert('rmm_metric_hourly', 1, 2)['ok']);
        $guard = gmdate('Y-m-d H:i:s', $today - 35 * 86400);   // the rollup is stuck 35 days back
        $m = $pm->maintain(['rmm_metric_hourly' => 14], 1, 2, false, ['rmm_metric_hourly' => $guard]);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'), 'the 30 day old hour is still there');
        $this->assertStringContainsString('rollup', $m['tables']['rmm_metric_hourly']['message']);
        $m = $pm->maintain(['rmm_metric_hourly' => 14], 1, 2, false, ['rmm_metric_hourly' => gmdate('Y-m-d H:i:s', $today)]);
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'));
        $this->assertGreaterThan(0, $m['tables']['rmm_metric_hourly']['dropped']);
    }

    public function testTheCheckHistoryPrimaryKeyIsExtendedAndTheAutoIncrementKeepsWorking(): void
    {
        $pm = new PartitionMaintenance($this->sql);
        $now = gmdate('Y-m-d H:i:s', $this->clock->now()->getTimestamp());
        $this->h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (1, 'a', 'ok', '', '$now')");
        $r = $pm->convert('endpoint_agent_check_history', 1, 2);
        $this->assertTrue($r['ok'], $r['message']);
        $this->h->q("INSERT INTO endpoint_agent_check_history (device_id, check_key, status, detail, reported_at) VALUES (1, 'b', 'fail', '', '$now'), (1, 'c', 'ok', '', '$now')");
        $ids = array_map('intval', array_column($this->h->rows('SELECT hist_id FROM endpoint_agent_check_history ORDER BY hist_id'), 'hist_id'));
        $this->assertSame(3, count(array_unique($ids)));
        $this->assertSame('hist_id,reported_at', (string) $this->h->one("SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'endpoint_agent_check_history' AND INDEX_NAME = 'PRIMARY'"));
    }

    public function testTheStorageMaintenanceRunsTheRollupBeforeThePartitionsAndReportsWhichTablesArePartitioned(): void
    {
        $today = $this->today();
        $this->clock->advance(3 * 86400);
        $this->hourly(3, 'cpu.utilization', $today - 20 * 86400, 1, 10, 20);
        $this->hourly(3, 'cpu.utilization', $today, 1, 10, 20);
        $limits = $this->h->module->settings()->limits();
        $sm = new StorageMaintenance(new PartitionMaintenance($this->sql), new MetricRollup($this->sql));
        $off = $sm->run($limits, [], 14, 400);
        $this->assertSame([], $off['partitioned'], 'partitioning is off by default: nothing is converted');
        $on = $sm->run(['partitioning' => 1] + $limits, [], 14, 400);
        $this->assertContains('rmm_metric_hourly', $on['partitioned']);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily WHERE day_start = \'' . gmdate('Y-m-d H:i:s', $today - 20 * 86400) . '\''), 'the day was rolled up before its partition was dropped');
        $this->assertSame(0, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly WHERE hour_start < \'' . gmdate('Y-m-d H:i:s', $today - 14 * 86400) . '\''));
    }
}
