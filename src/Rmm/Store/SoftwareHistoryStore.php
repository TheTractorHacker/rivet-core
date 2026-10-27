<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store: the software change log (`rmm_software_history`: installed, upgraded, downgraded, removed). Append-only. The CURRENT list of a device
 * (`rmm_device_software`) is control data and stays in the control database.
 *
 * @api
 */
interface SoftwareHistoryStore
{
    /**
     * @param list<array{key:string,name:string,source:string,publisher:string,type:string,old:?string,new:?string}> $changes `type` is installed|upgraded|downgraded|removed
     * @param string $atUtc the UTC database datetime every change of the batch is stamped with
     */
    public function append(int $deviceId, array $changes, string $atUtc): void;

    /**
     * Changes of one device, newest first.
     *
     * @param string|null $nameLike substring of the software name (case-insensitive), or null
     * @return array{rows:list<array<string,mixed>>,total:int} rows carry name, source, change_type, old_version, new_version, publisher, occurred_at
     */
    public function forDevice(int $deviceId, ?string $nameLike, int $limit, int $offset): array;

    /**
     * Changes of one device between two UTC datetimes (inclusive), newest first.
     *
     * @return list<array<string,mixed>> same columns as {@see forDevice()}
     */
    public function between(int $deviceId, string $fromUtc, string $toUtc, int $limit): array;

    /** Delete at most $limit changes that occurred before $cutoffUtc; returns the rows deleted. */
    public function deleteOlderThan(string $cutoffUtc, int $limit): int;
}
