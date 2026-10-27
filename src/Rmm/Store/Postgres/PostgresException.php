<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

use RivetCore\Database\DatabaseException;

/**
 * A failure of the PostgreSQL connection or of a statement on it (phase 2 of #89). Every driver error is mapped to one of the subclasses; callers catch
 * {@see DatabaseException} (the Core storage failure) or the precise class. The message never contains the connection password or a bound value.
 *
 * @api
 */
class PostgresException extends DatabaseException
{
    public function __construct(string $message, private readonly string $sqlState = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    /** The five character SQLSTATE the server reported, or '' when the failure did not come from the server (driver, network, configuration). */
    public function sqlState(): string
    {
        return $this->sqlState;
    }

    /** Whether the same transaction may be tried again from the start (deadlock, serialization failure). */
    public function isRetryable(): bool
    {
        return false;
    }
}
