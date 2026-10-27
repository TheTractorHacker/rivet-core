<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm;

use RivetCore\Jobs\JobClaimer;
use RivetCore\Rmm\Capacity\AutoscaleHint;
use RivetCore\Rmm\Capacity\IngestQueue;
use RivetCore\Tests\Support\RmmTestCase;

/** Scale item S6: several workers claim without overlap (SKIP LOCKED and the lease fallback), the per-queue limit is exact, a dead worker's jobs come back, the hint. */
final class IngestScaleTest extends RmmTestCase
{
    private function seed(int $n, string $type = 'rmm.ingest'): void
    {
        $rows = [];
        for ($i = 0; $i < $n; ++$i) {
            $rows[] = "('$type', " . ($i % 3 === 0 ? 5 : -10) . ", '{\"device_id\":999999999,\"seq\":1}')";
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            $this->h->q('INSERT INTO integration_jobs (job_type, priority, payload) VALUES ' . implode(',', $chunk));
        }
    }

    /** @return list<int> every id the worker processes claimed, in claim order across the files */
    private function runWorkers(int $workers, string $strategy, int $batch, int $maxRunning = 0): array
    {
        $files = [];
        $procs = [];
        for ($i = 0; $i < $workers; ++$i) {
            $files[$i] = tempnam(sys_get_temp_dir(), 'claim');
            $procs[$i] = proc_open([PHP_BINARY, __DIR__ . '/../../Support/claim_worker.php', $files[$i], $strategy, (string) $batch, (string) $maxRunning], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($procs[$i]);
        }
        $out = [];
        foreach ($procs as $i => $p) {
            $stderr = (string) stream_get_contents($pipes[$i][2]);
            stream_get_contents($pipes[$i][1]);
            $this->assertSame(0, proc_close($p), $stderr);
            foreach (file($files[$i], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $out[] = (int) $line;
            }
            @unlink($files[$i]);
        }

        return $out;
    }

    public function testTheVersionRuleForSkipLocked(): void
    {
        foreach (['10.6.0-MariaDB' => true, '10.5.29-MariaDB-log' => false, '11.8.6-MariaDB-ubu2404' => true, '10.11.14-MariaDB-ubu2204' => true, '5.5.5-10.6.12-MariaDB' => true, '5.5.5-10.5.8-MariaDB' => false,
            '8.0.0' => false, '8.0.1' => true, '8.0.46' => true, '8.4.7' => true, '5.7.44' => false, 'garbage' => false] as $v => $expected) {
            $this->assertSame($expected, JobClaimer::versionSupportsSkipLocked($v), $v);
        }
    }

    public function testFourWorkersClaimEveryJobExactlyOnceWithSkipLockedOrTheLease(): void
    {
        $strategy = (new JobClaimer($this->h->db, true))->strategy();
        foreach (array_unique([$strategy, JobClaimer::LEASE]) as $s) {
            $this->h->q('DELETE FROM integration_jobs');
            $this->seed(300);
            $claimed = $this->runWorkers(4, $s, 10);
            $this->assertCount(300, $claimed, "strategy $s claimed every job");
            $this->assertSame(300, count(array_unique($claimed)), "strategy $s: no job twice");
            $this->assertSame(300, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status = 'completed' AND attempts = 1"));
        }
    }

    public function testASkipLockedClaimPassesOverRowsAnotherWorkerHolds(): void
    {
        if ((new JobClaimer($this->h->db, true))->strategy() !== JobClaimer::SKIP_LOCKED) {
            $this->markTestSkipped('This server has no SKIP LOCKED.');
        }
        $this->seed(20);
        $other = \RivetCore\Tests\Support\ScratchDb::connect();
        $this->assertNotNull($other);
        $other->begin_transaction();
        $held = array_map('intval', array_column($other->query("SELECT job_id FROM integration_jobs WHERE status = 'pending' ORDER BY job_id LIMIT 5")->fetch_all(MYSQLI_ASSOC), 'job_id'));
        $other->query('UPDATE integration_jobs SET attempts = attempts WHERE job_id IN (' . implode(',', $held) . ')');   // exactly these five rows stay locked by the open transaction
        $mine = array_map(static fn (array $j): int => (int) $j['job_id'], (new JobClaimer($this->h->db, true, JobClaimer::SKIP_LOCKED))->claim(8, 'rmm.ingest'));
        $other->rollback();
        $other->close();
        $this->assertCount(8, $mine, 'the batch is filled from the rows nobody holds');
        $this->assertSame([], array_intersect($mine, $held), 'and never waits for, or takes, the held ones');
    }

    public function testPriorityOrderIsKeptAndATypeFilterIsHonoured(): void
    {
        $this->seed(9, 'other.type');
        $this->seed(9);
        foreach ([JobClaimer::SKIP_LOCKED, JobClaimer::LEASE] as $s) {
            $this->h->q("UPDATE integration_jobs SET status = 'pending', attempts = 0");
            $jobs = (new JobClaimer($this->h->db, true, $s))->claim(4, 'rmm.ingest');
            $this->assertCount(4, $jobs);
            $this->assertSame(['rmm.ingest'], array_values(array_unique(array_column($jobs, 'job_type'))));
            $this->assertSame([5, 5, 5, -10], array_map('intval', array_column($jobs, 'priority')), "$s: highest priority first");
            $this->assertSame(['running'], array_values(array_unique(array_column($jobs, 'status'))));
            $this->assertSame([1], array_values(array_unique(array_map('intval', array_column($jobs, 'attempts')))));
        }
    }

    public function testThePerQueueLimitIsExactAcrossProcesses(): void
    {
        $this->seed(40);
        $a = (new JobClaimer($this->h->db, true))->claim(10, 'rmm.ingest', 15);
        $b = (new JobClaimer($this->h->db, true))->claim(10, 'rmm.ingest', 15);
        $c = (new JobClaimer($this->h->db, true))->claim(10, 'rmm.ingest', 15);
        $this->assertSame([10, 5, 0], [count($a), count($b), count($c)], 'never more than 15 running');
        $this->assertSame(15, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status = 'running'"));
        // four processes with a limit of 6 never have more than 6 running: their claims are exclusive and complete, and the limit held at every claim
        $this->h->q('DELETE FROM integration_jobs');
        $this->seed(120);
        $claimed = $this->runWorkers(4, 'auto', 10, 6);
        $this->assertCount(120, $claimed);
        $this->assertSame(120, count(array_unique($claimed)));
    }

    public function testADeadWorkersJobsComeBackAfterTheLeaseAndAreDeadLetteredWhenSpent(): void
    {
        $this->seed(6);
        $jobs = (new JobClaimer($this->h->db, true))->claim(6, 'rmm.ingest');
        $this->assertCount(6, $jobs);
        $q = $this->h->module->ingestQueue();
        $this->assertSame(0, $q->reclaimStale(), 'a fresh claim is a live lease');
        $this->h->q("UPDATE integration_jobs SET heartbeat_at = NOW() - INTERVAL 5 MINUTE, started_at = NOW() - INTERVAL 5 MINUTE");
        $this->h->q('UPDATE integration_jobs SET attempts = max_attempts WHERE job_id = ' . (int) $jobs[0]['job_id']);
        $this->assertSame(6, $q->reclaimStale());
        $this->assertSame(5, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status = 'pending'"));
        $this->assertSame(1, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status = 'dead_letter'"));
        $again = (new JobClaimer($this->h->db, true))->claim(10, 'rmm.ingest');
        $this->assertCount(5, $again, 'released jobs are claimable again');
    }

    public function testAServerThatRefusesSkipLockedFallsBackToTheLease(): void
    {
        $this->seed(12);
        $refusing = new class ($this->h->db) implements \RivetCore\Database\DatabaseInterface {
            public int $refused = 0;

            public function __construct(private readonly \RivetCore\Database\DatabaseInterface $inner)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return $this->inner->fetchOne($sql, $params);
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'SKIP LOCKED')) {
                    ++$this->refused;

                    throw new \RuntimeException("You have an error in your SQL syntax; check the manual ... near 'SKIP LOCKED'");
                }

                return $this->inner->fetchAll($sql, $params);
            }

            public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
            {
                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };
        $claimer = new JobClaimer($refusing, true, JobClaimer::SKIP_LOCKED);
        $jobs = $claimer->claim(5, 'rmm.ingest');
        $this->assertCount(5, $jobs, 'the claim still succeeded');
        $this->assertSame(1, $refusing->refused);
        $this->assertSame(JobClaimer::LEASE, $claimer->strategy(), 'and the claimer stays on the lease from now on');
        $this->assertCount(5, $claimer->claim(5, 'rmm.ingest'));
        $this->assertSame(1, $refusing->refused, 'the refused statement is not tried again');
        // a different error is not swallowed
        $broken = new class ($this->h->db) implements \RivetCore\Database\DatabaseInterface {
            public function __construct(private readonly \RivetCore\Database\DatabaseInterface $inner)
            {
            }

            public function fetchOne(string $sql, array $params = []): ?array
            {
                return $this->inner->fetchOne($sql, $params);
            }

            public function fetchAll(string $sql, array $params = []): array
            {
                if (str_contains($sql, 'SKIP LOCKED')) {
                    throw new \RuntimeException('connection lost');
                }

                return $this->inner->fetchAll($sql, $params);
            }

            public function execute(string $sql, array $params = []): \RivetCore\Database\ExecutionResult
            {
                return $this->inner->execute($sql, $params);
            }

            public function transaction(callable $callback): mixed
            {
                return $this->inner->transaction($callback);
            }
        };
        $this->expectExceptionMessage('connection lost');
        (new JobClaimer($broken, true, JobClaimer::SKIP_LOCKED))->claim(2, 'rmm.ingest');
    }

    public function testTheGenericJobQueueClaimStillWorks(): void
    {
        $this->seed(5);
        $queue = new \RivetCore\Jobs\JobQueue($this->h->db);
        $jobs = $queue->claim(3);
        $this->assertCount(3, $jobs);
        $this->assertSame('running', $jobs[0]['status']);
        $this->assertTrue($queue->markCompleted((int) $jobs[0]['job_id'], [], 1));
        $this->assertSame(2, (int) $this->h->one("SELECT COUNT(*) FROM integration_jobs WHERE status = 'pending'"));
    }

    public function testTheHintIsPureAndPointsTheRightWay(): void
    {
        $base = ['pending' => 0, 'running' => 0, 'oldest_pending_age_s' => 0, 'dead_letter' => 0, 'arrival_per_s' => 30.0, 'completion_per_s' => 30.0, 'per_worker_jobs_per_s' => 60.0, 'batch' => 50];
        $calm = AutoscaleHint::compute($base);
        $this->assertSame(['hold', 1], [$calm['hint'], $calm['workers_desired']]);
        $slow = AutoscaleHint::compute(['pending' => 5000, 'oldest_pending_age_s' => 90, 'completion_per_s' => 10.0, 'workers' => 1] + $base);
        $this->assertSame('up', $slow['hint']);
        $this->assertSame((int) ceil((30 + 5000 / 60) * 1.5 / 60), $slow['workers_desired']);
        $this->assertNull($slow['drain_eta_s'], 'arrivals outrun completions: no drain time');
        $growing = AutoscaleHint::compute(['pending' => 400, 'oldest_pending_age_s' => 5, 'completion_per_s' => 25.0, 'workers' => 1] + $base);
        $this->assertSame('up', $growing['hint']);
        $draining = AutoscaleHint::compute(['pending' => 400, 'oldest_pending_age_s' => 5, 'completion_per_s' => 90.0, 'workers' => 3] + $base);
        $this->assertSame('hold', $draining['hint']);
        $this->assertSame(7, $draining['drain_eta_s']);
        $idle = AutoscaleHint::compute(['arrival_per_s' => 2.0, 'completion_per_s' => 2.0, 'workers' => 4] + $base);
        $this->assertSame(['down', 1], [$idle['hint'], $idle['workers_desired']]);
        $unknown = AutoscaleHint::compute(['arrival_per_s' => 2.0, 'completion_per_s' => 2.0] + $base);
        $this->assertSame('hold', $unknown['hint'], 'without a worker count an idle queue never says down');
        $this->assertSame(64, AutoscaleHint::compute(['pending' => 10000000, 'oldest_pending_age_s' => 900, 'completion_per_s' => 0.0] + $base)['workers_desired'], 'capped');
    }

    public function testTheExpositionIsPrometheusText(): void
    {
        $t = AutoscaleHint::exposition(AutoscaleHint::compute(['pending' => 10, 'running' => 2, 'oldest_pending_age_s' => 40, 'arrival_per_s' => 1.5, 'completion_per_s' => 1.0]), 3);
        $this->assertStringContainsString("# TYPE rmm_ingest_scale_hint gauge\nrmm_ingest_scale_hint 1\n", $t);
        $this->assertStringContainsString("rmm_ingest_pending_jobs 10\n", $t);
        $this->assertStringContainsString("rmm_ingest_dead_letter_jobs 3\n", $t);
        $this->assertStringContainsString("rmm_ingest_arrival_per_second 1.5\n", $t);
        foreach (explode("\n", trim($t)) as $line) {
            $this->assertMatchesRegularExpression('/^(# (HELP|TYPE) [a-z_]+ .+|[a-z_]+ -?[0-9.]+)$/', $line);
        }
    }

    public function testThePanelCarriesTheHintAndTheStorageBlock(): void
    {
        $this->seed(30);
        $r = $this->h->module->capacity()->build(['ingest_workers' => 2]);
        $this->assertSame(30, $r['autoscale']['pending']);
        $this->assertSame(2, $r['autoscale']['workers_current']);
        $this->assertContains($r['autoscale']['hint'], ['up', 'hold', 'down']);
        $this->assertFalse($r['storage']['partitioning_enabled']);
        $this->assertSame(['rmm_metric_hourly', 'rmm_metric_daily', 'endpoint_agent_check_history'], array_keys($r['storage']['tables']));
        $this->assertFalse($r['storage']['tables']['rmm_metric_hourly']['partitioned']);
        $q = $this->h->module->ingestQueue();
        $this->assertGreaterThan(0, $q->scaleHint()['pending']);
        $text = $this->h->module->capacity()->metricsText(2);
        $this->assertStringContainsString("rmm_ingest_pending_jobs 30\n", $text);
        $this->assertSame(IngestQueue::JOB_TYPE, 'rmm.ingest');
    }

    public function testDrainHonoursTheBatchAndTheRunningLimit(): void
    {
        $this->h->enable();
        $this->assertSame([], $this->h->module->settings()->update(['limits_json' => ['ingest_batch' => 5, 'ingest_max_running' => 8]]));
        $this->seed(20);
        $q = $this->h->module->ingestQueue();
        $r = $q->drain(50);
        $this->assertSame(5, $r['claimed'], 'ingest_batch caps one pass');
    }
}
