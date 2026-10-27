<?php

declare(strict_types=1);

namespace RivetCore\Tests\Unit\Reporting;

use PHPUnit\Framework\TestCase;
use RivetCore\Reporting\ReportingCatalog;

/**
 * The Power Query (M) and DAX samples (#91) could not be run in Power BI Desktop. This is everything short of that: the text is well formed (brackets, quotes,
 * `let ... in`, steps defined before use), each query navigates to the view of its file name with the right connector, each typed column exists in the view and
 * every column of the view is typed, and every `table[column]` and `[measure]` in the DAX files exists.
 */
final class ReportingSamplesTest extends TestCase
{
    private const DIR = __DIR__ . '/../../../docs/reporting/samples';

    /** @return string the text without comments and with string literals emptied */
    private static function bare(string $text): string
    {
        $text = (string) preg_replace('~"(?:[^"]|"")*"~', '""', $text);   // strings first: a // inside a string is not a comment
        $text = (string) preg_replace('~//[^\n]*~', '', $text);

        return $text;
    }

    /** @return string the text without whole-line comments (the samples only use those), strings kept */
    private static function code(string $text): string
    {
        return implode("\n", array_filter(explode("\n", $text), static fn (string $l): bool => !str_starts_with(ltrim($l), '//')));
    }

    private function assertBalanced(string $bare, string $what): void
    {
        $stack = [];
        $pairs = [')' => '(', ']' => '[', '}' => '{'];
        foreach (str_split($bare) as $i => $ch) {
            if (in_array($ch, ['(', '[', '{'], true)) {
                $stack[] = $ch;
            } elseif (isset($pairs[$ch])) {
                $this->assertSame($pairs[$ch], array_pop($stack), "$what: unbalanced $ch at offset $i");
            }
        }
        $this->assertSame([], $stack, "$what: unclosed brackets");
        $this->assertSame(0, substr_count($bare, '"') % 2, "$what: odd number of quotes");
    }

