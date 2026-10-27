<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Capacity;

/**
 * Where the token buckets of {@see HerdControl} live. One call takes one token if the bucket has one: the bucket holds at most $burst tokens and refills at
 * $perSecond. The take must be atomic (two requests never both get the last token). A store that cannot be reached must fail OPEN (admit): a missing guard is
 * better than a refused fleet.
 *
 * @api
 */
interface TokenBucketStoreInterface
{
    /**
     * @return array{ok:bool,retry_ms:int} ok = a token was taken; otherwise retry_ms is how long until the next token
     */
    public function take(string $key, int $burst, float $perSecond, int $nowMs): array;
}
