<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingSchema;
use RivetCore\Reporting\ReportingView;

require_once __DIR__ . '/../../../scripts/lib/ReportingDocs.php';

/**
 * The reporting views are a public data contract (#91): the column list of every view is FROZEN in tests/Fixtures/reporting_views.json, and no secret, credential or
 * free-form text can be exposed. This file needs no database; the live checks (columns of the created views, tenant, role) are in tests/Integration/Reporting.
 */
final class ReportingContractTest extends TestCase
{
    /** Words that must never appear in an exposed column name or in a base column the views read (substring match). */
    private const SECRET_WORDS = ['token', 'secret', 'password', 'passwd', 'hash', 'key', 'credential', 'cookie', 'signature', 'private', 'salt', 'cipher', 'sealed', 'otp', 'seed'];
    /** Name parts (split at underscores) of columns that hold free-form text or personal data: counts, codes and types are exposed, never these. */
    private const FREE_TEXT_PARTS = ['output', 'script', 'command', 'cmdline', 'detail', 'message', 'summary', 'payload', 'body', 'content', 'ip', 'mac', 'serial', 'json', 'stdout', 'stderr', 'params', 'metadata'];

    public function testFixtureFreezesEveryColumnOfEveryView(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../Fixtures/reporting_views.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $fixture['contract']);
        $live = json_decode(\ReportingDocs::fixtureJson(), true, 512, JSON_THROW_ON_ERROR);
        $hint = "\nThe reporting view contract changed. A column may only be added ON PURPOSE: append it to the END of the view, run `php scripts/reporting-docs.php` and commit the new tests/Fixtures/reporting_views.json (and the migration that re-creates the view).";
        $this->assertSame(array_keys($fixture['views']), array_keys($live['views']), 'The set of views changed.' . $hint);
        foreach ($fixture['views'] as $name => $frozen) {
            $this->assertSame($frozen, $live['views'][$name], "$name changed." . $hint);
        }
    }

    public function testViewDefinitionsAreConsistent(): void
    {
        $names = [];
        foreach (ReportingCatalog::views() as $v) {
            $this->assertNotContains($v->name, $names, 'duplicate view');
            $names[] = $v->name;
            $cols = array_column($v->columns, 0);
            $this->assertSame(array_values(array_unique($cols)), $cols, $v->name . ' has a duplicate column');
            $this->assertNotSame([], $cols);
            foreach ($v->keys as $k) {
                $this->assertContains($k, $cols, $v->name . ' key ' . $k);
            }
            foreach ($v->postgresOmit as $o) {
                $this->assertContains($o, $cols, $v->name . ' omits unknown ' . $o);
            }
            $this->assertSame($v->inPostgres(), $v->store === ReportingView::STORE_HOT, $v->name . ': exactly the hot-table views exist in the PostgreSQL store');
            if ($v->clientScoped) {
                $this->assertContains('client_id', $cols, $v->name . ' is client-scoped and must carry client_id');
            } else {
                $this->assertNotContains('client_id', $cols);
            }
            foreach ($v->columns as [$c, , $meaning]) {
                $this->assertNotSame('', trim($meaning), $v->name . '.' . $c . ' has no description');
            }
            foreach ($v->related as $col => $to) {
                $this->assertContains($col, $cols);
                if (preg_match('/^(rpt_[a-z_]+)\.([a-z_]+)$/', $to, $m) === 1) {
                    $this->assertContains($m[2], array_column(ReportingCatalog::get($m[1])->columns, 0), "$to in " . $v->name);
                }
            }
        }
        // The documented exception: the audit summary has no client. Nothing else may be unscoped.
        $unscoped = array_map(static fn (ReportingView $v): string => $v->name, array_filter(ReportingCatalog::views(), static fn (ReportingView $v): bool => !$v->clientScoped));
        $this->assertSame(['rpt_audit_events'], array_values($unscoped));
    }

    public function testCreateStatementsPinTheColumnsAndNeverSelectStar(): void
    {
        foreach (ReportingSchema::mariadbStatements() as $sql) {
            $this->assertMatchesRegularExpression('/^CREATE OR REPLACE VIEW `rpt_[a-z_]+` \(`[a-z_]+`(, `[a-z_]+`)*\) AS\nSELECT /', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bSELECT\s+\*|\w\.\*|,\s*\*/i', $sql, 'SELECT * would expose a column added later');
        }
        foreach (ReportingSchema::postgresStatements() as $sql) {
            $this->assertMatchesRegularExpression('/^CREATE OR REPLACE VIEW "rpt_[a-z_]+" \("[a-z_]+"(, "[a-z_]+")*\) AS\nSELECT /', $sql);
            $this->assertDoesNotMatchRegularExpression('/\bSELECT\s+\*|\w\.\*|,\s*\*/i', $sql);
        }
        $this->assertCount(count(ReportingCatalog::views()), ReportingSchema::mariadbStatements());
        $this->assertSame(['rpt_device_health_hourly', 'rpt_device_health_daily', 'rpt_check_results', 'rpt_patch_history', 'rpt_software_changes', 'rpt_change_events'], ReportingSchema::viewNames('postgres'));
    }

    public function testNoSecretColumnCanBeExposed(): void
    {
        foreach (ReportingCatalog::views() as $v) {
            foreach (['mariadb', 'postgres'] as $dialect) {
                foreach ($v->columnNames($dialect) as $c) {
                    $this->assertNoSecret($c, $v->name . ' exposes ' . $c);
                    $this->assertSame([], array_intersect(explode('_', $c), self::FREE_TEXT_PARTS), $v->name . '.' . $c . ' looks like free-form text or personal data');
                }
            }
        }
    }

    /** Every underlying table column the SQL reads (alias.column) is scanned too: a view must not even READ a secret column (that is how one gets exposed by accident). */
    public function testNoViewReadsASecretBaseColumn(): void
    {
        $total = 0;
        foreach (ReportingCatalog::views() as $v) {
            foreach (array_filter([$v->mariadbSql, $v->postgresSql]) as $sql) {
                $bare = preg_replace("/'[^']*'/", "''", $sql) ?? '';
                preg_match_all('/\b[a-z]{1,3}\.`?"?([a-z_]+)`?"?/', $bare, $m);
                foreach (array_unique($m[1]) as $col) {
                    ++$total;
                    if (in_array($col, ReportingCatalog::ALLOWED_BASE_COLUMNS, true)) {
                        continue;
                    }
                    $this->assertNoSecret($col, $v->name . ' reads base column ' . $col);
                    $this->assertSame([], array_intersect(explode('_', $col), self::FREE_TEXT_PARTS), $v->name . ' reads free-text column ' . $col);
                }
            }
        }
        $this->assertGreaterThan(100, $total, 'the scan found the columns');
    }

    public function testTheDenyListWouldCatchWhatItIsMeantToCatch(): void
    {
        foreach (['token_hash', 'signing_private_key_enc', 'password_sealed', 'api_key', 'client_secret', 'session_cookie', 'signature', 'credential_id', 'tokens'] as $bad) {
            $this->assertTrue($this->isSecret($bad), $bad);
        }
        foreach (['device_id', 'client_id', 'hour_start', 'metric_name', 'avg_value', 'status'] as $ok) {
            $this->assertFalse($this->isSecret($ok), $ok);
        }
    }

    public function testGeneratedDocsAreUpToDate(): void
    {
        foreach (\ReportingDocs::all() as $rel => $content) {
            $path = \ReportingDocs::root() . '/' . $rel;
            $this->assertFileExists($path, $rel);
            $this->assertSame($content, file_get_contents($path), "$rel is stale: run php scripts/reporting-docs.php");
        }
        $this->assertFileExists(\ReportingDocs::root() . '/docs/reporting/DATA_DICTIONARY.md');
        $this->assertStringContainsString(\ReportingDocs::DICTIONARY_BEGIN, (string) file_get_contents(\ReportingDocs::root() . '/docs/reporting/DATA_DICTIONARY.md'));
    }

    public function testPostgresViewStatementsAreFrozenOnceShipped(): void
    {
        // The PostgreSQL migrator checksums the statements of an applied migration and reports a change. Version 1 must never change: a new column is a NEW versioned list
        // and a new migration id. If this fails because a view was deliberately extended, add postgresStatementsV2() instead of editing this hash.
        $this->assertSame('87baebdf12d81b80230ab20a5e79c238e5129d8c57337067fdb8535efc3d46d8', hash('sha256', implode("\n;\n", ReportingSchema::postgresStatements())));
    }

    private function isSecret(string $name): bool
    {
        foreach (self::SECRET_WORDS as $w) {
            if (str_contains($name, $w)) {
                return true;
            }
        }

        return preg_match('/(^|_)enc($|_)/', $name) === 1;
    }

    private function assertNoSecret(string $name, string $message): void
    {
        $this->assertFalse($this->isSecret($name), $message);
    }
}
