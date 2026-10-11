<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Rmm\Metrics\CompositeMetricSink;
use RivetCore\Rmm\Metrics\ExternalMetricReader;
use RivetCore\Rmm\Metrics\ExternalMetricSink;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\RmmHarness;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\ScratchDb;
use RivetCore\Tests\Support\ScriptedExternalStore;

/** The module finds the database sink and the external buffer inside a composite sink, and housekeeping drives the storage jobs and the flush. */
final class ScaleWiringTest extends RmmTestCase
{
    private ScriptedExternalStore $store;
    private MutableClock $clock;
    private CompositeMetricSink $composite;

    private ?\mysqli $conn = null;

    protected function makeHarness(): RmmHarness
    {
        $this->clock = new MutableClock();
        $this->store = new ScriptedExternalStore();
        // the sinks use a connection of their own (the harness closes its connections between tests)
        $this->conn = ScratchDb::connect();
        $db = new MysqliDatabase($this->conn);
        $this->composite = new CompositeMetricSink([[new DatabaseMetricSink($db, $this->clock), true], [new ExternalMetricSink($db, $this->clock, $this->store), false]]);

        return new RmmHarness($this->clock, null, [], false, $this->composite);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->conn?->close();
    }

    public function testHousekeepingFlushesTheExternalBufferAndRunsTheRollupThroughAComposite(): void
    {
        $this->h->enable();
        $at = new \DateTimeImmutable('@' . (intdiv($this->clock->now()->getTimestamp(), 86400) * 86400 - 3 * 86400 + 3600));
        $this->composite->ingest([['asset_id' => 7, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 40, 'at' => $at, 'label' => null]], 1);
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_outbox'));
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_hourly'));
        $r = $this->h->module->housekeeping()->run();
        $this->assertSame(1, $r['external_sent'], 'the buffered batch was delivered by housekeeping');
        $this->assertSame(0, $r['external_pending']);
        $this->assertSame(1, $this->store->pointCount());
        $this->assertGreaterThanOrEqual(1, $r['rollup_days'], 'the database sink inside the composite is rolled up');
        $this->assertSame(1, (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_daily'));
        $this->assertArrayHasKey('pruned_cache', $r);
        $panel = $this->h->module->capacity()->build();
        $this->assertSame(['pending_batches' => 0, 'dropped_points' => 0], $panel['external_store']);
    }

    public function testAMetricReaderOptionAnswersTheHistoryQuestions(): void
    {
        $local = new DatabaseMetricSink($this->h->db, $this->clock);
        $reader = new ExternalMetricReader($this->store, $local);
        $h = new RmmHarness($this->clock, null, ['metric_reader' => $reader], false, $this->composite);
        $this->assertNotNull($h->module->readModel());
        $m = new \ReflectionMethod($h->module, 'metricReader');
        $this->assertSame($reader, $m->invoke($h->module));
        $m2 = new \ReflectionMethod($this->h->module, 'metricReader');
        $this->assertInstanceOf(DatabaseMetricSink::class, $m2->invoke($this->h->module), 'without the option the database sink inside the composite is the reader');
    }

    public function testWithoutAnExternalSinkThePanelHasNoExternalBlock(): void
    {
        $h = new RmmHarness($this->clock);
        $this->assertNull($h->module->capacity()->build()['external_store']);
        $this->assertArrayNotHasKey('external_sent', $h->module->housekeeping()->run());
    }
}
