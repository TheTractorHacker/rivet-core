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
        $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock());
    }

    public function testAppliesThenIsIdempotent(): void
    {
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities'], $this->runner()->pending());
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities'], $this->runner()->run());
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
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes', '0005_webhook_deliveries', '0006_automation_rules', '0007_workflow_tables', '0008_compliance', '0009_compliance_shared_report', '0010_compliance_subjects', '0011_compliance_responsibilities'], $this->runner()->run());
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
        $this->assertCount(11, $before);
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
        $other->query("SELECT GET_LOCK('" . MigrationRunner::LOCK_NAME . "', 1)");
        try {
            $impatient = new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock(), 1);
            $this->expectException(\RuntimeException::class);
            $impatient->run();
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . MigrationRunner::LOCK_NAME . "')");
            $this->assertCount(11, $this->runner()->pending(), 'nothing was applied while the lock was held elsewhere');
            $this->assertCount(11, $this->runner()->run());
        }
    }

    public function testRunTwiceInARowAppliesNothingTheSecondTime(): void
    {
        $first = $this->runner()->run();
        $this->assertCount(11, $first);
        $this->assertSame([], $this->runner()->run());
        $this->assertSame(11, (int) $this->db->fetchOne('SELECT COUNT(*) c FROM rivet_core_migrations')['c']);
    }
}
