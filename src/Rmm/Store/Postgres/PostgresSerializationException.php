<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * Deadlock detected or serialization failure (SQLSTATE 40P01, 40001): the server rolled the transaction back. {@see PostgresConnectionInterface::transaction()}
 * repeats the whole callback a bounded number of times before this reaches the caller.
 *
 * @api
 */
final class PostgresSerializationException extends PostgresException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
