<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Database\DatabaseException;
use RivetCore\Database\DatabaseInterface;
use RivetCore\Database\ExecutionResult;
use RivetCore\Rmm\Metrics\CompositeMetricSink;
use RivetCore\Rmm\Metrics\ExternalMetricReader;
use RivetCore\Rmm\Metrics\ExternalMetricSink;
use RivetCore\Rmm\Metrics\ExternalStoreException;
use RivetCore\Rmm\Support\DatabaseMetricSink;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\RmmTestCase;
use RivetCore\Tests\Support\ScriptedExternalStore;

/** The outbox in front of an external metric store, on the real table (migration 0021). */
final class ExternalMetricSinkTest extends RmmTestCase
{
    private MutableClock $clock;
    private ScriptedExternalStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h->q('DELETE FROM rmm_metric_outbox');
        $this->clock = new MutableClock();
        $this->store = new ScriptedExternalStore();
    }

    /** @param array{max_rows?:int, max_age_s?:int, batch_rows?:int, max_attempts?:?int} $options */
    private function sink(array $options = [], ?DatabaseInterface $db = null): ExternalMetricSink
    {
        return new ExternalMetricSink($db ?? $this->h->counting, $this->clock, $this->store, $options);
    }

    /** @return array{asset_id:int,key:string,instance:?string,value:int|float,at:\DateTimeImmutable,label:?string} */
    private static function s(int $asset, string $key = 'cpu.utilization', int|float $v = 10, ?string $inst = null, ?int $ts = null, ?string $label = null): array
    {
        return ['asset_id' => $asset, 'key' => $key, 'instance' => $inst, 'value' => $v, 'at' => new \DateTimeImmutable('@' . ($ts ?? time() - 10)), 'label' => $label];
    }

    private function outbox(): int
    {
        return (int) $this->h->one('SELECT COUNT(*) FROM rmm_metric_outbox');
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        return $this->h->rows('SELECT * FROM rmm_metric_outbox ORDER BY outbox_id');
    }

    private function utc(int $offset = 0): string
    {
        return gmdate('Y-m-d H:i:s', time() + $offset);
    }

    // ------------------------------------------------------------------ ingest

    public function testIngestIsOneStatementAndNoNetwork(): void
    {
        $sink = $this->sink();
        $before = $this->h->counting->statements;
        $sink->ingest([self::s(1), self::s(1, 'memory.utilization', 50), self::s(2, 'disk.utilization', 30, 'C:', null, 'System')], 1);
        $this->assertSame(1, $this->h->counting->statements - $before, 'exactly one INSERT');
        $this->assertSame(0, $this->store->writeCalls, 'no store I/O while ingesting');
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['points']);
        $this->assertSame(0, (int) $rows[0]['attempts']);
        $this->assertNull($rows[0]['lease_until']);
        $this->assertSame(0, $sink->droppedCount());
    }

    public function testManyPointsAreSplitIntoRowsOfFiveHundredInOneStatement(): void
    {
        $samples = [];
        for ($i = 0; $i < 1203; ++$i) {
            $samples[] = self::s(1, 'cpu.utilization', $i % 100, null, 1_700_000_000 + $i);
        }
        $before = $this->h->counting->statements;
        $this->sink()->ingest($samples, 1);
        $this->assertSame(1, $this->h->counting->statements - $before);
        $this->assertSame([500, 500, 203], array_map(static fn (array $r): int => (int) $r['points'], $this->rows()));
    }

    public function testInvalidSamplesAreDroppedLikeTheDatabaseSink(): void
    {
        $this->sink()->ingest([
            self::s(1, 'cpu.utilization', 150), self::s(1, 'cpu.utilization', -1), self::s(1, 'cpu.utilization', NAN), self::s(1, 'memory.total_bytes', INF),
            self::s(0), self::s(-4), self::s(1, 'Bad Key'), self::s(1, "cpu.utilization\n"), self::s(1, ''), self::s(1, str_repeat('a', 65)),
            self::s(1, 'cpu.utilization', 100), self::s(1, 'memory.total_bytes', 17179869184), self::s(1, 'cpu.utilization', 0),
        ], 1);
        $this->sink()->ingest([self::s(1, 'cpu.utilization', 150)], 1);
        $this->assertSame(1, $this->outbox(), 'all-invalid batches insert nothing');
        $store = new ScriptedExternalStore();
        (new ExternalMetricSink($this->h->db, $this->clock, $store))->flush();
        $this->assertSame([100, 17179869184, 0], array_column($store->batches[0] ?? [], 'value'));
    }

    public function testLongInstanceAndLabelAreCutAndTheValuesSurviveTheTrip(): void
    {
        $this->sink()->ingest([self::s(9, 'disk.utilization', 12.25, str_repeat('é', 100), 1_700_000_123, str_repeat('L', 100)), self::s(9, 'memory.total_bytes', 8_589_934_592, null, 1_700_000_124), self::s(9, 'cpu.utilization', 50.0, 'Ünï "q" \\ ,', 1_700_000_125)], 1);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'permanent' => 0, 'dropped' => 0, 'pending' => 0], $this->sink()->flush());
        $p = $this->store->batches[0];
        $this->assertSame(str_repeat('é', 64), $p[0]['instance']);
        $this->assertSame(str_repeat('L', 64), $p[0]['label']);
        $this->assertSame(12.25, $p[0]['value']);
        $this->assertSame(1_700_000_123, $p[0]['at']->getTimestamp());
        $this->assertSame(8_589_934_592, $p[1]['value'], 'an integer stays an integer');
        $this->assertNull($p[1]['instance']);
        $this->assertNull($p[1]['label']);
        $this->assertSame('Ünï "q" \\ ,', $p[2]['instance']);
        $this->assertSame(50.0, $p[2]['value'], 'a float stays a float');
        $this->assertSame(9, $p[2]['asset_id']);
    }

    public function testADatabaseFailureNeverFailsIngest(): void
    {
        $broken = new class implements DatabaseInterface {
            public function fetchOne(string $sql, array $params = []): ?array
            {
                throw new DatabaseException('down');
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                throw new DatabaseException('down');
            }

            public function execute(string $sql, array $params = []): ExecutionResult
            {
                throw new DatabaseException('down');
            }

            public function transaction(callable $callback): mixed
            {
                throw new DatabaseException('down');
            }
        };
        $sink = $this->sink([], $broken);
        $sink->ingest([self::s(1), self::s(1, 'memory.utilization', 20)], 1);
        $this->assertSame(2, $sink->droppedCount());
        $sink->ingest([self::s(1)], 1);
        $this->assertSame(3, $sink->droppedCount());
        $sink->ingest([], 1);
        $this->assertSame(3, $sink->droppedCount());
    }

    public function testAMissingTableNeverFailsIngestEither(): void
    {
        $this->h->q('RENAME TABLE rmm_metric_outbox TO rmm_metric_outbox_gone');
        try {
            $sink = $this->sink();
            $sink->ingest([self::s(1)], 1);
            $this->assertSame(1, $sink->droppedCount());
        } finally {
            $this->h->q('RENAME TABLE rmm_metric_outbox_gone TO rmm_metric_outbox');
        }
    }

    public function testAnUnreachableStoreDoesNotMatterToIngest(): void
    {
        $this->store->script = array_fill(0, 5, new ExternalStoreException('down'));
        $sink = $this->sink();
        for ($i = 0; $i < 10; ++$i) {
            $sink->ingest([self::s(1)], 1);
        }
        $this->assertSame(10, $this->outbox());
        $this->assertSame(0, $this->store->writeCalls);
    }

    // ------------------------------------------------------------------ bounds

    public function testTheOutboxIsBoundedByRowsKeepingTheNewest(): void
    {
        $sink = $this->sink(['max_rows' => 5]);
        for ($i = 1; $i <= 12; ++$i) {
            $sink->ingest([self::s($i)], 1);
        }
        $this->assertSame(12, $this->outbox());
        $this->assertSame(7, $sink->trim());
        $this->assertSame(5, $this->outbox());
        $this->assertSame(0, $sink->trim(), 'idempotent');
        $store = new ScriptedExternalStore();
        (new ExternalMetricSink($this->h->db, $this->clock, $store))->flush();
        $this->assertSame([8, 9, 10, 11, 12], array_map(static fn (array $b): int => $b[0]['asset_id'], $store->batches), 'the oldest rows were dropped');
    }

    public function testTheOutboxIsBoundedByAge(): void
    {
        $sink = $this->sink(['max_age_s' => 3600]);
        $sink->ingest([self::s(1)], 1);
        $sink->ingest([self::s(2)], 1);
        $this->h->q("UPDATE rmm_metric_outbox SET created_at = '" . $this->utc(-7200) . "' ORDER BY outbox_id LIMIT 1");
        $this->assertSame(1, $sink->trim());
        $this->assertSame(1, $this->outbox());
        $this->clock->advance(3601);
        $result = $sink->flush();
        $this->assertSame(1, $result['dropped'], 'flush trims first: what is older than max_age_s is not sent');
        $this->assertSame(0, $result['sent']);
        $this->assertSame(0, $result['pending']);
    }

    public function testFlushTrimsBeforeItSends(): void
    {
        $sink = $this->sink(['max_rows' => 2]);
        for ($i = 1; $i <= 4; ++$i) {
            $sink->ingest([self::s($i)], 1);
        }
        $r = $sink->flush();
        $this->assertSame(['sent' => 2, 'failed' => 0, 'permanent' => 0, 'dropped' => 2, 'pending' => 0], $r);
    }

    // ------------------------------------------------------------------ flush

    public function testFlushSendsAndDeletesInOrderAndIsIdempotent(): void
    {
        $sink = $this->sink();
        foreach ([1, 2, 3] as $a) {
            $sink->ingest([self::s($a)], 1);
        }
        $this->assertSame(['sent' => 3, 'failed' => 0, 'permanent' => 0, 'dropped' => 0, 'pending' => 0], $sink->flush());
        $this->assertSame([1, 2, 3], array_map(static fn (array $b): int => $b[0]['asset_id'], $this->store->batches));
        $this->assertSame(['sent' => 0, 'failed' => 0, 'permanent' => 0, 'dropped' => 0, 'pending' => 0], $sink->flush());
        $this->assertSame(3, $this->store->writeCalls);
    }

    public function testFlushIsBoundedByTheNumberOfBatches(): void
    {
        $sink = $this->sink(['batch_rows' => 2]);
        for ($i = 1; $i <= 7; ++$i) {
            $sink->ingest([self::s($i)], 1);
        }
        $r = $sink->flush(2);
        $this->assertSame(4, $r['sent'], '2 rounds of 2 rows');
        $this->assertSame(3, $r['pending']);
        $this->assertSame(3, $sink->flush(10)['sent']);
    }

    public function testATransientFailureKeepsTheRowWithBackoffAndStopsTheFlush(): void
    {
        $sink = $this->sink();
        $sink->ingest([self::s(1)], 1);
        $sink->ingest([self::s(2)], 1);
        $this->store->script = [new ExternalStoreException('metric store request failed: Authorization: Bearer S3CRET-TOKEN https://user:pw@vm.example/x'), null];
        $r = $sink->flush();
        $this->assertSame(['sent' => 0, 'failed' => 1, 'permanent' => 0, 'dropped' => 0, 'pending' => 2], $r);
        $this->assertSame(1, $this->store->writeCalls, 'a down store costs one attempt per flush, not one per row');
        $row = $this->rows()[0];
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertNull($row['lease_until'], 'the lease is cleared');
        $delay = strtotime($row['next_attempt_at'] . ' UTC') - $this->clock->now()->getTimestamp();
        $this->assertGreaterThanOrEqual(3, $delay);
        $this->assertLessThanOrEqual(7, $delay, 'first retry in 5 seconds +/- 20%');
        $this->assertStringNotContainsString('S3CRET-TOKEN', (string) $row['last_error']);
        $this->assertStringNotContainsString('pw@', (string) $row['last_error']);
        $this->assertStringContainsString('metric store request failed', (string) $row['last_error']);
        // the failed row is not due yet; the row behind it was never tried and goes through
        $this->assertSame(1, $sink->flush()['sent']);
        $this->assertSame(2, $this->store->writeCalls);
        $this->assertSame(0, $sink->flush()['sent']);
        // after the delay the failed row goes through
        $this->clock->advance(10);
        $this->assertSame(1, $sink->flush()['sent']);
        $this->assertSame(0, $this->outbox());
    }

    public function testBackoffGrowsWithEveryFailure(): void
    {
        $sink = $this->sink();
        $sink->ingest([self::s(1)], 1);
        $delays = [];
        for ($i = 0; $i < 6; ++$i) {
            $this->store->script = [new ExternalStoreException('down')];
            $this->assertSame(1, $sink->flush()['failed']);
            $row = $this->rows()[0];
            $delays[] = strtotime($row['next_attempt_at'] . ' UTC') - $this->clock->now()->getTimestamp();
            $this->clock->advance(2000);
        }
        $this->assertSame(6, (int) $this->rows()[0]['attempts']);
        $expected = [5, 10, 20, 40, 80, 160];
        foreach ($delays as $i => $d) {
            $this->assertEqualsWithDelta($expected[$i], $d, $expected[$i] * 0.2 + 2, "failure #$i");
        }
    }

    public function testAPermanentFailureDeletesThePoisonRowAndTheQueueMovesOn(): void
    {
        $sink = $this->sink();
        foreach ([1, 2, 3] as $a) {
            $sink->ingest([self::s($a)], 1);
        }
        $this->store->script = [null, new ExternalStoreException('metric store write failed: HTTP 400', true), null];
        $this->assertSame(['sent' => 2, 'failed' => 0, 'permanent' => 1, 'dropped' => 0, 'pending' => 0], $sink->flush());
        $this->assertSame([1, 3], array_map(static fn (array $b): int => $b[0]['asset_id'], $this->store->batches));
    }

    public function testACorruptPayloadIsDroppedNotRetriedForever(): void
    {
        $this->h->q("INSERT INTO rmm_metric_outbox (points, payload) VALUES (1, 'not json'), (1, '[[1,2]]'), (1, '{\"a\":1}')");
        $r = $this->sink()->flush();
        $this->assertSame(3, $r['permanent']);
        $this->assertSame(0, $r['pending']);
        $this->assertSame(0, $this->store->writeCalls);
    }

    public function testAnUnexpectedExceptionFromTheStoreIsTransient(): void
    {
        $sink = $this->sink();
        $sink->ingest([self::s(1)], 1);
        $this->store->script = [new \RuntimeException('curl said Bearer S3CRET')];
        $this->assertSame(1, $sink->flush()['failed']);
        $this->assertSame(1, $this->outbox());
        $this->assertStringNotContainsString('S3CRET', (string) $this->rows()[0]['last_error']);
    }

    public function testMaxAttemptsGivesUp(): void
    {
        $sink = $this->sink(['max_attempts' => 3]);
        $sink->ingest([self::s(1)], 1);
        $dropped = 0;
        for ($i = 0; $i < 3; ++$i) {
            $this->store->script = [new ExternalStoreException('down')];
            $r = $sink->flush();
            $dropped += $r['dropped'];
            $this->clock->advance(2000);
        }
        $this->assertSame(1, $dropped);
        $this->assertSame(0, $this->outbox());
    }

    // ------------------------------------------------------------------ concurrency

    public function testTwoFlushersNeverSendTheSameRow(): void
    {
        $a = $this->sink();
        $b = new ExternalMetricSink($this->h->db, $this->clock, $this->store);
        foreach ([1, 2, 3] as $n) {
            $a->ingest([self::s($n)], 1);
        }
        $inner = null;
        // while worker A is sending its first row, worker B runs a whole flush
        $this->store->during = function () use ($b, &$inner): void {
            $inner = $b->flush();
        };
        $outer = $a->flush();
        $this->assertSame(2, $inner['sent'] ?? -1, 'B got the two rows A had not claimed');
        $this->assertSame(1, $outer['sent'], 'A sent its own row and skipped the two B had taken');
        $assets = array_map(static fn (array $batch): int => $batch[0]['asset_id'], $this->store->batches);
        sort($assets);
        $this->assertSame([1, 2, 3], $assets, 'every row sent exactly once');
        $this->assertSame(0, $this->outbox());
    }

    public function testALeasedRowIsSkippedAndAnExpiredLeaseIsReclaimed(): void
    {
        $sink = $this->sink();
        $sink->ingest([self::s(1)], 1);
        $this->h->q("UPDATE rmm_metric_outbox SET lease_until = '" . $this->utc(120) . "'");
        $this->assertSame(0, $sink->flush()['sent'], 'another worker holds the lease');
        $this->assertSame(0, $this->store->writeCalls);
        $this->h->q("UPDATE rmm_metric_outbox SET lease_until = '" . $this->utc(-5) . "'");
        $this->assertSame(1, $sink->flush()['sent'], 'a crashed worker lease has expired');
    }

    public function testARowDeletedByAnotherWorkerBetweenSelectAndClaimIsHarmless(): void
    {
        $sink = $this->sink();
        $sink->ingest([self::s(1)], 1);
        $sink->ingest([self::s(2)], 1);
        $this->store->during = function (): void {
            $this->h->q('DELETE FROM rmm_metric_outbox');
        };
        $r = $sink->flush();
        $this->assertSame(1, $r['sent']);
        $this->assertSame(0, $r['pending']);
    }

    // ------------------------------------------------------------------ composition

    public function testCompositeFeedsTheLocalSinkAndTheBufferAndAnOutboxFailureIsIsolated(): void
    {
        $local = new DatabaseMetricSink($this->h->db, $this->clock);
        $external = $this->sink();
        $composite = new CompositeMetricSink([[$local, true], [$external, false]]);
        $ts = time() - 30;
        $composite->ingest([self::s(77, 'cpu.utilization', 42, null, $ts)], 1);
        $this->assertSame([], $composite->failures());
        $this->assertEquals(42, $local->latest(77)[0]['value']);
        $this->assertSame(1, $this->outbox());
        $this->assertSame(1, $external->flush()['sent']);
    }

    public function testTheReaderFallsBackToTheLocalRollupWhileTheStoreIsDown(): void
    {
        $local = new DatabaseMetricSink($this->h->db, $this->clock);
        $b = intdiv(time(), 3600) * 3600 - 3600;
        $local->ingest([self::s(78, 'cpu.utilization', 61, null, $b + 60)], 1);
        $reader = new ExternalMetricReader($this->store, $local);   // the scripted store has no data
        $this->assertEquals(61, $reader->peak(78, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b))['max'] ?? null);
        $this->assertEquals(61, $reader->series(78, 'cpu.utilization', null, new \DateTimeImmutable('@' . $b), new \DateTimeImmutable('@' . ($b + 3599)))[0]['max'] ?? null);
        $this->assertEquals(61, $reader->latest(78)[0]['value']);
    }
}
