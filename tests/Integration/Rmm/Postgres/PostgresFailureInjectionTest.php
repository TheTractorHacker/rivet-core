<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresChangeEventStore;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionException;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresLogStore;
use RivetCore\Rmm\Store\Postgres\PostgresMetricStore;
use RivetCore\Rmm\Store\Postgres\PostgresSoftwareHistoryStore;
use RivetCore\Tests\Support\MutableClock;
use RivetCore\Tests\Support\PostgresTestDatabase;

/**
 * The connection is cut in the middle of a store operation (the server is told to terminate the session, exactly what a restart, a failover or a network drop does to
 * the client): nothing partial is committed, the failure is a PostgresConnectionException, and the very next operation reconnects and works.
 */
final class PostgresFailureInjectionTest extends TestCase
{
    private const T = 1_800_000_000;
    private const CAPS = ['rate_per_min' => 10000, 'device_max_rows' => 10000, 'fleet_max_rows' => 100000];

    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->db);
    }

    /** A connection that terminates its own server session just before the $after-th statement of its first transaction. */
    private function cutAfter(int $after): PostgresConnectionInterface
    {
        $victim = PostgresTestDatabase::connect();
        $killer = PostgresTestDatabase::connect();

        return new class($victim, $killer, $after) implements PostgresConnectionInterface {
            private int $n = 0;
            private bool $cut = false;

            public function __construct(private readonly PostgresConnectionInterface $inner, private readonly PostgresConnectionInterface $killer, private readonly int $after)
            {
            }

            private function maybeCut(): void
            {
                if ($this->inner->inTransaction() && !$this->cut && ++$this->n === $this->after) {
                    $this->cut = true;
                    $pid = (int) $this->inner->value('SELECT pg_backend_pid()');
                    $this->killer->value('SELECT pg_terminate_backend(?)', [$pid]);
                    usleep(200000);
                }
            }

            public function query(string $sql, array $params = []): array
            {
                $this->maybeCut();

                return $this->inner->query($sql, $params);
            }

            public function queryOne(string $sql, array $params = []): ?array
            {
                $this->maybeCut();

                return $this->inner->queryOne($sql, $params);
            }

            public function value(string $sql, array $params = []): mixed
            {
                $this->maybeCut();

                return $this->inner->value($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                $this->maybeCut();

                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }

            public function inTransaction(): bool
            {
                return $this->inner->inTransaction();
            }

            public function serverVersion(): string
            {
                return $this->inner->serverVersion();
            }

            public function close(): void
            {
                $this->inner->close();
            }
        };
    }

    private function entries(int $n, string $tag): array
    {
        return array_map(static fn (int $i): array => ['ts' => self::T + $i, 'level' => 4, 'source' => 'S', 'code' => null, 'message' => "$tag $i", 'hash' => substr(hash('sha256', "$tag $i"), 0, 32)], range(1, $n));
    }

    public function testALogBatchCutAtAnyStatementLeavesNothingAndTheRetryWorks(): void
    {
        $leftovers = [];
        foreach ([1, 2, 3, 4, 5] as $after) {
            $store = new PostgresLogStore($this->cutAfter($after));
            try {
                $store->admit(9, $this->entries(250, 'cut'), self::CAPS, self::T + 100, 0, 0, 3);   // 3 INSERT chunks: statements 1 (state), 2 (lock), 3 (fleet sum), 4.. (inserts), then the ledger update
                $this->fail("statement $after: the cut must surface");
            } catch (PostgresConnectionException) {
            }
            $rows = (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events');
            $ledger = (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_state');
            if ($rows !== 0 || $ledger !== 0) {
                $leftovers[] = $after;
            }
            // the same store object reconnects by itself
            $r = $store->admit(9, $this->entries(250, 'retry'), self::CAPS, self::T + 200, 0, 0, 3);
            $this->assertSame(250, $r['inserted'], "statement $after: the retry stores the whole batch");
            $this->assertSame(250, (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 9'));
            $this->db->execute('TRUNCATE endpoint_agent_log_events, endpoint_agent_log_state');
        }
        $this->assertSame([], $leftovers, 'a cut before the commit never leaves rows or a ledger behind');
    }

    public function testAChangeBatchAndASoftwareAppendAreAllOrNothing(): void
    {
        $rec = fn (int $i): array => ['ts' => self::T + $i, 'kind' => 'registry', 'watch' => 'win.run', 'change' => 2, 'target' => 'T' . $i, 'item' => 'i', 'old' => 'a', 'new' => 'b', 'summary' => '', 'old_hash' => '', 'new_hash' => '',
            'actor' => '', 'process' => '', 'flags' => 0, 'dedupe' => substr(hash('sha256', "r$i"), 0, 32)];
        foreach ([2, 3, 4] as $after) {
            $store = new PostgresChangeEventStore($this->cutAfter($after));
            try {
                $store->admit(4, array_map($rec, range(1, 120)), self::CAPS, self::T + 500, 0, 0, 'aaaaaaaaaaaaaaaa', '{"x":1}', 2);
                $this->fail('cut');
            } catch (PostgresConnectionException) {
            }
            $this->assertSame([0, 0], [(int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_change_records'), (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_change_state')]);
        }
        $changes = array_map(static fn (int $i): array => ['key' => 'k' . $i, 'name' => 'App ' . $i, 'source' => 'msi', 'publisher' => '', 'type' => 'installed', 'old' => null, 'new' => '1'], range(1, 500));
        $sw = new PostgresSoftwareHistoryStore($this->cutAfter(2));   // 500 changes = 2 chunks: the cut lands between them
        try {
            $sw->append(4, $changes, gmdate('Y-m-d H:i:s', self::T));
            $this->fail('cut');
        } catch (PostgresConnectionException) {
        }
        $this->assertSame(0, (int) $this->db->value('SELECT COUNT(*) FROM rmm_software_history'), 'the first chunk was rolled back with the second');
        $sw->append(4, $changes, gmdate('Y-m-d H:i:s', self::T));
        $sw->append(4, $changes, gmdate('Y-m-d H:i:s', self::T));   // the retry of a retry
        $this->assertSame(500, (int) $this->db->value('SELECT COUNT(*) FROM rmm_software_history'), 'and a repeated append stores nothing twice');
    }

    public function testAMetricBatchCutBetweenTheLatestAndTheHourlyWriteIsRolledBack(): void
    {
        $at = new \DateTimeImmutable('@' . (intdiv(time(), 3600) * 3600 - 3600 + 5));
        // 230 series: more than one statement's worth, so the batch takes the multi-statement transaction
        $samples = array_map(static fn (int $i): array => ['asset_id' => 55, 'key' => 'cpu.utilization', 'instance' => 'i' . $i, 'value' => 10, 'at' => $at, 'label' => null], range(1, 230));
        $store = new PostgresMetricStore($this->cutAfter(2), new MutableClock());   // statement 1: the first latest chunk, statement 2: the rest of the latest rows
        try {
            $store->ingest($samples, 1);
            $this->fail('cut');
        } catch (PostgresConnectionException) {
        }
        $this->assertSame([0, 0], [(int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_latest'), (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly')], 'latest and hourly are never half written');
        $store->ingest($samples, 1);
        $this->assertSame([230, 230], [(int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_latest'), (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly')]);
        // a check-in sized batch is ONE statement (a data-modifying CTE): one round trip, atomic by itself, no transaction to cut
        $this->db->execute('TRUNCATE rmm_metric_latest, rmm_metric_hourly');
        $one = [['asset_id' => 56, 'key' => 'cpu.utilization', 'instance' => null, 'value' => 10, 'at' => $at, 'label' => null], ['asset_id' => 56, 'key' => 'memory.utilization', 'instance' => null, 'value' => 20, 'at' => $at, 'label' => null]];
        $counting = new class($this->db) implements PostgresConnectionInterface {
            public int $statements = 0;
            public int $transactions = 0;

            public function __construct(private readonly PostgresConnectionInterface $inner)
            {
            }

            public function query(string $sql, array $params = []): array
            {
                ++$this->statements;

                return $this->inner->query($sql, $params);
            }

            public function queryOne(string $sql, array $params = []): ?array
            {
                ++$this->statements;

                return $this->inner->queryOne($sql, $params);
            }

            public function value(string $sql, array $params = []): mixed
            {
                ++$this->statements;

                return $this->inner->value($sql, $params);
            }

            public function execute(string $sql, array $params = []): int
            {
                ++$this->statements;

                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                ++$this->transactions;

                return $this->inner->transaction($callback);
            }

            public function inTransaction(): bool
            {
                return $this->inner->inTransaction();
            }

            public function serverVersion(): string
            {
                return $this->inner->serverVersion();
            }

            public function close(): void
            {
                $this->inner->close();
            }
        };
        (new PostgresMetricStore($counting, new MutableClock()))->ingest($one, 1);
        $this->assertSame([1, 0], [$counting->statements, $counting->transactions]);
        $this->assertSame([2, 2], [(int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_latest'), (int) $this->db->value('SELECT COUNT(*) FROM rmm_metric_hourly')]);
    }

    public function testAPruneBatchCutMidWayKeepsTheLedgerEqualToTheRows(): void
    {
        $good = new PostgresLogStore($this->db);
        $good->admit(1, $this->entries(100, 'p'), self::CAPS, self::T + 500, 0, 0, 1);
        $store = new PostgresLogStore($this->cutAfter(3));   // select for update, delete, then the ledger update is cut
        try {
            $store->enforceLimits(gmdate('Y-m-d H:i:s', self::T + 1000), 1000, 100000, 100000, 40);
            $this->fail('cut');
        } catch (PostgresConnectionException) {
        }
        $this->assertSame(100, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame(100, (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 1'), 'the delete and the counter move together or not at all');
        $r = $store->enforceLimits(gmdate('Y-m-d H:i:s', self::T + 1000), 1000, 100000, 100000, 40);   // reconnects
        $this->assertSame(100, $r['retention']);
        $this->assertSame([0, 0], [(int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'), (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 1')]);
    }
}
