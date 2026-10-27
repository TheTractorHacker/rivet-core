<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\RmmTestCase;

/** What the database metric sink promises beyond the conformance kits: rollups, batching, retention, the module wiring. */
final class DatabaseMetricSinkTest extends RmmTestCase
{
    private function sink(?int $retention = null): DatabaseMetricSink
    {
        return $retention === null ? new DatabaseMetricSink($this->h->counting, $this->h->clock) : new DatabaseMetricSink($this->h->counting, $this->h->clock, $retention);
    }

    /** @return array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string} */
    private static function s(int $asset, string $key, int|float $v, int $ts, ?string $inst = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $inst, 'value' => $v, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => $inst];
    }

    public function testOneHourBecomesOneRollupRowWithCountSumMinMax(): void
    {
        $hour = intdiv(time(), 3600) * 3600 - 7200;
        $this->sink()->ingest([self::s(9001, 'cpu.utilization', 10, $hour + 60), self::s(9001, 'cpu.utilization', 30, $hour + 600), self::s(9001, 'cpu.utilization', 20, $hour + 1200)], 1);
        $rows = $this->h->rows('SELECT * FROM rmm_metric_hourly WHERE asset_id = 9001');
        $this->assertCount(1, $rows);
        $this->assertSame([3, 60.0, 10.0, 30.0], [(int) $rows[0]['samples'], (float) $rows[0]['sum_value'], (float) $rows[0]['min_value'], (float) $rows[0]['max_value']]);
        $this->assertSame(gmdate('Y-m-d H:i:s', $hour), $rows[0]['hour_start']);
        // a later batch in the same hour folds into the same row
        $this->sink()->ingest([self::s(9001, 'cpu.utilization', 50, $hour + 1800)], 1);
        $rows = $this->h->rows('SELECT * FROM rmm_metric_hourly WHERE asset_id = 9001');
        $this->assertSame([4, 110.0, 10.0, 50.0], [(int) $rows[0]['samples'], (float) $rows[0]['sum_value'], (float) $rows[0]['min_value'], (float) $rows[0]['max_value']]);
        $p = $this->sink()->peak(9001, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($hour - 3600)));
        $this->assertSame(27.5, $p['avg'] ?? null);
    }

    public function testASpanOfHoursIsOneRowPerHourAndSeriesReadsThemBack(): void
    {
        $base = intdiv(time(), 3600) * 3600 - 5 * 3600;
        $samples = [];
        for ($h = 0; $h < 4; ++$h) {
            $samples[] = self::s(9002, 'memory.utilization', 40 + $h, $base + $h * 3600 + 30);
        }
        $this->sink()->ingest($samples, 1);
        $series = $this->sink()->series(9002, 'memory.utilization', null, new \DateTimeImmutable('@' . $base), new \DateTimeImmutable('@' . ($base + 4 * 3600)));
        $this->assertSame([40.0, 41.0, 42.0, 43.0], array_column($series, 'avg'));
        $this->assertSame([$base, $base + 3600, $base + 7200, $base + 10800], array_map(static fn (array $p): int => $p['at']->getTimestamp(), $series));
    }

    public function testTotalsAndUptimeOnlyKeepTheirLatestValue(): void
    {
        $this->sink()->ingest([self::s(9003, 'memory.total_bytes', 17179869184, time() - 5), self::s(9003, 'system.uptime_seconds', 7200, time() - 5), self::s(9003, 'cpu.utilization', 5, time() - 5)], 1);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly WHERE asset_id = 9003'), 'only the rollup keys have an hourly history');
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_latest WHERE asset_id = 9003'));
        $latest = $this->sink()->latest(9003, ['memory.total_bytes']);
        $this->assertSame(17179869184, $latest[0]['value'], 'a large integer reads back as an integer');
    }

    public function testManySamplesCostAHandfulOfStatements(): void
    {
        $now = time();
        $samples = [];
        for ($asset = 1; $asset <= 250; ++$asset) {
            foreach (['cpu.utilization', 'memory.utilization', 'network.rx_bytes_per_s', 'network.tx_bytes_per_s'] as $k) {
                $samples[] = self::s(20000 + $asset, $k, $asset % 100, $now - 30, $k[0] === 'n' ? 'total' : null);
            }
        }
        $this->h->counting->statements = 0;
        $this->sink()->ingest($samples, 1);
        $this->assertLessThanOrEqual(12, $this->h->counting->statements, '1000 samples: two tables, 200 rows per statement');
        $this->assertSame(1000, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_latest WHERE asset_id > 20000'));
        $this->assertSame(1000, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly WHERE asset_id > 20000'));
    }

    public function testInvalidSamplesAreDroppedNeverClampedOrStored(): void
    {
        $t = time() - 10;
        $this->sink()->ingest([
            self::s(9004, 'cpu.utilization', 101, $t), self::s(9004, 'cpu.utilization', -1, $t), self::s(9004, 'disk.utilization', NAN, $t, 'C:'), self::s(9004, 'Bad Key!', 5, $t),
            self::s(0, 'cpu.utilization', 5, $t), self::s(9004, 'memory.utilization', 100, $t),
        ], 1);
        $this->assertSame([['memory.utilization', 100.0]], array_map(static fn (array $r): array => [$r['metric_key'], (float) $r['value']], $this->h->rows('SELECT * FROM rmm_metric_latest WHERE asset_id = 9004')));
    }

    public function testARetriedDeliveryDoublesCountAndSumSoTheMeanIsUnchanged(): void
    {
        $hour = intdiv(time(), 3600) * 3600 - 3600;
        $batch = [self::s(9005, 'cpu.utilization', 20, $hour + 10), self::s(9005, 'cpu.utilization', 40, $hour + 20)];
        $this->sink()->ingest($batch, 1);
        $this->sink()->ingest($batch, 1);
        $p = $this->sink()->peak(9005, 'cpu.utilization', null, new \DateTimeImmutable('@' . $hour));
        $this->assertSame([20.0, 40.0, 30.0], [$p['min'] ?? null, $p['max'] ?? null, $p['avg'] ?? null]);
    }

    public function testPruneDeletesExpiredRowsInBatchesAndKeepsTheRest(): void
    {
        $old = time() - 20 * 86400;
        $new = time() - 3600;
        $this->sink()->ingest([self::s(9006, 'cpu.utilization', 1, $old), self::s(9006, 'cpu.utilization', 2, $new), self::s(9007, 'cpu.utilization', 3, $old)], 1);
        $this->assertSame(3, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly WHERE asset_id IN (9006, 9007)'));
        $paused = 0;
        $deleted = $this->sink(14)->prune(200000, function (int $us) use (&$paused): void {
            ++$paused;
        });
        $this->assertSame(3, $deleted, 'two expired hourly rows and the latest row of the asset that stopped reporting');
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly WHERE asset_id IN (9006, 9007)'));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_latest WHERE asset_id IN (9006, 9007)'), 'the asset with a fresh reading keeps its latest row');
        $this->assertSame(0, $paused, 'a small backlog needs no pause');
        $this->assertSame(0, $this->sink(14)->prune(), 'nothing left to delete');
    }

    public function testTheModuleReadsTheNetworkPeakFromTheSinkAndHousekeepingPrunesIt(): void
    {
        $sink = new DatabaseMetricSink($this->h->counting, $this->h->clock);
        $h = new \RivetCore\Tests\Support\RmmHarness(null, null, [], false, $sink);
        $h->enable();
        $asset = $h->asset(['name' => 'PC', 'serial' => 'NP-1']);
        [, , $j] = $h->enroll($h->token(null, 24, 10), $h::device(['serial' => 'NP-1', 'hostname' => 'NETPC']));
        $dev = (int) $j['device_id'];
        $tok = $j['device_token'];
        // 8000 bits/s = 1000 bytes/s now; earlier today the device hit 4,000,000 bytes/s
        $sink->ingest([self::s($asset, 'network.rx_bytes_per_s', 4000000, time() - 7200, 'total')], 1);
        [$c] = $h->checkin($tok, ['metrics' => ['cpu_pct' => 1, 'mem_pct' => 1, 'disk' => [], 'net_rx_bps' => 8000, 'net_tx_bps' => 16000]]);
        $this->assertSame(200, $c);
        $n = $h->module->readModel()->networkPeak($dev);
        $this->assertTrue($n['history']);
        $this->assertSame(1000.0, $n['rx']['current']);
        $this->assertSame(2000.0, $n['tx']['current']);
        $this->assertSame(4000000.0, $n['rx']['peak']);
        $this->assertNotNull($n['rx']['peak_at']);
        // the housekeeping run prunes the sink it was given
        $h->q("UPDATE rmm_metric_hourly SET hour_start = '2020-01-01 00:00:00' WHERE hour_start < UTC_TIMESTAMP() - INTERVAL 1 HOUR");
        $r = $h->module->housekeeping()->run();
        $this->assertGreaterThanOrEqual(1, $r['pruned_metrics']);
    }

    public function testWithoutAHistorySinkTheNetworkBarHasOnlyTheCurrentRate(): void
    {
        $this->h->enable();
        $this->h->asset(['name' => 'PC2', 'serial' => 'NP-2']);
        [, , $j] = $this->h->enroll($this->h->token(null, 24, 10), $this->h::device(['serial' => 'NP-2']));
        $n = $this->h->module->readModel()->networkPeak((int) $j['device_id']);
        $this->assertTrue($n['history'], 'the in-memory reference sink implements the reader too');
        $module = new \RivetCore\Rmm\RmmModule($this->h->counting, $this->h->clock, $this->h->tenancy, $this->h->assets, $this->h->bridge, $this->h->box, $this->h->audit, new \RivetCore\Rmm\Support\NullRmmMetricSink(), null,
            ['allow_insecure_http' => true], null, $this->h->policy);
        $n = $module->readModel()->networkPeak((int) $j['device_id']);
        $this->assertFalse($n['history']);
        $this->assertNull($n['rx']['peak']);
        $this->assertNull($n['rx']['current'], 'no metrics reported yet');
    }
}
