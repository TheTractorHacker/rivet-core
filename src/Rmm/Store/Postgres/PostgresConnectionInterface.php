<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The port between Core's PostgreSQL stores and a PostgreSQL driver (phase 2 of #89). Core's stores never see a driver handle: they pass SQL with positional `?`
 * placeholders and PHP scalars, and get arrays back. {@see Pdo\PdoPostgresConnection} is the shipped adapter; an edition may pass its own.
 *
 * Contract every implementation keeps:
 *  - the session runs in UTC (`timestamptz` values go in and come out as UTC wall clock strings `Y-m-d H:i:s` through the stores' own `to_char`);
 *  - values are always sent as bound parameters on server-side prepared statements, never interpolated; a `?` inside the SQL text is a placeholder, so the jsonb
 *    operators that use `?` must not be written (use `jsonb_exists()`);
 *  - a statement timeout and a lock timeout apply to every statement;
 *  - failures surface as {@see PostgresException} subclasses (never `false`, never the driver's exception, never with the password or a bound value in the message);
 *  - after a {@see PostgresConnectionException} the next call opens a new session;
 *  - the connection is opened on first use, so building it never touches the network.
 *
 * Parameters are `string|int|float|bool|null`. Rows are associative arrays: integers and floats come back as PHP numbers, booleans as bool, `numeric` and `jsonb`
 * as strings, `bytea` is not supported (select `encode(col, 'hex')`).
 *
 * @api
 */
interface PostgresConnectionInterface
{
    /**
     * All rows, possibly none.
     *
     * @param list<mixed> $params
     * @return list<array<string,mixed>>
     * @throws PostgresException
     */
    public function query(string $sql, array $params = []): array;

    /**
     * The first row, or null.
     *
     * @param list<mixed> $params
     * @return array<string,mixed>|null
     * @throws PostgresException
     */
    public function queryOne(string $sql, array $params = []): ?array;

    /**
     * The first column of the first row, or null.
     *
     * @param list<mixed> $params
     * @throws PostgresException
     */
    public function value(string $sql, array $params = []): mixed;

    /**
     * INSERT / UPDATE / DELETE / DDL; returns the rows affected (rows actually inserted for `ON CONFLICT DO NOTHING`).
     *
     * @param list<mixed> $params
     * @throws PostgresException
     */
    public function execute(string $sql, array $params = []): int;

    /**
     * Run $callback in a transaction: commit when it returns, roll back and rethrow when it throws. Nested calls join the open transaction (a savepoint, so an
     * inner failure the caller catches does not poison the outer one). A deadlock or serialization failure of the OUTERMOST transaction repeats the callback, at
     * most the configured number of times, so a callback must be safe to run again.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws PostgresException
     */
    public function transaction(callable $callback): mixed;

    public function inTransaction(): bool;

    /** The server version as the server reports it ("16.4"), opening the connection if needed. */
    public function serverVersion(): string;

    /** Close the session; the next call opens a new one. */
    public function close(): void;
}
