<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store\Migration;

/**
 * A data store migration that cannot start, cannot go on or must not be cut over. The message is meant for the administrator; it never contains a credential.
 *
 * @api
 */
final class MigrationException extends \RuntimeException
{
}
