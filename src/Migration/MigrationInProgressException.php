<?php

declare(strict_types=1);

namespace RivetCore\Migration;

/**
 * Another process holds the migration lock for longer than the configured wait. Nothing was changed; try again.
 * Extends \RuntimeException, so existing `catch (\RuntimeException)` callers keep working.
 *
 * @api
 */
final class MigrationInProgressException extends \RuntimeException
{
}
