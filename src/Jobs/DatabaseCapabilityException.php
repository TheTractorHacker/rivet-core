<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/**
 * The database refused a statement because this server does not have the feature (for example `SKIP LOCKED`); the caller falls back.
 *
 * @internal
 */
final class DatabaseCapabilityException extends \RuntimeException
{
}
