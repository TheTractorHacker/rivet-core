<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Postgres;

/**
 * The statement was cancelled by the statement timeout (SQLSTATE 57014) or could not get its lock within the lock timeout (55P03, 55006).
 *
 * @api
 */
final class PostgresTimeoutException extends PostgresException
{
}
