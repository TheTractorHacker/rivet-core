<?php

declare(strict_types=1);

namespace RivetCore\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingRole;
use RivetCore\Tests\Support\ReportingTestDb;

/**
 * The read-only reporting account on a real MariaDB / MySQL (#91 phase 2): the SQL of {@see ReportingRole} is executed here as the administrator would,
 * then the account itself is used. It can read the views and nothing else.
 */
final class ReportingRoleTest extends TestCase
{
    private \mysqli $admin;
    private string $schema;
    private string $user = 'rpt_test_reader';

    protected function setUp(): void
    {
        $t = ReportingTestDb::get();
        if ($t === null) {
            $this->markTestSkipped('RIVETCORE_TEST_DB_NAME not set, or the account cannot create the private reporting database.');
        }
        $this->admin = $t['mysqli'];
        $this->schema = $t['name'];
        mysqli_report(MYSQLI_REPORT_OFF);
        $this->drop();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            mysqli_report(MYSQLI_REPORT_OFF);
            $this->drop();
        }
    }

    private function drop(): void
    {
        foreach (ReportingRole::dropMariadbStatements($this->user, '%') as $sql) {
            $this->admin->query($sql);
        }
    }

    /** @param list<string> $statements */
    private function exec(array $statements): void
    {
        foreach ($statements as $sql) {
            $this->assertTrue($this->admin->query($sql) === true, $sql . ' => ' . $this->admin->error);
        }
    }

    private function connectAs(string $password): \mysqli
    {
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = new \mysqli((string) (getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1'), $this->user, $password, $this->schema, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
        $this->assertSame(0, $c->connect_errno, 'the reporting account cannot connect: ' . $c->connect_error);

        return $c;
    }

    public function testTheAccountCanReadTheViewsAndNothingElse(): void
    {
        $pw = ReportingRole::generatePassword();
        $this->exec(ReportingRole::mariadbStatements($this->schema, $pw, $this->user, '%', false));
        $c = $this->connectAs($pw);

        foreach (ReportingCatalog::views() as $v) {
            $r = $c->query('SELECT * FROM `' . $v->name . '` LIMIT 5');
            $this->assertNotFalse($r, $v->name . ': ' . $c->error);
        }
        $this->assertSame(6, (int) $c->query('SELECT COUNT(*) FROM rpt_devices')->fetch_row()[0]);

        // only the views are visible at all
        $visible = [];
        foreach ($c->query('SHOW TABLES')->fetch_all() as $row) {
            $visible[] = $row[0];
        }
        sort($visible);
        $expected = array_map(static fn ($v): string => $v->name, ReportingCatalog::views());
        sort($expected);
        $this->assertSame($expected, $visible);

        // no base table: read, write, and the rest are refused with "command denied" (1142)
        foreach (['SELECT * FROM endpoint_agent_devices', 'SELECT token_hash FROM endpoint_agent_devices', 'SELECT * FROM audit_events', 'SELECT * FROM rmm_alert_meta',
            "INSERT INTO endpoint_agent_devices (install_id) VALUES ('x')", "UPDATE endpoint_agent_devices SET hostname = 'x'", 'DELETE FROM endpoint_agent_devices', 'TRUNCATE endpoint_agent_devices',
            "INSERT INTO audit_events (event_type, action) VALUES ('a', 'b')"] as $sql) {
            $this->assertFalse($c->query($sql), 'must be refused: ' . $sql);
            $this->assertContains($c->errno, [1142, 1143, 1044, 1045], $sql . ' => ' . $c->error);
        }
        // not even through a view
        foreach (["UPDATE rpt_devices SET hostname = 'x'", 'DELETE FROM rpt_devices', "INSERT INTO rpt_device_tags (device_id, tag_id) VALUES (1, 1)", 'DROP VIEW rpt_devices',
            'CREATE TABLE rpt_probe (a int)', 'CREATE VIEW rpt_probe AS SELECT 1', 'ALTER VIEW rpt_devices AS SELECT 1', 'CREATE USER x@y IDENTIFIED BY \'zzzzzzzzzzzzzzzzzzzz\'', 'GRANT SELECT ON *.* TO x@y'] as $sql) {
            $this->assertFalse($c->query($sql), 'must be refused: ' . $sql);
        }
        $this->assertSame(6, (int) $this->admin->query('SELECT COUNT(*) FROM endpoint_agent_devices')->fetch_row()[0], 'nothing was changed');
        $c->close();

        // the exact grants: SELECT on the views, no GRANT OPTION, nothing on the schema or globally
        $admin = $this->admin->query("SHOW GRANTS FOR '" . $this->user . "'@'%'");
        $grants = array_map(static fn (array $r): string => $r[0], $admin->fetch_all());
        $selects = array_filter($grants, static fn (string $g): bool => str_starts_with($g, 'GRANT SELECT ON '));
        $this->assertCount(count(ReportingCatalog::views()), $selects);
        foreach ($grants as $g) {
            $this->assertStringNotContainsString('GRANT OPTION', $g);
            $this->assertDoesNotMatchRegularExpression('/ON `?\*`?\.`?\*`? TO/', preg_replace('/^GRANT USAGE ON \*\.\* TO .*/', '', $g) ?? '');
        }
    }

    public function testRunningTheStatementsAgainRotatesThePasswordAndRevokesExtras(): void
    {
        $old = ReportingRole::generatePassword();
        $this->exec(ReportingRole::mariadbStatements($this->schema, $old, $this->user, '%', false));
        $this->assertTrue($this->admin->query('GRANT SELECT ON `' . $this->schema . '`.`endpoint_agent_devices` TO \'' . $this->user . '\'@\'%\'') === true, $this->admin->error);
        $new = ReportingRole::generatePassword();
        $this->exec(ReportingRole::mariadbStatements($this->schema, $new, $this->user, '%', false));

        mysqli_report(MYSQLI_REPORT_OFF);
        $bad = @new \mysqli((string) (getenv('RIVETCORE_TEST_DB_HOST') ?: '127.0.0.1'), $this->user, $old, $this->schema, (int) (getenv('RIVETCORE_TEST_DB_PORT') ?: 0));
        $this->assertNotSame(0, $bad->connect_errno, 'the old password must stop working');
        $c = $this->connectAs($new);
        $this->assertFalse($c->query('SELECT * FROM endpoint_agent_devices'), 'a grant added by hand is revoked when the statements run again');
        $this->assertNotFalse($c->query('SELECT * FROM rpt_devices'));
    }

    public function testTlsIsRequiredByDefaultAndTheConnectionCountIsLimited(): void
    {
        $this->exec(ReportingRole::mariadbStatements($this->schema, ReportingRole::generatePassword(), $this->user));
        $row = $this->admin->query("SELECT ssl_type, max_user_connections AS m FROM mysql.user WHERE User = '" . $this->user . "'")->fetch_assoc();
        $this->assertIsArray($row);
        $this->assertSame('ANY', $row['ssl_type'], 'REQUIRE SSL');
        $this->assertSame(ReportingRole::DEFAULT_MAX_CONNECTIONS, (int) $row['m']);
    }

    public function testOnlyTheListedViewsAreGranted(): void
    {
        $pw = ReportingRole::generatePassword();
        $this->exec(ReportingRole::mariadbStatements($this->schema, $pw, $this->user, '%', false, ['rpt_devices', 'rpt_alerts']));
        $c = $this->connectAs($pw);
        $this->assertNotFalse($c->query('SELECT * FROM rpt_alerts'));
        $this->assertFalse($c->query('SELECT * FROM rpt_audit_events'), 'a view that was not granted is not readable');
    }
}
