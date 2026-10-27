<?php

declare(strict_types=1);

namespace RivetCore\Reporting;

/**
 * One reporting view of the `rpt_` family (#91 phase 2): its name, which store holds it, its frozen column list and the SQL for each dialect.
 *
 * Column types are LOGICAL ({@see TYPES}); the live database types are checked against the family of the logical type by the view-contract test, not compared
 * byte for byte, because MariaDB, MySQL and PostgreSQL name the same type differently.
 *
 * @api
 */
final class ReportingView
{
    /** Logical column types. `flag` is a 0/1 integer; `int` covers every integer width; `double` also covers decimal and numeric. */
    public const TYPES = ['int', 'double', 'text', 'datetime', 'date', 'flag'];

    /** The view lives in the control database (MariaDB / MySQL), whatever the RMM data store is. */
    public const STORE_CONTROL = 'control';
    /** The view reads a hot RMM table: it exists in the control database AND in the PostgreSQL store when `rmm_data_store` is `postgres`. */
    public const STORE_HOT = 'hot';

    /**
     * @param list<array{0:string,1:string,2:string}> $columns name, logical type, meaning; in the order of the view (MariaDB / MySQL)
     * @param list<string> $keys the columns that identify a row (the first is the primary key of the grain), then foreign keys
     * @param list<string> $postgresOmit columns that the PostgreSQL variant does not have (the hot table there has no device table to join); empty when the same
     * @param array<string,string> $related column => "view.column" (or a description) relationships for the data dictionary
     */
    public function __construct(
        public readonly string $name,
        public readonly string $store,
        public readonly string $grain,
        public readonly string $purpose,
        public readonly string $refresh,
        public readonly bool $clientScoped,
        public readonly array $columns,
        public readonly array $keys,
        public readonly string $mariadbSql,
        public readonly ?string $postgresSql = null,
        public readonly array $postgresOmit = [],
        public readonly array $related = [],
        public readonly string $note = '',
    ) {
        if (preg_match('/^rpt_[a-z0-9_]+$/', $name) !== 1) {
            throw new \InvalidArgumentException('A reporting view name must be rpt_ plus lowercase letters, digits and underscores: ' . $name);
        }
        foreach ($columns as [$c, $t]) {
            if (preg_match('/^[a-z][a-z0-9_]*$/', $c) !== 1 || !in_array($t, self::TYPES, true)) {
                throw new \InvalidArgumentException("Bad column $c ($t) in $name.");
            }
        }
    }

    /** Whether the view also exists in the PostgreSQL store. */
    public function inPostgres(): bool
    {
        return $this->postgresSql !== null;
    }

    /**
     * Columns of the view in the given dialect, in order.
     *
     * @return list<array{0:string,1:string,2:string}>
     */
    public function columnsFor(string $dialect): array
    {
        if ($dialect !== 'postgres') {
            return $this->columns;
        }

        return array_values(array_filter($this->columns, fn (array $c): bool => !in_array($c[0], $this->postgresOmit, true)));
    }

    /** @return list<string> */
    public function columnNames(string $dialect = 'mariadb'): array
    {
        return array_map(static fn (array $c): string => $c[0], $this->columnsFor($dialect));
    }
}
