<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The server refused the statement: syntax error, missing table, constraint violation, value too long, permission denied and every other SQLSTATE that is not
 * a connection, timeout or serialization failure. A bug or a schema that was not migrated, not an outage.
 *
 * @api
 */
final class PostgresQueryException extends PostgresException
{
}
