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

/** Security review 2026-10, findings that need a real MariaDB/MySQL (docs/security/review-2026-10.md). */
final class SecurityReviewTest extends TestCase
{
    private MysqliDatabase $db;
    private \mysqli $raw;

    protected function setUp(): void
    {
        $m = ScratchDb::connect();
        if ($m === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set (scratch DB required).');
        }
        $this->raw = $m;
        $this->db = new MysqliDatabase($m);
        $this->db->execute('DROP TABLE IF EXISTS audit_events');
        $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
        (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock()))->run();
        $this->db->execute('TRUNCATE TABLE audit_events');
    }

    /** SR-05: under strict SQL a metadata value over the TEXT limit used to make the INSERT fail, so the event vanished. */
    public function testOversizedMetadataIsStoredAsATruncatedRowUnderStrictSqlMode(): void
    {
        $this->raw->query("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
        $audit = new AuditService($this->db, new NullRequestContext());
        $audit->log('mcp.tool_call', 4, 'mcp_tool', 'search', 'read', 'MCP search: ok', ['tool' => 'search', 'args' => ['q' => str_repeat('é', 300000)], 'password' => 'p']);

        $row = $this->db->fetchOne('SELECT metadata_json, event_type FROM audit_events');
        self::assertNotNull($row, 'the audit row must exist');
        self::assertSame('mcp.tool_call', $row['event_type']);
        $meta = json_decode((string) $row['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($meta['_truncated']);
        self::assertSame('search', $meta['tool']);
        self::assertSame('[redacted]', $meta['password']);
    }

    /** SR-11: GET_LOCK() names are server-wide, so the migration lock must not depend on another schema's runner. */
    public function testAMigrationLockHeldForAnotherSchemaDoesNotBlockThisOne(): void
    {
        $other = ScratchDb::connect();
        self::assertNotNull($other);
        $name = MigrationRunner::LOCK_NAME . ':' . md5('some_other_schema');
        $other->query("SELECT GET_LOCK('" . $other->real_escape_string($name) . "', 1)");
        try {
            $this->db->execute('DROP TABLE IF EXISTS rivet_core_migrations');
            $applied = (new MigrationRunner($this->db, CoreMigrations::all(), new FixedClock(), 1))->run();
            self::assertNotSame([], $applied);
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . $other->real_escape_string($name) . "')");
        }
    }
}
