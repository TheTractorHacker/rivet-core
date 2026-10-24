<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store: the patch install history (`rmm_patch_history`). Append-only. The scan results, the available updates and the install ledger are control data.
 *
 * @api
 */
interface PatchHistoryStore
{
    /**
     * @param array{device_id:int,client_id:int,update_id:string,title:string,action:string,result:string,code:string,detail:string,at:string,job_id:string,user_id:?int} $entry
     *        `at` is a UTC database datetime; the caller has already cut the text fields to their column sizes
     */
    public function record(array $entry): void;

    /**
     * One device's history, newest first.
     *
     * @return array{rows:list<array<string,mixed>>,total:int} rows carry update_id, title, action, result, code, detail, at, job_id, user_id
     */
    public function forDevice(int $deviceId, int $limit, int $offset): array;

    /** Delete at most $limit entries older than $cutoffUtc; returns the rows deleted. */
    public function deleteOlderThan(string $cutoffUtc, int $limit): int;
}
