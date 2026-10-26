<?php

declare(strict_types=1);

use RivetCore\Reporting\ReportingCatalog;
use RivetCore\Reporting\ReportingView;

/**
 * Generates, from the reporting catalog, everything in the reporting docs that must not drift from the views: the frozen-column fixture, the generated part of
 * docs/reporting/DATA_DICTIONARY.md and the Power Query (M) sample for every view and source. Used by scripts/reporting-docs.php and by the test that fails when a
 * generated file is stale. Development tooling only (scripts/ is not part of the package).
 */
final class ReportingDocs
{
    public const DICTIONARY_BEGIN = '<!-- BEGIN GENERATED: views (php scripts/reporting-docs.php) -->';
    public const DICTIONARY_END = '<!-- END GENERATED: views -->';

    /** The column that incremental refresh filters on, per view (the history tables). */
    public const INCREMENTAL = [
        'rpt_device_health_hourly' => 'hour_start', 'rpt_device_health_daily' => 'day_start', 'rpt_check_results' => 'reported_at', 'rpt_patch_history' => 'occurred_at',
        'rpt_software_changes' => 'occurred_at', 'rpt_change_events' => 'occurred_at', 'rpt_audit_events' => 'created_at',
    ];

    private const M_TYPES = ['int' => 'Int64.Type', 'flag' => 'Int64.Type', 'double' => 'type number', 'text' => 'type text', 'datetime' => 'type datetime', 'date' => 'type date'];

