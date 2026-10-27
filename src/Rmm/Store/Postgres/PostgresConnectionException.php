<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The server cannot be reached or the session was lost: refused or timed-out connect, bad credentials, a connection dropped mid-statement, server shutdown or
 * restart, too many connections, or no connection configured at all. The hot store is DOWN from the caller's point of view ({@see \RivetCore\Rmm\Store\Postgres\Guard\HotStoreGuard}).
 *
 * @api
 */
final class PostgresConnectionException extends PostgresException
{
}
