<?php

declare(strict_types=1);

namespace RivetCore\Reporting;

/**
 * The DDL of the reporting views (#91 phase 2). Idempotent: every statement is `CREATE OR REPLACE VIEW`, so running it twice, or after a column was appended,
 * changes nothing that a reader can see except the new column.
 *
 *  - {@see mariadbStatements()}: every view, in the control database (MariaDB / MySQL). The hot-table views read the control copy of the hot tables, which is the
 *    live data while `rmm_data_store` is `mariadb` and an empty or frozen leftover after a move to PostgreSQL (the views over the PostgreSQL store are the live ones then).
 *  - {@see postgresStatements()}: the hot-table views, in the PostgreSQL data store; run by the PostgreSQL migrator as migration `0002_reporting_views`. The version-1 list
 *    is checksummed by the migrator once applied: it must never change. A later release that needs a new column adds a `postgresStatementsV2()` and a new migration id.
 *
 * Both dialects create the views with the privileges of the creating user (MariaDB / MySQL `SQL SECURITY DEFINER`, the default; PostgreSQL view owner), which is what lets a
 * reporting role read the views without any grant on the base tables ({@see ReportingRole}).
 *
 * @api
 */
final class ReportingSchema
{
    /** @return list<string> one `CREATE OR REPLACE VIEW` per view, in catalog order */
    public static function mariadbStatements(): array
    {
        $out = [];
        foreach (ReportingCatalog::views() as $v) {
            $out[] = self::create($v->name, $v->columnNames('mariadb'), $v->mariadbSql, '`');
        }

        return $out;
    }

    /** @return list<string> one `CREATE OR REPLACE VIEW` per hot view, in catalog order (version 1) */
    public static function postgresStatements(): array
    {
        $out = [];
        foreach (ReportingCatalog::views() as $v) {
            if ($v->postgresSql !== null) {
                $out[] = self::create($v->name, $v->columnNames('postgres'), $v->postgresSql, '"');
            }
        }

        return $out;
    }

    /**
     * Names of the views that exist in a store.
     *
     * @param string $dialect `mariadb` (also MySQL) or `postgres`
     * @return list<string>
     */
    public static function viewNames(string $dialect = 'mariadb'): array
    {
        $out = [];
        foreach (ReportingCatalog::views() as $v) {
            if ($dialect !== 'postgres' || $v->inPostgres()) {
                $out[] = $v->name;
            }
        }

        return $out;
    }

    /**
     * An explicit column list pins the names, so a renamed expression in the SELECT can never silently rename a column of the contract.
     *
     * @param list<string> $columns
     */
    private static function create(string $name, array $columns, string $select, string $q): string
    {
        return 'CREATE OR REPLACE VIEW ' . $q . $name . $q . ' (' . implode(', ', array_map(static fn (string $c): string => $q . $c . $q, $columns)) . ") AS\n" . $select;
    }
}
