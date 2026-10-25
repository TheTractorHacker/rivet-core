<?php

declare(strict_types=1);

namespace RivetCore\Testing;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\ChangeCriteria;
use RivetCore\Rmm\Store\LogCriteria;
use RivetCore\Rmm\Store\MetricStore;
use RivetCore\Rmm\Store\RmmDataStores;

/**
 * Contract tests of the HOT RMM stores ({@see RmmDataStores}, issue #89). The class under test supplies the stores; every implementation (MariaDB / MySQL now,
 * PostgreSQL in phase 2) must pass the same cases, which pin the observable behaviour of every method of every store interface: what is stored, what a read
 * returns and in which order, how the caps and the ledger behave, how retention deletes, and what stays untouched.
 *
 * The control data the stores refer to (devices with a client and a host name, catalog packages, hypervisor VMs) always lives in the MariaDB control database,
 * whatever store is under test; the subclass creates it through {@see device()}, {@see package()}, {@see vm()} and {@see deleteVm()}.
 *
 * Every test starts from EMPTY stores and an empty control database (the subclass resets both in setUp). Times are explicit parameters of the store methods, so
 * the cases use fixed timestamps; only the metric cases read the real clock (they place samples relative to now).
 *
 * @api
 */
abstract class RmmDataStoreContractTestCase extends TestCase
{
    /** The kind the stores report ({@see \RivetCore\Rmm\Store\RmmDataStoreKind}). */
    abstract protected function expectedKind(): string;

    /** The stores under test, built with a {@see MetricStore} as the metric store. */
    abstract protected function stores(): RmmDataStores;

    /** A device of $clientId with the given host name in the control database; returns its id. */
    abstract protected function device(int $clientId, string $hostname): int;

    /** A catalog package with the given name in the control database; returns its id. */
    abstract protected function package(string $name): int;

    /** A hypervisor VM of $deviceId in the control database; returns its id. */
    abstract protected function vm(int $deviceId): int;

    /** Remove a VM from the control database (its history becomes orphaned). */
    abstract protected function deleteVm(int $vmId): void;

    /**
     * Which of the given VM ids are still in the control database's inventory (what the caller of {@see \RivetCore\Rmm\Store\HypervisorHistoryStore::deleteOrphans()} passes).
     *
     * @param list<int> $vmIds
     * @return list<int>
     */
    abstract protected function existingVms(array $vmIds): array;

    /** A fixed point in time well after every retention, so cases can place rows before and after it. */
    private const T = 1_800_000_000;
    private const DAY = 86400;

    private static function utc(int $ts): string
    {
        return gmdate('Y-m-d H:i:s', $ts);
    }

    private function scopedLogs(): \RivetCore\Rmm\Store\ClientScopedLogStore
    {
        $s = $this->stores()->logs();
        $this->assertInstanceOf(\RivetCore\Rmm\Store\ClientScopedLogStore::class, $s);

        return $s;
    }

    private function scopedChanges(): \RivetCore\Rmm\Store\ClientScopedChangeEventStore
    {
        $s = $this->stores()->changes();
        $this->assertInstanceOf(\RivetCore\Rmm\Store\ClientScopedChangeEventStore::class, $s);

        return $s;
    }

    private function inventoryHypervisor(): \RivetCore\Rmm\Store\InventoryAwareHypervisorHistoryStore
    {
        $s = $this->stores()->hypervisorHistory();
        $this->assertInstanceOf(\RivetCore\Rmm\Store\InventoryAwareHypervisorHistoryStore::class, $s);

        return $s;
    }

    public function testTheStoresReportTheirKindAndOfferEveryStore(): void
    {
        $s = $this->stores();
        $this->assertSame($this->expectedKind(), $s->kind());
        $this->assertInstanceOf(MetricStore::class, $s->metrics());
        $this->assertInstanceOf(\RivetCore\Rmm\Store\StoreMaintenance::class, $s->maintenance());
    }

    // ------------------------------------------------------------------ metrics

    private function metrics(): MetricStore
    {
        $m = $this->stores()->metrics();
        $this->assertNotNull($m);

        return $m;
    }

