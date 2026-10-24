<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Store;

/**
 * An RMM data store was asked for that this release does not provide ("postgres" in phase 1 of #89) or does not know.
 *
 * @api
 */
final class DataStoreUnavailableException extends \RuntimeException
{
}
