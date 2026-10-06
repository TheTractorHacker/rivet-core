<?php

declare(strict_types=1);

namespace RivetCore\Jobs;

/** Thrown by a job handler when retrying cannot help (the thing the job needs no longer exists): the job is dead-lettered at once.
 *
 * @api
 */
final class PermanentJobFailure extends \RuntimeException
{
}
