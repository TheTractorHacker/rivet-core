<?php

declare(strict_types=1);

namespace RivetCore\Database;

/** Any storage failure (connect, prepare, execute, transaction).
 *
 * @api
 */
class DatabaseException extends \RuntimeException
{
}
