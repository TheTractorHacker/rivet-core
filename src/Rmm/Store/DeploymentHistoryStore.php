<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * HOT store (uncertain, see the design note): the software deployment history (`rmm_package_history`). Append-only. The package catalog, the install ledger and
 * the assignments are control data.
 *
 * @api
 */
interface DeploymentHistoryStore
{
    /**
     * @param array{device_id:int,client_id:int,package_id:int,version_id:?int,action:string,mode:string,result:string,code:string,detail:string,version:string,at:string,job_id:?string,user_id:?int} $entry
     */
    public function record(array $entry): void;

    /**
     * One device's history, newest first. `name` is the package's CURRENT name (the MariaDB store reads it from the catalog; a store in another database must be given a catalog lookup).
     *
     * @return array{rows:list<array<string,mixed>>,total:int} rows carry package_id, name, action, mode, result, code, detail, version, at, job_id, user_id
     */
    public function forDevice(int $deviceId, int $limit, int $offset): array;

    /** Delete at most $limit entries older than $cutoffUtc; returns the rows deleted. */
    public function deleteOlderThan(string $cutoffUtc, int $limit): int;
}
