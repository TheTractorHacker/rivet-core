<?php

declare(strict_types=1);

namespace RivetCore\Rmm\Support;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;

/**
 * Discards every sample (editions without a Metrics subsystem).
 *
 * @api
 */
final class NullRmmMetricSink implements RmmMetricSinkInterface
{
    public function ingest(array $samples, int $integrationId): void
    {
    }
}
