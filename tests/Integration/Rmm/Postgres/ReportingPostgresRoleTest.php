<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Rmm\Postgres;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingRole;
use RivetCore\Reporting\ReportingSchema;
use RivetCore\Rmm\Store\Postgres\PostgresConnectionInterface;
use RivetCore\Rmm\Store\Postgres\PostgresException;
use RivetCore\Tests\Support\PostgresTestDatabase;
use RivetCore\Tests\Support\ReportingSeed;

/** The read-only reporting role on a real PostgreSQL (#91 phase 2): created from the helper's SQL, it can read the views and do nothing else. */
final class ReportingPostgresRoleTest extends TestCase
{
    private PostgresConnectionInterface $admin;
    private string $role = 'rpt_test_reader';

    protected function setUp(): void
    {
        if (($why = PostgresTestDatabase::unavailable()) !== null) {
            $this->markTestSkipped($why);
        }
        $this->admin = PostgresTestDatabase::connect();
        PostgresTestDatabase::prepare($this->admin);
        ReportingSeed::postgres($this->admin);
        $this->dropRole();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->dropRole();
        }
    }

    private function dropRole(): void
    {
        $exists = $this->admin->value('SELECT 1 FROM pg_roles WHERE rolname = ?', [$this->role]);
        if ($exists !== null) {
            foreach (ReportingRole::dropPostgresStatements((string) getenv('RIVETCORE_TEST_PG_NAME'), $this->role) as $sql) {
                $this->admin->execute($sql);
            }
        }
    }

    private function connectAs(string $password): PostgresConnectionInterface
    {
        return PostgresTestDatabase::connect(['user' => $this->role, 'password' => $password]);
    }

    private function refused(PostgresConnectionInterface $c, string $sql): void
    {
        try {
            $c->execute($sql);
            $this->fail('must be refused: ' . $sql);
        } catch (PostgresException $e) {
            $this->assertMatchesRegularExpression('/permission denied|read-only|must be owner|cannot execute/i', $e->getMessage(), $sql);
        }
    }

    public function testTheRoleReadsTheViewsAndNothingElse(): void
    {
        $pw = ReportingRole::generatePassword();
        foreach (ReportingRole::postgresStatements((string) getenv('RIVETCORE_TEST_PG_NAME'), $pw, $this->role) as $sql) {
            $this->admin->execute($sql);
        }
        $c = $this->connectAs($pw);

        foreach (ReportingSchema::viewNames('postgres') as $v) {
            $c->query("SELECT * FROM $v LIMIT 3");
        }
        $this->assertSame(6, (int) $c->value('SELECT COUNT(*) FROM rpt_device_health_hourly WHERE asset_id = 101'));
        $this->assertSame('on', $c->value('SHOW default_transaction_read_only'));
        $this->assertSame('UTC', $c->value('SHOW TimeZone'));

        foreach (['SELECT * FROM endpoint_agent_check_history', 'SELECT * FROM rmm_metric_hourly', 'SELECT * FROM endpoint_agent_change_records', 'SELECT * FROM rivet_core_pg_meta', 'SELECT * FROM rivet_core_pg_migrations',
            "INSERT INTO rmm_metric_hourly (asset_id, metric_key, hour_start, min_value, max_value) VALUES (1, 'x', now(), 0, 0)", 'UPDATE rmm_patch_history SET result = \'x\'', 'DELETE FROM rmm_patch_history', 'TRUNCATE rmm_patch_history',
            "INSERT INTO rpt_patch_history (result) VALUES ('x')", 'UPDATE rpt_patch_history SET result = \'x\'', 'DELETE FROM rpt_change_events', 'DROP VIEW rpt_patch_history', 'CREATE TABLE rpt_probe (a int)',
            'CREATE VIEW rpt_probe AS SELECT 1', 'ALTER TABLE rmm_metric_hourly ADD COLUMN z int', 'CREATE ROLE rpt_other', 'GRANT SELECT ON rmm_metric_hourly TO PUBLIC'] as $sql) {
            $this->refused($c, $sql);
        }
        // even after switching the session to read-write the grants still refuse writes
        $c->execute('SET default_transaction_read_only = off');
        $this->refused($c, 'DELETE FROM rmm_patch_history');
        $this->refused($c, 'DELETE FROM rpt_patch_history');
        $this->assertSame(4, (int) $this->admin->value('SELECT COUNT(*) FROM rmm_patch_history'), 'nothing was changed');

        // exactly the six views, and no table privilege on anything else
        $grants = $this->admin->query("SELECT table_name, privilege_type FROM information_schema.role_table_grants WHERE grantee = ? ORDER BY table_name, privilege_type", [$this->role]);
        $this->assertSame(array_fill(0, 6, 'SELECT'), array_column($grants, 'privilege_type'));
        $names = array_column($grants, 'table_name');
        sort($names);
        $expected = ReportingSchema::viewNames('postgres');
        sort($expected);
        $this->assertSame($expected, $names);
        $this->assertFalse((bool) $this->admin->value("SELECT rolsuper OR rolcreatedb OR rolcreaterole OR rolreplication FROM pg_roles WHERE rolname = ?", [$this->role]));
        $this->assertSame(ReportingRole::DEFAULT_MAX_CONNECTIONS, (int) $this->admin->value('SELECT rolconnlimit FROM pg_roles WHERE rolname = ?', [$this->role]));
        $c->close();
    }

    public function testRunningTheStatementsAgainRotatesThePasswordAndRevokesExtras(): void
    {
        $db = (string) getenv('RIVETCORE_TEST_PG_NAME');
        $old = ReportingRole::generatePassword();
        foreach (ReportingRole::postgresStatements($db, $old, $this->role) as $sql) {
            $this->admin->execute($sql);
        }
        $this->admin->execute('GRANT SELECT ON rmm_patch_history TO "' . $this->role . '"');
        $new = ReportingRole::generatePassword();
        foreach (ReportingRole::postgresStatements($db, $new, $this->role) as $sql) {
            $this->admin->execute($sql);
        }
        try {
            $this->connectAs($old)->value('SELECT 1');
            $this->fail('the old password must stop working');
        } catch (PostgresException) {
            $this->addToAssertionCount(1);
        }
        $c = $this->connectAs($new);
        $this->refused($c, 'SELECT * FROM rmm_patch_history');
        $this->assertSame(2, (int) $c->value('SELECT COUNT(*) FROM rpt_patch_history WHERE client_id = 7'));
    }
}
