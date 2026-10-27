<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Tests\Support\PostgresTestDatabase;

/** Real concurrent writers (separate PHP processes, separate PostgreSQL sessions) on the hot stores: no lost update, no duplicate, the ledger always equals the rows. */
final class PostgresConcurrencyTest extends TestCase
{
    private const T = 1_800_000_000;
    private PostgresConnectionInterface $db;

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->db = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->db);
    }

    /**
     * @param list<array{0:string,1:array<string,mixed>}> $jobs
     * @return list<array<string,mixed>>
     */
    private function workers(array $jobs): array
    {
        $procs = [];
        foreach ($jobs as [$job, $args]) {
            $procs[] = $p = proc_open([PHP_BINARY, dirname(__DIR__, 3) . '/Support/pg_worker.php', $job, (string) json_encode($args)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $pipesAll[] = $pipes;
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $line = trim((string) stream_get_contents($pipesAll[$i][1]));
            $err = trim((string) stream_get_contents($pipesAll[$i][2]));
            proc_close($p);
            $r = json_decode($line, true);
            $this->assertIsArray($r, "worker $i printed: '$line' / '$err'");
            $this->assertSame([], $r['errors'], 'worker errors');
            $out[] = $r;
        }

        return $out;
    }

    public function testTwoWritersOnTheSameDeviceStoreEveryEntryOnceAndTheLedgerMatches(): void
    {
        $shared = range(1, 40);   // 40 entries both writers send: stored once
        $r = $this->workers([
            ['logs', ['name' => 'A', 'device' => 5, 't' => self::T, 'batches' => 25, 'per_batch' => 20, 'shared' => $shared]],
            ['logs', ['name' => 'B', 'device' => 5, 't' => self::T + 7, 'batches' => 25, 'per_batch' => 20, 'shared' => $shared]],
        ]);
        $unique = 2 * 25 * 20 + 40;
        $this->assertSame($unique, $r[0]['inserted'] + $r[1]['inserted'], 'the entries sent by both were stored by exactly one of them');
        $this->assertSame($unique, (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'));
        $this->assertSame($unique, (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = 5'), 'the ledger equals the rows');
        $this->assertSame($unique, (int) $this->db->value('SELECT COUNT(DISTINCT encode(dedupe, \'hex\')) FROM endpoint_agent_log_events'));
    }

    public function testWritersOfDifferentDevicesDoNotInterfere(): void
    {
        $r = $this->workers([
            ['logs', ['name' => 'A', 'device' => 1, 't' => self::T, 'batches' => 15, 'per_batch' => 10]],
            ['logs', ['name' => 'B', 'device' => 2, 't' => self::T, 'batches' => 15, 'per_batch' => 10]],
            ['logs', ['name' => 'C', 'device' => 3, 't' => self::T, 'batches' => 15, 'per_batch' => 10]],
        ]);
        $this->assertSame([150, 150, 150], array_column($r, 'inserted'));
        foreach ([1, 2, 3] as $d) {
            $this->assertSame(150, (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = ?', [$d]));
        }
        $this->assertSame(450, (int) $this->db->value('SELECT SUM(row_count) FROM endpoint_agent_log_state'));
    }

    public function testWritersAndPrunersRunningTogetherLeaveTheLedgerEqualToTheRows(): void
    {
        $r = $this->workers([
            ['logs', ['name' => 'A', 'device' => 1, 't' => self::T, 'batches' => 30, 'per_batch' => 12]],
            ['logs', ['name' => 'B', 'device' => 2, 't' => self::T, 'batches' => 30, 'per_batch' => 12]],
            ['prune', ['cutoff' => self::T - 1, 'device_max' => 60, 'batch' => 25, 'rounds' => 25]],
            ['prune', ['cutoff' => self::T - 1, 'device_max' => 40, 'batch' => 7, 'rounds' => 25]],
        ]);
        $this->assertSame(720, $r[0]['inserted'] + $r[1]['inserted']);
        foreach ([1, 2] as $d) {
            $rows = (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events WHERE device_id = ?', [$d]);
            $this->assertSame($rows, (int) $this->db->value('SELECT row_count FROM endpoint_agent_log_state WHERE device_id = ?', [$d]), "device $d: the ledger follows every insert and delete");
        }
        $this->assertSame(720 - ($r[2]['deleted'] + $r[3]['deleted']), (int) $this->db->value('SELECT COUNT(*) FROM endpoint_agent_log_events'), 'inserted minus deleted is what is left');
    }

    public function testConcurrentMetricUpsertsLoseNoSample(): void
    {
        $hour = intdiv(time(), 3600) * 3600 - 7200;
        $this->workers([
            ['metrics', ['asset' => 777, 'at' => $hour + 5, 'value' => 10, 'times' => 60, 'name' => 'a']],
            ['metrics', ['asset' => 777, 'at' => $hour + 9, 'value' => 30, 'times' => 60, 'name' => 'b']],
            ['metrics', ['asset' => 777, 'at' => $hour + 7, 'value' => 20, 'times' => 60, 'name' => 'c']],
        ]);
        $r = $this->db->queryOne("SELECT samples, sum_value, min_value, max_value FROM rmm_metric_hourly WHERE asset_id = 777 AND metric_key = 'cpu.utilization'");
        $this->assertSame([180, 3600.0, 10.0, 30.0], [(int) $r['samples'], (float) $r['sum_value'], (float) $r['min_value'], (float) $r['max_value']], 'every upsert added to the row; none replaced it');
        $latest = $this->db->queryOne('SELECT value, label FROM rmm_metric_latest WHERE asset_id = 777');
        $this->assertSame([30.0, 'b'], [(float) $latest['value'], $latest['label']], 'the newest reading (hour+9) won whoever wrote last');
    }
}
