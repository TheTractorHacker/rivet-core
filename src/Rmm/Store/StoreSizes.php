<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * How big the hot RMM tables of one store are (phase 3 of #89): rows and bytes per table, for the store that is ACTIVE. Rows are the engine's estimates
 * (information_schema.TABLE_ROWS, pg_class.reltuples), never counts. It never throws: a store that cannot be asked answers `available = false` with the reason.
 *
 * @api
 */
interface StoreSizes
{
    /**
     * @return array{kind:string,available:bool,error:?string,tables:list<array{table:string,rows:int,bytes:int}>,total_rows:int,total_bytes:int,oldest_at:?string}
     *         `oldest_at` is the oldest history row (UTC `Y-m-d H:i:s`) among the indexed time columns, or null when unknown
     */
    public function report(): array;
}
