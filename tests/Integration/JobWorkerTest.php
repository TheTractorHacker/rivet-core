<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Jobs\JobContext;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\JobWorker;
use RivetCore\Jobs\Migration\Migration0002IntegrationJobs;
use RivetCore\Jobs\Migration\Migration0012JobHeartbeat;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class JobWorkerTest extends TestCase
{
    private MysqliDatabase $db;
    private JobQueue $q;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS integration_jobs');
        (new Migration0002IntegrationJobs())->up($this->db);
        (new Migration0012JobHeartbeat())->up($this->db);
        $this->q = new JobQueue($this->db);
    }

    private function row(int $id): array
    {
        return $this->db->fetchOne('SELECT * FROM integration_jobs WHERE job_id = ?', [$id]) ?? [];
    }

    public function testMigrationIsIdempotentAndQueueDetectsColumn(): void
    {
        (new Migration0012JobHeartbeat())->up($this->db);
        $this->assertTrue($this->q->supportsHeartbeat());
        $this->db->execute('ALTER TABLE integration_jobs DROP COLUMN heartbeat_at');
        $legacy = new JobQueue($this->db);
        $this->assertFalse($legacy->supportsHeartbeat());
        $id = $legacy->enqueue('t');
        $this->assertCount(1, $legacy->claim());
        $this->assertSame(1, $legacy->heartbeat($id), 'no column: reported alive, nothing stored');
        $this->assertSame(0, $legacy->requeueStale(15));
    }

    public function testRegistryIntrospection(): void
    {
        $w = (new JobWorker($this->q))->register('a', fn () => [], 5)->register('b', fn () => []);
        $this->assertTrue($w->has('a'));
        $this->assertFalse($w->has('zzz'));
        $this->assertSame(['a', 'b'], $w->types());
        $this->assertSame(5, $w->timeoutFor('a'));
        $this->assertNull($w->timeoutFor('b'));
        $this->assertSame(9, $w->setDefaultTimeout(9)->timeoutFor('b'));
    }

    public function testUnknownTypeIsDeadLetteredAtOnceWithAClearError(): void
    {
        $id = $this->q->enqueue('mystery', [], null, null, 0, 5);
        $out = (new JobWorker($this->q))->run();
        $this->assertSame(1, $out['dead']);
        $row = $this->row($id);
        $this->assertSame('dead_letter', $row['status']);
        $this->assertStringContainsString("No handler registered for job type 'mystery'", (string) $row['error']);
    }

    public function testExistingTwoArgumentHandlersStillWork(): void
    {
        $id = $this->q->enqueue('t', ['x' => 1]);
        $w = (new JobWorker($this->q))->register('t', fn (array $p, array $job) => ['got' => $p['x'], 'id' => $job['job_id']]);
        $this->assertSame(1, $w->run()['completed']);
        $this->assertSame('completed', $this->row($id)['status']);
    }

    public function testOverrunningJobIsRecordedAsFailedAttemptAndRetried(): void
    {
        $id = $this->q->enqueue('slow', [], null, null, 0, 3);
        $w = (new JobWorker($this->q))->register('slow', function () {
            usleep(1_150_000);

            return ['done' => true];
        }, 1);
        $out = $w->run();
        $this->assertSame(1, $out['retrying']);
        $this->assertSame(0, $out['completed']);
        $row = $this->row($id);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('1', (string) $row['attempts']);
        $this->assertStringContainsString('1s timeout', (string) $row['error']);
        $this->assertNull($row['result']);
    }

    public function testCheckpointThrowsOnceTheJobTimeoutIsSpentAndHeartbeats(): void
    {
        $id = $this->q->enqueue('loop', [], null, null, 0, 2);
        $seen = [];
        $w = (new JobWorker($this->q))->register('loop', function (array $p, array $job, JobContext $ctx) use (&$seen) {
            $seen[] = $ctx->remainingSeconds();
            $ctx->checkpoint();
            usleep(1_100_000);
            $ctx->checkpoint();

            return [];
        }, 1);
        $this->db->execute('UPDATE integration_jobs SET heartbeat_at = NOW() - INTERVAL 1 HOUR');
        $out = $w->run();
        $this->assertSame(1, $out['retrying']);
        $this->assertStringContainsString('exceeded its time budget', (string) $this->row($id)['error']);
        $this->assertNotNull($seen[0]);
        $this->assertLessThanOrEqual(1.0, $seen[0]);
        $this->assertGreaterThan(0.0, $seen[0]);
    }

    public function testNoJobIsStartedAfterTheRunBudgetAndClaimedOnesAreReleased(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; $i++) {
            $ids[] = $this->q->enqueue('t');
        }
        $ran = 0;
        $w = (new JobWorker($this->q))->register('t', function () use (&$ran) {
            $ran++;
            usleep(1_100_000);

            return [];
        });
        $out = $w->run(20, 1);
        $this->assertSame(1, $ran);
        $this->assertSame(1, $out['completed']);
        $this->assertSame(3, $out['deferred']);
        $this->assertSame(1, $out['claimed']);
        $left = $this->db->fetchAll("SELECT status, attempts, started_at FROM integration_jobs WHERE status = 'pending'");
        $this->assertCount(3, $left);
        foreach ($left as $r) {
            $this->assertSame('0', (string) $r['attempts'], 'a deferred job keeps its full attempts');
            $this->assertNull($r['started_at']);
        }
    }

    public function testHeartbeatKeepsALongRunningJobFromBeingReclaimed(): void
    {
        $a = $this->q->enqueue('t');
        $b = $this->q->enqueue('t');
        $this->q->claim(2);
        $this->db->execute('UPDATE integration_jobs SET started_at = NOW() - INTERVAL 2 HOUR, heartbeat_at = NOW() - INTERVAL 2 HOUR');
        $this->assertSame(1, $this->q->heartbeat($a));
        $this->assertSame(1, $this->q->requeueStale(15), 'only the job whose worker stopped heartbeating');
        $this->assertSame('running', $this->row($a)['status']);
        $this->assertSame('pending', $this->row($b)['status']);
        $this->assertSame(0, $this->q->heartbeat($b), 'a reclaimed job reports that it is no longer ours');
    }

    public function testJobsWithoutHeartbeatFallBackToStartedAt(): void
    {
        $a = $this->q->enqueue('t');
        $this->q->claim();
        $this->db->execute('UPDATE integration_jobs SET started_at = NOW() - INTERVAL 2 HOUR, heartbeat_at = NULL');
        $this->assertSame(1, $this->q->requeueStale(15));
        $this->assertSame('pending', $this->row($a)['status']);
    }

    public function testTwoWorkersNeverClaimTheSameJob(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->q->enqueue('t');
        }
        $second = ScratchDb::connect();
        $this->assertNotNull($second);
        $q2 = new JobQueue(new MysqliDatabase($second));
        $got1 = $got2 = [];
        for ($round = 0; $round < 10; $round++) {
            foreach ($this->q->claim(2) as $j) {
                $got1[] = (int) $j['job_id'];
            }
            foreach ($q2->claim(3) as $j) {
                $got2[] = (int) $j['job_id'];
            }
        }
        $this->assertSame([], array_intersect($got1, $got2));
        $this->assertCount(30, array_unique(array_merge($got1, $got2)));
        $this->assertSame(30, (int) $this->db->fetchOne("SELECT COUNT(*) c FROM integration_jobs WHERE status='running' AND attempts=1")['c']);
    }

    public function testRivalWinningBetweenSelectAndUpdateIsNotDoubleClaimed(): void
    {
        $id = $this->q->enqueue('t');
        $rival = ScratchDb::connect();
        $this->assertNotNull($rival);
        $rivalDb = new MysqliDatabase($rival);
        // Both workers SELECT the same candidate; the decorator lets the rival win the UPDATE first.
        $racing = new class ($this->db, $rivalDb) implements \RivetCore\Database\DatabaseInterface {
            private bool $raced = false;

            public function __construct(private MysqliDatabase $inner, private MysqliDatabase $rival)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return $this->inner->fetchOne($sql, $params);
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                return $this->inner->fetchAll($sql, $params);
            }

            public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
            {
                if (!$this->raced && str_contains($sql, "SET status = 'running'")) {
                    $this->raced = true;
                    $this->rival->execute($sql, $params);
                }

                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };
        $this->assertSame([], (new JobQueue($racing))->claim());
        $this->assertSame('1', (string) $this->row($id)['attempts'], 'exactly one claim happened');
    }

    public function testForkedWorkersPartitionTheQueue(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available.');
        }
        for ($i = 0; $i < 60; $i++) {
            $this->q->enqueue('t');
        }
        $files = [];
        $pids = [];
        foreach ([1, 2, 3] as $n) {
            $file = tempnam(sys_get_temp_dir(), 'rcjobs');
            $files[] = $file;
            $pid = pcntl_fork();
            if ($pid === 0) {
                $q = new JobQueue(new MysqliDatabase(ScratchDb::connect()));
                $mine = [];
                while (($jobs = $q->claim(2)) !== []) {
                    foreach ($jobs as $j) {
                        $mine[] = (int) $j['job_id'];
                    }
                }
                file_put_contents($file, implode(',', $mine));
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
        $all = [];
        foreach ($files as $f) {
            $c = (string) file_get_contents($f);
            unlink($f);
            $all = array_merge($all, $c === '' ? [] : array_map('intval', explode(',', $c)));
        }
        $this->assertCount(60, $all);
        $this->assertCount(60, array_unique($all), 'no job was claimed by two workers');
    }
}
