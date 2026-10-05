<?php

declare(strict_types=1);

namespace RivetCore\Database;

/**
 * Storage contract for RivetCore. Core code never sees mysqli (or PDO): it
 * passes SQL with positional `?` placeholders and ordinary PHP values, and
 * gets arrays or a Core-owned ExecutionResult back.
 *
 * Parameters are string|int|float|bool|null. The adapter decides how to bind
 * them. Failures surface as DatabaseException, never as `false`.
 */
interface DatabaseInterface
{
    /** First row as an associative array, or null when nothing matches. */
    public function fetchOne(string $sql, array $params = []): ?array;

    /** All rows as a list of associative arrays (empty list when nothing matches). */
    public function fetchAll(string $sql, array $params = []): array;

    /** INSERT / UPDATE / DELETE / DDL. */
    public function execute(string $sql, array $params = []): ExecutionResult;

    /**
     * Run $callback inside a transaction: commit on return, roll back and
     * rethrow on any Throwable. Returns the callback's return value.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed;
}
