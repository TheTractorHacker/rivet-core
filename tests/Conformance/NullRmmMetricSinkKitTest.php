<?php

declare(strict_types=1);

namespace RivetCore\Tests\Conformance;

use RivetCore\Rmm\Contracts\RmmMetricSinkInterface;
use RivetCore\Rmm\Support\NullRmmMetricSink;
use RivetCore\Testing\RmmMetricSinkConformanceTestCase;

/** Core's own null sink passes the kit (the observable checks are skipped: it keeps nothing). */
final class NullRmmMetricSinkKitTest extends RmmMetricSinkConformanceTestCase
{
    protected function sink(): RmmMetricSinkInterface
    {
        return new NullRmmMetricSink();
    }
}
