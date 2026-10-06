<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/** A job overran its declared timeout. Retried like any failed attempt (with backoff), then dead-lettered.
 *
 * @api
 */
final class JobTimeout extends \RuntimeException
{
}
