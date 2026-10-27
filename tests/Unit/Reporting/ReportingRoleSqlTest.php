<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingRole;
use RivetCore\Reporting\ReportingSchema;

/** The generated account SQL, without a database: what it contains, and that nothing can be injected through its inputs. */
final class ReportingRoleSqlTest extends TestCase
{
    public function testMariadbStatementsGrantSelectOnTheViewsOnly(): void
    {
        $pw = ReportingRole::generatePassword();
        $sql = ReportingRole::mariadbStatements('itflow', $pw, 'rivet_reporting', '10.0.5.%');
        $this->assertStringStartsWith("CREATE USER IF NOT EXISTS 'rivet_reporting'@'10.0.5.%'", $sql[0]);
        $this->assertStringContainsString('REQUIRE SSL WITH MAX_USER_CONNECTIONS 5', $sql[1]);
        $this->assertStringContainsString('REVOKE ALL PRIVILEGES, GRANT OPTION', $sql[2]);
        $grants = array_slice($sql, 3);
        $this->assertCount(count(ReportingCatalog::views()), $grants);
        foreach ($grants as $g) {
            $this->assertMatchesRegularExpression("/^GRANT SELECT ON `itflow`\\.`rpt_[a-z_]+` TO 'rivet_reporting'@'10\\.0\\.5\\.%'\$/", $g);
        }
        $this->assertStringNotContainsString('ALL PRIVILEGES ON', implode("\n", $sql));
        $this->assertStringContainsString('REQUIRE NONE', ReportingRole::mariadbStatements('itflow', $pw, 'u', '%', false)[1]);
    }

    public function testPostgresStatementsAreReadOnlyAndScoped(): void
    {
        $sql = ReportingRole::postgresStatements('rmm', ReportingRole::generatePassword(), 'rivet_reporting', 'public');
        $all = implode("\n", $sql);
        $this->assertStringContainsString('NOSUPERUSER NOCREATEDB NOCREATEROLE', $all);
        $this->assertStringContainsString('default_transaction_read_only = on', $all);
        $this->assertStringContainsString("statement_timeout = '120s'", $all);
        $this->assertStringContainsString("TimeZone = 'UTC'", $all);
        $grants = array_values(array_filter($sql, static fn (string $s): bool => str_starts_with($s, 'GRANT SELECT')));
        $this->assertCount(count(ReportingSchema::viewNames('postgres')), $grants);
        $this->assertStringNotContainsString('ALL TABLES IN SCHEMA "public" TO', $all);
        $this->assertSame(6, count($grants));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badInputs')]
    public function testHostileInputsAreRejectedBeforeAnySqlIsBuilt(string $what, string $value): void
    {
        $pw = ReportingRole::generatePassword();
        $this->expectException(\InvalidArgumentException::class);
        match ($what) {
            'db' => ReportingRole::mariadbStatements($value, $pw),
            'user' => ReportingRole::mariadbStatements('itflow', $pw, $value),
            'host' => ReportingRole::mariadbStatements('itflow', $pw, 'u', $value),
            'password' => ReportingRole::mariadbStatements('itflow', $value),
            'pgdb' => ReportingRole::postgresStatements($value, $pw),
            'pguser' => ReportingRole::postgresStatements('rmm', $pw, $value),
            'pgschema' => ReportingRole::postgresStatements('rmm', $pw, 'u', $value),
            'pgpassword' => ReportingRole::postgresStatements('rmm', $value),
        };
    }

    /** @return iterable<string,array{string,string}> */
    public static function badInputs(): iterable
    {
        yield 'db quote' => ['db', 'itflow`; DROP DATABASE x; --'];
        yield 'user quote' => ['user', "u'; GRANT ALL ON *.* TO 'u"];
        yield 'host quote' => ['host', "%' OR '1'='1"];
        yield 'host space' => ['host', '10.0.0.1 x'];
        yield 'password quote' => ['password', "aaaaaaaaaaaaaaaaaaaa'; DROP USER root; --"];
        yield 'password backslash' => ['password', 'aaaaaaaaaaaaaaaaaaaa\\'];
        yield 'password short' => ['password', 'short'];
        yield 'pg db' => ['pgdb', 'rmm"; DROP DATABASE x; --'];
        yield 'pg user' => ['pguser', 'u" SUPERUSER'];
        yield 'pg schema' => ['pgschema', 'public; DROP SCHEMA x'];
        yield 'pg password' => ['pgpassword', "aaaaaaaaaaaaaaaaaaaa'; ALTER ROLE x SUPERUSER; --"];
        yield 'pg password dollar' => ['pgpassword', 'aaaaaaaaaaaaaaaaaaaa$$'];
    }

    public function testUnknownViewsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportingRole::mariadbStatements('itflow', ReportingRole::generatePassword(), 'u', '%', true, ['audit_events']);
    }

    public function testPostgresHasNoControlViews(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportingRole::postgresStatements('rmm', ReportingRole::generatePassword(), 'u', 'public', ['rpt_devices']);
    }

    public function testGeneratedPasswordsAreLongRandomAndQuotingSafe(): void
    {
        $a = ReportingRole::generatePassword();
        $b = ReportingRole::generatePassword();
        $this->assertNotSame($a, $b);
        $this->assertSame(32, strlen($a));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_.~+]{32}$/', $a);
        $this->assertSame(64, strlen(ReportingRole::generatePassword(64)));
        $this->expectException(\InvalidArgumentException::class);
        ReportingRole::generatePassword(8);
    }

    public function testNotesMentionTlsForBothServers(): void
    {
        $this->assertStringContainsString('hostssl', implode(' ', ReportingRole::notes('postgres')));
        $this->assertStringContainsString('REQUIRE SSL', implode(' ', ReportingRole::notes('mariadb')));
    }
}
