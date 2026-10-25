<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

/**
 * Table-level access to one hot store for the migration: the same operations on MariaDB and on PostgreSQL, rows as normalised arrays (integers as int, doubles as float,
 * text as string, times as `Y-m-d H:i:s` UTC, binary as lower case hex, NULL as null; see {@see HotTable} for the types). The migration never goes through the
 * store interfaces for the bulk copy: it needs keyset reads, explicit surrogate ids and conflict handling that the services have no use for.
 *
 * @internal
 */
interface StoreEndpoint
{
    /** `mariadb` or `postgres` */
    public function kind(): string;

    public function tableExists(HotTable $table): bool;

    /** @return list<string> the table's column names in the database (for the registry test) */
    public function columns(HotTable $table): array;

    /** Rows of the table, optionally only those whose time column is before / at or after the given instant. */
    public function count(HotTable $table, ?string $timeBefore = null, ?string $timeFrom = null): int;

    /** Highest surrogate id (0 when empty or the table has none). */
    public function maxId(HotTable $table): int;

    /**
     * Keyset page: the rows after $after (the key values of the last row read, in key order; null = from the start), ordered by key.
     *
     * @param ?int $upToId for an id table: only ids up to and including this one
     * @return list<array<string,mixed>>
     */
    public function readBatch(HotTable $table, ?array $after, int $limit, ?int $upToId = null, ?string $timeBefore = null, ?string $timeFrom = null): array;

    /**
     * The rows of one device (or asset) that are older than $timeBefore, ordered by time then key; for the checksum sample.
     *
     * @return list<array<string,mixed>>
     */
    public function readOwner(HotTable $table, int $owner, ?string $timeBefore, int $limit): array;

    /**
     * Insert rows. $mode: `ignore` (a row that exists is kept), `overwrite` (a row that exists is replaced by the given values), `newer` (the latest-reading rule: replaced only when the
     * given time column is newer). $withId keeps the given surrogate ids of an id table; otherwise the store assigns them.
     *
     * @param list<array<string,mixed>> $rows
     * @return int rows actually written
     */
    public function insert(HotTable $table, array $rows, string $mode, bool $withId): int;

    /** Make the next surrogate id at least $next (never lowers it, never below the highest id present). */
    public function raiseNextId(HotTable $table, int $next): void;

    /** Remove every row of the table. */
    public function truncate(HotTable $table): void;

    /** Per-device row counts of an event table: device id => rows. */
    public function countsByDevice(HotTable $table): array;

    /** Distinct owners (device or asset ids) that have rows, for sampling. @return list<int> */
    public function owners(HotTable $table, int $limit): array;

    public function transaction(\Closure $work): mixed;

    /** Cheap liveness check that throws when the store cannot be reached. */
    public function ping(): void;
}
