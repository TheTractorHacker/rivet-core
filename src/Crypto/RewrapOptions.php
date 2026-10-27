<?php

declare(strict_types=1);

namespace RivetCore\Crypto;

/**
 * Knobs of one {@see Rewrapper::run()}. Use named arguments.
 *
 * @api
 */
final class RewrapOptions
{
    /**
     * @param bool $dryRun        read and verify everything, write nothing, save no progress
     * @param bool $resume        continue from the saved cursor of an unfinished run (a finished run starts again from the top and, being idempotent, changes nothing)
     * @param int|null $maxBatches stop cleanly after this many batches (time slicing, tests); the saved cursor lets the next run continue
     * @param int|null $maxItems   stop cleanly after this many scanned rows
     * @param float|null $deadlineSeconds stop cleanly after this much wall time
     * @param bool $stopOnError    stop at the first value that cannot be read instead of counting it and moving on
     * @param int|null $actorUserId recorded in the audit event
     */
    public function __construct(
        public readonly int $batchSize = 200,
        public readonly bool $dryRun = false,
        public readonly bool $resume = true,
        public readonly ?int $maxBatches = null,
        public readonly ?int $maxItems = null,
        public readonly ?float $deadlineSeconds = null,
        public readonly bool $stopOnError = false,
        public readonly ?int $actorUserId = null,
    ) {
        if ($batchSize < 1 || $batchSize > 5000) {
            throw new \InvalidArgumentException('batchSize must be 1-5000.');
        }
    }
}
