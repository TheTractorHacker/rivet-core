<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/** Thrown inside a PostgreSQL transaction to roll it back on purpose (the connection test creates a table to prove the role may, then undoes it). @internal */
final class ProbeRollback extends \RuntimeException
{
}