    /** @return iterable<string,array{string,string,string}> */
    public static function queryFiles(): iterable
    {
        foreach (ReportingCatalog::views() as $v) {
            yield $v->name . ' (mysql)' => [self::DIR . '/power-query/mysql-mariadb/' . $v->name . '.pq', $v->name, 'mysql'];
            if ($v->inPostgres()) {
                yield $v->name . ' (postgres)' => [self::DIR . '/power-query/postgresql/' . $v->name . '.pq', $v->name, 'postgres'];
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('queryFiles')]
    public function testEveryPowerQueryIsWellFormedAndMatchesItsView(string $file, string $view, string $dialect): void
    {
        $this->assertFileExists($file);
        $raw = (string) file_get_contents($file);
        $bare = self::bare($raw);
        $this->assertBalanced($bare, basename($file));
        $this->assertSame(1, preg_match_all('/^let\s*$/m', $bare), 'one let');
        $this->assertSame(1, preg_match_all('/^in\s*$/m', $bare), 'one in');
        $this->assertMatchesRegularExpression('/\bin\s+Typed\s*$/s', $bare, 'the query returns the Typed step');
        $this->assertSame(1, preg_match('/^let\s*\n(.*?)\n^in\s*$/ms', self::code($raw)), 'let ... in');

        // steps: "Name = expression," one per line between let and in; defined once, referenced only after they are defined
        preg_match('/^let\s*\n(.*?)\n^in\s*$/ms', self::code($raw), $m);
        $steps = [];
        foreach (preg_split('/\n/', rtrim($m[1])) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $this->assertSame(1, preg_match('/^\s*([A-Za-z_]+) = (.*?),?\s*$/', $line, $s), 'a step per line: ' . $line);
            $this->assertArrayNotHasKey($s[1], $steps, 'step defined twice');
            foreach (['Source', 'Rows', 'Utc', 'Typed'] as $known) {
                if ($known !== $s[1] && preg_match('/\b' . $known . '\b/', $s[2]) === 1) {
                    $this->assertArrayHasKey($known, $steps, "$known used by {$s[1]} before it is defined");
                }
            }
            $steps[$s[1]] = $s[2];
        }
        $this->assertSame($dialect === 'postgres' ? ['Source', 'Rows', 'Utc', 'Typed'] : ['Source', 'Rows', 'Typed'], array_keys($steps), $dialect === 'postgres' ? 'the timestamps step is only for PostgreSQL' : '');

        $this->assertStringContainsString($dialect === 'postgres' ? 'PostgreSQL.Database(ServerName, DatabaseName' : 'MySQL.Database(ServerName, DatabaseName', $steps['Source']);
        $this->assertStringContainsString('Item = "' . $view . '"', $steps['Rows']);
        $this->assertStringContainsString($dialect === 'postgres' ? 'Schema = "public"' : 'Schema = DatabaseName', $steps['Rows']);

        // every column of the view (in this dialect) is typed exactly once, no column the view lacks is mentioned
        $expected = ReportingCatalog::get($view)->columnNames($dialect === 'postgres' ? 'postgres' : 'mariadb');
        preg_match_all('/\{"([a-z_]+)", /', $steps['Typed'], $typed);
        $names = $typed[1];
        if ($dialect === 'postgres') {
            preg_match_all('/\{"([a-z_]+)", each /', $steps['Utc'], $utc);
            $names = array_merge($names, $utc[1]);
        }
        $this->assertEqualsCanonicalizing($expected, $names, 'typed columns = view columns');
        $this->assertSame(count($expected), count($names), 'no column typed twice');

        // the commented incremental-refresh filter names a real timestamp column of a history view
        if (preg_match('/Filtered = Table\.SelectRows\(Typed, each \[([a-z_]+)\] >= RangeStart and \[\1\] < RangeEnd\)/', $raw, $f) === 1) {
            $this->assertContains($f[1], $expected);
            $this->assertSame('datetime', array_column(ReportingCatalog::get($view)->columns, 1, 0)[$f[1]]);
        }
    }

    public function testNoStaleSampleFilesAreLeftBehind(): void
    {
        $want = [];
        foreach (ReportingCatalog::views() as $v) {
            $want[] = 'mysql-mariadb/' . $v->name . '.pq';
            if ($v->inPostgres()) {
                $want[] = 'postgresql/' . $v->name . '.pq';
            }
        }
        $have = [];
        foreach (['mysql-mariadb', 'postgresql'] as $d) {
            foreach (glob(self::DIR . "/power-query/$d/*.pq") ?: [] as $f) {
                $have[] = $d . '/' . basename($f);
            }
        }
        $this->assertEqualsCanonicalizing($want, $have);
    }

    public function testTheModelHelperQueriesAreWellFormed(): void
    {
        foreach (['DeviceAssets', 'Clients'] as $name) {
            $raw = (string) file_get_contents(self::DIR . "/power-query/model/$name.pq");
            $bare = self::bare($raw);
            $raw = self::code($raw);   // string literals kept, comments dropped
            $this->assertBalanced($bare, $name);
            $this->assertMatchesRegularExpression('/^let\s*\n.*\n^in\s*\n\s+\w+\s*$/ms', $bare);
            preg_match_all('/"([a-z_]+)"/', $raw, $cols);
            $devices = ReportingCatalog::get('rpt_devices')->columnNames();
            foreach (array_unique($cols[1]) as $c) {
                $this->assertContains($c, $devices, "$name names a column rpt_devices lacks: $c");
            }
        }
    }

    /** @return iterable<string,array{string}> */
    public static function daxFiles(): iterable
    {
        yield 'measures.dax' => ['measures.dax'];
        yield 'measures-edition.dax' => ['measures-edition.dax'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('daxFiles')]
    public function testTheDaxFilesAreWellFormed(string $file): void
    {
        $raw = (string) file_get_contents(self::DIR . '/' . $file);
        $bare = self::bare($raw);
        $this->assertBalanced($bare, $file);
        $defs = self::measures($bare);
        $this->assertGreaterThan(5, count($defs), 'measures found');
        $this->assertSame(count($defs), count(array_unique(array_keys($defs))), 'a measure is defined once');
        foreach ($defs as $name => $body) {
            $this->assertNotSame('', trim($body), "$name has a body");
            $this->assertSame(0, preg_match('/^\s*=/', $body), $name);
        }
    }

    public function testEveryColumnReferencedInTheCoreMeasuresExists(): void
    {
        $bare = self::bare((string) file_get_contents(self::DIR . '/measures.dax'));
        preg_match_all('/\b(rpt_[a-z_]+)\[([a-z_]+)\]/', $bare, $refs, PREG_SET_ORDER);
        $this->assertGreaterThan(30, count($refs));
        foreach ($refs as [, $view, $col]) {
            $this->assertContains($col, ReportingCatalog::get($view)->columnNames(), "{$view}[{$col}] in measures.dax");
        }
        // an unqualified [Name] is a measure: it must be defined in this file
        $defs = self::measures($bare);
        $noTable = (string) preg_replace('/\b[A-Za-z_]+\[[^\]]+\]/', '', $bare);
        preg_match_all('/\[([^\]]+)\]/', $noTable, $m);
        foreach (array_unique($m[1]) as $ref) {
            $this->assertArrayHasKey($ref, $defs, "[$ref] is used but not defined");
        }
        // the table names the measures use are view names (the guide tells the reader to load them under these names)
        preg_match_all('/\b(rpt_[a-z_]+)\b/', $bare, $t);
        foreach (array_unique($t[1]) as $view) {
            ReportingCatalog::get($view);
            $this->addToAssertionCount(1);
        }
    }

    public function testTheEditionMeasuresOnlyReferenceCoreByViewAndMeasureNames(): void
    {
        $edition = self::bare((string) file_get_contents(self::DIR . '/measures-edition.dax'));
        $core = self::measures(self::bare((string) file_get_contents(self::DIR . '/measures.dax')));
        $mine = self::measures($edition);
        preg_match_all('/\b(rpt_[a-z_]+)\[([a-z_]+)\]/', $edition, $refs, PREG_SET_ORDER);
        foreach ($refs as [, $view, $col]) {
            if (in_array($view, ['rpt_tickets', 'rpt_alert_tickets'], true)) {
                continue;   // the edition's view (proposed columns, see docs/design/reporting.md)
            }
            $this->assertContains($col, ReportingCatalog::get($view)->columnNames(), "{$view}[{$col}]");
        }
        $noTable = (string) preg_replace('/\b[A-Za-z_]+\[[^\]]+\]/', '', $edition);
        preg_match_all('/\[([^\]]+)\]/', $noTable, $m);
        foreach (array_unique($m[1]) as $ref) {
            $this->assertTrue(isset($core[$ref]) || isset($mine[$ref]), "[$ref] is not a defined measure");
        }
    }

    /** @return array<string,string> measure name => body */
    private static function measures(string $bare): array
    {
        $out = [];
        $name = null;
        foreach (explode("\n", $bare) as $line) {
            if (preg_match('/^([A-Za-z][A-Za-z0-9 %()#\-\.]*?) =\s*(.*)$/', $line, $m) === 1 && !str_starts_with($line, ' ') && preg_match('/^(VAR|RETURN)\b/', $line) !== 1) {
                $name = trim($m[1]);
                $out[$name] = $m[2];
            } elseif ($name !== null) {
                $out[$name] .= "\n" . $line;
            }
        }

        return $out;
    }
}
