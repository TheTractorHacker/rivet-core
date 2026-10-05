<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Jobs\JobQueue;
use RivetCore\Jobs\Migration\Migration0002IntegrationJobs;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class JobQueueTest extends TestCase
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
        $this->q = new JobQueue($this->db);
    }

    public function testEnqueueStoresPayloadAndReturnsId(): void
    {
        $id = $this->q->enqueue('odoo.sync', ['a' => 1], 7, 'employee', 3, 4);
        $row = $this->db->fetchOne('SELECT * FROM integration_jobs WHERE job_id = ?', [$id]);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('{"a":1}', $row['payload']);
        $this->assertEquals(4, $row['max_attempts']);
        $this->assertEquals(7, $row['integration_id']);
    }

    public function testClaimOrdersByPriorityThenIdAndMarksRunning(): void
    {
        $low = $this->q->enqueue('t', [], null, null, 0);
        $high = $this->q->enqueue('t', [], null, null, 9);
        $low2 = $this->q->enqueue('t', [], null, null, 0);
        $claimed = $this->q->claim(2);
        $this->assertSame([$high, $low], array_map(fn ($j) => (int) $j['job_id'], $claimed));
        $this->assertSame('running', $claimed[0]['status']);
        $this->assertSame(1, $claimed[0]['attempts']);
        $this->assertSame('pending', $this->db->fetchOne('SELECT status FROM integration_jobs WHERE job_id = ?', [$low2])['status']);
        $this->assertNotNull($this->db->fetchOne('SELECT started_at FROM integration_jobs WHERE job_id = ?', [$high])['started_at']);
    }

    public function testJobIsClaimedOnlyOnce(): void
    {
        $this->q->enqueue('t');
        $this->assertCount(1, $this->q->claim(10));
        $this->assertCount(0, (new JobQueue($this->db))->claim(10));
    }

    public function testStaleCandidateIsNotClaimedTwice(): void
    {
        // Simulate a rival worker winning the row between our SELECT and UPDATE.
        $id = $this->q->enqueue('t');
        $rival = $this->db->execute("UPDATE integration_jobs SET status='running', attempts=1 WHERE job_id = ? AND status='pending'", [$id]);
        $this->assertSame(1, $rival->affectedRows);
        $again = $this->db->execute("UPDATE integration_jobs SET status='running', attempts=attempts+1 WHERE job_id = ? AND status='pending'", [$id]);
        $this->assertSame(0, $again->affectedRows);
    }

    public function testFutureJobsAreNotClaimable(): void
    {
        $id = $this->q->enqueue('t');
        $this->db->execute('UPDATE integration_jobs SET available_at = NOW() + INTERVAL 1 HOUR WHERE job_id = ?', [$id]);
        $this->assertSame([], $this->q->claim());
    }

    public function testMarkCompleted(): void
    {
        $id = $this->q->enqueue('t');
        $this->q->claim();
        $this->q->markCompleted($id, ['ok' => true]);
        $row = $this->db->fetchOne('SELECT * FROM integration_jobs WHERE job_id = ?', [$id]);
        $this->assertSame('completed', $row['status']);
        $this->assertSame('{"ok":true}', $row['result']);
        $this->assertNotNull($row['completed_at']);
    }

    public function testFailureBacksOffThenDeadLetters(): void
    {
        $id = $this->q->enqueue('t', [], null, null, 0, 3);
        $expected = [1, 5];
        foreach ($expected as $i => $minutes) {
            $job = $this->q->claim()[0] ?? null;
            $this->assertNotNull($job, 'attempt ' . ($i + 1));
            $this->assertSame($i + 1, $job['attempts']);
            $this->q->markFailed($id, 'boom', $job['attempts'], (int) $job['max_attempts']);
            $row = $this->db->fetchOne(
                'SELECT status, error, TIMESTAMPDIFF(MINUTE, NOW(), available_at) AS m FROM integration_jobs WHERE job_id = ?',
                [$id]
            );
            $this->assertSame('pending', $row['status']);
            $this->assertSame('boom', $row['error']);
            $this->assertEqualsWithDelta($minutes, (int) $row['m'], 1);
            $this->db->execute('UPDATE integration_jobs SET available_at = NOW() WHERE job_id = ?', [$id]);
        }
        $job = $this->q->claim()[0];
        $this->q->markFailed($id, 'final', $job['attempts'], (int) $job['max_attempts']);
        $this->assertSame('dead_letter', $this->db->fetchOne('SELECT status FROM integration_jobs WHERE job_id = ?', [$id])['status']);
    }

    public function testTheDefaultFiveAttemptsEndInDeadLetterWithoutCrashing(): void
    {
        // max_attempts defaults to 5; attempts past the backoff table (4 entries) must reuse the longest wait.
        $id = $this->q->enqueue('t');
        $waits = [];
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->db->execute('UPDATE integration_jobs SET available_at = NOW() WHERE job_id = ?', [$id]);
            $job = $this->q->claim()[0];
            $this->assertSame($attempt, $job['attempts']);
            $this->q->markFailed($id, 'boom', $job['attempts'], (int) $job['max_attempts']);
            $row = $this->db->fetchOne('SELECT status, TIMESTAMPDIFF(MINUTE, NOW(), available_at) AS m FROM integration_jobs WHERE job_id = ?', [$id]);
            $waits[] = (int) $row['m'];
            $this->assertSame($attempt < 5 ? 'pending' : 'dead_letter', $row['status'], "attempt $attempt");
        }
        $this->assertEqualsWithDelta(120, $waits[3], 1);
        $this->assertEqualsWithDelta(120, $waits[4], 1, 'a fifth failure reuses the longest wait instead of crashing');
    }
}