    /** @return array{asset_id:int, key:string, instance:?string, value:int|float, at:\DateTimeImmutable, label:?string} */
    private function sample(int $asset, string $key, int|float $value, int $ts, ?string $instance = null, ?string $label = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $instance, 'value' => $value, 'at' => new \DateTimeImmutable('@' . $ts), 'label' => $label];
    }

    private static function hourStart(int $ts): int
    {
        return $ts - $ts % 3600;
    }

    public function testMetricRetentionDefaultsAreReported(): void
    {
        $m = $this->metrics();
        $this->assertGreaterThanOrEqual(1, $m->retentionDays());
        $this->assertGreaterThanOrEqual($m->retentionDays(), $m->dailyRetentionDays());
    }

    public function testMetricLatestKeepsTheNewestReadingPerKeyAndInstance(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $t = self::hourStart(time()) - 7200;
        $m->ingest([
            $this->sample($asset, 'cpu.utilization', 10, $t + 60),
            $this->sample($asset, 'disk.utilization', 40, $t + 60, 'C:', 'System'),
            $this->sample($asset, 'disk.utilization', 70, $t + 60, 'D:'),
            $this->sample($asset, 'system.uptime_seconds', 99, $t + 60),
        ], 1);
        $m->ingest([$this->sample($asset, 'cpu.utilization', 30, $t + 120), $this->sample($asset, 'cpu.utilization', 5, $t + 30)], 1);   // the older reading must not replace the newer
        $m->ingest([$this->sample($asset, 'cpu.utilization', 7, $t + 10, null, 'late')], 1);   // a late delivery of an older reading in a batch of its own: the stored one wins, label and time included
        $latest = $m->latest($asset);
        $byKey = [];
        foreach ($latest as $r) {
            $byKey[$r['key'] . '|' . ($r['instance'] ?? '')] = $r;
        }
        $this->assertCount(4, $latest);
        $this->assertSame(30, $byKey['cpu.utilization|']['value']);
        $this->assertSame($t + 120, $byKey['cpu.utilization|']['at']->getTimestamp());
        $this->assertNull($byKey['cpu.utilization|']['label'], 'the late reading\'s label did not replace the newer reading\'s');
        $this->assertNull($byKey['cpu.utilization|']['instance']);
        $this->assertSame('System', $byKey['disk.utilization|C:']['label']);
        $this->assertSame(70, $byKey['disk.utilization|D:']['value']);
        $this->assertSame(99, $byKey['system.uptime_seconds|']['value']);
        $this->assertSame([], $m->latest($asset, []), 'an empty key list asks for nothing');
        $only = $m->latest($asset, ['cpu.utilization']);
        $this->assertCount(1, $only);
        $this->assertSame('cpu.utilization', $only[0]['key']);
        $this->assertSame([], $m->latest($asset + 1));
    }

    public function testMetricValuesOutOfRangeAreDroppedNeverClamped(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $t = self::hourStart(time()) - 7200;
        $m->ingest([
            $this->sample($asset, 'cpu.utilization', 101, $t),
            $this->sample($asset, 'memory.utilization', -1, $t),
            $this->sample($asset, 'disk.free_bytes', NAN, $t),
            $this->sample($asset, 'disk.utilization', 100, $t),
            $this->sample($asset, 'bad key!', 1, $t),
            $this->sample(0, 'cpu.utilization', 1, $t),
        ], 1);
        $latest = $m->latest($asset);
        $this->assertCount(1, $latest);
        $this->assertSame('disk.utilization', $latest[0]['key']);
        $this->assertNull($m->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($t - 3600))));
    }

    public function testMetricHourlyRollupHoldsCountSumMinAndMaxAndOnlyRollupKeysGainHistory(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $h = self::hourStart(time()) - 5 * 3600;
        $m->ingest([
            $this->sample($asset, 'cpu.utilization', 10, $h + 10),
            $this->sample($asset, 'cpu.utilization', 50, $h + 20),
            $this->sample($asset, 'cpu.utilization', 30, $h + 3700),
            $this->sample($asset, 'system.uptime_seconds', 5, $h + 10),
        ], 1);
        $series = $m->series($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($h + 1800)), new \DateTimeImmutable('@' . ($h + 7200)));
        $this->assertCount(2, $series, 'a window starting at 00:30 includes the 00:00 hour; one point per hour that has data, oldest first');
        $this->assertSame($h, $series[0]['at']->getTimestamp());
        $this->assertSame([2, 10.0, 50.0, 30.0], [$series[0]['samples'], $series[0]['min'], $series[0]['max'], $series[0]['avg']]);
        $this->assertSame($h + 3600, $series[1]['at']->getTimestamp());
        $this->assertSame([], $m->series($asset, 'system.uptime_seconds', null, new \DateTimeImmutable('@' . ($h - 3600)), new \DateTimeImmutable('@' . ($h + 7200))), 'a metric that is not worth a history only keeps its latest value');
        $peak = $m->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . $h));
        $this->assertNotNull($peak);
        $this->assertSame([3, 10.0, 50.0, 30.0], [$peak['samples'], $peak['min'], $peak['max'], $peak['avg']]);
        $this->assertSame($h, $peak['peak_at']->getTimestamp(), 'peak_at is the start of the hour that held the maximum');
        $this->assertNull($m->peak($asset, 'cpu.utilization', 'other', new \DateTimeImmutable('@' . $h)), 'another instance is another series');
        $this->assertNull($m->peak($asset + 1, 'cpu.utilization', null, new \DateTimeImmutable('@' . $h)));
    }

    public function testTheHourlyRollupAccumulatesAcrossBatches(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $h = self::hourStart(time()) - 4 * 3600;
        $m->ingest([$this->sample($asset, 'cpu.utilization', 40, $h + 5)], 1);
        $m->ingest([$this->sample($asset, 'cpu.utilization', 10, $h + 6), $this->sample($asset, 'cpu.utilization', 20, $h + 7)], 1);
        $m->ingest([$this->sample($asset, 'cpu.utilization', 90, $h + 8)], 1);
        $peak = $m->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . $h));
        $this->assertNotNull($peak);
        $this->assertSame([4, 10.0, 90.0], [$peak['samples'], $peak['min'], $peak['max']], 'count adds up, the minimum keeps the smallest and the maximum the largest of every batch');
        $this->assertEqualsWithDelta(40.0, $peak['avg'], 1e-9);
    }

    public function testAPeakThatTiesBetweenTheDailyAndTheHourlyTierIsAttributedToTheMoreRecentOne(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $today = intdiv(time(), self::DAY) * self::DAY;
        $m->ingest([$this->sample($asset, 'cpu.utilization', 80, $today - 3 * self::DAY + 3600 * 10 + 5)], 1);
        $m->rollUp(10);   // that day is now in the daily tier
        $recent = time() - 120;
        $m->ingest([$this->sample($asset, 'cpu.utilization', 80, $recent)], 1);   // the same maximum in the newest hour, which is read from the hourly tier
        $peak = $m->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 9 * self::DAY)));
        $this->assertNotNull($peak);
        $this->assertSame([2, 80.0], [$peak['samples'], $peak['max']]);
        $this->assertSame(self::hourStart($recent), $peak['peak_at']->getTimestamp(), 'on a tie the more recent tier holds the peak');
    }

    public function testARedeliveredBatchDoublesCountAndSumTogether(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $h = self::hourStart(time()) - 4 * 3600;
        $batch = [$this->sample($asset, 'memory.utilization', 20, $h + 5), $this->sample($asset, 'memory.utilization', 60, $h + 9)];
        $m->ingest($batch, 1);
        $m->ingest($batch, 1);
        $peak = $m->peak($asset, 'memory.utilization', null, new \DateTimeImmutable('@' . $h));
        $this->assertNotNull($peak);
        $this->assertSame(4, $peak['samples']);
        $this->assertEqualsWithDelta(40.0, $peak['avg'], 1e-9, 'the mean is unchanged');
        $this->assertSame([20.0, 60.0], [$peak['min'], $peak['max']]);
    }

    public function testTheDailyRollupIsIdempotentAndLongWindowsAreAnsweredFromIt(): void
    {
        $m = $this->metrics();
        $this->assertNull($m->rollupWatermark());
        $this->assertSame(0, $m->rollUp(5)['days'], 'nothing to roll up yet');
        $asset = random_int(1_000_000, 2_000_000_000);
        $today = intdiv(time(), self::DAY) * self::DAY;
        $d3 = $today - 3 * self::DAY;
        $d2 = $today - 2 * self::DAY;
        $m->ingest([
            $this->sample($asset, 'cpu.utilization', 10, $d3 + 3600 * 10 + 5),
            $this->sample($asset, 'cpu.utilization', 90, $d3 + 3600 * 11 + 5),
            $this->sample($asset, 'cpu.utilization', 40, $d2 + 3600 * 5 + 5),
        ], 1);
        $r = $m->rollUp(10);
        $this->assertGreaterThanOrEqual(2, $r['days']);
        $this->assertSame(['days', 'slices', 'rows', 'watermark', 'behind_days'], array_keys($r));
        $wm = $m->rollupWatermark();
        $this->assertNotNull($wm);
        $this->assertGreaterThanOrEqual($d2 + self::DAY, strtotime($wm . ' UTC'), 'both complete days are behind the watermark');
        $again = $m->rollUp(10);
        $this->assertSame(0, $again['days'], 'no complete day is left');
        $this->assertSame($wm, $m->rollupWatermark(), 'a second run changes nothing');
        // a window longer than a week reads the daily tier for the rolled-up days: one point per day
        $series = $m->series($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 9 * self::DAY)), new \DateTimeImmutable('@' . $today));
        $byAt = [];
        foreach ($series as $p) {
            $byAt[$p['at']->getTimestamp()] = $p;
        }
        $this->assertArrayHasKey($d3, $byAt);
        $this->assertSame([2, 10.0, 90.0, 50.0], [$byAt[$d3]['samples'], $byAt[$d3]['min'], $byAt[$d3]['max'], $byAt[$d3]['avg']]);
        $this->assertSame(1, $byAt[$d2]['samples']);
        $peak = $m->peak($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($today - 9 * self::DAY)));
        $this->assertNotNull($peak);
        $this->assertSame([3, 10.0, 90.0], [$peak['samples'], $peak['min'], $peak['max']]);
        $this->assertEqualsWithDelta(140 / 3, $peak['avg'], 1e-9);
    }

    public function testMetricPruneDeletesWhatIsPastRetentionOnly(): void
    {
        $m = $this->metrics();
        $asset = random_int(1_000_000, 2_000_000_000);
        $now = time();
        $m->ingest([
            $this->sample($asset, 'cpu.utilization', 10, $now - 50 * self::DAY, null),
            $this->sample($asset, 'memory.utilization', 20, $now - 3600, null),
        ], 1);
        $this->assertSame(1, count($m->latest($asset, ['cpu.utilization'])));
        $m->rollUp(10);   // the 50 day old hour reaches the daily tier
        $n = $m->prune(1000, null, 14, 30);
        $this->assertGreaterThanOrEqual(3, $n, 'the old hourly row, the old latest reading and the old daily row');
        $this->assertSame([], $m->latest($asset, ['cpu.utilization']));
        $this->assertSame([], $m->series($asset, 'cpu.utilization', null, new \DateTimeImmutable('@' . ($now - 60 * self::DAY)), new \DateTimeImmutable('@' . ($now - 40 * self::DAY))));
        $this->assertCount(1, $m->latest($asset, ['memory.utilization']), 'recent data stays');
        $this->assertNotNull($m->peak($asset, 'memory.utilization', null, new \DateTimeImmutable('@' . ($now - 2 * 3600))));
        $this->assertSame(0, $m->prune(1000, null, 14, 30), 'nothing left to delete');
    }

    // ------------------------------------------------------------------ check history

    public function testCheckHistoryAppendReadWindowAndPrune(): void
    {
        $h = $this->stores()->checkHistory();
        $dev = $this->device(1, 'CH-1');
        $other = $this->device(1, 'CH-2');
        $entries = [];
        for ($i = 0; $i < 450; ++$i) {   // more than one INSERT chunk
            $entries[] = ['key' => 'disk_c', 'status' => $i % 2 === 0 ? 'ok' : 'warn', 'detail' => 'd' . $i, 'at' => self::utc(self::T + $i * 60)];
        }
        $entries[] = ['key' => 'cpu', 'status' => 'fail', 'detail' => 'x', 'at' => self::utc(self::T)];
        $h->append($dev, $entries);
        $h->append($dev, []);
        $h->append($other, [['key' => 'disk_c', 'status' => 'ok', 'detail' => '', 'at' => self::utc(self::T)]]);
        $all = $h->since($dev, 'disk_c', self::utc(self::T), 1000);
        $this->assertCount(450, $all);
        $this->assertSame(['ok', 'd0', self::utc(self::T)], [$all[0]['status'], $all[0]['detail'], $all[0]['reported_at']]);
        $this->assertSame('d449', $all[449]['detail'], 'oldest first');
        $this->assertCount(5, $h->since($dev, 'disk_c', self::utc(self::T), 5), 'the limit applies');
        $this->assertCount(449, $h->since($dev, 'disk_c', self::utc(self::T + 60), 1000), 'the lower bound is inclusive');
        $this->assertSame([], $h->since($dev, 'nothing', self::utc(self::T), 10));
        // equal times keep insertion order
        $h->append($dev, [['key' => 'tie', 'status' => 'ok', 'detail' => 'first', 'at' => self::utc(self::T)], ['key' => 'tie', 'status' => 'fail', 'detail' => 'second', 'at' => self::utc(self::T)]]);
        $tie = $h->since($dev, 'tie', self::utc(self::T), 10);
        $this->assertSame(['first', 'second'], [$tie[0]['detail'], $tie[1]['detail']]);
        $before = $h->lastBefore($dev, 'disk_c', self::utc(self::T + 60 * 10));
        $this->assertNotNull($before);
        $this->assertSame(['warn', self::utc(self::T + 60 * 9)], [$before['status'], $before['reported_at']], 'the newest result strictly before the bound (minute 9 is odd)');
        $this->assertNull($h->lastBefore($dev, 'disk_c', self::utc(self::T)), 'strictly before');
        $this->assertNull($h->lastBefore($dev, 'unknown', self::utc(self::T + 99999)));
        $this->assertSame(0, $h->deleteOlderThan(self::utc(self::T), 100), 'nothing is older than the first instant');
        // older than minute 5: disk_c minutes 0..4 (5 rows), cpu (1), the two tie rows (2) and the other device's row (1)
        $this->assertSame(4, $h->deleteOlderThan(self::utc(self::T + 60 * 5), 4), 'a limit bounds one call');
        $this->assertSame(5, $h->deleteOlderThan(self::utc(self::T + 60 * 5), 100));
        $this->assertSame(0, $h->deleteOlderThan(self::utc(self::T + 60 * 5), 100));
        $this->assertCount(445, $h->since($dev, 'disk_c', self::utc(self::T - self::DAY), 1000));
        $this->assertCount(0, $h->since($other, 'disk_c', self::utc(self::T - self::DAY), 10), 'the other device lost its row too: the cutoff is global');
        $this->assertCount(0, $h->since($dev, 'cpu', self::utc(self::T - self::DAY), 10));
    }

    // ------------------------------------------------------------------ software history

    public function testSoftwareHistoryAppendFilterPageAndPrune(): void
    {
        $s = $this->stores()->softwareHistory();
        $dev = $this->device(1, 'SW-1');
        $other = $this->device(1, 'SW-2');
        $changes = [];
        for ($i = 0; $i < 300; ++$i) {   // more than one INSERT chunk
            $changes[] = ['key' => 'k' . $i, 'name' => 'App ' . $i, 'source' => 'msi', 'publisher' => 'Acme', 'type' => 'installed', 'old' => null, 'new' => '1.' . $i];
        }
        $changes[] = ['key' => 'pct', 'name' => '100% pure_app', 'source' => 'msi', 'publisher' => '', 'type' => 'upgraded', 'old' => '1', 'new' => '2'];
        $changes[] = ['key' => 'plain', 'name' => '100 purexapp', 'source' => 'msi', 'publisher' => '', 'type' => 'removed', 'old' => '3', 'new' => null];
        $s->append($dev, $changes, self::utc(self::T));
        $s->append($dev, [], self::utc(self::T));
        $s->append($other, [['key' => 'x', 'name' => 'Other', 'source' => 'store', 'publisher' => 'P', 'type' => 'removed', 'old' => '9', 'new' => null]], self::utc(self::T + 10));
        $page = $s->forDevice($dev, null, 100, 0);
        $this->assertSame(302, $page['total']);
        $this->assertCount(100, $page['rows']);
        $rows = $page['rows'];
        $this->assertSame(['name', 'source', 'change_type', 'old_version', 'new_version', 'publisher', 'occurred_at'], array_keys(array_intersect_key(array_flip(['name', 'source', 'change_type', 'old_version', 'new_version', 'publisher', 'occurred_at']), $rows[0])));
        $this->assertSame(self::utc(self::T), (string) $rows[0]['occurred_at']);
        $ids = array_map(static fn (array $r): string => (string) $r['name'], $s->forDevice($dev, null, 500, 0)['rows']);
        $this->assertSame('100 purexapp', $ids[0], 'newest first; rows of one instant newest-inserted first');
        $this->assertSame('100% pure_app', $ids[1]);
        $this->assertCount(2, $s->forDevice($dev, null, 2, 300)['rows'], 'offset');
        $this->assertSame(302, $s->forDevice($dev, null, 2, 300)['total']);
        $this->assertSame(1, $s->forDevice($dev, '100%', 10, 0)['total'], 'a percent sign in the filter is a character, not a wildcard');
        $this->assertSame(1, $s->forDevice($dev, 'pure_app', 10, 0)['total'], 'an underscore in the filter is a character, not a wildcard');
        $this->assertSame(11, $s->forDevice($dev, '  App 7 ', 100, 0)['total'], 'the filter is trimmed: "App 7", "App 70".."App 79", "App 7" matches 11 names');
        $this->assertSame(302, $s->forDevice($dev, '   ', 10, 0)['total'], 'a blank filter is no filter');
        $this->assertSame(1, $s->forDevice($other, null, 10, 0)['total']);
        $this->assertSame(0, $s->forDevice($dev + 999, null, 10, 0)['total']);
        $this->assertCount(302, $s->between($dev, self::utc(self::T), self::utc(self::T), 1000), 'both bounds are inclusive');
        $this->assertCount(0, $s->between($dev, self::utc(self::T + 1), self::utc(self::T + 5), 1000));
        $this->assertCount(7, $s->between($dev, self::utc(self::T - 1), self::utc(self::T + 1), 7), 'the limit applies');
        $this->assertSame(0, $s->deleteOlderThan(self::utc(self::T), 100));
        $this->assertSame(5, $s->deleteOlderThan(self::utc(self::T + 5), 5));
        $this->assertSame(297, $s->forDevice($dev, null, 500, 0)['total']);
        $this->assertSame(1, $s->forDevice($other, null, 500, 0)['total'], 'a row after the cutoff stays');
    }

    // ------------------------------------------------------------------ patch history

    public function testPatchHistoryRecordPageAndPrune(): void
    {
        $p = $this->stores()->patchHistory();
        $dev = $this->device(1, 'PH-1');
        $other = $this->device(2, 'PH-2');
        for ($i = 0; $i < 5; ++$i) {
            $p->record(['device_id' => $dev, 'client_id' => 1, 'update_id' => 'KB' . $i, 'title' => 'Update ' . $i, 'action' => 'install', 'result' => $i === 4 ? 'failed' : 'ok', 'code' => 'c' . $i,
                'detail' => 'detail ' . $i, 'at' => self::utc(self::T + $i), 'job_id' => 'job-' . $i, 'user_id' => $i === 0 ? null : 7]);
        }
        $p->record(['device_id' => $other, 'client_id' => 2, 'update_id' => 'KBX', 'title' => 'T', 'action' => 'scan', 'result' => 'ok', 'code' => '', 'detail' => '', 'at' => self::utc(self::T), 'job_id' => 'j', 'user_id' => null]);
        $page = $p->forDevice($dev, 3, 0);
        $this->assertSame(5, $page['total']);
        $this->assertCount(3, $page['rows']);
        $this->assertSame(['KB4', 'KB3', 'KB2'], array_map(static fn (array $r): string => (string) $r['update_id'], $page['rows']), 'newest first');
        $r = $page['rows'][0];
        $this->assertSame(['Update 4', 'install', 'failed', 'c4', 'detail 4', self::utc(self::T + 4), 'job-4', 7], [(string) $r['title'], (string) $r['action'], (string) $r['result'], (string) $r['code'], (string) $r['detail'], (string) $r['at'], (string) $r['job_id'], (int) $r['user_id']]);
        $this->assertSame(['KB1', 'KB0'], array_map(static fn (array $r): string => (string) $r['update_id'], $p->forDevice($dev, 3, 3)['rows']));
        $this->assertNull($p->forDevice($dev, 10, 4)['rows'][0]['user_id'], 'a NULL user stays NULL');
        $this->assertSame(1, $p->forDevice($other, 10, 0)['total']);
        $this->assertSame(0, $p->deleteOlderThan(self::utc(self::T), 10));
        $this->assertSame(2, $p->deleteOlderThan(self::utc(self::T + 2), 2), 'the two oldest of the cutoff, bounded by the limit');
        $this->assertSame(1, $p->deleteOlderThan(self::utc(self::T + 2), 10));
        $this->assertSame(3, $p->forDevice($dev, 10, 0)['total']);
    }

    // ------------------------------------------------------------------ deployment history

    public function testDeploymentHistoryRecordPageNameAndPrune(): void
    {
        $d = $this->stores()->deploymentHistory();
        $dev = $this->device(1, 'DH-1');
        $pkg = $this->package('Contract Package');
        for ($i = 0; $i < 4; ++$i) {
            $d->record(['device_id' => $dev, 'client_id' => 1, 'package_id' => $pkg, 'version_id' => $i === 0 ? null : 3, 'action' => 'install', 'mode' => 'live', 'result' => 'installed', 'code' => 'c',
                'detail' => 'detail ' . $i, 'version' => '1.' . $i, 'at' => self::utc(self::T + $i), 'job_id' => $i === 0 ? null : 'job-' . $i, 'user_id' => $i === 0 ? null : 5]);
        }
        $d->record(['device_id' => $dev, 'client_id' => 1, 'package_id' => $pkg + 1000, 'version_id' => null, 'action' => 'remove', 'mode' => 'dry_run', 'result' => 'ok', 'code' => '', 'detail' => '', 'version' => '',
            'at' => self::utc(self::T + 10), 'job_id' => null, 'user_id' => null]);
        $page = $d->forDevice($dev, 100, 0);
        $this->assertSame(5, $page['total']);
        $this->assertCount(5, $page['rows']);
        $first = $page['rows'][0];
        $this->assertSame($pkg + 1000, (int) $first['package_id']);
        $this->assertArrayNotHasKey('name', $first, 'the catalog is control data: the store does not join it, the caller adds the name');
        $this->assertSame([$pkg, '1.3', 'detail 3', 'live', 'job-3', 5], [(int) $page['rows'][1]['package_id'], (string) $page['rows'][1]['version'], (string) $page['rows'][1]['detail'],
            (string) $page['rows'][1]['mode'], (string) $page['rows'][1]['job_id'], (int) $page['rows'][1]['user_id']]);
        $this->assertSame(['1.3', '1.2'], array_map(static fn (array $r): string => (string) $r['version'], $d->forDevice($dev, 2, 1)['rows']), 'newest first, with an offset');
        $this->assertSame(0, $d->forDevice($dev + 999, 10, 0)['total']);
        $this->assertSame(0, $d->deleteOlderThan(self::utc(self::T), 10));
        $this->assertSame(1, $d->deleteOlderThan(self::utc(self::T + 1), 10));
        $this->assertSame(4, $d->forDevice($dev, 10, 0)['total']);
        $this->assertSame(3, $d->deleteOlderThan(self::utc(self::T + 4), 10), 'the limit allows three more');
        $this->assertSame(1, $d->forDevice($dev, 10, 0)['total']);
    }

    // ------------------------------------------------------------------ hypervisor history

    public function testHypervisorHistoryRingRecentAndOrphans(): void
    {
        $h = $this->inventoryHypervisor();
        $dev = $this->device(1, 'HV-1');
        $a = $this->vm($dev);
        $b = $this->vm($dev);
        $states = ['running', 'stopped', 'running', 'paused', 'running'];
        for ($i = 1; $i < count($states); ++$i) {
            $h->recordTransition($a, $dev, self::utc(self::T + $i), $states[$i - 1], $states[$i], 3);
        }
        $h->recordTransition($b, $dev, self::utc(self::T), 'running', 'stopped', 3);
        $recent = $h->recent($a);
        $this->assertCount(3, $recent, 'the ring keeps the newest three');
        $this->assertSame([['at' => self::utc(self::T + 4), 'from_state' => 'paused', 'to_state' => 'running'], ['at' => self::utc(self::T + 3), 'from_state' => 'running', 'to_state' => 'paused'],
            ['at' => self::utc(self::T + 2), 'from_state' => 'stopped', 'to_state' => 'running']], $recent, 'newest first');
        $this->assertCount(2, $h->recent($a, 2));
        $this->assertCount(1, $h->recent($b));
        $this->assertSame([], $h->recent($b + 1000));
        $exists = fn (array $ids): array => $this->existingVms($ids);
        $this->assertSame(0, $h->deleteOrphans($exists), 'both VMs still exist');
        $this->deleteVm($a);
        $this->assertSame(3, $h->deleteOrphans($exists));
        $this->assertSame([], $h->recent($a));
        $this->assertCount(1, $h->recent($b), 'the other VM keeps its history');
        $this->assertSame(0, $h->deleteOrphans($exists));
    }

    // ------------------------------------------------------------------ logs

    /** @return array{rows:int,dropped_total:int,last_event_at:?string,last_batch_at:?string} */
    private function logLedger(int $deviceId): array
    {
        $l = $this->scopedLogs()->deviceLedger($deviceId);
        $this->assertNotNull($l, 'the device has a log ledger');

        return $l;
    }

    /** @return array{rows:int,dropped_total:int,last_change_at:?string,last_batch_at:?string,config_version:string,monitor_json:?string,monitor_at:?string} */
    private function changeLedger(int $deviceId): array
    {
        $l = $this->scopedChanges()->deviceLedger($deviceId);
        $this->assertNotNull($l, 'the device has a change ledger');

        return $l;
    }

    private const CAPS = ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000];

    /** @return array{ts:int,level:int,source:string,code:?int,message:string,hash:string} */
    private function entry(int $ts, int $level = 4, string $source = 'Service', ?int $code = null, string $message = 'message'): array
    {
        return ['ts' => $ts, 'level' => $level, 'source' => $source, 'code' => $code, 'message' => $message, 'hash' => substr(hash('sha256', $ts . '|' . $level . '|' . $source . '|' . ($code ?? '') . '|' . $message), 0, 32)];
    }

    public function testLogAdmitStoresEntriesAndKeepsTheLedger(): void
    {
        $l = $this->scopedLogs();
        $dev = $this->device(1, 'LG-1');
        $this->assertNull($l->deviceLedger($dev));
        $this->assertSame(0, $l->fleetRowCount());
        $r = $l->admit($dev, [$this->entry(self::T + 1), $this->entry(self::T + 9, 2, 'Kernel', 41, 'boom'), $this->entry(self::T + 5)], self::CAPS, self::T + 100, 2, 7);
        $this->assertSame(3, $r['inserted']);
        $this->assertSame(2, $r['dropped'], 'the entries the caller already rejected are part of the answer');
        $this->assertCount(3, $r['kept']);
        $ledger = $this->logLedger($dev);
        $this->assertSame(3, $ledger['rows']);
        $this->assertSame(2 + 7, $ledger['dropped_total'], 'rejected plus what the agent dropped itself');
        $this->assertSame(self::utc(self::T + 9), $ledger['last_event_at']);
        $this->assertSame(self::utc(self::T + 100), $ledger['last_batch_at']);
        $this->assertSame(3, $l->fleetRowCount());
        $r2 = $l->admit($dev, [$this->entry(self::T + 3)], self::CAPS, self::T + 200, 0, 0);
        $this->assertSame(1, $r2['inserted']);
        $this->assertSame(self::utc(self::T + 9), $this->logLedger($dev)['last_event_at'], 'the last event time never goes backwards');
        $this->assertSame(4, $this->logLedger($dev)['rows']);
        $this->assertSame(['inserted' => 0, 'dropped' => 0, 'kept' => []], $l->admit($dev, [], self::CAPS, self::T + 300, 0, 0));
    }

    public function testLogRepeatedEntriesAreStoredOnce(): void
    {
        $l = $this->scopedLogs();
        $dev = $this->device(1, 'LG-2');
        $other = $this->device(1, 'LG-3');
        $e = $this->entry(self::T + 1);
        $r = $l->admit($dev, [$e, $e, $this->entry(self::T + 2)], self::CAPS, self::T + 10, 0, 0);
        $this->assertSame(2, $r['inserted']);
        $this->assertCount(3, $r['kept'], 'kept is what the budgets admitted; the difference to inserted is the number of duplicates');
        $r = $l->admit($dev, [$e], self::CAPS, self::T + 20, 0, 0);
        $this->assertSame(0, $r['inserted'], 'a resent batch adds nothing');
        $this->assertSame(2, $this->logLedger($dev)['rows'], 'the ledger counts stored rows only');
        $this->assertSame(1, $l->admit($other, [$e], self::CAPS, self::T + 30, 0, 0)['inserted'], 'the same entry of another device is another entry');
    }

    public function testLogBudgetWindowAndCaps(): void
    {
        $l = $this->scopedLogs();
        $dev = $this->device(1, 'LG-4');
        $many = fn (int $n, int $from = 0): array => array_map(fn (int $i): array => $this->entry(self::T + $from + $i, 4, 'S', null, 'm' . $from . '-' . $i), range(0, $n - 1));
        $caps = ['rate_per_min' => 3, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000];
        $r = $l->admit($dev, $many(5), $caps, self::T + 1000, 1, 4);
        $this->assertSame([3, 3], [$r['inserted'], $r['dropped']], 'three fit the minute; two are over budget, one was rejected by the caller');
        $this->assertSame(1 + 2 + 4, $this->logLedger($dev)['dropped_total']);
        $r = $l->admit($dev, $many(2, 100), $caps, self::T + 1030, 0, 0);
        $this->assertSame([0, 2], [$r['inserted'], $r['dropped']], 'the budget of the minute is used up');
        $r = $l->admit($dev, $many(2, 200), $caps, self::T + 1061, 0, 0);
        $this->assertSame(2, $r['inserted'], 'a new minute starts a new budget');
        // the device cap is tolerated up to 20 % between prunes
        $dev2 = $this->device(1, 'LG-5');
        $caps = ['rate_per_min' => 1000, 'device_max_rows' => 10, 'fleet_max_rows' => 100000];
        $r = $l->admit($dev2, $many(15), $caps, self::T + 2000, 0, 0);
        $this->assertSame([12, 3], [$r['inserted'], $r['dropped']]);
        // the fleet cap is tolerated up to 10 %
        $dev3 = $this->device(1, 'LG-6');
        $total = $l->fleetRowCount();
        $caps = ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => $total];
        $room = (int) ceil($total * 1.1) - $total;
        $r = $l->admit($dev3, $many($room + 5), $caps, self::T + 3000, 0, 0);
        $this->assertSame([$room, 5], [$r['inserted'], $r['dropped']]);
    }

    public function testLogSearchFiltersOrdersPagesAndScopes(): void
    {
        $l = $this->scopedLogs();
        $a = $this->device(1, 'LOG-A');
        $b = $this->device(2, 'LOG-B');
        $l->admit($a, [$this->entry(self::T + 10, 1, 'Kernel', 100, 'disk failing'), $this->entry(self::T + 20, 4, 'Service Control', 7036, 'The 100% service entered_the running state'),
            $this->entry(self::T + 30, 5, 'Debugger', null, 'chatty'), $this->entry(self::T + 40, 2, 'Kernel', 101, 'second kernel')], self::CAPS, self::T + 99, 0, 0, 1);
        $l->admit($b, [$this->entry(self::T + 25, 3, 'Other', 5, 'warn on b')], self::CAPS, self::T + 99, 0, 0, 2);
        $window = fn (array $over = []): LogCriteria => new LogCriteria(self::utc(self::T), self::utc(self::T + 1000), ...$over);
        $all = $l->search($window(), 100, 0);
        $this->assertSame(5, $all['total']);
        $this->assertSame([self::utc(self::T + 40), self::utc(self::T + 30), self::utc(self::T + 25), self::utc(self::T + 20), self::utc(self::T + 10)], array_map(static fn (array $r): string => (string) $r['occurred_at'], $all['rows']), 'newest first');
        $row = $all['rows'][0];
        $this->assertSame([1, $a, 2, 'Kernel', 101, 'second kernel'], [(int) $row['client_id'], (int) $row['device_id'], (int) $row['level'], $row['source'], (int) $row['code'], $row['message']]);
        $this->assertArrayHasKey('event_id', $row);
        $this->assertArrayNotHasKey('hostname', $row, 'the host name is control data: the caller adds it');
        $this->assertSame(4, $l->search($window(['clientId' => 1]), 100, 0)['total']);
        $this->assertSame(1, $l->search($window(['clientId' => 2]), 100, 0)['total']);
        $this->assertSame(4, $l->search($window(['deviceId' => $a]), 100, 0)['total']);
        $this->assertSame(1, $l->search($window(['visibleClientIds' => [2]]), 100, 0)['total']);
        $this->assertSame(5, $l->search($window(['visibleClientIds' => [1, 2]]), 100, 0)['total']);
        $this->assertSame(['rows' => [], 'total' => 0], $l->search($window(['visibleClientIds' => []]), 100, 0), 'nobody visible, nothing found');
        $this->assertSame(0, $l->search($window(['clientId' => 2, 'visibleClientIds' => [1]]), 100, 0)['total'], 'the scope and the filter both apply');
        $this->assertSame(2, $l->search($window(['maxLevel' => 2]), 100, 0)['total'], 'this severity or worse');
        $this->assertSame(2, $l->search($window(['sourceContains' => 'kern']), 100, 0)['total'], 'case-insensitive substring');
        $this->assertSame(1, $l->search($window(['sourceContains' => 'Service Control']), 100, 0)['total']);
        $this->assertSame(1, $l->search($window(['code' => 7036]), 100, 0)['total']);
        $this->assertSame(1, $l->search($window(['messageContains' => '100%']), 100, 0)['total'], 'a percent sign is a character');
        $this->assertSame(1, $l->search($window(['messageContains' => 'entered_the']), 100, 0)['total'], 'an underscore is a character');
        $this->assertSame(0, $l->search($window(['messageContains' => 'enteredXthe']), 100, 0)['total']);
        $this->assertSame(0, $l->search($window(['messageContains' => "x' OR '1'='1"]), 100, 0)['total']);
        $this->assertSame(3, $l->search(new LogCriteria(self::utc(self::T + 20), self::utc(self::T + 30)), 100, 0)['total'], 'both bounds are inclusive');
        $page = $l->search($window(), 2, 1);
        $this->assertSame(5, $page['total']);
        $this->assertSame([self::utc(self::T + 30), self::utc(self::T + 25)], array_map(static fn (array $r): string => (string) $r['occurred_at'], $page['rows']));
        $this->assertCount(1, $l->search($window(), 0, 0)['rows'], 'a limit below one is one');
        $this->assertSame(0, $l->search($window(['deviceId' => $a + 12345]), 100, 0)['total']);
    }

    public function testLogCountMatchingStopsAtTheGivenCount(): void
    {
        $l = $this->scopedLogs();
        $dev = $this->device(1, 'LG-7');
        $other = $this->device(1, 'LG-8');
        $l->admit($dev, [$this->entry(self::T + 1, 1, 'Kernel', 100, 'disk a'), $this->entry(self::T + 2, 1, 'Kernel', 100, 'disk b'), $this->entry(self::T + 3, 4, 'Kernel', 100, 'disk c'),
            $this->entry(self::T + 4, 1, 'Net', 5, 'link 100% up'), $this->entry(self::T - 100, 1, 'Kernel', 100, 'old')], self::CAPS, self::T + 50, 0, 0);
        $l->admit($other, [$this->entry(self::T + 1, 1, 'Kernel', 100, 'disk a')], self::CAPS, self::T + 50, 0, 0);
        $since = self::utc(self::T);
        $this->assertSame(4, $l->countMatching($dev, $since, 5, '', null, '', 100));
        $this->assertSame(3, $l->countMatching($dev, $since, 2, '', null, '', 100), 'severity filter: three of the four are critical or error');
        $this->assertSame(3, $l->countMatching($dev, $since, 5, '', null, '', 3), 'counting stops at the given number');
        $this->assertSame(3, $l->countMatching($dev, $since, 5, 'kern', 100, 'disk', 100));
        $this->assertSame(2, $l->countMatching($dev, $since, 1, 'kern', 100, 'disk', 100));
        $this->assertSame(1, $l->countMatching($dev, $since, 5, '', null, '100%', 100));
        $this->assertSame(0, $l->countMatching($dev, $since, 5, '', null, 'link_100', 100));
        $this->assertSame(0, $l->countMatching($dev, self::utc(self::T + 100), 5, '', null, '', 100));
    }

    public function testLogOldestEventAndEnforceLimits(): void
    {
        $l = $this->scopedLogs();
        $a = $this->device(1, 'LGE-A');
        $b = $this->device(1, 'LGE-B');
        $this->assertNull($l->oldestEventAt());
        $this->assertNull($l->oldestEventAt($a));
        $mk = fn (int $n, int $from): array => array_map(fn (int $i): array => $this->entry($from + $i, 4, 'S', null, 'm' . $from . '-' . $i), range(0, $n - 1));
        $l->admit($a, array_merge($mk(4, self::T - 10 * self::DAY), $mk(8, self::T)), self::CAPS, self::T + 1, 0, 0);
        $l->admit($b, $mk(3, self::T + 100), self::CAPS, self::T + 1, 0, 0);
        $this->assertSame(self::utc(self::T - 10 * self::DAY), $l->oldestEventAt());
        $this->assertSame(self::utc(self::T - 10 * self::DAY), $l->oldestEventAt($a));
        $this->assertSame(self::utc(self::T + 100), $l->oldestEventAt($b));
        $pauses = [];
        $pause = static function (int $us) use (&$pauses): void {
            $pauses[] = $us;
        };
        // retention: the four old entries, in batches of two
        $r = $l->enforceLimits(self::utc(self::T - 5 * self::DAY), 1000, 1000, 100, 2, $pause);
        $this->assertSame(['retention' => 4, 'device_cap' => 0, 'fleet_cap' => 0], $r);
        $this->assertNotSame([], $pauses, 'the pause is called between batches');
        $this->assertSame(8, $this->logLedger($a)['rows'], 'the ledger follows the deletes');
        $this->assertSame(11, $l->fleetRowCount());
        $this->assertSame(self::utc(self::T), $l->oldestEventAt($a));
        // the device cap trims the oldest entries of the device above it
        $r = $l->enforceLimits(self::utc(self::T - 5 * self::DAY), 5, 1000, 100, 2);
        $this->assertSame(['retention' => 0, 'device_cap' => 3, 'fleet_cap' => 0], $r);
        $this->assertSame(5, $this->logLedger($a)['rows']);
        $this->assertSame(3, $this->logLedger($b)['rows'], 'a device under its cap is left alone');
        $this->assertSame(self::utc(self::T + 3), $l->oldestEventAt($a), 'the oldest went first');
        // the fleet cap trims the oldest entries of the fleet
        $r = $l->enforceLimits(self::utc(self::T - 5 * self::DAY), 1000, 6, 100, 100);
        $this->assertSame(['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 2], $r);
        $this->assertSame(6, $l->fleetRowCount());
        // a run cap bounds the whole call
        $r = $l->enforceLimits(self::utc(self::T + 1_000_000), 1000, 1000, 3, 2);
        $this->assertSame(3, $r['retention']);
        $this->assertSame(3, $l->fleetRowCount());
        $this->assertSame(['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 0], $l->enforceLimits(self::utc(self::T - 100 * self::DAY), 1000, 1000, 100, 100));
    }

    // ------------------------------------------------------------------ change records

    private const CHANGE_CAPS = ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000];

    /** @return array{ts:int,kind:string,watch:string,change:int,target:string,item:string,old:string,new:string,summary:string,old_hash:string,new_hash:string,actor:string,process:string,flags:int,dedupe:string} */
    private function record(int $ts, string $target = 'HKLM\\Run', string $item = 'Updater', int $change = 2, string $watch = 'win.run', string $kind = 'registry', string $actor = 'SYSTEM', string $new = 'v2', string $summary = ''): array
    {
        return ['ts' => $ts, 'kind' => $kind, 'watch' => $watch, 'change' => $change, 'target' => $target, 'item' => $item, 'old' => 'v1', 'new' => $new, 'summary' => $summary, 'old_hash' => '', 'new_hash' => '',
            'actor' => $actor, 'process' => 'proc.exe', 'flags' => 0, 'dedupe' => substr(hash('sha256', implode('|', [$ts, $kind, $watch, $change, $target, $item, $new, $actor])), 0, 32)];
    }

    public function testChangeAdmitKeepsTheLedgerAndTheAgentStatus(): void
    {
        $c = $this->scopedChanges();
        $dev = $this->device(1, 'CG-1');
        $this->assertFalse($c->hasLedger());
        $this->assertNull($c->deviceLedger($dev));
        $this->assertSame(['rows' => 0, 'devices' => 0], $c->ledgerTotals());
        $r = $c->admit($dev, [$this->record(self::T + 1), $this->record(self::T + 9, 'HKLM\\Other'), $this->record(self::T + 5, 'HKLM\\Third')], self::CHANGE_CAPS, self::T + 100, 1, 6, 'aaaaaaaaaaaaaaaa', '{"ok":true}');
        $this->assertSame([3, 1], [$r['inserted'], $r['dropped']]);
        $this->assertCount(3, $r['kept']);
        $this->assertTrue($c->hasLedger());
        $ledger = $this->changeLedger($dev);
        $this->assertSame([3, 1 + 6, self::utc(self::T + 9), self::utc(self::T + 100), 'aaaaaaaaaaaaaaaa', '{"ok":true}', self::utc(self::T + 100)],
            [$ledger['rows'], $ledger['dropped_total'], $ledger['last_change_at'], $ledger['last_batch_at'], $ledger['config_version'], $ledger['monitor_json'], $ledger['monitor_at']]);
        $this->assertSame(['rows' => 3, 'devices' => 1], $c->ledgerTotals());
        // an empty version and a null status leave what is stored
        $c->admit($dev, [$this->record(self::T + 20, 'HKLM\\Fourth')], self::CHANGE_CAPS, self::T + 200, 0, 0, '', null);
        $ledger = $this->changeLedger($dev);
        $this->assertSame(['aaaaaaaaaaaaaaaa', '{"ok":true}', self::utc(self::T + 100), 4], [$ledger['config_version'], $ledger['monitor_json'], $ledger['monitor_at'], $ledger['rows']]);
        $c->admit($dev, [], self::CHANGE_CAPS, self::T + 300, 0, 0, 'bbbbbbbbbbbbbbbb', '{"ok":false}');
        $ledger = $this->changeLedger($dev);
        $this->assertSame(['bbbbbbbbbbbbbbbb', '{"ok":false}', self::utc(self::T + 300)], [$ledger['config_version'], $ledger['monitor_json'], $ledger['monitor_at']]);
        // a repeated record is stored once
        $again = $c->admit($dev, [$this->record(self::T + 1)], self::CHANGE_CAPS, self::T + 400, 0, 0, '', null);
        $this->assertSame([0, 1], [$again['inserted'], count($again['kept'])]);
        $this->assertSame(4, $this->changeLedger($dev)['rows']);
    }

    public function testChangeBudgetAndCaps(): void
    {
        $c = $this->scopedChanges();
        $dev = $this->device(1, 'CG-2');
        $many = fn (int $n, int $from = 0): array => array_map(fn (int $i): array => $this->record(self::T + $from + $i, 'HKLM\\K' . $i), range(0, $n - 1));
        $caps = ['rate_per_min' => 3, 'device_max_rows' => 1000, 'fleet_max_rows' => 100000];
        $r = $c->admit($dev, $many(5), $caps, self::T + 1000, 0, 0, '', null);
        $this->assertSame([3, 2], [$r['inserted'], $r['dropped']]);
        $r = $c->admit($dev, $many(2, 100), $caps, self::T + 1030, 0, 0, '', null);
        $this->assertSame([0, 2], [$r['inserted'], $r['dropped']]);
        $r = $c->admit($dev, $many(2, 200), $caps, self::T + 1061, 0, 0, '', null);
        $this->assertSame(2, $r['inserted']);
        $dev2 = $this->device(1, 'CG-3');
        $r = $c->admit($dev2, $many(15), ['rate_per_min' => 1000, 'device_max_rows' => 10, 'fleet_max_rows' => 100000], self::T + 2000, 0, 0, '', null);
        $this->assertSame([12, 3], [$r['inserted'], $r['dropped']], 'the device cap plus 20 %');
        $dev3 = $this->device(1, 'CG-4');
        $total = $c->fleetRowCount();
        $room = (int) ceil($total * 1.1) - $total;
        $r = $c->admit($dev3, $many($room + 4), ['rate_per_min' => 1000, 'device_max_rows' => 1000, 'fleet_max_rows' => $total], self::T + 3000, 0, 0, '', null);
        $this->assertSame([$room, 4], [$r['inserted'], $r['dropped']], 'the fleet cap plus 10 %');
    }

    public function testChangeDedupeKeysOfOneInstant(): void
    {
        $c = $this->scopedChanges();
        $dev = $this->device(1, 'CG-5');
        $a = $this->record(self::T + 1);
        $b = $this->record(self::T + 2, 'HKLM\\B');
        $c->admit($dev, [$a, $b], self::CHANGE_CAPS, self::T + 50, 0, 0, '', null);
        $c->admit($dev, [$this->record(self::T + 3, 'HKLM\\C')], self::CHANGE_CAPS, self::T + 60, 0, 0, '', null);
        $keys = $c->dedupeKeysReceivedAt($dev, self::utc(self::T + 50));
        sort($keys);
        $expected = [$a['dedupe'], $b['dedupe']];
        sort($expected);
        $this->assertSame($expected, $keys, 'lower-case hex digests of the records received at that second');
        $this->assertSame([], $c->dedupeKeysReceivedAt($dev, self::utc(self::T + 51)));
        $this->assertSame([], $c->dedupeKeysReceivedAt($dev + 999, self::utc(self::T + 50)));
    }

    public function testChangeSearchFiltersOrdersPagesAndScopes(): void
    {
        $c = $this->scopedChanges();
        $a = $this->device(1, 'CHG-A');
        $b = $this->device(2, 'CHG-B');
        $c->admit($a, [
            $this->record(self::T + 10, 'HKLM\\Run', 'Updater', 2, 'win.run', 'registry', 'alice', 'v2', 'changed the updater'),
            $this->record(self::T + 20, 'C:\\Tools\\100%_done.txt', 'file', 1, 'win.tools', 'file', 'bob', 'new', ''),
            $this->record(self::T + 30, 'HKLM\\Run', 'Other', 3, 'win.run', 'registry', 'alice', '', ''),
            $this->record(self::T + 40, 'lnx-group', 'wheel', 2, 'lnx.groups', 'group', '', 'bob', 'sudo membership'),
        ], self::CHANGE_CAPS, self::T + 99, 0, 0, '', null, 1);
        $c->admit($b, [$this->record(self::T + 25, 'HKLM\\Run', 'Updater', 2, 'win.run', 'registry', 'carol', 'v3', '')], self::CHANGE_CAPS, self::T + 99, 0, 0, '', null, 2);
        $q = fn (array $over = []): ChangeCriteria => new ChangeCriteria(self::utc(self::T), self::utc(self::T + 1000), ...$over);
        $all = $c->search($q(), 100, 0);
        $this->assertSame(5, $all['total']);
        $this->assertSame([self::utc(self::T + 40), self::utc(self::T + 30), self::utc(self::T + 25), self::utc(self::T + 20), self::utc(self::T + 10)], array_map(static fn (array $r): string => (string) $r['occurred_at'], $all['rows']), 'newest first');
        $row = $all['rows'][0];
        $this->assertSame([1, $a, 'group', 'lnx.groups', 2, 'lnx-group', 'wheel'], [(int) $row['client_id'], (int) $row['device_id'], $row['kind'], $row['watch'], (int) $row['change_type'], $row['target'], $row['item']]);
        $this->assertArrayNotHasKey('hostname', $row, 'the host name is control data: the caller adds it');
        foreach (['change_id', 'old_value', 'new_value', 'summary', 'old_hash', 'new_hash', 'actor', 'process', 'flags'] as $col) {
            $this->assertArrayHasKey($col, $row);
        }
        $this->assertSame(4, $c->search($q(['deviceId' => $a]), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['clientId' => 2]), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['visibleClientIds' => [2]]), 100, 0)['total']);
        $this->assertSame(['rows' => [], 'total' => 0], $c->search($q(['visibleClientIds' => []]), 100, 0));
        $this->assertSame(0, $c->search($q(['clientId' => 2, 'visibleClientIds' => [1]]), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['kind' => 'file']), 100, 0)['total']);
        $this->assertSame(3, $c->search($q(['watch' => 'win.run']), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['changeType' => 3]), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['targetContains' => '100%_done']), 100, 0)['total'], 'percent and underscore are characters');
        $this->assertSame(0, $c->search($q(['targetContains' => '100X_done']), 100, 0)['total']);
        $this->assertSame(2, $c->search($q(['itemContains' => 'updat']), 100, 0)['total'], 'case-insensitive substring');
        $this->assertSame(2, $c->search($q(['actorContains' => 'ALICE']), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['anyText' => 'sudo']), 100, 0)['total'], 'any of target, item, old, new, summary');
        $this->assertSame(1, $c->search($q(['anyText' => 'changed the']), 100, 0)['total']);
        $this->assertSame(1, $c->search($q(['anyText' => 'v3']), 100, 0)['total']);
        $this->assertSame(0, $c->search($q(['anyText' => "x' OR '1'='1"]), 100, 0)['total']);
        $this->assertSame(0, $c->search($q(['deviceId' => $a, 'kind' => 'task']), 100, 0)['total'], 'filters combine');
        $this->assertSame(4, $c->search(new ChangeCriteria(self::utc(self::T + 20), self::utc(self::T + 40)), 100, 0)['total'], 'both bounds are inclusive');
        $page = $c->search($q(), 2, 1);
        $this->assertSame(5, $page['total']);
        $this->assertSame([self::utc(self::T + 30), self::utc(self::T + 25)], array_map(static fn (array $r): string => (string) $r['occurred_at'], $page['rows']));
    }

    public function testChangeTimelineCountsAndCountMatching(): void
    {
        $c = $this->scopedChanges();
        $a = $this->device(1, 'CHT-A');
        $b = $this->device(2, 'CHT-B');
        $d1 = intdiv(self::T, self::DAY) * self::DAY;
        $c->admit($a, [
            $this->record($d1 + 100, 'HKLM\\Run', 'Updater', 2, 'win.run', 'registry', 'alice'),
            $this->record($d1 + 200, 'HKLM\\Run', 'Other', 2, 'win.run', 'registry', 'alice'),
            $this->record($d1 + self::DAY + 5, 'C:\\x.txt', 'file', 1, 'win.tools', 'file', 'bob'),
            $this->record($d1 + 3 * self::DAY, 'HKLM\\Run', 'Late', 3, 'win.run', 'registry', ''),
        ], self::CHANGE_CAPS, $d1 + 4 * self::DAY, 0, 0, '', null, 1);
        $c->admit($b, [$this->record($d1 + 150, 'HKLM\\Run', 'Updater', 2, 'win.run', 'registry', 'carol')], self::CHANGE_CAPS, $d1 + 4 * self::DAY, 0, 0, '', null, 2);
        $tl = $c->timeline($a, self::utc($d1), self::utc($d1 + 5 * self::DAY), 100);
        $this->assertCount(4, $tl['rows']);
        $this->assertSame($d1 + 3 * self::DAY, strtotime($tl['rows'][0]['occurred_at'] . ' UTC'), 'newest first');
        $this->assertSame([$a, 1], [(int) $tl['rows'][0]['device_id'], (int) $tl['rows'][0]['client_id']]);
        $this->assertSame('alice', $tl['actors'][0]['actor'], 'most active first');
        $this->assertSame(2, $tl['actors'][0]['c']);
        $this->assertCount(3, $tl['actors']);
        $this->assertSame([[gmdate('Y-m-d', $d1 + 3 * self::DAY), 1], [gmdate('Y-m-d', $d1 + self::DAY), 1], [gmdate('Y-m-d', $d1), 2]], array_map(static fn (array $r): array => [$r['d'], $r['c']], $tl['days']), 'one count per day, newest day first');
        $this->assertCount(2, $c->timeline($a, self::utc($d1), self::utc($d1 + 5 * self::DAY), 2)['rows'], 'the limit bounds the rows');
        $this->assertSame([], $c->timeline($a, self::utc($d1 + 10 * self::DAY), self::utc($d1 + 11 * self::DAY), 100)['rows']);
        // countMatching
        $since = self::utc($d1);
        $this->assertSame(4, $c->countMatching($a, $since, '', '', 0, '', '', 100));
        $this->assertSame(3, $c->countMatching($a, $since, 'win.run', '', 0, '', '', 100));
        $this->assertSame(3, $c->countMatching($a, $since, '', 'registry', 0, '', '', 100));
        $this->assertSame(1, $c->countMatching($a, $since, '', '', 3, '', '', 100));
        $this->assertSame(3, $c->countMatching($a, $since, '', '', 0, 'HKLM', '', 100), 'target filter');
        $this->assertSame(1, $c->countMatching($a, $since, '', '', 0, 'HKLM', 'updater', 100), 'target and item filters combine');
        $this->assertSame(2, $c->countMatching($a, $since, '', '', 0, '', '', 2), 'counting stops at the given number');
        $this->assertSame(0, $c->countMatching($a, self::utc($d1 + 10 * self::DAY), '', '', 0, '', '', 100));
        // counts by watch, fleet rows
        $watches = $c->countsByWatch(self::utc($d1), null);
        $this->assertSame(['win.run' => 4, 'win.tools' => 1], array_column($watches, 'c', 'watch'));
        $this->assertSame('win.run', $watches[0]['watch'], 'most first');
        $this->assertSame(['win.run' => 1], array_column($c->countsByWatch(self::utc($d1), [2]), 'c', 'watch'));
        $this->assertSame([], $c->countsByWatch(self::utc($d1), []));
        $this->assertSame([], $c->countsByWatch(self::utc($d1 + 10 * self::DAY), null));
        $this->assertSame(5, $c->fleetRowCount());
        $this->assertSame(5, $c->fleetRowCount(null));
        $this->assertSame(1, $c->fleetRowCount([2]));
        $this->assertSame(0, $c->fleetRowCount([]));
        $this->assertSame(['rows' => 5, 'devices' => 2], $c->ledgerTotals());
    }

    public function testChangeEnforceLimits(): void
    {
        $c = $this->scopedChanges();
        $a = $this->device(1, 'CHE-A');
        $b = $this->device(1, 'CHE-B');
        $mk = fn (int $n, int $from): array => array_map(fn (int $i): array => $this->record($from + $i, 'HKLM\\K' . $from . '-' . $i), range(0, $n - 1));
        $c->admit($a, array_merge($mk(4, self::T - 10 * self::DAY), $mk(8, self::T)), self::CHANGE_CAPS, self::T + 1, 0, 0, '', null);
        $c->admit($b, $mk(3, self::T + 100), self::CHANGE_CAPS, self::T + 1, 0, 0, '', null);
        $pauses = [];
        $pause = static function (int $us) use (&$pauses): void {
            $pauses[] = $us;
        };
        $r = $c->enforceLimits(self::utc(self::T - 5 * self::DAY), 1000, 1000, 100, 2, $pause);
        $this->assertSame(['retention' => 4, 'device_cap' => 0, 'fleet_cap' => 0], $r);
        $this->assertNotSame([], $pauses);
        $this->assertSame(8, $this->changeLedger($a)['rows'], 'the ledger follows the deletes');
        $this->assertSame(11, $c->fleetRowCount());
        $r = $c->enforceLimits(self::utc(self::T - 5 * self::DAY), 5, 1000, 100, 2);
        $this->assertSame(['retention' => 0, 'device_cap' => 3, 'fleet_cap' => 0], $r);
        $this->assertSame([5, 3], [$this->changeLedger($a)['rows'], $this->changeLedger($b)['rows']]);
        $r = $c->enforceLimits(self::utc(self::T - 5 * self::DAY), 1000, 6, 100, 100);
        $this->assertSame(['retention' => 0, 'device_cap' => 0, 'fleet_cap' => 2], $r);
        $this->assertSame(6, $c->fleetRowCount());
        $r = $c->enforceLimits(self::utc(self::T + 1_000_000), 1000, 1000, 3, 2);
        $this->assertSame(3, $r['retention'], 'a run cap bounds the whole call');
        $this->assertSame(3, $c->fleetRowCount());
        $this->assertTrue($c->hasLedger(), 'the ledger rows stay');
    }

    // ------------------------------------------------------------------ the client travels with the rows

    public function testLogEntriesCarryTheirClientAndAMoveFollowsTheDevice(): void
    {
        $l = $this->scopedLogs();
        $a = $this->device(1, 'LC-A');
        $unknown = $this->device(1, 'LC-B');
        $l->admit($a, [$this->entry(self::T + 1), $this->entry(self::T + 2)], self::CAPS, self::T + 10, 0, 0, 1);
        $l->admit($unknown, [$this->entry(self::T + 3)], self::CAPS, self::T + 10, 0, 0);   // written without a client (an older release during the upgrade)
        $q = fn (array $over = []): LogCriteria => new LogCriteria(self::utc(self::T), self::utc(self::T + 100), ...$over);
        $rows = $l->search($q(), 10, 0)['rows'];
        $this->assertSame([null, 1, 1], array_map(static fn (array $r): ?int => $r['client_id'] === null ? null : (int) $r['client_id'], $rows), 'newest first; a row without a client stays NULL');
        $this->assertSame(3, $l->search($q(), 10, 0)['total'], 'an unscoped reader sees every row');
        $this->assertSame(2, $l->search($q(['visibleClientIds' => [1]]), 10, 0)['total'], 'a reader scoped to client 1 sees the rows stored with client 1 and not the unknown ones');
        $this->assertSame(0, $l->search($q(['visibleClientIds' => [2]]), 10, 0)['total']);
        $this->assertSame(2, $l->reassignClient($a, 2), 'both entries of the device move');
        $this->assertSame(0, $l->reassignClient($a, 2), 'a second call changes nothing');
        $this->assertSame(0, $l->search($q(['visibleClientIds' => [1]]), 10, 0)['total']);
        $this->assertSame(2, $l->search($q(['clientId' => 2]), 10, 0)['total']);
        $this->assertSame(1, $l->reassignClient($unknown, 1), 'a row without a client gets one');
        $this->assertSame(1, $l->search($q(['visibleClientIds' => [1]]), 10, 0)['total']);
        $this->assertSame(2, $this->logLedger($a)['rows'], 'the ledger is not touched');
    }

    public function testChangeRecordsAndTheirLedgerCarryTheClientAndAMoveFollowsTheDevice(): void
    {
        $c = $this->scopedChanges();
        $a = $this->device(1, 'CC-A');
        $b = $this->device(2, 'CC-B');
        $c->admit($a, [$this->record(self::T + 1), $this->record(self::T + 2, 'HKLM\\Two')], self::CHANGE_CAPS, self::T + 10, 0, 0, '', null, 1);
        $c->admit($b, [$this->record(self::T + 3, 'HKLM\\Three')], self::CHANGE_CAPS, self::T + 10, 0, 0, '', null, 2);
        $q = fn (array $over = []): ChangeCriteria => new ChangeCriteria(self::utc(self::T), self::utc(self::T + 100), ...$over);
        $this->assertSame([2, 1], [$c->fleetRowCount([1]), $c->fleetRowCount([2])], 'the scoped fleet count follows the ledger, not a join');
        $this->assertSame(2, $c->search($q(['visibleClientIds' => [1]]), 10, 0)['total']);
        $this->assertSame(['win.run' => 2], array_column($c->countsByWatch(self::utc(self::T), [1]), 'c', 'watch'));
        $this->assertSame(2, $c->reassignClient($a, 2));
        $this->assertSame(0, $c->reassignClient($a, 2));
        $this->assertSame([0, 3], [$c->fleetRowCount([1]), $c->fleetRowCount([2])], 'the ledger moved with the records');
        $this->assertSame(0, $c->search($q(['visibleClientIds' => [1]]), 10, 0)['total']);
        $this->assertSame(3, $c->search($q(['visibleClientIds' => [2]]), 10, 0)['total']);
        // a later batch of a device that changed client by itself (the caller passes the current one) updates the ledger
        $c->admit($b, [$this->record(self::T + 4, 'HKLM\\Four')], self::CHANGE_CAPS, self::T + 20, 0, 0, '', null, 1);
        $this->assertSame([2, 2], [$c->fleetRowCount([1]), $c->fleetRowCount([2])], 'the ledger row took the new client (the old records keep theirs until reassignClient)');
        // a batch without a client leaves the ledger's client alone
        $c->admit($b, [$this->record(self::T + 5, 'HKLM\\Five')], self::CHANGE_CAPS, self::T + 30, 0, 0, '', null);
        $this->assertSame([3, 2], [$c->fleetRowCount([1]), $c->fleetRowCount([2])]);
    }

    // ------------------------------------------------------------------ maintenance

    public function testMaintenanceRunAndTiersReportTheirShape(): void
    {
        $m = $this->stores()->maintenance();
        $limits = ['partitioning' => 0, 'partition_span_days' => 1, 'partition_ahead' => 7, 'check_history_days' => 7, 'metric_hourly_days' => 14, 'metric_daily_days' => 400];
        $run = $m->run($limits, [], 14, 400, 5);
        $this->assertSame(['rollup', 'partitions', 'partitioned', 'retention'], array_keys($run));
        $this->assertSame([], $run['partitioned'], 'partitioning is off: nothing is trimmed by dropping partitions');
        $this->assertSame(14, $run['retention']['rmm_metric_hourly']);
        $this->assertSame(400, $run['retention']['rmm_metric_daily']);
        $this->assertSame(7, $run['retention']['endpoint_agent_check_history']);
        $tiers = $m->tiers($limits, ['metric_hourly_days' => 30]);
        $this->assertSame(['partitioning_enabled', 'rollup_watermark', 'rollup_behind_days', 'tables'], array_keys($tiers));
        $this->assertFalse($tiers['partitioning_enabled']);
        $this->assertNull($tiers['rollup_watermark']);
        $this->assertNull($tiers['rollup_behind_days']);
        $this->assertSame(30, $tiers['tables']['rmm_metric_hourly']['retention_days'], 'an explicit limit wins');
        $this->assertSame(400, $tiers['tables']['rmm_metric_daily']['retention_days']);
        foreach ($tiers['tables'] as $t) {
            $this->assertFalse($t['partitioned']);
            $this->assertSame(0, $t['partitions']);
        }
    }
}
