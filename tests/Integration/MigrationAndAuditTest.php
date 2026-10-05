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
        $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock());
    }

    public function testAppliesThenIsIdempotent(): void
    {
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes'], $this->runner()->pending());
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes'], $this->runner()->run());
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
        $this->assertSame(['0001_audit_events', '0002_integration_jobs', '0003_mcp_unlinked_identities', '0004_problems_and_changes'], $this->runner()->run());
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
}
