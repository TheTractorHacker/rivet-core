<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration;

use PHPUnit\Framework\TestCase;
use RivetCore\Audit\AuditService;
use RivetCore\Migration\CoreMigrations;
use RivetCore\Migration\MigrationRunner;
use RivetCore\Support\NullRequestContext;
use RivetCore\Tests\Support\FixedClock;
use RivetCore\Tests\Support\MysqliDatabase;
use RivetCore\Tests\Support\ScratchDb;

final class MigrationAndAuditTest extends TestCase
{
    private MysqliDatabase $db;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS audit_events');
        $this->db->execute('DROP TABLE IF EXISTS integration_jobs');
        $this->db->execute('DROP TABLE IF EXISTS mcp_unlinked_identities');
        $this->db->execute('DROP TABLE IF EXISTS problems');
        $this->db->execute('DROP TABLE IF EXISTS changes');
        foreach (['webhook_deliveries', 'automation_rules', 'workflow_run_tasks', 'workflow_runs', 'workflow_template_tasks', 'workflow_templates'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        foreach (['endpoint_agent_binaries', 'endpoint_agent_releases', 'endpoint_agent_mesh_nodes', 'endpoint_agent_jobs', 'endpoint_agent_checks', 'endpoint_agent_checkins', 'endpoint_agent_devices', 'endpoint_agent_enroll_attempts', 'endpoint_agent_enrollment_tokens', 'endpoint_agent_settings'] as $t) {
            $this->db->execute("DROP TABLE IF EXISTS $t");
        }
        $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock());
    }

    public function testAppliesThenIsIdempotent(): void
    {
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities', '0012_job_heartbeat', '0013_retention_indexes', '0014_endpoint_agent_core', '0015_endpoint_agent_converge', '0016_rmm_module_switches', '0017_mcp_identity_binary_collation'], $this->runner()->pending());
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities', '0012_job_heartbeat', '0013_retention_indexes', '0014_endpoint_agent_core', '0015_endpoint_agent_converge', '0016_rmm_module_switches', '0017_mcp_identity_binary_collation'], $this->runner()->run());
        $this->assertSame([], $this->runner()->run());
        $this->assertSame([], $this->runner()->pending());
        $row = $this->db->fetchOne('SELECT * FROM rivet_core_migrations');
        $this->assertSame('2026-01-02 03:04:05', $row['applied_at']);
    }

    public function testMigrationIsNoOpWhenEditionAlreadyOwnsTheTable(): void
    {
        $this->runner()->run();
        $this->db->execute('INSERT INTO audit_events (event_type, action) VALUES (?, ?)', ['keep', 'me']);
        $this->db->execute('DROP TABLE rivet_core_migrations');
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities', '0012_job_heartbeat', '0013_retention_indexes', '0014_endpoint_agent_core', '0015_endpoint_agent_converge', '0016_rmm_module_switches', '0017_mcp_identity_binary_collation'], $this->runner()->run());
        $this->assertSame(1, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM audit_events')['c']);
    }

    public function testAuditWritesARealRow(): void
    {
        $this->runner()->run();
        (new AuditService($this->db, new NullRequestContext()))->log('t.e', 3, 'thing', 9, 'create', 'sum', ['k' => 'v']);
        $row = $this->db->fetchOne('SELECT * FROM audit_events');
        $this->assertSame('t.e', $row['event_type']);
        $this->assertEquals(3, $row['actor_user_id']);
        $this->assertSame('9', $row['entity_id']);
        $this->assertSame('{"k":"v"}', $row['metadata_json']);
        $this->assertNotEmpty($row['created_at']);
    }

    public function testStatusListsEveryMigrationWithItsAppliedTime(): void
    {
        $before = $this->runner()->status();
        $this->assertCount(17, $before);
        $this->assertSame(['0001_audit_events', null], [$before[0]['id'], $before[0]['applied_at']]);
        $this->runner()->run();
        $after = $this->runner()->status();
        $this->assertSame('2026-01-02 03:04:05', $after[0]['applied_at']);
        $this->assertNotContains(null, array_column($after, 'applied_at'));
    }

    public function testSecondRunnerGivesUpCleanlyWhileAnotherHoldsTheLock(): void
    {
        // A different connection holds the migration lock, as a concurrent web request or CLI run would.
        $other = ScratchDb::connect();
        $other->query("SELECT GET_LOCK(CONCAT('" . MigrationRunner::LOCK_NAME . "', ':', MD5(IFNULL(DATABASE(), ''))), 1)");
        try {
            $impatient = new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock(), 1);
            $this->expectException(\RivetCore\Migration\MigrationInProgressException::class);
            $impatient->run();
        } finally {
            $other->query("SELECT RELEASE_LOCK(CONCAT('" . MigrationRunner::LOCK_NAME . "', ':', MD5(IFNULL(DATABASE(), ''))))");
            $this->assertCount(17, $this->runner()->pending(), 'nothing was applied while the lock was held elsewhere');
            $this->assertCount(17, $this->runner()->run());
        }
    }

    public function testRetentionIndexesExistAndTheMigrationIsIdempotent(): void
    {
        $this->runner()->run();
        $wanted = ['audit_events' => 'idx_audit_events_created', 'webhook_deliveries' => 'idx_webhook_deliveries_created', 'integration_jobs' => 'idx_integration_jobs_status_created'];
        $count = fn (string $t, string $i): int => (int) $this->db->fetchOne(
            'SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$t, $i]
        )['c'];
        foreach ($wanted as $table => $index) {
            $this->assertGreaterThan(0, $count($table, $index), $index);
        }
        (new \RivetCore\Retention\Migration\Migration0013RetentionIndexes())->up($this->db);
        foreach ($wanted as $table => $index) {
            $this->assertGreaterThan(0, $count($table, $index), "$index after a second up()");
        }
    }

    public function testRunTwiceInARowAppliesNothingTheSecondTime(): void
    {
        $first = $this->runner()->run();
        $this->assertCount(17, $first);
        $this->assertSame([], $this->runner()->run());
        $this->assertSame(17, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rivet_core_migrations')['c']);
    }
}
