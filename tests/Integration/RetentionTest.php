<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Retention\RetentionService;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class RetentionTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        foreach (['audit_events', 'webhook_deliveries', 'integration_jobs', 'rivet_core_migrations'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
    }

    private function seed(): void
    {
        $this->db->execute("INSERT INTO audit_events (event_type, action, created_at) VALUES ('old', 'a', NOW() - INTERVAL 200 DAY), ('recent', 'a', NOW() - INTERVAL 5 DAY)");
        $this->db->execute("INSERT INTO webhook_deliveries (webhook_id, event_type, created_at) VALUES (1, 'old', NOW() - INTERVAL 200 DAY), (1, 'recent', NOW() - INTERVAL 5 DAY)");
        $this->db->execute("INSERT INTO integration_jobs (job_type, status, created_at) VALUES
            ('old-completed', 'completed', NOW() - INTERVAL 200 DAY), ('old-dead', 'dead_letter', NOW() - INTERVAL 200 DAY),
            ('old-pending', 'pending', NOW() - INTERVAL 200 DAY), ('old-running', 'running', NOW() - INTERVAL 200 DAY),
            ('recent-completed', 'completed', NOW() - INTERVAL 5 DAY)");
    }

    private function rows(string $table): int
    {
        return (int) $this->db->fetchOne("SELECT COUNT(*) c FROM $table")['c'];
    }

    public function testPrunesOnlyOldRowsAndNeverUnfinishedJobs(): void
    {
        $this->seed();
        $deleted = (new RetentionService($this->db))->prune(90);
        $this->assertSame(['audit_events' => 1, 'webhook_deliveries' => 1, 'integration_jobs' => 2], $deleted);
        $this->assertSame(['recent'], array_column($this->db->fetchAll('SELECT event_type FROM audit_events'), 'event_type'));
        $this->assertSame(['recent'], array_column($this->db->fetchAll('SELECT event_type FROM webhook_deliveries'), 'event_type'));
        $left = array_column($this->db->fetchAll('SELECT job_type FROM integration_jobs ORDER BY job_id'), 'job_type');
        $this->assertSame(['old-pending', 'old-running', 'recent-completed'], $left, 'pending and running jobs survive however old they are');
    }

    public function testZeroOrNegativeKeepsEverything(): void
    {
        $this->seed();
        $svc = new RetentionService($this->db);
        $this->assertSame([], $svc->prune(0));
        $this->assertSame([], $svc->prune(-5));
        $this->assertSame(2, $this->rows('audit_events'));
        $this->assertSame(2, $this->rows('webhook_deliveries'));
        $this->assertSame(5, $this->rows('integration_jobs'));
    }

    public function testIsIdempotent(): void
    {
        $this->seed();
        $svc = new RetentionService($this->db);
        $svc->prune(90);
        $this->assertSame(['audit_events' => 0, 'webhook_deliveries' => 0, 'integration_jobs' => 0], $svc->prune(90));
    }

    public function testALongerHorizonKeepsMore(): void
    {
        $this->seed();
        $this->assertSame(['audit_events' => 0, 'webhook_deliveries' => 0, 'integration_jobs' => 0], (new RetentionService($this->db))->prune(365));
    }
}
