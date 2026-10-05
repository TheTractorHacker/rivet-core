<?php

declare(strict_types=1);

namespace RivetCore\Database;

/** Any storage failure (connect, prepare, execute, transaction). */
class DatabaseException extends \RuntimeException
{
}
