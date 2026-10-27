<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store: the per-check result ring (`endpoint_agent_check_history`): the trend dots and the availability figure of a device page. Append-only; a row
 * is never updated. The decision WHICH results are worth a row (status change, or one per gap) is made by the caller from control data.
 *
 * @api
 */
interface CheckHistoryStore
{
    /**
     * @param list<array{key:string,status:string,detail:string,at:string}> $entries `at` is a UTC database datetime
     */
    public function append(int $deviceId, array $entries): void;

    /**
     * Results of one check at or after $sinceUtc, oldest first (ties in insertion order).
     *
     * @return list<array{status:string,detail:string,reported_at:string}>
     */
    public function since(int $deviceId, string $checkKey, string $sinceUtc, int $limit): array;

    /**
     * The newest result strictly before $beforeUtc, or null.
     *
     * @return array{status:string,reported_at:string}|null
     */
    public function lastBefore(int $deviceId, string $checkKey, string $beforeUtc): ?array;

    /** Delete at most $limit results reported before $cutoffUtc; returns the rows deleted. The caller repeats while it returns $limit. */
    public function deleteOlderThan(string $cutoffUtc, int $limit): int;
}
