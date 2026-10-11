<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Remote;

/**
 * Thrown inside a transaction when the job layer refuses a job, so the ledger row written beside it is rolled back.
 *
 * @api
 */
final class JobRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'conflict')
    {
        parent::__construct($message);
    }
}