    public static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function fixtureJson(): string
    {
        $views = [];
        foreach (ReportingCatalog::views() as $v) {
            $views[$v->name] = [
                'store' => $v->store,
                'client_scoped' => $v->clientScoped,
                'columns' => array_map(static fn (array $c): array => [$c[0], $c[1]], $v->columns),
                'postgres_columns' => $v->inPostgres() ? array_map(static fn (array $c): array => [$c[0], $c[1]], $v->columnsFor('postgres')) : null,
            ];
        }

        return json_encode(['contract' => 1, 'views' => $views], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function dictionaryBlock(): string
    {
        $o = [self::DICTIONARY_BEGIN, ''];
        $o[] = '| View | Grain | Store | Client-scoped |';
        $o[] = '|---|---|---|---|';
        foreach (ReportingCatalog::views() as $v) {
            $o[] = '| [`' . $v->name . '`](#' . $v->name . ') | ' . $v->grain . ' | ' . self::storeText($v) . ' | ' . ($v->clientScoped ? 'yes' : '**no** (administrators only)') . ' |';
        }
        $o[] = '';
        foreach (ReportingCatalog::views() as $v) {
            $o[] = '### ' . $v->name;
            $o[] = '';
            $o[] = $v->purpose;
            $o[] = '';
            $o[] = '* **Grain:** ' . $v->grain . '.';
            $o[] = '* **Store:** ' . self::storeText($v) . '.';
            $o[] = '* **Refresh:** ' . $v->refresh . '.';
            $o[] = '* **Keys:** ' . implode(', ', array_map(static fn (string $k): string => '`' . $k . '`', $v->keys)) . ' (the columns that identify a row come first, then the foreign keys).';
            if ($v->related !== []) {
                $r = [];
                foreach ($v->related as $col => $to) {
                    $r[] = '`' . $col . '` relates to ' . (str_contains($to, '.') ? '`' . $to . '`' : $to);
                }
                $o[] = '* **Relationships:** ' . implode('; ', $r) . '.';
            }
            $o[] = '* **Client scope:** ' . ($v->clientScoped ? ($v->inPostgres() && $v->postgresOmit !== [] && in_array('client_id', $v->postgresOmit, true)
                ? '`client_id` on every row in the control database; the PostgreSQL copy has no `client_id` (relate `device_id` or `asset_id` to `rpt_devices` and scope there)'
                : '`client_id` on every row') : 'none, the view has no `client_id` and must not be granted to a per-client account') . '.';
            if ($v->note !== '') {
                $o[] = '* **Note:** ' . $v->note;
            }
            $o[] = '';
            $o[] = '| # | Column | Type | Meaning |' . ($v->inPostgres() && $v->postgresOmit !== [] ? ' PostgreSQL copy |' : '');
            $o[] = '|---|---|---|---|' . ($v->inPostgres() && $v->postgresOmit !== [] ? '---|' : '');
            foreach ($v->columns as $i => [$c, $t, $m]) {
                $o[] = '| ' . ($i + 1) . ' | `' . $c . '` | ' . $t . ' | ' . str_replace('|', '\\|', $m) . ' |' . ($v->inPostgres() && $v->postgresOmit !== [] ? (in_array($c, $v->postgresOmit, true) ? ' not present |' : ' yes |') : '');
            }
            $o[] = '';
        }
        $o[] = self::DICTIONARY_END;

        return implode("\n", $o);
    }

    private static function storeText(ReportingView $v): string
    {
        return $v->store === ReportingView::STORE_CONTROL
            ? 'control database (MariaDB / MySQL)'
            : 'control database; also the PostgreSQL store when `data_store` = `postgres` (that copy is the live one then)';
    }

    /** @param string $dialect mysql|postgres */
    public static function powerQuery(ReportingView $v, string $dialect): string
    {
        $pg = $dialect === 'postgres';
        $cols = $pg ? $v->columnsFor('postgres') : $v->columns;
        $o = [];
        $o[] = '// ' . $v->name . ': ' . $v->grain . '.';
        $o[] = '// Source: ' . ($pg ? 'the PostgreSQL RMM data store (data_store = postgres)' : 'the control database (MySQL or MariaDB)') . '. NOT VERIFIED in Power BI Desktop: the SQL and the column names are tested, the M text is only structure-checked.';
        $o[] = '// Parameters (Home > Manage parameters, type Text): ServerName = "host:' . ($pg ? '5432' : '3306') . '", DatabaseName = "your database".';
        $steps = [
            $pg ? 'Source = PostgreSQL.Database(ServerName, DatabaseName, [CreateNavigationProperties = false])'
                : 'Source = MySQL.Database(ServerName, DatabaseName, [ReturnSingleDatabase = true, CreateNavigationProperties = false])',
            'Rows = Source{[Schema = ' . ($pg ? '"public"' : 'DatabaseName') . ', Item = "' . $v->name . '"]}[Data]',
        ];
        $times = [];
        $types = [];
        foreach ($cols as [$c, $t]) {
            if ($pg && $t === 'datetime') {
                $times[] = '{"' . $c . '", each if _ is datetimezone then DateTimeZone.RemoveZone(DateTimeZone.SwitchZone(_, 0)) else _, type nullable datetime}';
            } else {
                $types[] = '{"' . $c . '", ' . self::M_TYPES[$t] . '}';
            }
        }
        $prev = 'Rows';
        if ($times !== []) {
            // timestamptz arrives with a zone; keep the UTC wall-clock time (every timestamp in the views is UTC)
            $steps[] = 'Utc = Table.TransformColumns(Rows, {' . implode(', ', $times) . '})';
            $prev = 'Utc';
        }
        $steps[] = 'Typed = Table.TransformColumnTypes(' . $prev . ', {' . implode(', ', $types) . '}, "en-US")';
        $o[] = 'let';
        foreach ($steps as $i => $s) {
            $o[] = '    ' . $s . ($i < count($steps) - 1 ? ',' : '');
        }
        if (isset(self::INCREMENTAL[$v->name])) {
            $f = self::INCREMENTAL[$v->name];
            $o[] = '    // INCREMENTAL REFRESH (optional, append-only history): create two Date/Time parameters named RangeStart and RangeEnd, add a comma after the Typed step,';
            $o[] = '    // uncomment the next line and change the line after "in" to Filtered:';
            $o[] = '    // Filtered = Table.SelectRows(Typed, each [' . $f . '] >= RangeStart and [' . $f . '] < RangeEnd)';
        }
        $o[] = 'in';
        $o[] = '    Typed';

        return implode("\n", $o) . "\n";
    }

    /** @return array<string,string> path relative to the repository root => content */
    public static function samples(): array
    {
        $out = [];
        foreach (ReportingCatalog::views() as $v) {
            $out['docs/reporting/samples/power-query/mysql-mariadb/' . $v->name . '.pq'] = self::powerQuery($v, 'mysql');
            if ($v->inPostgres()) {
                $out['docs/reporting/samples/power-query/postgresql/' . $v->name . '.pq'] = self::powerQuery($v, 'postgres');
            }
        }

        return $out;
    }

    /** @return array<string,string> every generated file, relative path => content */
    public static function all(): array
    {
        $out = self::samples();
        $out['tests/Fixtures/reporting_views.json'] = self::fixtureJson();
        $dict = self::root() . '/docs/reporting/DATA_DICTIONARY.md';
        if (is_file($dict)) {
            $cur = (string) file_get_contents($dict);
            $b = strpos($cur, self::DICTIONARY_BEGIN);
            $e = strpos($cur, self::DICTIONARY_END);
            if ($b !== false && $e !== false && $e > $b) {
                $out['docs/reporting/DATA_DICTIONARY.md'] = substr($cur, 0, $b) . self::dictionaryBlock() . substr($cur, $e + strlen(self::DICTIONARY_END));
            }
        }

        return $out;
    }
}
